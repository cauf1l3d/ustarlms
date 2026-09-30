const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {JSDOM}=require('jsdom'),Mustache=require('mustache');
const base=path.resolve(__dirname,'../../moodle/local/ustar'),tick=()=>new Promise(r=>setImmediate(r));
async function run(){
 const widgets=['tasks','checklists','notes'].map(id=>`<section data-home-block="${id}" data-home-label="${id}" class="u-home-widget"><h2>${id}</h2></section>`).join('');
 const html=Mustache.render(fs.readFileSync(path.join(base,'templates/home.mustache'),'utf8'),{viewhome:true,canpersonalize:true,homelayoutjson:JSON.stringify({revision:0,items:{}}),homelayouturl:'/local/ustar/home_layout.php',sesskey:'key',workflowshtml:widgets,homeextras:[{id:'achievements',label:'Достижения'}]});
 const dom=new JSDOM(html,{runScripts:'outside-only'}),w=dom.window,calls=[];let revision=0;
 w.fetch=async(url,opts)=>{calls.push(Object.fromEntries(opts.body));assert.equal(url,'/local/ustar/home_layout.php');assert.equal(Number(opts.body.get('revision')),revision);assert.equal(opts.body.get('sesskey'),'key');await tick();return {ok:true,json:async()=>({ok:true,revision:++revision})};};
 w.eval(fs.readFileSync(path.join(base,'home_layout.js'),'utf8'));w.document.dispatchEvent(new w.Event('DOMContentLoaded'));
 let ids=()=>Array.from(w.document.querySelector('[data-home-layout]').children).map(el=>el.dataset.homeBlock);
 assert.deepEqual(ids(),['hero','next','tasks','checklists','notes','links','achievements']);
 assert.equal(w.document.querySelector('[data-home-block=achievements]').hidden,true);
 let checkbox=w.document.querySelector('[data-home-controls] input');checkbox.checked=false;checkbox.dispatchEvent(new w.Event('change'));
 assert.equal(w.document.querySelector('[data-home-block=hero]').hidden,true);
 let spans=w.document.querySelectorAll('[data-home-controls] select');spans[0].value='6';spans[0].dispatchEvent(new w.Event('change'));
 for(let i=0;i<8;i++)await tick();assert.equal(calls.length,2);assert.equal(JSON.parse(calls[1].layout).hero.span,6);
 const row=w.document.querySelector('.u-home-control');row.querySelector('button[aria-label^="Опустить"]').click();for(let i=0;i<4;i++)await tick();assert.equal(ids()[0],'next');
 w.document.querySelector('[data-home-reset]').click();for(let i=0;i<4;i++)await tick();assert.equal(ids()[0],'hero');assert.equal(w.document.querySelector('[data-home-block=hero]').hidden,false);
 w.close();console.log('HOME_LAYOUT_DOM_OK default-order hidden-add size serial-revision csrf reorder reset');
}
run().catch(e=>{console.error(e);process.exit(1);});
