// Real native Mustache/theme; synthetic UI transport. Actual Moodle POST/SQL is a separate fixture.
const fs=require('fs'),path=require('path'),assert=require('assert'),http=require('http');
const Mustache=require('mustache'),sass=require('sass'),{chromium}=require('playwright');
const repo=path.resolve(__dirname,'../..');
const template=fs.readFileSync(path.join(repo,'moodle/local/ustar/templates/notifications.mustache'),'utf8');
const initial=[
  {id:1,source:'local',subject:'Local unread',message:'Acknowledge without completing a task',unread:true,canack:true},
  {id:2,source:'local',subject:'Local read',message:'Reading is a different state',unread:false,read:true,canack:true},
  {id:3,source:'local',subject:'Local acknowledged',message:'First receipt is preserved',unread:false,read:true,acknowledged:true,acktime:'21.10.2026 12:00'},
  {id:1,source:'moodle',subject:'Core read, same numeric ID',message:'Core reading has no USTAR ACK',unread:false,read:true}
];
function render(accepted=new Set([3])) {
  const notifications=initial.map(row=>row.source==='local'&&accepted.has(row.id)
      ?{...row,canack:false,unread:false,acknowledged:true,acktime:'21.10.2026 12:00'}:row);
  const unread=notifications.filter(row=>row.unread).length;
  return Mustache.render(template,{sesskey:'synthetic-session',hasnotifications:true,hasunread:unread>0,unread,
    acklabel:'Принял',acknowledgedlabel:'Подтверждено',readlabel:'Прочитано',
    notifications});
}
const html=render();
assert.strictEqual((html.match(/name="action" value="ack"/g)||[]).length,2,'Only eligible local notices expose explicit acknowledgement');
assert(html.includes('Принял')&&html.includes('Подтверждено')&&html.includes('Прочитано'),'Read and acknowledgement labels must be readable and distinct');
assert.strictEqual((html.match(/name="action" value="read"/g)||[]).length,1,'Read controls belong only to an unread row');
assert(!render(new Set([1,3])).includes('name="action" value="all"'),'Acknowledging the last unread notice clears its unread counter');
assert(!html.includes('notification.lease')&&!html.includes('bridge.key'));
if(process.argv.includes('--render-only')) { console.log('NOTIFICATION_ACK_TEMPLATE=PASS');process.exit(0); }
const lib=fs.readFileSync(path.join(repo,'moodle/theme/ustar/lib.php'),'utf8');
const styles=[...lib.matchAll(/__DIR__ \. '\/scss\/([^']+)'/g)].map(m=>fs.readFileSync(path.join(repo,'moodle/theme/ustar/scss',m[1]),'utf8')).join('\n');
const css=sass.compileString(styles,{logger:sass.Logger.silent}).css;
const sessions=new Map(),posts=[],evidence=[];
const server=http.createServer((req,res)=>{
  const url=new URL(req.url,'http://localhost'),run=url.searchParams.get('run')||'0';
  if(!sessions.has(run))sessions.set(run,new Set([3]));
  if(req.method==='POST') {
    let raw='';req.on('data',chunk=>raw+=chunk);req.on('end',()=>{
      const form=Object.fromEntries(new URLSearchParams(raw));
      assert.strictEqual(form.action,'ack');assert.strictEqual(form.source,'local');assert.strictEqual(form.sesskey,'synthetic-session');
      assert(['1','2'].includes(form.id));assert(!Object.hasOwn(form,'userid'));
      posts.push({id:form.id,source:form.source,action:form.action});sessions.get(run).add(Number(form.id));
      res.writeHead(303,{Location:'/notifications?run='+run});res.end();
    });return;
  }
  res.setHeader('Content-Type','text/html; charset=utf-8');
  res.end('<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>*{box-sizing:border-box}body{margin:0;font:16px/1.5 system-ui}button,input{font:inherit}'+css+'</style></head><body>'+render(sessions.get(run))+'</body></html>');
});
(async()=>{
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
  const browser=await chromium.launch({headless:true,args:['--no-sandbox'],...(process.env.USTAR_CHROMIUM?{executablePath:process.env.USTAR_CHROMIUM}:{})});
  const out=path.join(repo,'artifacts/notification-ack');fs.mkdirSync(out,{recursive:true});
  try {
    for(const width of [320,390,768,1366,1920]) {
      const page=await browser.newPage({viewport:{width,height:844}}),errors=[];
      page.on('pageerror',e=>errors.push(e.message));
      await page.goto('http://127.0.0.1:'+server.address().port+'/notifications?run='+width);
      const own=page.locator('article').filter({hasText:'Local unread'});
      const core=page.locator('article').filter({hasText:'Core read, same numeric ID'});
      assert.strictEqual(await core.getByRole('button',{name:'Принял',exact:true}).count(),0);
      assert.strictEqual(await core.getByRole('button',{name:'Отметить прочитанным',exact:true}).count(),0);
      const button=own.getByRole('button',{name:'Принял',exact:true});
      await button.focus();await button.press('Enter');
      await own.getByRole('status').filter({hasText:'Подтверждено'}).waitFor();
      assert.strictEqual(await own.getByRole('button',{name:'Принял',exact:true}).count(),0);
      assert.strictEqual(await page.getByRole('button',{name:/^Прочитать всё/}).count(),0);
      assert(await page.locator('article').filter({hasText:'Local read'}).getByText('Прочитано',{exact:true}).isVisible());
      assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Notification controls overflow viewport');
      assert.deepStrictEqual(errors,[]);await page.screenshot({path:path.join(out,'ack-'+width+'.png'),fullPage:true});
      evidence.push({width,keyboard_submit:true,source_isolated:true,overflow:false,js_errors:errors});await page.close();
    }
    assert.strictEqual(posts.length,5);assert(posts.every(p=>p.id==='1'&&p.source==='local'));
    fs.writeFileSync(path.join(out,'results.json'),JSON.stringify(evidence,null,2));
    console.log('NOTIFICATION_ACK_CHROMIUM=PASS scenarios='+evidence.length);
  } finally {await browser.close();server.close();}
})().catch(e=>{console.error(e);server.close();process.exitCode=1;});
