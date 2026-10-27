// Opening catalogue source must select its canonical draft and retain the accepted revision.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
const {JSDOM,VirtualConsole}=require('jsdom'),{indexedDB}=require('fake-indexeddb');
const dir=path.resolve(__dirname,'../../moodle/local/ustar/course_studio');
const config={api:'/api',sesskey:'csrf',cache:'fixture-material-open-2',userId:'2',material:'/material.php',materialId:7};
const html=fs.readFileSync(dir+'/editor.html','utf8').replaceAll('__USTAR_ASSETS__','/assets').replace('__USTAR_CONFIG__',JSON.stringify(config)).replace(/<script src="[^"]+"><\/script>/g,'');
const vc=new VirtualConsole(),dom=new JSDOM(html,{url:'https://academy.example/studio',runScripts:'outside-only',pretendToBeVisual:true,virtualConsole:vc}),w=dom.window;
w.TextEncoder=TextEncoder;w.TextDecoder=TextDecoder;w.AbortSignal=AbortSignal;w.Uint8Array=Uint8Array;w.ArrayBuffer=ArrayBuffer;w.indexedDB=indexedDB;
Object.defineProperty(w.crypto,'subtle',{value:crypto.webcrypto.subtle});w.URL.createObjectURL=()=> 'blob:fixture';w.URL.revokeObjectURL=()=>{};
w.HTMLElement.prototype.scrollIntoView=()=>{};w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};w.HTMLDialogElement.prototype.close=function(){this.open=false;};
const project={data:{code:'MATERIAL-7',title:'Порядок работы',version:'1.0',pages:[{id:'MATERIAL-7-1',title:'Начало',kind:'lesson',chapter:'',html:'<p>Редактируемый материал</p>',source_text:'',sources:[]}]},assets:{},fonts:[],theme:{font:'Arial',headingFont:'Arial',fontSize:17,headingSize:34,lineHeight:1.65,ink:'#2b2b2b',accent:'#ebc500',paper:'#ffffff',background:'#ededed'},catalogue:{},revision:0};
const fingerprint=p=>crypto.createHash('sha256').update(JSON.stringify(p)).digest('hex');
const fileMode=process.env.USTAR_TEST_MATERIAL_FILE==='1',fileText='Редактируемый материал',sourceToken='a'.repeat(64);
const requests=[],writes=[];
w.fetch=async(url,init={})=>{const route=new URL(url,w.location).searchParams.get('route');requests.push([route,init]);
 if(route==='session')return Response.json({authenticated:true,userId:'2'});
 if(route==='courses')return Response.json({courses:[]});
 if(route==='materials/7/open')return Response.json(fileMode?{kind:'file',format:'text',filename:'Инструкция.txt',size:Buffer.byteLength(fileText),sourceToken,origin:{id:7,title:'Порядок работы',published:true,editingCopy:true}}:{kind:'project',code:'MATERIAL-7',contentid:8,origin:{id:7,title:'Порядок работы',published:true,editingCopy:true}});
 if(route==='materials/7/file')return new Response(fileText);
 if(route==='materials/7/import'){const input=JSON.parse(init.body);assert.equal(input.sourceToken,sourceToken);assert.equal(init.headers['X-Moodle-Sesskey'],'csrf');Object.assign(project,input.course);project.data.code='MATERIAL-7';return Response.json({kind:'project',code:'MATERIAL-7',contentid:8,origin:{id:7,title:'Порядок работы',published:true,editingCopy:true}});}

 if(route==='courses/MATERIAL-7'){
  if(init.method){const input=JSON.parse(init.body);writes.push(input);return Response.json({revision:4,contentmodified:42,fingerprint:fingerprint(input.course)});}
  return Response.json({code:'MATERIAL-7',contentid:8,revision:3,contentmodified:41,status:'draft',fingerprint:fingerprint(project),course:project});
 }
 throw Error('Unexpected request '+route);
};
for(const script of w.document.querySelectorAll('script:not([src])'))w.eval(script.textContent);
for(const name of ['runtime.js','model.js','rich.js','cloud.js','pdf.js','word.js','material.js','app.js'])w.eval(fs.readFileSync(dir+'/'+name,'utf8'));
async function until(predicate){for(let i=0;i<250;i++){if(predicate())return;await new Promise(r=>setTimeout(r,10));}throw Error('Material open timed out: '+w.document.querySelector('#toast').textContent);}
(async()=>{
 await until(()=>w.document.querySelector('#course-select').value==='MATERIAL-7');
 assert.equal(w.document.querySelector('#course-select').options.length,1,'direct opening must not leave an empty phantom course');
 assert(w.document.querySelector('#editor-content').textContent.includes('Редактируемый материал'));
 assert.equal(w.document.querySelector('#material-origin-link').getAttribute('href'),'/material.php?id=7');
 assert(w.document.querySelector('#material-origin-text').textContent.includes('черновик'));
 assert.equal(writes.length,0,'opening a draft must not write an empty course');
 const opened=requests.find(([route])=>route==='materials/7/open')[1];assert.equal(opened.method,'POST');assert.equal(opened.headers['X-Moodle-Sesskey'],'csrf');
 const editor=w.document.querySelector('#editor-content');editor.innerHTML='<p>Уточнённый материал</p>';editor.dispatchEvent(new w.Event('input',{bubbles:true}));
 w.document.querySelector('#cloud-refresh').click();await until(()=>writes.length===1);
 assert.equal(writes[0].baseRevision,3);assert.equal(writes[0].contentmodified,41);
 assert(writes[0].course.data.pages[0].html.includes('Уточнённый материал'));
 const server=JSON.parse(require('node:child_process').execFileSync(process.env.USTAR_PHP||'php',[path.join(__dirname,'course_studio_source.php')],{encoding:'utf8',env:{...process.env,USTAR_SOURCE_JSON:'1'}}));
 const hydrated={...server.course,assets:{'assets/imported.png':{type:'image/png',data:'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jZLkAAAAASUVORK5CYII='}}};
 const wire=await new w.RCCloud.CloudSync({model:w.RCModel}).manifest(hydrated);
 assert.equal(wire.fingerprint,server.fingerprint,'server-created source must pass browser fingerprint verification');
 const empty=new Uint8Array();await assert.rejects(()=>w.StudioMaterial.convert({size:0,format:'text',filename:'empty.txt',origin:{title:'Пустой'}},empty),/пуст|нет текста/);
 assert.equal(requests.filter(([r])=>r==='materials/7/import').length,fileMode?1:0);
 console.log('COURSE_STUDIO_MATERIAL_DOM_OK '+(fileMode?'authorized file conversion source token':'exact draft')+' no phantom inherited revision safe local text');dom.window.close();
 if(!fileMode)process.stdout.write(require('node:child_process').execFileSync(process.execPath,[__filename],{encoding:'utf8',env:{...process.env,USTAR_TEST_MATERIAL_FILE:'1'}}));
})().catch(e=>{dom.window.close();console.error(e);process.exitCode=1;});
