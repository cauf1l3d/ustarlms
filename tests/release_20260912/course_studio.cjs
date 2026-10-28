const assert = require('node:assert/strict');
const {JSDOM} = require('jsdom');
const path = require('node:path');
const root = path.resolve(__dirname, '../../moodle/local/ustar/course_studio');
global.JSZip = require(path.join(root, 'vendor/jszip.min.js'));
const M = require(path.join(root, 'model.js'));
const course = () => M.normalizeCourse({data:{code:'SALES-01',title:'Продажи',version:'1.0',pages:[{id:'a',title:'Начало',kind:'lesson',html:'<p>Материал</p>'}]}});
(async () => {
 const c=course();
 const evil=course(); evil.data.pages[0].html='<p><a href="jav&#97;script:alert(1)">Ссылка</a></p>';
 assert(M.validateCourse(evil).length, 'encoded script link must be rejected before rendering');
 const media=course();media.data.pages[0].html='<video src="https://example.org/leak"></video>';assert(M.validateCourse(media).length,'active external media must fail before rendering');
 const foreign=course();foreign.data.pages[0].html='<math><mtext>unexpected</mtext></math>';assert(M.validateCourse(foreign).length,'foreign HTML namespace must fail before rendering');
 const folded=course();folded.data.pages[0].html='<a href="java&#10;script:alert(1)">bad</a>';assert(M.validateCourse(folded).length,'folded script URL must fail before rendering');
 const slash=course();slash.assets["assets/x.png"]={type:"image/png",data:"AAAA"};slash.data.pages[0].html='<img src="assets/x.png" /onerror="window.pwned=true">';assert(M.validateCourse(slash).length,'slash-separated event attribute must be rejected before rendering');
 const css=course();css.data.pages[0].titleStyle={backgroundImage:'url(https://example.org/leak)'};
 assert(M.validateCourse(css).length, 'title style must not introduce external requests');
 const shape=course();shape.theme.fontSize='1; background:url(https://example.org)';
 assert(M.validateCourse(shape).length,'theme values must be numeric and bounded');
 M.setRuntime('body{color:#2b2b2b}','window.testPlayer=true;');
 const files=await M.buildFiles(c);
 assert(new TextDecoder().decode(files['index.html']).includes('АКАДЕМИЯ / USTAR'));
 const zip=await M.exportScorm(c); const imported=(await M.importScorm(zip))[0];
 assert.equal(imported.data.code,c.data.code);assert.equal(imported.data.pages[0].html,c.data.pages[0].html);
 const z=new global.JSZip(); z.file('data.js','window.COURSE='+JSON.stringify(c.data)+';');z.file('assets/../evil.png','oops');
 await assert.rejects(()=>M.importScorm(new Uint8Array(Buffer.from([]))),/./);
 const portable=M.parseProject(M.serializeProject([c]));assert.equal(portable[0].data.title,c.data.title);
 const {window}=new JSDOM('<body/>');
 // User-supplied workspace is optional local evidence and never enters Git.
 if(process.env.USTAR_STUDIO_WORKSPACE){
  const fs=require('node:fs');const courses=M.parseProject(fs.readFileSync(process.env.USTAR_STUDIO_WORKSPACE,'utf8'));
  assert.equal(courses.length,11);assert.equal(courses.reduce((n,c)=>n+c.data.pages.length,0),153);
  for(const c of courses){assert.deepEqual(M.validateCourse(c),[]); const files=await M.buildFiles(c); const doc=new window.DOMParser().parseFromString(new TextDecoder().decode(files['index.html']),'text/html');assert(doc.querySelector('nav'));}
 }
 console.log('COURSE_STUDIO_CODEC_OK');
})().catch(e=>{console.error(e);process.exitCode=1;});
