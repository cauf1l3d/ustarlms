#!/usr/bin/env python3
"""Build portable 4-bit glyph coverage; Pillow is a build tool, never a worker dependency."""
import base64
import json
from pathlib import Path
import zlib
from PIL import Image, ImageDraw, ImageFont

ROOT=Path(__file__).resolve().parents[2]
FONT=ROOT/'server-console/backend/ustar_console/fonts'
TARGET=ROOT/'connect-worker/ustar_connect/_bot_font.py'
CHARS=''.join(chr(n) for n in (*range(32,127),*range(0x400,0x460)))+'«»–—…№₽€←→↑↓−≤≥•·'

def build():
    masks=bytearray();faces={}
    for size,bold in ((28,False),(32,False),(36,True),(48,True),(64,True)):
        face=ImageFont.truetype(str(FONT/('UstarSans-'+('Bold' if bold else 'Regular')+'.ttf')),size)
        glyphs={}
        for char in CHARS:
            left,top,right,bottom=face.getbbox(char,anchor='ls')
            width,height=right-left,bottom-top
            canvas=Image.new('L',(max(1,width),max(1,height)))
            ImageDraw.Draw(canvas).text((-left,-top),char,font=face,fill=255,anchor='ls')
            glyphs[char]=[round(face.getlength(char)),left,top,width,height,len(masks)]
            masks.extend(bytes((v+8)//17 for v in canvas.tobytes()[:width*height]))
        faces[str(size)]=[face.getmetrics()[0],glyphs]
    content=zlib.compress(json.dumps(faces,ensure_ascii=False,separators=(',',':')).encode()+b'\n'+masks,9)
    encoded=base64.b85encode(content).decode()
    license=(FONT/'LICENSE.txt').read_text()
    lines='\n'.join(repr(encoded[n:n+100]) for n in range(0,len(encoded),100))
    TARGET.write_text('"""Generated glyph atlas; rebuild with scripts/infra/build_connect_card_font.py.\n'
        'Ustar Sans, derived from DejaVu Sans. License retained below. Runtime: stdlib only.\n"""\n'
        'LICENSE = '+repr(license)+'\nDATA = (\n'+lines+'\n)\n')
    print(json.dumps({'glyphs':len(CHARS),'fonts':len(faces),'bytes':TARGET.stat().st_size}))

if __name__=='__main__':build()
