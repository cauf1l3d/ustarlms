const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {JSDOM}=require('jsdom');
const root=path.resolve(__dirname,'../../moodle/local/ustar/course_studio');
const window=new JSDOM('<!doctype html><body/>').window;
global.DOMParser=window.DOMParser;
global.JSZip=require(root+'/vendor/jszip.min.js');
const M=require(root+'/model.js');
const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jZLkAAAAASUVORK5CYII=','base64');
async function fixture({prefix='',wrapper=false,missing=false,unsafe=false}={}){
 const z=new JSZip();
 z.file(prefix+'imsmanifest.xml','<?xml version="1.0"?><manifest xmlns="http://www.imsproject.org/xsd/imscp_rootv1p1p2"><organizations default="org"><organization identifier="org"><title>Товароведение</title><item identifier="one" identifierref="res"><title>Инструменты</title></item></organization></organizations><resources><resource identifier="res" type="webcontent" href="pages/start.html"/></resources></manifest>');
 z.file(prefix+'pages/start.html','<!doctype html><html><head><style>:root{--ink:#123456}.page{display:none}.tile{color:var(--ink);border:1px solid #ccc}</style></head><body><nav>Navigation only</nav><section class="page active" id="one"><h1>Рулетки</h1><p class="tile" onclick="window.BAD=1">Точный замер</p><img src="'+(unsafe?'../../escape.png':'../images/photo.png')+'" alt="Рулетка"></section><section class="page" id="two"><h2>Угольники</h2><table><tr><td>90 градусов</td></tr></table></section><script>window.BAD=1</script></body></html>');
 if(!missing)z.file(prefix+'images/photo.png',png);
 const bytes=await z.generateAsync({type:'uint8array'});
 if(!wrapper)return bytes;
 const outer=new JSZip();outer.file('Экспорт курса.zip',bytes);return outer.generateAsync({type:'uint8array'});
}
(async()=>{
 M.setRuntime('body{color:#2b2b2b}','window.runtime=true');
 const [c]=await M.importScorm(await fixture());
 assert.equal(c.data.title,'Товароведение');assert.equal(c.data.pages.length,2);
 assert.equal(c.data.pages[0].title,'Рулетки');assert.equal(c.data.pages[1].title,'Угольники');
 assert(c.data.pages[0].html.includes('Точный замер'));assert(c.data.pages[1].html.includes('90 градусов'));
 assert(!c.data.pages[0].html.includes('onclick'));assert(!c.data.pages[0].html.includes('<script'));assert(!c.data.pages[0].html.includes('Navigation only'));
 assert.equal(global.BAD,undefined);assert.equal(Object.keys(c.assets).length,1);
 assert(Buffer.from(Object.values(c.assets)[0].data,'base64').equals(png));assert.deepEqual(M.validateCourse(c),[]);
 assert(c.data.pages[0].html.includes('#123456'),'safe original CSS colour is editable inline');
 const [nested]=await M.importScorm(await fixture({wrapper:true}));assert.equal(nested.data.pages.length,2);
 const [folder]=await M.importScorm(await fixture({prefix:'course/'}));assert.equal(folder.data.pages.length,2);
 c.data.pages[0].html=c.data.pages[0].html.replace('Точный замер','Изменено в Студии');
 const [back]=await M.importScorm(await M.exportScorm(c));assert(back.data.pages[0].html.includes('Изменено в Студии'));assert.equal(back.data.pages.length,2);
 await assert.rejects(()=>fixture({missing:true}).then(b=>M.importScorm(b)),/изображен|ресурс|файл/i);
 await assert.rejects(()=>fixture({unsafe:true}).then(b=>M.importScorm(b)),/путь|ресурс|архив/i);

 const alter=async change=>{const z=await JSZip.loadAsync(await fixture());await change(z);return z.generateAsync({type:'uint8array'});};
 const unrelated=await alter(z=>z.file('data.js','window.OTHER={name:"legacy"};'));
 assert.equal((await M.importScorm(unrelated))[0].data.pages.length,2,'ordinary data.js is not an embedded USTAR project');
 const dynamic=await alter(z=>z.file('pages/start.html','<html><body><div id="app"></div><script>window.BAD=2</script></body></html>'));
 await assert.rejects(()=>M.importScorm(dynamic),/исходн|содержим/i);
 const external=await alter(async z=>z.file('pages/start.html',(await z.file('pages/start.html').async('string')).replace('../images/photo.png','https://external.example/leak.png')));
 await assert.rejects(()=>M.importScorm(external),/путь|ресурс/i);
 const interactive=await alter(z=>z.file('pages/start.html','<main><h1>Вопрос</h1><form><input name="answer"></form></main>'));
 await assert.rejects(()=>M.importScorm(interactive),/интерактивн|исходник/i);
 const doctype=await alter(async z=>z.file('imsmanifest.xml',(await z.file('imsmanifest.xml').async('string')).replace('<?xml version="1.0"?>','<?xml version="1.0"?><!DOCTYPE manifest [<!ENTITY leak SYSTEM "https://external.example/">]>')));
 await assert.rejects(()=>M.importScorm(doctype),/XML|манифест/i);
 let deep=await fixture();for(let i=0;i<5;i++){const z=new JSZip();z.file('inside.zip',deep);deep=await z.generateAsync({type:'uint8array'});}
 await assert.rejects(()=>M.importScorm(deep),/вложенн|архив/i);
 const several=await alter(async z=>{
  let xml=await z.file('imsmanifest.xml').async('string');xml=xml.replace('<item identifier="one"','<item identifier="first" identifierref="second"><title>Второй SCO</title></item><item identifier="one"').replace('</resources>','<resource identifier="second" type="webcontent" href="second.html"/></resources>');
  z.file('imsmanifest.xml',xml);z.file('second.html','<main><h1>Первый по манифесту</h1><p>Другая тема</p></main>');
 });
 const [multi]=await M.importScorm(several);assert.equal(multi.data.pages.length,3);assert.equal(multi.data.pages[0].title,'Первый по манифесту');
 if(process.env.USTAR_SCORM_FIXTURE){
  const [real]=await M.importScorm(new Uint8Array(fs.readFileSync(process.env.USTAR_SCORM_FIXTURE)));
  assert.equal(real.data.pages.length,20);assert.equal(real.data.pages.reduce((n,p)=>n+(p.html.match(/<img\b/g)||[]).length,0),82);
  assert.deepEqual(M.validateCourse(real),[]);const [round]=await M.importScorm(await M.exportScorm(real));assert.equal(round.data.pages.length,20);
  console.log('SUPPLIED_SCORM=PASS 20 pages/82 image occurrences/editable roundtrip');
 }
 console.log('COURSE_STUDIO_SCORM=PASS legacy HTML/wrapper/folder/assets/roundtrip/inert scripts');
})().catch(e=>{console.error(e);process.exitCode=1;});
