"""Exact self-profile boundary. No role grant or private upstream field is reflected."""
from .bridge import _token


def grant(value, now):
    if (not isinstance(value,dict) or set(value)!={'session','scope','expires_at','idle_expires_at'}
            or value['scope']!='actor_read' or type(value['expires_at']) is not int
            or not now<value['expires_at']<=now+28800 or type(value['idle_expires_at']) is not int
            or not now<value['idle_expires_at']<=min(now+900,value['expires_at'])):
        raise ValueError('invalid_actor_response')
    _token(value['session'],'ua1')
    return value


def profile(value, now, absolute):
    if (not isinstance(value,dict) or set(value)!={'scope','expires_at','idle_expires_at','features','profile'}
            or value['scope']!='actor_read' or type(value['expires_at']) is not int
            or value['expires_at']!=absolute or type(value['idle_expires_at']) is not int
            or not now<value['idle_expires_at']<=min(now+900,absolute) or value['features']!=['profile']):
        raise ValueError('invalid_actor_response')
    data=value['profile']
    if (not isinstance(data,dict) or set(data)!={'username','fullname','position','department','account_state'}
            or data['account_state']!='active' or any(not isinstance(v,str) or len(v)>256
                or any(ord(c)<32 or ord(c)==127 for c in v) for v in data.values())
            or not data['username'] or not data['fullname']):
        raise ValueError('invalid_actor_response')
    return value
