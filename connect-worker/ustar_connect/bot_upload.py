"""Bounded photo transfer to the authenticated Academy draft; no public URL or password."""
import base64
from .bridge import _token


def upload_photo(api,bridge,event):
    common={k:event.payload[k] for k in ('auth_date','event','provider','subject','tenant')}
    def send(part,upload='',chunk='',file=''):
        result=bridge.request('bot.upload',{**common,'part':part,'upload':upload,'chunk':chunk,'file':file})
        if not isinstance(result,dict) or result.get('ok') is not True or not isinstance(result.get('result'),dict):
            raise ValueError('invalid_bot_upload')
        return result['result']
    start=send('start',file=event.photo[0]);token=_token(start.get('upload'),'bf4')
    data=api.download_bot_photo(event.photo[0],event.photo[1])
    if not isinstance(data,bytes) or not 0<len(data)<=512*1024:raise ValueError('invalid_bot_photo')
    for n,offset in enumerate(range(0,len(data),6000),1):
        result=send(str(n),token,base64.b64encode(data[offset:offset+6000]).decode('ascii'))
        if result.get('next')!=n+1:raise ValueError('invalid_bot_upload')
    result=send('finish',token)
    if result.get('complete') is not True or result.get('upload')!=token:raise ValueError('invalid_bot_upload')
    return token
