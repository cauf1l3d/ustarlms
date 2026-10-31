"""Bounded in-memory PNGs from the already authorized Academy view. Standard library only."""
import base64
from datetime import datetime, timedelta, timezone
from functools import lru_cache
import json
import math
import re
import struct
import time
import unicodedata
import zlib

from .bot_cards import render_view, _text

WIDTH=960
MAX_HEIGHT=2400
MAX_BYTES=262144
BG='#17191d'
PANEL='#24272c'
BORDER='#383c43'
TEXT='#f3f4f6'
MUTED='#b9c0cb'
YELLOW='#ebc500'
BLUE='#6ca7ff'
GREEN='#68d7ad'
RED='#ff8a94'


@lru_cache(maxsize=1)
def _font():
    from ._bot_font import DATA
    raw=zlib.decompress(base64.b85decode(DATA))
    data,masks=raw.split(b'\n',1)
    return json.loads(data),masks


def _plain(value,limit=1500):
    value=_text(value,limit)
    # Font contains Cyrillic, Latin, numbers and typographical symbols. Emoji stay
    # in captions/buttons, rather than rendering missing-glyph rectangles in PNGs.
    glyphs=_font()[0]['32'][1]
    if any(c not in glyphs and unicodedata.category(c)[0] in ('L','N','M') and c not in ('\ufe0e','\ufe0f') for c in value):
        raise ValueError('unavailable_card_glyph')
    return ''.join(c if c in glyphs or c=='\n' else (' ' if unicodedata.category(c)!='Mn' else '') for c in value).strip()


def _width(text,size):
    glyphs=_font()[0][str(size)][1]
    return sum(glyphs.get(c,glyphs['?'])[0] for c in text)


def _wrap(text,size,width):
    rows=[]
    for paragraph in text.split('\n'):
        line=''
        for word in paragraph.split():
            candidate=(line+' '+word).strip()
            if _width(candidate,size)<=width:
                line=candidate;continue
            if line:rows.append(line);line=''
            while word and _width(word,size)>width:
                cut=1
                while cut<len(word) and _width(word[:cut+1],size)<=width:cut+=1
                rows.append(word[:cut]);word=word[cut:]
            line=word
        rows.append(line)
    return rows


class Canvas:
    def __init__(self,height):
        self.height=height;self.palette=[];self.blends={}
        self.pixels=bytearray([self.color(BG)])*(WIDTH*height)

    def color(self,color):
        rgb=tuple(bytes.fromhex(color.lstrip('#'))) if isinstance(color,str) else color
        if rgb not in self.palette:self.palette.append(rgb)
        if len(self.palette)>256:raise ValueError('card_palette_limit')
        return self.palette.index(rgb)

    def rect(self,x,y,width,height,color,radius=0):
        index=self.color(color)
        for yy in range(max(0,y),min(self.height,y+height)):
            edge=min(yy-y,y+height-1-yy)
            inset=math.ceil(radius-math.sqrt(max(0,radius**2-(radius-edge-1)**2))) if edge<radius else 0
            left,right=max(0,x+inset),min(WIDTH,x+width-inset)
            if left<right:self.pixels[yy*WIDTH+left:yy*WIDTH+right]=bytes([index])*(right-left)

    def text(self,x,y,value,size=32,color=TEXT,background=BG):
        faces,masks=_font();ascent,glyphs=faces[str(size)]
        key=(color,background)
        if key not in self.blends:
            fg,bg=(bytes.fromhex(c[1:]) for c in key)
            levels=[self.color(tuple(round(b+(f-b)*a/15) for f,b in zip(fg,bg))) for a in range(16)]
            self.blends[key]=bytes(levels+[0]*240)
        table=self.blends[key]
        for char in value:
            advance,left,top,width,height,offset=glyphs.get(char,glyphs['?'])
            for row in range(height):
                yy=y+ascent+top+row;xx=x+left
                if not 0<=yy<self.height:continue
                lo,hi=max(0,-xx),min(width,WIDTH-xx)
                if lo<hi:
                    self.pixels[yy*WIDTH+xx+lo:yy*WIDTH+xx+hi]=masks[offset+row*width+lo:offset+row*width+hi].translate(table)
            x+=advance

    def png(self):
        def chunk(kind,raw):return struct.pack('>I',len(raw))+kind+raw+struct.pack('>I',zlib.crc32(kind+raw)&0xffffffff)
        scan=b''.join(b'\x00'+self.pixels[row*WIDTH:(row+1)*WIDTH] for row in range(self.height))
        return (b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',struct.pack('>IIBBBBB',WIDTH,self.height,8,3,0,0,0))+
            chunk(b'PLTE',bytes(c for rgb in self.palette for c in rgb))+chunk(b'IDAT',zlib.compress(scan,6))+chunk(b'IEND',b''))


def _choices(view,keyboard):
    """Known list layouts in bot_{work,people,learning}; never infer callback rights."""
    title=_plain(view['title']).casefold()
    kind=None
    if title in ('мои задачи','сводка по задачам','очередь проверки'):kind='задачу'
    elif title=='кадровые заявки':kind='заявку'
    elif title=='чек-листы':kind='чек-лист'
    elif title=='проверки аттестаций':kind='аттестацию'
    elif title=='обучение команды':kind='маршрут'
    if not kind or not view['rows']:return None
    start=0
    if title=='кадровые заявки':
        while start<len(view['buttons']) and _plain(view['buttons'][start]['label']) in ('Заявка на найм','Заявка на увольнение'):start+=1
    count=len(view['rows'])
    # If a later Academy version changes the layout, preserve original labels.
    if count>8 or len(keyboard)<start+count:return None
    selected=view['buttons'][start:start+count]
    if any('callback' not in b for b in selected):return None
    rows=[]
    for i,(row,button) in enumerate(zip(view['rows'],selected),1):
        label=_plain(button['label'],60)
        detail=' · '.join(_plain(str(v),120) for v in row)
        if title in ('кадровые заявки','обучение команды'):
            label=_plain(str(row[0]),120)
            kind_label=_plain(button['label'],60).split(' · ',1)[-1]
            detail=(kind_label+' · ' if title=='кадровые заявки' else '')+_plain(str(row[1]),120)
        rows.append((str(i)+' · '+label,detail))
        keyboard[start+i-1][0]['text']=str(i)+' · Открыть '+kind
        keyboard[start+i-1][0]['style']='primary'
    return rows


def _raster(view,keyboard,now):
    title=_wrap(_plain(view['title'],120),48,864)
    description=_wrap(_plain(view['text']),32,864) if view['text'] else []
    if len(view['rows'])>12:description+=['Показана часть списка.']
    choices=_choices(view,keyboard)
    rows=choices if choices is not None else [(_plain(str(k),120),_plain(str(v),180)) for k,v in view['rows'][:12]]
    blocks=[]
    y=100+len(title)*62+20
    if description:blocks.append(('description',y,description));y+=len(description)*43+28
    for metric in view['metrics']:
        label=_plain(metric['label'],40)
        number=str(metric['value'])+' / '+str(metric['total']) if metric['total'] or metric['value'] else 'Нет назначений'
        if _width(label,32)>420 or _width(number,36)>400:return None
        blocks.append(('metric',y,metric));y+=166
    for label,value in rows:
        labels=_wrap(label,36,800);values=_wrap(value,32,800)
        height=32+len(labels)*47+len(values)*43
        blocks.append(('row',y,(labels,values,height)));y+=height+14
    height=y+118
    if height>MAX_HEIGHT:return None
    canvas=Canvas(height)
    canvas.rect(0,0,WIDTH,8,YELLOW)
    canvas.text(48,32,'USTAR  /  АКАДЕМИЯ',28,YELLOW)
    for i,line in enumerate(title):canvas.text(48,100+i*62,line,48)
    for kind,y,data in blocks:
        if kind=='description':
            for i,line in enumerate(data):canvas.text(48,y+i*43,line,32,MUTED)
        elif kind=='metric':
            canvas.rect(48,y,864,146,PANEL,20)
            label,value,total=_plain(data['label'],40),data['value'],data['total']
            color=RED if re.search('просроч|не начат',label,re.I) else BLUE if 'провер' in label.lower() else YELLOW
            canvas.text(72,y+16,label,32,MUTED,PANEL)
            number=str(value)+' / '+str(total) if total or value else 'Нет назначений'
            size=48 if _width(number,48)<400 else 36
            canvas.text(888-_width(number,size),y+12,number,size,color,PANEL)
            canvas.rect(72,y+99,816,13,BORDER,6)
            length=round(816*min(value,total)/total) if total else 0
            if length:canvas.rect(72,y+99,max(13,length),13,color,6)
        else:
            labels,values,h=data
            canvas.rect(48,y,864,h,PANEL,18)
            yy=y+16
            for line in labels:canvas.text(72,yy,line,36,TEXT,PANEL);yy+=47
            for line in values:canvas.text(72,yy,line,32,MUTED,PANEL);yy+=43
    canvas.rect(48,height-90,864,2,BORDER)
    stamp=datetime.fromtimestamp(now,timezone(timedelta(hours=3))).strftime('%d.%m.%Y · %H:%M МСК')
    canvas.text(48,height-66,'Снимок · '+stamp,28,MUTED)
    value=canvas.png()
    return value if len(value)<=MAX_BYTES else None


def presentation(view,*,academy_origin=None,now=None):
    fallback=render_view(view,academy_origin=academy_origin)  # Strict contract / URL checks before drawing.
    keyboard=json.loads(json.dumps(fallback['reply_markup']['inline_keyboard'],ensure_ascii=False))
    try:photo=_raster(view,keyboard,time.time() if now is None else now)
    except (ValueError,KeyError,OverflowError,zlib.error):photo=None
    if photo is None:return fallback
    title=_text(view['title'],120)
    caption=title+'\n'+('Выберите действие по номеру.' if any(' · Открыть ' in row[0]['text'] for row in keyboard) else 'Действия — на кнопках ниже.')
    return {'photo':photo,'caption':caption,'reply_markup':{'inline_keyboard':keyboard},'_fallback':fallback}
