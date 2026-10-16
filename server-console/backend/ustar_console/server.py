from pathlib import Path
import hmac
import time

from fastapi import FastAPI, HTTPException, Request, Response
from fastapi.responses import FileResponse, JSONResponse
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


class Password(BaseModel):
    model_config = ConfigDict(extra='forbid')
    current: str = Field(min_length=1, max_length=256)
    replacement: str = Field(min_length=14, max_length=256)


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

    @app.post('/api/login')
    def login(body:Login, request:Request, response:Response):
        try:
            token = auth.login(body.username, body.password, request.client.host if request.client else 'unknown')
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
            auth.password(value,body.current,body.replacement)
        except Denied as denied:
            raise HTTPException(429 if denied.retry else 403,'password_rejected') from None
        response.delete_cookie(COOKIE,path='/',secure=True,httponly=True,samesite='strict')
        return {'status':'sessions_revoked'}

    @app.get('/api/overview')
    def overview(request:Request):
        identity(request)
        return snapshot(settings.snapshot)

    @app.get('/api/history')
    def chart(request:Request, metric:str, period:str='1h'):
        identity(request)
        try:
            return history(settings,metric,period)
        except ValueError:
            raise HTTPException(422,'unsupported_query') from None

    @app.get('/api/events')
    def events(request:Request):
        identity(request)
        return {'events':auth.events(),'scope':'console_security_only', 'host_logs':'not_connected'}

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
    uvicorn.run(create_app(),host='127.0.0.1',port=8098,proxy_headers=True,
                forwarded_allow_ips='127.0.0.1',access_log=False,server_header=False,limit_concurrency=32,
                timeout_keep_alive=5)
