const fs=require('node:fs'),assert=require('node:assert/strict'),Mustache=require('mustache'),{JSDOM}=require('jsdom');
const root=__dirname+'/../../moodle/local/ustar',tpl=fs.readFileSync(root+'/templates/library_manage.mustache','utf8');
const partialPath=root+'/templates/library_question_fields.mustache',partials={};
if(fs.existsSync(partialPath))partials['local_ustar/library_question_fields']=fs.readFileSync(partialPath,'utf8');
const questionfields=Array.from({length:5},(_,i)=>({index:i,number:i+1,text:'Question '+i,options:Array.from({length:6},(_,j)=>({index:j,question:i,number:j+1,value:j<2?'Answer '+i+'-'+j:'',checked:j===(i%2)}))}));
const blankquestion=[{index:'__key__',number:1,text:'',options:Array.from({length:6},(_,j)=>({index:j,question:'__key__',number:j+1,value:'',checked:j===0}))}];
partials['local_ustar/library_manage_pagination']=fs.readFileSync(root+'/templates/library_manage_pagination.mustache','utf8');
const model={blankquestion,formerror:true,checkerror:true,writable:true,assessor:true,checkid:8,checkrevision:3,checktitle:'Typed bank',questionfields,questioncount:2,passpercent:90,maxattempts:4,targetrefs:[{value:'topic',selected:true,label:'Topic'}],checkstatuses:[{value:'draft',selected:true,label:'Draft'}]};
const dom=new JSDOM(Mustache.render(tpl,model,partials),{url:'https://fixture.invalid',runScripts:'outside-only'}),doc=dom.window.document;
const add=doc.querySelector('[data-library-add-question]');assert(add,'An author must be able to add a sixth question');
if(process.env.USTAR_LIBRARY_BUILD==='1'){let api;dom.window.define=(name,deps,factory)=>{api={};factory(api);};dom.window.eval(fs.readFileSync(root+'/amd/build/library_manage.min.js','utf8'));api.init();}else{const source=fs.readFileSync(root+'/amd/src/library_manage.js','utf8').replace(/export const init\s*=/,'const init=');dom.window.eval(source+';init();');}
const form=doc.querySelector('input[value=savecheck]').form;const pendingEvent=new dom.window.Event('beforeunload',{cancelable:true});dom.window.dispatchEvent(pendingEvent);assert(pendingEvent.defaultPrevented,'Rejected POST remains unsaved before the next edit');form.dispatchEvent(new dom.window.Event('submit'));const savedEvent=new dom.window.Event('beforeunload',{cancelable:true});dom.window.dispatchEvent(savedEvent);assert(!savedEvent.defaultPrevented,'Intentional form submission releases its guard');const list=form.querySelector('[data-library-questions]'),rows=()=>[...list.querySelectorAll('[data-library-question]')];
add.click();assert.equal(rows().length,6);const last=rows().at(-1),key=last.dataset.libraryQuestion;
last.querySelector('textarea').value='Sixth question';last.querySelectorAll('input[type=text]')[0].value='Sixth first';last.querySelectorAll('input[type=text]')[1].value='Sixth second';last.querySelectorAll('input[type=radio]')[1].checked=true;
const moveButton=last.querySelector('[data-library-question-action=up]');moveButton.focus();moveButton.click();assert.equal(doc.activeElement,moveButton,'Reordering preserves keyboard focus');assert.equal(rows().at(-2),last,'Move preserves row identity');
rows()[0].querySelector('[data-library-question-action=remove]').click();assert.equal(rows().length,5);
let data=new dom.window.FormData(form);assert.equal(data.get('questions['+key+'][text]'),'Sixth question');assert.equal(data.get('questions['+key+'][correct]'),'1','Move/delete preserves correct option');assert.equal(data.get('expectedrevision'),'3','Client does not rebase stale revision');
const keys=rows().map(r=>r.dataset.libraryQuestion);while(rows().length<100)add.click();assert(add.disabled,'Add stops at the server limit');add.click();assert.equal(rows().length,100);assert.equal(new Set(rows().map(r=>r.dataset.libraryQuestion)).size,100,'Keys remain unique after deletion and addition');
rows().at(-1).querySelector('[data-library-question-action=remove]').click();assert(!add.disabled,'Deleting reopens capacity');
assert(form.querySelector('[data-library-question-status][aria-live]'),'Question operations have an accessible status');
console.log('LIBRARY_MANAGEMENT_DOM PASS sixth question, stable correct, order, stale token,100limit, capacity, live status');

// Server context must remain visible on scoped search and immutable edits.
const pinned=Mustache.render(tpl,{writable:true,author:true,grantor:true,id:7,subject:'content:501',selectedsubjectlabel:'Old selected source',subjects:[{value:'content:501',label:'Old selected source',selected:true}],historyq:'needle',historysearchfields:[{name:'edit',value:7},{name:'check',value:8},{name:'personq',value:'Allowed'}],historypagination:{number:6,hasprevious:true,hasnext:false,previousurl:'/manage?historypage=4&historyq=needle&edit=7#library-history'},subjectpagination:{number:1}},partials);
const pinnedDoc=new JSDOM(pinned).window.document,pinnedForm=pinnedDoc.querySelector('input[value=save]').form;
assert.equal(pinnedForm.querySelector('select[name=subject]'),null,'Existing offer cannot silently select another subject');
assert.equal(new pinnedDoc.defaultView.FormData(pinnedForm).get('subject'),'content:501');
const historySearch=pinnedDoc.querySelector('[name=historyq]').form;assert.equal(new pinnedDoc.defaultView.FormData(historySearch).get('edit'),'7');assert.equal(new pinnedDoc.defaultView.FormData(historySearch).get('check'),'8');assert.equal(new pinnedDoc.defaultView.FormData(historySearch).get('personq'),'Allowed');
assert(pinnedDoc.querySelector('a[href*=historypage]'),'History has backward navigation beyond the old first100');
console.log('LIBRARY_MANAGEMENT_DOM_CONTEXT PASS immutable binding, search context, previous history page');
