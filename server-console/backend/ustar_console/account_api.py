"""Session-bound account preferences; bot credentials never enter the web process."""
from pathlib import Path

from fastapi import HTTPException, Request
from pydantic import BaseModel, ConfigDict, Field

from .auth import Denied


TELEGRAM_CREDENTIAL=Path('/etc/ustar-console/telegram.json')


class Credentials(BaseModel):
    model_config=ConfigDict(extra='forbid',strict=True)
    password: str=Field(min_length=1,max_length=256)
    otp: str=Field(default='',max_length=6,pattern=r'^[0-9]{0,6}$')


class Preferences(Credentials):
    preferences: dict


def rpc(method,**kwargs):
    from .notifier_control import rpc as call
    return call(method,**kwargs)


def _call(method,**kwargs):
    try:
        return rpc(method,**kwargs)
    except (OSError,TimeoutError):
        raise HTTPException(503,'notifier_unavailable') from None
    except ValueError:
        raise HTTPException(409,'notification_request_refused') from None


def before_user_change(username):
    """Do not commit an account revocation while its dynamic bot access survives."""
    try:
        return rpc('revoke',username=username)
    except FileNotFoundError:
        # A deployment without a bot has never granted dynamic bot access. Existing
        # credential configuration requires a live worker: fail closed otherwise.
        if not TELEGRAM_CREDENTIAL.exists():
            return None
        raise HTTPException(503,'notifier_unavailable') from None
    except (OSError,TimeoutError):
        if not TELEGRAM_CREDENTIAL.exists():return None
        raise HTTPException(503,'notifier_unavailable') from None
    except ValueError as error:
        if getattr(error,'code',None)=='notifier_unavailable':
            if not TELEGRAM_CREDENTIAL.exists():return None
            raise HTTPException(503,'notifier_unavailable') from None
        raise HTTPException(409,'notification_request_refused') from None


def register(app,auth,identity,write_identity,elevate):
    def change(value,method,**kwargs):
        # Serialize account changes and link creation across the two private stores.
        # A request that passed reauth before another owner revoked it cannot create
        # a fresh Telegram code after that revocation commits.
        with auth.connection() as db:
            db.execute('BEGIN IMMEDIATE')
            try:auth.active_session(db,value)
            except Denied:raise HTTPException(401,'session_expired') from None
            result=_call(method,username=value['username'],**kwargs)
            auth.audit(db,'telegram_preferences_updated' if method=='set_preferences' else
                       'telegram_link_code_created' if method=='create_link' else 'telegram_unlinked')
            db.commit()
            return result

    @app.get('/api/account/telegram')
    def account(request:Request):
        value=identity(request)
        return _call('get_account',username=value['username'])

    @app.post('/api/account/telegram/link')
    def create_link(body:Credentials,request:Request):
        value=write_identity(request);elevate(value,body)
        return change(value,'create_link')

    @app.post('/api/account/telegram/unlink')
    def unlink(body:Credentials,request:Request):
        value=write_identity(request);elevate(value,body)
        return change(value,'unlink')

    @app.put('/api/account/notifications')
    def preferences(body:Preferences,request:Request):
        value=write_identity(request);elevate(value,body)
        return change(value,'set_preferences',preferences=body.preferences)
