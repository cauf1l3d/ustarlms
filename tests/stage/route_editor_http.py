"""Real authenticated controller GET/POST on disposable native Moodle only."""
import http.cookiejar
import json
from html.parser import HTMLParser
from urllib.parse import urlencode
from urllib.request import HTTPCookieProcessor,build_opener
from pathlib import Path
import subprocess

class Forms(HTMLParser):
    def __init__(self,html):
        super().__init__(convert_charrefs=True);self.forms=[];self.current=None;self.select=None;self.option=None;self.area=None;self.feed(html)
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if tag=='form':self.current=[];self.forms.append(self.current)
        if self.current is None:return
        if tag=='input' and a.get('name') and (a.get('type') not in ('checkbox','radio') or 'checked' in a):self.current.append((a['name'],a.get('value','')))
        if tag=='select':self.select=[a.get('name'),[]]
        if tag=='option' and self.select is not None:self.option=[a.get('value',''),'selected' in a];self.select[1].append(self.option)
        if tag=='textarea':self.area=[a.get('name'),'']
    def handle_data(self,data):
        if self.area is not None:self.area[1]+=data
    def handle_endtag(self,tag):
        if tag=='textarea' and self.area is not None:
            if self.area[0]:self.current.append(tuple(self.area))
            self.area=None
        if tag=='select' and self.select is not None:
            name,options=self.select;selected=[o[0] for o in options if o[1]] or ([options[0][0]] if options else [])
            self.current.extend((name,v) for v in selected if name);self.select=None
        if tag=='form':self.current=None
    def action(self,name):return next(f for f in self.forms if ('action',name) in f)

fixture=json.loads(Path('/artifacts/route-editor-fixture.json').read_text())
jar=http.cookiejar.MozillaCookieJar('/artifacts/shell-cookies.txt');jar.load(ignore_discard=True,ignore_expires=True)
client=build_opener(HTTPCookieProcessor(jar))
url='http://127.0.0.1:8087/local/ustar/route_studio.php?'+urlencode({'family':fixture['family'],'view':'parent','point':fixture['point']})
def get():
    with client.open(url,timeout=60) as r:return r.read().decode()
def post(fields):
    with client.open(url,data=urlencode(fields).encode(),timeout=60) as r:
        assert r.status==200;return r.read().decode()
def state(mode):subprocess.run(['php','/source/tests/stage/route_editor_http_fixture.php',mode],check=True)
html=get();assert 'rows[1][base]' in html and 'Debug info:' not in html
fields=Forms(html).action('save_version');fields.append(('status','draft'));post(fields);state('draft')
html=get();form=Forms(html).action('set_point_active');post(form);state('hidden')
html=get();form=Forms(html).action('set_point_active');assert ('active','1') in form;post(form);state('restored')
fields=Forms(get()).action('save_version');updates={'advancepolicy':'timed','reviewduration':'0','title':'HTTP retained input'}
fields=[(k,updates.get(k,v)) for k,v in fields];fields.append(('status','draft'))
html=post(fields);assert 'Изменения не сохранены' in html and 'HTTP retained input' in html;state('error')
Path('/artifacts/route-editor-http.html').write_text(html)
print('ROUTE_EDITOR_HTTP=PASS draft/hidden/restore/validation-input; immutable published requirements retained')
