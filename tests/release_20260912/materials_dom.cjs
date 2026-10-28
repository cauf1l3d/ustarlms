const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {JSDOM}=require('jsdom'),mustache=require('mustache');
const root=path.resolve(__dirname,'../../moodle/local/ustar');
const data={viewkey:'materials:fixture:2:view',canmanage:true,total:2,visiblecount:2,filteredtotal:2,rangefrom:1,rangeto:2,currentfolder:'Материалы',hasmaterials:true,sesskey:'csrf',materials:[
 {id:1,title:'Учебный материал с длинным названием',visualclass:'doc',visualtype:'DOCX',summary:'Краткое содержание',statuslabel:'Опубликован',url:'/material.php?id=1',openstudiourl:'/course_studio.php?materialid=1',canopenstudio:true,canmove:true,timemodified:123,folderoptions:[{id:2,name:'Инструкции'}]},
 {id:2,title:'Инструкции',visualclass:'folder',visualtype:'ПАПКА',isfolder:true,canmove:true,timemodified:124,url:'/materials.php?parent=2'}]};
const template=fs.readFileSync(root+'/templates/materials.mustache','utf8'),source=fs.readFileSync(root+'/amd/build/materials.min.js','utf8');
function boot(manager=true,stored=''){
 const d=new JSDOM(mustache.render(template,{...data,canmanage:manager,materials:data.materials.map(r=>({...r,canmove:manager,canopenstudio:manager&&r.canopenstudio}))}),{url:'https://academy.example/materials.php',runScripts:'outside-only'}),w=d.window;
 if(stored)w.localStorage.setItem(data.viewkey,stored);
 let api;w.define=(deps,f)=>api=f();w.eval(source);api.init();return d;
}
for(const manager of [false,true]){
 const d=boot(manager,'blocks'),w=d.window,doc=w.document;
 assert.equal(doc.querySelector('[data-materials-view]').dataset.materialsView,'blocks','view restored for readers and authors');
 assert.equal(doc.querySelectorAll('.u-material-row-shell').length,2,'one item DOM shared by all views');
 for(const view of ['list','blocks','cards']){
  doc.querySelector('[data-u-material-view="'+view+'"]').click();
  assert.equal(doc.querySelector('[data-materials-view]').dataset.materialsView,view);
  assert.equal(w.localStorage.getItem(data.viewkey),view);
  assert.equal(doc.querySelectorAll('[data-u-material-view][aria-pressed="true"]').length,1);
  assert.equal(doc.querySelectorAll('.u-material-row-shell').length,2);
 }
 if(manager){
  const menu=doc.querySelector('.u-material-row-menu');menu.open=true;menu.querySelector('summary').focus();
  menu.querySelector('summary').dispatchEvent(new w.KeyboardEvent('keydown',{key:'Escape',bubbles:true}));assert(!menu.open);assert.equal(doc.activeElement,menu.querySelector('summary'));
  const form=doc.querySelector('[data-u-move-form]');let moved=0;form.submit=()=>moved++;
  for(const view of ['list','blocks','cards']){
   doc.querySelector('[data-u-material-view="'+view+'"]').click();
   const transfer={setData(){},effectAllowed:''};
   const drag=new w.Event('dragstart',{bubbles:true});Object.defineProperty(drag,'dataTransfer',{value:transfer});doc.querySelector('[data-u-drag-content="1"]').dispatchEvent(drag);
   const drop=new w.Event('drop',{bubbles:true,cancelable:true});Object.defineProperty(drop,'dataTransfer',{value:transfer});doc.querySelector('[data-u-drop-folder="2"]').dispatchEvent(drop);
   assert.equal(form.elements.contentid.value,'1');assert.equal(form.elements.expectedmodified.value,'123');assert.equal(form.elements.targetparentid.value,'2');
   // A new navigation is normally produced by submit; clear the fixture's busy guard.
   form.removeAttribute('aria-busy');
  }
  assert.equal(moved,3);
 }
 d.window.close();
}
const invalid=boot(false,'invalid');assert.equal(invalid.window.document.querySelector('[data-materials-view]').dataset.materialsView,'cards');invalid.window.close();
const broken=boot(false);Object.defineProperty(broken.window,'localStorage',{get(){throw Error('storage blocked')}});let api;broken.window.define=(d,f)=>api=f();broken.window.eval(source);api.init();broken.window.document.querySelector('[data-u-material-view="list"]').click();assert.equal(broken.window.document.querySelector('[data-materials-view]').dataset.materialsView,'list');broken.window.close();
console.log('MATERIALS_DOM_OK reader author persistent views single DOM keyboard menu drag revision storage failures');
