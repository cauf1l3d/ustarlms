// Real Mustache templates rendered with synthetic models; no browser/layout claim.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'../../../..');
const {JSDOM}=require(root+'/tests/release_20260912/node_modules/jsdom');
const Mustache=require(root+'/tests/release_20260912/node_modules/mustache');
const tpl=n=>fs.readFileSync(root+'/moodle/local/ustar/templates/'+n+'.mustache','utf8');
let dom=new JSDOM(Mustache.render(tpl('library_manage'),{writable:true,assessor:true,actionurl:'/',questionfields:Array.from({length:5},(_,i)=>({index:i,number:i+1,options:[]}))}));
const bankForm=dom.window.document.querySelector('input[value="savecheck"]').form;
const out={scope:'production Mustache + JSDOM synthetic view models; no live Moodle, visual, screen-reader or task timing test',new_bank:{question_fields:bankForm.querySelectorAll('textarea').length,buttons:[...bankForm.querySelectorAll('button')].map(x=>x.textContent)}};
assert.equal(out.new_bank.question_fields,5);assert.equal(out.new_bank.buttons.length,1);
dom=new JSDOM(Mustache.render(tpl('library'),{tab:'catalog',q:'',libraryurl:'/',hasmore:true,nexturl:'/?tab=catalog&page=2',showcatalog:true},{'local_ustar/library_catalog':tpl('library_catalog')}));
const search=dom.window.document.querySelector('form');
out.catalog_search_fields=[...search.querySelectorAll('input')].map(x=>x.name);
out.pagination_links=[...dom.window.document.querySelectorAll('a')].filter(x=>x.textContent.includes('страниц')).map(x=>x.textContent);
assert(!out.catalog_search_fields.includes('parent'));assert.deepEqual(out.pagination_links,['Следующая страница →']);
dom=new JSDOM(Mustache.render(tpl('library_checks'),{hasassessments:false,assessmentcount:0,q:'no match'}));
out.checks_empty_with_query=dom.window.document.querySelector('.u-empty').textContent.trim();
console.log(JSON.stringify(out,null,2));
