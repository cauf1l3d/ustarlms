const assert=require('node:assert/strict'),path=require('node:path');
const dir=path.resolve(__dirname,'../../moodle/local/ustar/course_studio');
global.window=globalThis;const M=require(dir+'/model.js');global.COURSE_STUDIO={api:'/local/ustar/course_studio_api.php',sesskey:'csrf-token'};
require(dir+'/cloud.js');
const clone=x=>structuredClone(x),records=new Map();let calls=0,loseResponse=false;
async function server(url,init={}) {
 const route=new URL(url,'https://academy.example').searchParams.get('route');
 if(init.method){assert.equal(init.method,'POST');assert.equal(init.headers['X-Moodle-Sesskey'],'csrf-token');}
 if(route==='courses')return Response.json({courses:[...records.values()].map(({course,...r})=>r)});
 if(route?.startsWith('courses/')){
  const code=decodeURIComponent(route.slice(8)),old=records.get(code);
  if(!init.method)return Response.json(old||{});
  const p=JSON.parse(init.body),fp=await hash(p.course);
  if(old&&old.fingerprint===fp)return Response.json(old);
  if(old&&(old.revision!==p.baseRevision||old.contentmodified!==p.contentmodified))return Response.json({code:'conflict'},{status:409});
  const r={code,course:p.course,fingerprint:fp,revision:(old?.revision||0)+1,contentmodified:(old?.contentmodified||100)+1,updatedAt:new Date().toISOString()};records.set(code,r);calls++;
  if(loseResponse){loseResponse=false;throw Error('response lost after commit')}
  return Response.json(r);
 }
 throw Error('unexpected '+route);
}
async function hash(c){return Buffer.from(await crypto.subtle.digest('SHA-256',new TextEncoder().encode(JSON.stringify(c)))).toString('hex');}
const project=()=>M.normalizeCourse({data:{code:'TEAM-01',title:'Курс',version:'1.0',pages:[{id:'one',title:'Начало',html:'<p>Материал</p>'}]}});
function client(initial=[]){let local=clone(initial),state;const s=new global.RCCloud.CloudSync({auto:false,model:M,fetch:server,expectedUserId:'2',getCourses:()=>local,isEdited:()=>true,loadState:()=>state,saveState:x=>{state=clone(x)},onRemote:c=>{const i=local.findIndex(v=>v.data.code===c.data.code);if(i<0)local.push(c);else local[i]=c;}});return {s,local:()=>local,state:()=>state};}
(async()=>{
 const a=client([project()]);await a.s.start({authenticated:true,userId:'2'});await a.s.flush();assert.equal(calls,1);
 const b=client();await b.s.start({authenticated:true,userId:'2'});assert.equal(b.local()[0].data.title,'Курс');
 a.local()[0].data.title='Первая правка';a.s.mark(a.local()[0]);await a.s.flush();
 b.local()[0].data.title='Вторая правка';b.s.mark(b.local()[0]);await b.s.flush();assert(b.s.state.entries['TEAM-01'].conflict);
 const copy=await b.s.resolve('TEAM-01');await b.s.flush();assert(copy);assert.equal(records.get('TEAM-01').course.data.title,'Первая правка');assert.equal(records.get(copy).course.data.title,'Вторая правка · копия 1 · мои правки');
 const before=calls;a.local()[0].data.pages[0].html='<p>Новая редакция</p>';a.s.mark(a.local()[0]);loseResponse=true;await a.s.flush();assert(a.s.problem);await a.s.run();assert.equal(calls,before+1);assert(!a.s.hasPending());
 // Settings-only writes must also conflict with a stale author.
 const d=client();await d.s.start({authenticated:true,userId:'2'});records.get('TEAM-01').contentmodified++;
 d.local().find(c=>c.data.code==='TEAM-01').data.title='Устаревшая правка';d.s.mark(d.local().find(c=>c.data.code==='TEAM-01'));await d.s.flush();assert(d.s.state.entries['TEAM-01'].conflict);
 const revoked=client();revoked.s.o.fetch=async()=>Response.json({error:'forbidden'},{status:403});await revoked.s.start({authenticated:true,userId:'2'});assert(!revoked.s.ready);assert(revoked.s.stopped);
 console.log('COURSE_STUDIO_SYNC_OK shared conflict keep-both retry metadata csrf revoked');

})().catch(e=>{console.error(e);process.exitCode=1});
