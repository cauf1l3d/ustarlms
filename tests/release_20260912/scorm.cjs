const fs=require('fs'),path=require('path'),assert=require('assert/strict'),{JSDOM,VirtualConsole}=require('jsdom');
const source=fs.readFileSync(process.argv[2]||path.join(__dirname,'../../moodle/local/ustar/scorm_route_return.js'),'utf8');
const tick=()=>new Promise(r=>setImmediate(r));let n=0;
function check(x,m){assert.ok(x,m);n++;}
(async()=>{
for(const trigger of ['click','change']){
 const dom=new JSDOM('<body id="page-mod-scorm-player" data-ustar-scorm-cmid="41" data-ustar-scorm-launch="freshlaunch" data-ustar-scorm-status-url="/local/ustar/scorm_route_status.php"><header class="u-topbar"></header><main class="u-main"><div id="scorm_toc_toggle">&gt;</div><form action="/mod/scorm/view.php"><button>Перейти на главную страницу курса</button></form><form action="/course/view.php"><button>К курсу</button></form><div id="scorm_layout"><div id="scormpage"><div class="unknown-auto-height-wrapper"><iframe id="scorm_object"></iframe></div></div></div></main><div data-ustar-scorm-return>Old</div></body>',{url:'http://ustar.local/mod/scorm/player.php',runScripts:'outside-only',pretendToBeVisual:true,virtualConsole:new VirtualConsole()});
 const w=dom.window,inner=w.document.querySelector('iframe').contentDocument;inner.body.innerHTML='<button>Завершить ✓</button>';
 let tasks=new Map(),id=0,posts=[],gets=0,ready=false,mode='ok';
 w.setTimeout=(fn,delay)=>{tasks.set(++id,{fn,delay});return id};w.clearTimeout=i=>tasks.delete(i);w.AbortController=AbortController;
 w.fetch=async(url,o)=>{if(o.method==='POST'){posts.push(new URLSearchParams(o.body));if(mode==='offline')throw Error('offline');return {ok:true,json:async()=>({confirmed:true,targeturl:mode==='external'?'https://elsewhere.invalid':'/local/ustar/route_next.php'})};}gets++;return {ok:true,json:async()=>({active:mode!=='stale',ready,sesskey:'livekey'})};};
 async function poll(){const e=[...tasks].find(([i,t])=>t.delay!==10000);if(e){tasks.delete(e[0]);await e[1].fn();}await tick();}
 w.eval(source);await tick();await poll();
 check(!w.document.querySelector('[data-ustar-scorm-return]'),'no outer panel');
 check(w.document.querySelector('#scorm_object').style.height===w.innerHeight+'px','iframe uses pixel height across unknown auto-height wrapper');
 const late=w.document.createElement('iframe');late.id='scorm_late';w.document.querySelector('#scormpage').appendChild(late);await tick();
 check(late.style.height===w.innerHeight+'px','late Moodle frame gets pixel height');
 Object.defineProperty(w,'innerHeight',{value:620,configurable:true});w.dispatchEvent(new w.Event('resize'));
 check(w.document.querySelector('#scorm_object').style.height==='620px','iframe follows viewport resize');
 check(!!w.document.querySelector('#ustar-scorm-fit-runtime'),'critical styles bundled with runtime');
 check(w.document.querySelector('#scorm_toc_toggle').style.display==='none','legacy toggle hidden');
 check(w.document.querySelector('form[action="/mod/scorm/view.php"] button').style.display==='none','legacy caption hidden independently of URL');
 check(w.document.querySelector('#scorm_layout').style.padding==='0px','player top padding removed');
 check(w.document.querySelector('.u-main').style.padding==='0px','main top padding removed');
 check(w.document.body.classList.contains('ustar-scorm-inline'),'inline layout enabled');
 check(!!w.document.querySelector('form.ustar-scorm-legacy'),'outer course button hidden');
 check(!!w.document.body.style.getPropertyValue('--ustar-scorm-height'),'viewport fit calculated');
 check(posts.length===0&&gets===0,'no request or navigation before finish');
 if(trigger==='click'){inner.querySelector('button').click();await tick();await poll();check(posts.length===0&&gets===0,'click alone cannot advance before studied state');}
 inner.querySelector('button').textContent='Изучено';
 await tick();await poll();check(gets>0&&posts.length===0,'finish waits for server completion');
 ready=true;mode='stale';await poll();check(posts.length===0,'stale launch cannot confirm');
 mode='offline';await poll();check(posts.length===1,'attempt confirmation after readiness');
 check(posts[0].get('sesskey')==='livekey'&&posts[0].get('launchid')==='freshlaunch'&&posts[0].get('acknowledged')==='1','protected POST retained');
 mode='external';await poll();check(posts.length===2,'network failure retries automatically');
 mode='ok';await poll();check(posts.length===3,'external destination rejected before retry');
 await poll();check(posts.length===3,'success stops further confirmation');
 w.dispatchEvent(new w.Event('pagehide'));w.close();
}
console.log('PASS inline assertions='+n);
})().catch(e=>{console.error(e);process.exitCode=1});
