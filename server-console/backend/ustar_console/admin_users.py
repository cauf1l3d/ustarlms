"""Owner-only local accounts; credentials never appear in API responses."""
from typing import Literal

from fastapi import HTTPException, Request
from pydantic import BaseModel, ConfigDict, Field, model_validator

from .auth import Denied, USERNAME, UserChangeError


Role = Literal['viewer', 'operator', 'owner']


class Credentials(BaseModel):
    model_config = ConfigDict(extra='forbid', strict=True)
    password: str = Field(min_length=1, max_length=256)
    otp: str = Field(default='', max_length=6, pattern=r'^[0-9]{0,6}$')


class CreateUser(Credentials):
    username: str = Field(min_length=1, max_length=48, pattern=r'^[a-z][a-z0-9_.-]*$')
    role: Role
    new_password: str = Field(min_length=14, max_length=256)


class UpdateUser(Credentials):
    role: Role | None = None
    enabled: bool | None = None
    new_password: str | None = Field(default=None, min_length=14, max_length=256)

    @model_validator(mode='after')
    def supplied_changes(self):
        changed = self.model_fields_set & {'role', 'enabled', 'new_password'}
        if not changed or any(getattr(self, field) is None for field in changed):
            raise ValueError('At least one non-null account change is required')
        return self


def register(app, auth, identity, write_identity, elevate, before_change=None):
    def owner(request, write=False):
        actor = write_identity(request) if write else identity(request)
        if actor['role'] != 'owner':
            raise HTTPException(403, 'owner_required')
        return actor

    @app.get('/api/admin/users')
    def users(request: Request):
        actor = owner(request)
        try:
            return {'users': auth.list_users(actor)}
        except Denied:
            raise HTTPException(403, 'owner_required') from None

    @app.post('/api/admin/users', status_code=201)
    def create(body: CreateUser, request: Request):
        actor = owner(request, True)
        elevate(actor, body)
        try:
            return {'user': auth.create_user(actor, body.username, body.new_password, body.role)}
        except Denied:
            raise HTTPException(403, 'owner_required') from None
        except UserChangeError as error:
            raise HTTPException(409, error.code) from None

    @app.patch('/api/admin/users/{username}')
    def update(username: str, body: UpdateUser, request: Request):
        actor = owner(request, True)
        if not USERNAME.fullmatch(username):
            raise HTTPException(422, 'invalid_request')
        elevate(actor, body)
        try:
            changes = body.model_dump(include={'role', 'enabled', 'new_password'}, exclude_unset=True)
            return {'user': auth.update_user(actor, username, before_change=before_change, **changes)}
        except Denied:
            raise HTTPException(403, 'owner_required') from None
        except UserChangeError as error:
            raise HTTPException(409, error.code) from None
        except LookupError:
            raise HTTPException(404, 'user_not_found') from None
        except (OSError, TimeoutError):
            raise HTTPException(409, 'notification_revocation_unavailable') from None
