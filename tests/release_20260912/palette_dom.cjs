const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {JSDOM}=require('jsdom');
const root=path.resolve(__dirname,'../../moodle/theme/ustar');
const template=fs.readFileSync(path.join(root,'templates/shell.mustache'),'utf8');
const early=template.match(/<script[^>]*>([\s\S]*?)<\/script>/)[1];
const source=fs.readFileSync(path.join(root,'amd/src/shell.js'),'utf8');
for(const preset of ['lavender','mint']){
 const dom=new JSDOM(`<html data-ustar-preset="${preset}"><body></body></html>`,{url:'https://ustar.test',runScripts:'outside-only'});
 const w=dom.window;w.localStorage.setItem('ustar-preset','coral');w.localStorage.setItem('ustar-theme','dark');
 w.eval(early);assert.equal(w.document.documentElement.dataset.ustarPreset,preset,'Early render must preserve the account palette');
 w.define=(_,factory)=>factory().init();w.eval(source);
 assert.equal(w.document.documentElement.dataset.ustarPreset,preset,'Another account browser cache must not override saved settings');
 assert.equal(w.document.documentElement.dataset.ustarTheme,'dark');w.close();
}
console.log('PALETTE_DOM_OK account-preference preserved at early render and AMD init');
