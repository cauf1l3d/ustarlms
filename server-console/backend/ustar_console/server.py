from pathlib import Path
import hmac
import time

from fastapi import FastAPI, HTTPException, Request, Response
from fastapi.responses import FileResponse, JSONResponse, StreamingResponse
from fastapi.middleware.trustedhost import TrustedHostMiddleware
from fastapi.exceptions import RequestValidationError
from pydantic import BaseModel, ConfigDict, Field
from urllib.parse import urlsplit

from .auth import Auth, Denied
from .config import load
from .data import snapshot
from .metrics import history

COOKIE = '__Host-ustar-console'
WEB = Path(__file__).parent/'web'


class Login(BaseModel):
    model_config = ConfigDict(extra='forbid')
    username: str = Field(min_length=1, max_length=48, pattern=r'^[a-z][a-z0-9_.-]*$')
    password: str = Field(min_length=1, max_length=256)
    otp: str = Field(default='',max_length=6,pattern=r'^[0-9]{0,6}$')


class Password(BaseModel):
    model_config = ConfigDict(extra='forbid')
    current: str = Field(min_length=1, max_length=256)
    replacement: str = Field(min_length=14, max_length=256)
    otp: str = Field(default='',max_length=6,pattern=r'^[0-9]{0,6}$')


class Plan(BaseModel):
    model_config=ConfigDict(extra='forbid')
    action: str = Field(pattern=r'^(manual_backup|academy_restart|lab_start|lab_stop)$')


class Credentials(BaseModel):
    model_config=ConfigDict(extra='forbid')
    password: str = Field(min_length=1,max_length=256)
    otp: str = Field(default='',max_length=6,pattern=r'^[0-9]{0,6}$')


class Confirm(Credentials):
    plan_id: str = Field(pattern=r'^[0-9a-f]{32}$')
    idempotency: str = Field(pattern=r'^[0-9a-f-]{16,64}$')
    confirmation: str = Field(max_length=32)


def create_app(settings=None):
    settings = settings or load()
    auth = Auth(settings)
    app = FastAPI(docs_url=None, redoc_url=None, openapi_url=None)
    app.state.auth = auth
    app.add_middleware(TrustedHostMiddleware, allowed_hosts=[urlsplit(settings.origin).hostname])

    @app.exception_handler(RequestValidationError)
    async def invalid_input(request, exc):
        # Pydantic's default error response may echo password input.
        return JSONResponse({'detail':'invalid_request'},status_code=422)

    @app.middleware('http')
    async def guards(request, call_next):
        if request.method in ('POST','PUT','PATCH','DELETE'):
            if request.headers.get('origin') != settings.origin:
                return JSONResponse({'detail':'origin_rejected'},status_code=403)
            if request.headers.get('content-type','').split(';')[0] != 'application/json':
                return JSONResponse({'detail':'json_required'},status_code=415)
            # Read at most 4KiB, even for chunked bodies without Content-Length.
            total = bytearray()
            async for part in request.stream():
                total.extend(part)
                if len(total) > 4096:
                    return JSONResponse({'detail':'body_limit'},status_code=413)
            request._body = bytes(total)
        response = await call_next(request)
        response.headers.update({
            'Cache-Control':'no-store', 'X-Content-Type-Options':'nosniff',
            'Referrer-Policy':'no-referrer', 'X-Frame-Options':'DENY',
            'Permissions-Policy':'camera=(), microphone=(), geolocation=()',
            'Content-Security-Policy':"default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'",
            'Strict-Transport-Security':'max-age=31536000',
        })
        return response

    def identity(request):
        try:
            return auth.session(request.cookies.get(COOKIE))
        except Denied:
            raise HTTPException(401,'session_expired') from None

    def write_identity(request):
        session = identity(request)
        if not hmac.compare_digest(request.headers.get('x-csrf-token',''),session['csrf']):
            raise HTTPException(403,'csrf_rejected')
        return session

    def operator(request,write=False):
        value=write_identity(request) if write else identity(request)
        if value['role'] not in ('operator','owner'):
            raise HTTPException(403,'operator_required')
        return value

    def elevate(value,body):
        try:
            auth.authorize(value,body.password,body.otp)
        except Denied as error:
            raise HTTPException(429 if error.retry else 403,'credentials_rejected',
                headers={'Retry-After':str(error.retry)} if error.retry else None) from None

    def broker(method,**kwargs):
        from .operations import rpc
        try:
            return rpc(method,**kwargs)
        except (OSError,ValueError,TimeoutError):
            raise HTTPException(409,'operation_unavailable_or_refused') from None

    @app.post('/api/login')
    def login(body:Login, request:Request, response:Response):
        try:
            token = auth.login(body.username, body.password, request.client.host if request.client else 'unknown',body.otp)
        except Denied as denied:
            raise HTTPException(429 if denied.retry else 401,'login_rejected',
                                headers={'Retry-After':str(denied.retry)} if denied.retry else None) from None
        response.set_cookie(COOKIE,token,httponly=True,secure=True,samesite='strict',path='/',max_age=settings.absolute_seconds)
        return {'status':'authenticated'}

    @app.get('/api/session')
    def session(request:Request):
        value = identity(request)
        return dict(username=value['username'],role=value['role'],csrf=value['csrf'],
                    expires_at=min(value['created']+settings.absolute_seconds,value['seen']+settings.idle_seconds))

    @app.post('/api/activity')
    def activity(request:Request):
        auth.touch(write_identity(request))
        return {'status':'active'}

    @app.post('/api/logout')
    def logout(request:Request, response:Response):
        auth.logout(write_identity(request))
        response.delete_cookie(COOKIE,path='/',secure=True,httponly=True,samesite='strict')
        return {'status':'signed_out'}

    @app.post('/api/password')
    def password(body:Password, request:Request, response:Response):
        value = write_identity(request)
        try:
            auth.password(value,body.current,body.replacement,body.otp)
        except Denied as denied:
            raise HTTPException(429 if denied.retry else 403,'password_rejected') from None
        response.delete_cookie(COOKIE,path='/',secure=True,httponly=True,samesite='strict')
        return {'status':'sessions_revoked'}

    @app.get('/api/overview')
    def overview(request:Request):
        identity(request)
        value=snapshot(settings.snapshot)
        from .notification_state import read
        alerts=read()
        if alerts and alerts['fresh']:
            value['notifications']=alerts
            rule=next(c for c in value['checks'] if c['id']=='rules')
            rule.update(status=alerts['status'] if alerts.get('polling')=='ok' else 'no_data',
                        reason_code='outbox_status',source='notification_worker',observed_at=alerts['observed_at'])
            resources=next(c for c in value['checks'] if c['id']=='resource_rules')
            resources.update(status='ok' if alerts.get('evaluation')=='ok' else 'no_data',reason_code='sustained_rules',source='notification_worker',observed_at=alerts['observed_at'])
            for row in alerts['rules']:
                if row['key'] in ('cpu_sustained','ram_sustained'):
                    status='no_data' if alerts['evaluation']!='ok' or row['state']=='no_data' else row['emitted']
                    value['checks'].append(dict(id=row['key'],label='CPU: длительная нагрузка' if row['key'].startswith('cpu') else 'RAM: длительная нехватка',
                        domain='server',status=status,reason_code='sustained_rules',source='notification_worker',observed_at=alerts['observed_at']))
        priority={'critical':4,'no_data':3,'warning':2,'ok':1,'stopped':0}
        for domain in value['domains']:
            value['domains'][domain]=max((c['status'] for c in value['checks'] if c['domain']==domain),
                                         key=priority.get,default='no_data')
        return value

    @app.get('/api/history')
    def chart(request:Request, metric:str, period:str='1h'):
        identity(request)
        try:
            if settings.native_history:
                from .history_store import read
                return read(metric,period)
            return history(settings,metric,period)
        except ValueError:
            raise HTTPException(422,'unsupported_query') from None

    @app.get('/api/events')
    def events(request:Request):
        identity(request)
        from .notification_state import read
        alerts=read()
        return dict(events=auth.events(),scope='console_security_and_monitoring',host_logs='not_connected',
                    incidents=alerts['incidents'] if alerts else [],
                    incidents_status=('ok' if alerts['fresh'] else 'stale') if alerts else 'no_data',
                    incidents_observed_at=alerts['observed_at'] if alerts else None)

    @app.get('/api/operations')
    def operations(request:Request):
        value=operator(request)
        return broker('status',actor=value['username'])

    @app.post('/api/operations/plan')
    def plan(body:Plan,request:Request):
        value=operator(request,True)
        return broker('plan',actor=value['username'],action=body.action)

    @app.post('/api/operations/confirm')
    def confirm(body:Confirm,request:Request):
        value=operator(request,True)
        elevate(value,body)
        return broker('confirm',actor=value['username'],plan_id=body.plan_id,
                      idempotency=body.idempotency,confirmation=body.confirmation)

    @app.post('/api/backup/download')
    def grant(body:Credentials,request:Request):
        value=operator(request,True)
        elevate(value,body)
        return broker('grant',actor=value['username'],session=value['token'])

    @app.get('/api/backup/archive/{token}')
    def download(token:str,request:Request):
        import os,re
        value=operator(request)
        if not re.fullmatch(r'[A-Za-z0-9_-]{40,64}',token):
            raise HTTPException(404)
        archive=broker('archive',actor=value['username'],session=value['token'],token=token)
        fd=archive.pop('_fd')
        size=archive['bytes'];start,end=0,size-1
        try:
            requested=request.headers.get('range')
            if requested:
                match=re.fullmatch(r'bytes=([0-9]+)-([0-9]*)',requested)
                if not match: raise ValueError('Invalid range')
                start=int(match[1]);end=int(match[2]) if match[2] else size-1
                if not 0<=start<=end<size: raise ValueError('Invalid range')
            headers={'Content-Disposition':'attachment; filename="'+archive['file']+'"',
                     'Content-Length':str(end-start+1),'Accept-Ranges':'bytes','ETag':'"'+archive['sha256']+'"'}
            if requested: headers['Content-Range']=f'bytes {start}-{end}/{size}'
        except (ValueError,KeyError,TypeError):
            os.close(fd)
            raise HTTPException(416,'range_rejected') from None
        source=os.fdopen(fd,'rb',closefd=True)
        def stream():
            try:
                remaining=end-start+1
                source.seek(start)
                while remaining:
                    part=source.read(min(262144,remaining))
                    if not part: break
                    remaining-=len(part)
                    yield part
            finally:
                source.close()
        class ArchiveResponse(StreamingResponse):
            async def __call__(self,scope,receive,send):
                try:
                    await super().__call__(scope,receive,send)
                finally:
                    source.close()
        return ArchiveResponse(stream(),status_code=206 if requested else 200,media_type='application/octet-stream',headers=headers)

    @app.get('/healthz')
    def healthz():
        # Service liveness, deliberately distinct from Academy/system health.
        return {'service':'ustar-console', 'status':'alive'}

    @app.get('/')
    def index():
        if not (WEB/'index.html').is_file():
            raise HTTPException(503,'ui_not_built')
        return FileResponse(WEB/'index.html')

    @app.get('/assets/{filename}')
    def asset(filename:str):
        import re
        if not re.fullmatch(r'[A-Za-z0-9_-]+\.(js|css)', filename):
            raise HTTPException(404)
        path = WEB/'assets'/filename
        if not path.is_file():
            raise HTTPException(404)
        return FileResponse(path)

    return app


def main():
    import uvicorn
    import ssl
    settings=load()
    tls=dict(ssl_certfile='/etc/ustar-console/tls/server.crt',ssl_keyfile='/etc/ustar-console/tls/server.key',
             ssl_version=ssl.PROTOCOL_TLS_SERVER) if settings.tls else {}
    uvicorn.run(create_app(settings),host=settings.listen,port=settings.port,proxy_headers=not settings.tls,
                forwarded_allow_ips='127.0.0.1',access_log=False,server_header=False,limit_concurrency=32,
                timeout_keep_alive=5,**tls)
