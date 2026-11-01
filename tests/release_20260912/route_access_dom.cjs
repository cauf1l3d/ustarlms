const fs=require('fs'),path=require('path'),assert=require('assert/strict'),{JSDOM,VirtualConsole}=require('jsdom');
const root=path.join(__dirname,'../../moodle/local/ustar');
const tick=()=>new Promise(resolve=>setImmediate(resolve));
(async()=>{
  const dom=new JSDOM('<form data-route-advance data-closes-access="1"><button>Далее</button></form><span data-route-deadline="1001" data-route-now="1000"></span><form data-route-deadline-sync></form>',{url:'https://ustar.invalid/local/ustar/route.php',runScripts:'outside-only'});
  const w=dom.window,form=w.document.querySelector('[data-route-advance]');let confirm=false,clock,submitted=0;
  w.confirm=()=>confirm;w.setInterval=fn=>{clock=fn;return 1};
  w.document.querySelector('[data-route-deadline-sync]').submit=()=>submitted++;
  w.eval(fs.readFileSync(path.join(root,'route_access.js'),'utf8'));
  assert.equal(form.dispatchEvent(new w.Event('submit',{cancelable:true})),false,'cancel prevents POST');
  assert.equal(form.querySelector('[name=confirmed]'),null,'cancel cannot add approval');
  confirm=true;assert.equal(form.dispatchEvent(new w.Event('submit',{cancelable:true})),true);
  assert.equal(form.querySelector('[name=confirmed]').value,'1');
  Object.defineProperty(w.performance,'now',{value:()=>2000});clock();clock();assert.equal(submitted,1,'deadline synchronizes once');w.close();

  const scorm=new JSDOM('<body data-ustar-scorm-cmid="41" data-ustar-scorm-launch="freshlaunch" data-ustar-scorm-status-url="/local/ustar/scorm_route_status.php"><main class="u-main"><iframe id="scorm_object"></iframe></main></body>',{url:'https://ustar.invalid/mod/scorm/player.php',runScripts:'outside-only',pretendToBeVisual:true,virtualConsole:new VirtualConsole()});
  const v=scorm.window,inner=v.document.querySelector('iframe').contentDocument;inner.body.innerHTML='<button disabled>Курс завершён</button>';
  let tasks=new Map(),n=0,posts=0;v.setTimeout=(fn,delay)=>{tasks.set(++n,{fn,delay});return n};v.clearTimeout=i=>tasks.delete(i);v.AbortController=AbortController;
  v.fetch=async(url,options)=>({ok:true,json:async()=>{
    if(options.method==='POST'){posts++;return {confirmed:true,navigate:false,targeturl:'/local/ustar/route.php'}}
    return {active:true,ready:true,sesskey:'fixturekey',reviewing:true};
  }});
  v.eval(fs.readFileSync(path.join(root,'scorm_route_return.js'),'utf8'));await tick();
  async function poll(){const t=[...tasks].find(([,t])=>t.delay!==10000);assert.ok(t,'scheduled check');tasks.delete(t[0]);await t[1].fn();await tick()}
  await poll();
  const control=v.document.querySelector('[data-ustar-scorm-finish]')||inner.querySelector('[data-ustar-scorm-finish]');
  assert.ok(control,'completed old package offers an enabled route control');
  assert.equal(posts,0,'opening a completed package does not submit or reward');
  control.click();await tick();await poll();assert.equal(posts,1,'explicit control confirms once');
  v.dispatchEvent(new v.Event('pagehide'));v.close();
  console.log('PASS route access browser: cancellation, confirmation, deadline, completed-package return');
})().catch(e=>{console.error(e);process.exitCode=1});
