const fs=require('node:fs'),assert=require('node:assert/strict');
const {JSDOM}=require('jsdom'),Mustache=require('mustache');
const tpl=n=>fs.readFileSync(__dirname+'/../../moodle/local/ustar/templates/'+n+'.mustache','utf8');
const dom=new JSDOM(Mustache.render(tpl('library'),{tab:'catalog',parent:71,q:'bolt',hasprevious:true,previousurl:'/?page=0',hasmore:true,nexturl:'/?page=2'}));
assert.equal(dom.window.document.querySelector('input[name=parent]')?.value,'71');
assert(dom.window.document.querySelector('a[href="/?page=0"]'));
const empty=new JSDOM(Mustache.render(tpl('library_checks'),{hasassessments:false,q:'missing'}));
assert(empty.window.document.body.textContent.includes('По вашему запросу'));
console.log('Library navigation DOM: PASS');
