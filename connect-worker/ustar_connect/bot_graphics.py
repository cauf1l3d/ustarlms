"""Bounded in-memory PNGs from the already authorized Academy view. Standard library only.

Design revision 2026-10-30 (v2): brand-tinted dark palette, editorial type scale,
metric cards with severity stripe, numbered row badges, hairline borders, compact
fallback layout. Public API and the authorization contract are unchanged:
`presentation()` accepts the same view, delegates every right/URL check to
`bot_cards.render_view`, and returns its fallback byte-for-byte when rendering
is not possible.
"""
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

# --- Palette: taken verbatim from theme_ustar/scss/_tokens.scss (dark) ---------
# Canvas/surface/text map to --u-canvas, --u-surface, --u-text-primary and friends.
# Status colours below are adapted for dark contrast, rather than literal tokens.
BG='#222120'          # --u-canvas (dark)
PANEL='#2a2928'       # --u-surface (dark)
PANEL2='#353432'      # --u-surface-muted (dark)
BORDER='#3d3b39'      # between --u-surface and --u-surface-muted
HAIR='#302f2d'        # --u-surface-raised, used as a 1px rule
TEXT='#f6f5f1'        # --u-text-primary (dark)
MUTED='#c9c6c1'       # --u-text-secondary (dark)
GOLD='#ebc500'        # --u-brand
GOLD_DIM='#c9a700'    # --u-brand-strong
BLUE='#7fb3d9'        # --u-info-fg, lifted for dark
GREEN='#6fd3a0'       # --u-success-fg, lifted for dark
RED='#ff8f85'         # --u-danger-fg, lifted for dark
AMBER='#e9a45c'       # --u-warning-fg, lifted for dark

MARGIN=48
CONTENT=WIDTH-2*MARGIN

# Text roles are capped so the palette stays under 256 entries:
# 13 solid colours + 13 (fg,bg) blend pairs x 16 levels = 221.
def _severity(value):
    """Map a Russian status word to a semantic colour. Never guesses rights.

    Order matters: an overdue row often also reads "на проверке", and overdue wins.
    """
    text=value.casefold()
    if re.search(r'просроч|не (?:выполн|подтвержд|сдан|принят|заверш|одобрен|согласован)|отклон|провал|отказ',text):return RED
    if re.search(r'готов|выполн|принят|заверш|сдан|подтвержд|одобрен|\bсогласован(?:о|а|ы)?\b',text):return GREEN
    if re.search(r'истека|скоро|предупрежд|внимание',text):return AMBER
    if re.search(r'провер|ожида|в работе|начат|черновик|на рассмотр|на согласован',text):return BLUE
    return GOLD


@lru_cache(maxsize=1)
def _font():
    from ._bot_font import DATA
    raw=zlib.decompress(base64.b85decode(DATA))
    data,masks=raw.split(b'\n',1)
    return json.loads(data),masks


def _plain(value,limit=1500):
    value=_text(value,limit)
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


def _fit(text,size,maxwidth):
    """Largest available size that keeps `text` on one line, else None."""
    for candidate in (size,48,36,32,28):
        if _width(text,candidate)<=maxwidth:return candidate
    return None


class Canvas:
    def __init__(self,height,levels=16):
        self.height=height;self.palette=[];self.blends={};self.levels=levels
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

    def circle(self,cx,cy,radius,color):
        self.rect(cx-radius,cy-radius,radius*2,radius*2,color,radius)

    def text(self,x,y,value,size=32,color=TEXT,background=BG):
        if not value:return 0
        faces,masks=_font();ascent,glyphs=faces[str(size)]
        key=(color,background)
        if key not in self.blends:
            fg,bg=(bytes.fromhex(c[1:]) for c in key)
            top=self.levels-1
            levels=[self.color(tuple(round(b+(f-b)*a/top) for f,b in zip(fg,bg))) for a in range(self.levels)]
            # Atlas alpha is always 0..15, including at reduced palette quality.
            self.blends[key]=bytes([levels[round(a*top/15)] for a in range(16)]+[0]*240)
        table=self.blends[key]
        start=x
        for char in value:
            advance,left,top,width,height,offset=glyphs.get(char,glyphs['?'])
            for row in range(height):
                yy=y+ascent+top+row;xx=x+left
                if not 0<=yy<self.height:continue
                lo,hi=max(0,-xx),min(width,WIDTH-xx)
                if lo<hi:
                    self.pixels[yy*WIDTH+xx+lo:yy*WIDTH+xx+hi]=masks[offset+row*width+lo:offset+row*width+hi].translate(table)
            x+=advance
        return x-start

    def text_right(self,xright,y,value,size=32,color=TEXT,background=BG):
        self.text(xright-_width(value,size),y,value,size,color,background)

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
        while start<len(view['buttons']) and _plain(view['buttons'][start]['label']) in ('Заявка на найм','Заявка на наём','Заявка на увольнение'):start+=1
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
        rows.append((label,detail))
        keyboard[start+i-1][0]['text']=str(i)+' · Открыть '+kind
        keyboard[start+i-1][0]['style']='primary'
    return rows


# --- layout -------------------------------------------------------------------
def _header(canvas,now,compact):
    canvas.rect(0,0,WIDTH,6,GOLD)
    canvas.rect(MARGIN,38,26,26,GOLD,7)
    canvas.rect(MARGIN+7,45,12,12,BG,3)
    canvas.text(MARGIN+42,34,'USTAR',28,TEXT)
    canvas.text(MARGIN+42+_width('USTAR',28)+14,34,'/ АКАДЕМИЯ',28,MUTED)
    stamp=datetime.fromtimestamp(now,timezone(timedelta(hours=3))).strftime('%d.%m · %H:%M МСК')
    canvas.text_right(WIDTH-MARGIN,38,stamp,28,MUTED)
    canvas.rect(MARGIN,92,CONTENT,2,HAIR)


def _metric_layout(metric,compact=False):
    label=_plain(metric['label'],40);value=metric['value'];total=metric['total']
    labels=_wrap(label,32,CONTENT-64)
    number=str(value)+' / '+str(total) if total or value else 'Нет назначений'
    size=_fit(number,64,CONTENT-200)
    if size is None:raise ValueError('card_metric_width')
    height=18+len(labels)*42+size+(18 if compact else 26)+14+24
    return labels,number,size,height


def _metric(canvas,y,metric,compact=False):
    labels,number,size,height=_metric_layout(metric,compact)
    accent=_severity(metric['label']);value=metric['value'];total=metric['total']
    canvas.rect(MARGIN,y,CONTENT,height,BORDER,20)
    canvas.rect(MARGIN+2,y+2,CONTENT-4,height-4,PANEL,18)
    canvas.rect(MARGIN+2,y+18,6,height-36,accent,3)
    for i,label in enumerate(labels):canvas.text(MARGIN+32,y+12+i*42,label,32,MUTED,PANEL)
    number_y=y+18+len(labels)*42
    canvas.text(MARGIN+32,number_y,number,size,accent,PANEL)
    # progress
    track_y=y+height-38;track_h=14
    canvas.rect(MARGIN+32,track_y,CONTENT-64,track_h,HAIR,7)
    if total:
        share=min(value,total)/total
        length=round((CONTENT-64)*share)
        if length:canvas.rect(MARGIN+32,track_y,max(track_h,length),track_h,accent,7)
        canvas.text_right(WIDTH-MARGIN-32,number_y+size-32,str(round(100*share))+'%',28,accent,PANEL)
    return height


def _row(canvas,y,index,label,detail,compact=False,status=None):
    labels=_wrap(label,36,CONTENT-140);values=_wrap(detail,32,CONTENT-140)
    line=32+(len(labels)*46)+(len(values)*42)
    height=max(line,72)+28
    accent=_severity(detail if status is None else status)
    canvas.rect(MARGIN,y,CONTENT,height,BORDER,16)
    canvas.rect(MARGIN+2,y+2,CONTENT-4,height-4,PANEL,14)
    # numbered badge
    badge=44;bcx=MARGIN+22+badge//2;bcy=y+2+(height-4)//2
    canvas.circle(bcx,bcy,badge//2,PANEL2)
    digits=str(index)
    canvas.text(bcx-_width(digits,28)//2,bcy-20,digits,28,GOLD,PANEL2)
    x=MARGIN+22+badge+22
    yy=y+(14 if compact else 18)
    for text_line in labels:
        canvas.text(x,yy,text_line,36,TEXT,PANEL);yy+=46
    for text_line in values:
        canvas.text(x,yy,text_line,32,MUTED,PANEL);yy+=42
    # severity dot in the top-right corner of the card
    canvas.circle(WIDTH-MARGIN-30,y+26,6,accent)
    return height


def _description(canvas,y,lines):
    for i,line in enumerate(lines):
        canvas.text(MARGIN,y+i*42,line,32,MUTED,BG)
    return len(lines)*42


def _raster(view,keyboard,now):
    title=_plain(view['title'],120)
    description=_wrap(_plain(view['text']),32,CONTENT) if view['text'] else []
    original_keyboard=json.loads(json.dumps(keyboard,ensure_ascii=False))
    choices=_choices(view,keyboard)
    rows=choices if choices is not None else [(_plain(str(k),120),_plain(str(v),180)) for k,v in view['rows'][:12]]

    title_size=_fit(title,64,CONTENT)
    if title_size is None:
        title_size=48
        title_lines=_wrap(title,48,CONTENT)
    else:
        title_lines=[title]

    # Fit: prefer the full list at roomy density, then compact, then fewer rows.
    # A shortened list is announced in the description, never silently truncated.
    chosen=None
    for limit in (len(rows),10,8,6,5,4,3):
        if limit>len(rows):continue
        shown=rows[:limit]
        note=list(description)
        if limit<len(view['rows']):note=description+['Показаны первые '+str(limit)+' из '+str(len(view['rows']))+'.']
        for compact in (False,True):
            gap_h=18 if compact else 26
            height=_measure(title_lines,title_size,note,view['metrics'],shown,compact,gap_h)
            if height<=MAX_HEIGHT:
                chosen=(shown,note,compact,gap_h,height);break
        if chosen:break
    if chosen is None:return None
    rows,description,compact,gap_h,height=chosen
    if choices is not None:
        for index,row in enumerate(keyboard):
            number=re.match(r'^(\d+) · Открыть ',row[0]['text'])
            if number and int(number[1])>len(rows):keyboard[index]=original_keyboard[index]

    canvas=None
    for levels in (16,8,4):
        try:
            canvas=Canvas(height,levels);_draw(canvas,height,now,compact,title_lines,title_size,description,view['metrics'],rows,gap_h,
                [_plain(str(row[1]),180) for row in view['rows'][:len(rows)]])
            _footer(canvas,height,now,bool(rows) and choices is not None)
            break
        except ValueError as error:
            if str(error)!='card_palette_limit':raise
            canvas=None
    if canvas is None:return None
    value=canvas.png()
    return (value,len(rows)) if len(value)<=MAX_BYTES else None


def _draw(canvas,height,now,compact,title_lines,title_size,description,metrics,rows,gap_h,statuses):
    _header(canvas,now,compact)
    y=124
    for line in title_lines:
        canvas.text(MARGIN,y,line,title_size,TEXT);y+=int(title_size*1.24)
    y+=gap_h
    if description:
        y+=_description(canvas,y,description)+gap_h
    for metric in metrics:
        y+=_metric(canvas,y,metric,compact)+gap_h
    for index,(label,detail) in enumerate(rows,1):
        y+=_row(canvas,y,index,label,detail,compact,status=statuses[index-1])+14


def _measure(title_lines,title_size,description,metrics,rows,compact,gap_h):
    y=124+int(title_size*1.24)*len(title_lines)+gap_h
    if description:y+=len(description)*42+gap_h
    for metric in metrics:y+=_metric_layout(metric,compact)[3]+gap_h
    for label,detail in rows:
        labels=len(_wrap(label,36,CONTENT-140));values=len(_wrap(detail,32,CONTENT-140))
        y+=max(32+labels*46+values*42,72)+28+14
    return y+120


def _footer(canvas,height,now,numbered):
    canvas.rect(MARGIN,height-92,CONTENT,2,HAIR)
    canvas.rect(MARGIN,height-52,18,4,GOLD_DIM,2)
    canvas.text(MARGIN+30,height-64,'USTAR Connect',28,MUTED)
    hint='Действия по номеру' if numbered else 'Действия на кнопках'
    canvas.text_right(WIDTH-MARGIN,height-64,hint,28,MUTED)


def presentation(view,*,academy_origin=None,now=None):
    fallback=render_view(view,academy_origin=academy_origin)  # Strict contract / URL checks before drawing.
    keyboard=json.loads(json.dumps(fallback['reply_markup']['inline_keyboard'],ensure_ascii=False))
    try:raster=_raster(view,keyboard,time.time() if now is None else now)
    except (ValueError,KeyError,OverflowError,zlib.error):raster=None
    if raster is None:return fallback
    photo,shown=raster
    title=_text(view['title'],120)
    numbered=any(' · Открыть ' in row[0]['text'] for row in keyboard)
    parts=[title]
    if view['rows']:
        parts.append(('Выберите действие по номеру · '+str(len(view['rows']))+' поз.'
                      if numbered else 'Действия — на кнопках ниже.'))
    else:
        parts.append('Действия — на кнопках ниже.')
    if shown<len(view['rows']):parts.append('Показаны первые '+str(shown)+' из '+str(len(view['rows']))+'.')
    return {'photo':photo,'caption':'\n'.join(parts),
            'reply_markup':{'inline_keyboard':keyboard},'_fallback':fallback}
