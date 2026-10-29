"""Escaped Telegram cards with bounded progress charts, compact tables and server-owned buttons."""
import html
import json
import re
import unicodedata
from urllib.parse import urlsplit, parse_qsl

from .bridge import _token


def _text(value, limit):
    if not isinstance(value, str):
        raise ValueError('invalid_bot_view')
    value=''.join(c for c in value if c in '\n\t' or unicodedata.category(c) not in ('Cc','Cs'))
    clipped=len(value)>limit
    return value[:limit]+('… [текст сокращён]' if clipped else '')


def _confirmation_url(value, origin):
    if not isinstance(value,str) or not isinstance(origin,str):
        raise ValueError('invalid_bot_url')
    actual,base=urlsplit(value),urlsplit(origin)
    if (base.scheme!='https' or not base.hostname or base.username or base.password
            or base.path not in ('','/') or base.query or base.fragment
            or actual.scheme!=base.scheme or actual.netloc!=base.netloc or actual.username or actual.password
            or actual.path!='/local/ustar/connect_bot_confirm.php' or actual.fragment):
        raise ValueError('invalid_bot_url')
    values=parse_qsl(actual.query,keep_blank_values=True,strict_parsing=True)
    if len(values)!=1 or values[0][0]!='action':
        raise ValueError('invalid_bot_url')
    _token(values[0][1],'cb4')
    return value


def render_view(view, *, academy_origin=None):
    if (not isinstance(view,dict) or set(view) not in ({'title','text','buttons','metrics','rows'}, {'title','text','buttons','metrics','rows','photos'})
            or not all(isinstance(view[k],list) for k in ('buttons','metrics','rows'))
            or len(view['buttons'])>24 or len(view['metrics'])>5):
        raise ValueError('invalid_bot_view')
    photos=view.get('photos',[])
    if not isinstance(photos,list) or len(photos)>5 or any(not isinstance(p,str) or not re.fullmatch(r'[A-Za-z0-9_-]{1,256}',p) for p in photos):
        raise ValueError('invalid_bot_photo')
    parts=['<b>'+html.escape(_text(view['title'],120))+'</b>']
    text=_text(view['text'],1500)
    if text:parts.append(html.escape(text))
    for metric in view['metrics']:
        if (not isinstance(metric,dict) or set(metric)!={'label','value','total'}
                or type(metric['value']) is not int or type(metric['total']) is not int
                or not 0<=metric['value']<2**31 or not 0<=metric['total']<2**31):
            raise ValueError('invalid_bot_metric')
        label=html.escape(_text(metric['label'],40))
        value,total=metric['value'],metric['total']
        cells=min(10,round(10*value/total)) if total else 0
        parts.append(label+' · <b>'+str(value)+'/'+str(total)+'</b>\n'+
                     ('▰'*cells+'▱'*(10-cells) if total else 'Нет назначений'))
    if view['rows']:
        rows=[]
        for row in view['rows'][:12]:
            if not isinstance(row,list) or len(row)!=2:
                raise ValueError('invalid_bot_table')
            label=_text(str(row[0]),22).replace('\n',' ')
            value=_text(str(row[1]),70).replace('\n',' ')
            rows.append(html.escape(label)+'  │  '+html.escape(value))
        parts.append('<pre>'+'\n'.join(rows)+'</pre>')
        if len(view['rows'])>12:parts.append('Показана часть списка.')
    keyboard=[]
    for button in view['buttons']:
        if not isinstance(button,dict) or set(button) not in ({'label','callback'},{'label','url'}):
            raise ValueError('invalid_bot_button')
        item={'text':_text(button['label'],52)}
        if 'callback' in button:item['callback_data']=_token(button['callback'],'cb4')
        else:item['url']=_confirmation_url(button['url'],academy_origin)
        # One short action per row also accommodates long Russian labels on narrow phones.
        keyboard.append([item])
    value={'text':'\n\n'.join(parts),'parse_mode':'HTML','link_preview_options':{'is_disabled':True},
           'reply_markup':{'inline_keyboard':keyboard}}
    if len(value['text'].encode('utf-16-le'))//2>4096 or len(json.dumps(value,ensure_ascii=False).encode())>15000:
        raise ValueError('bot_view_too_large')
    return value
