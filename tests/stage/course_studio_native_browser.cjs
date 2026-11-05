// Real native Moodle, YUI/AMD, headers and Studio API; synthetic identities/content only.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require('../mobile/node_modules/playwright');
const root=path.resolve(__dirname,'../..'),art=path.join(root,'artifacts');
const base='http://127.0.0.1:8087',url=base+'/local/ustar/course_studio.php';
function cookies(){return fs.readFileSync(art+'/shell-cookies.txt','utf8').split('\n').filter(l=>l && (!l.startsWith('#')||l.startsWith('#HttpOnly_'))).map(line=>{
 const httpOnly=line.startsWith('#HttpOnly_');const [domain,,cookiepath,secure,,name,value]=line.replace(/^#HttpOnly_/,'').split('\t');
 return {domain,path:cookiepath,name,value,httpOnly,secure:secure==='TRUE'};
});}
async function evalDenied(frame){
 // CDP evaluation bypasses unsafe-eval by default. Exercise CSP from a real input event,
 // after returning from evaluation, rather than a script synchronously inserted by CDP.
 await frame.evaluate(()=>{const b=document.createElement('button');b.id='ustar-csp-probe';b.textContent='CSP probe';b.style.cssText='position:fixed;top:4px;right:4px;z-index:2147483647';b.addEventListener('click',()=>{try{Function('return 1')();window.__ustarEvalResult='allowed'}catch(e){window.__ustarEvalResult=e.name}});document.body.append(b);});
 await frame.locator('#ustar-csp-probe').click();
 const result=await frame.evaluate(()=>window.__ustarEvalResult);
 await frame.locator('#ustar-csp-probe').evaluate(el=>el.remove());return result;
}
(async()=>{
 const browser=await chromium.launch({headless:true});const errors=[];const evidence={native:true,api:'real',policy_unchanged:true};
 try{
  const context=await browser.newContext({viewport:{width:1440,height:1000}});await context.addCookies(cookies());
  const page=await context.newPage();page.on('pageerror',e=>errors.push({message:e.message,stack:e.stack}));
  const response=await page.goto(url);assert.equal(response.status(),200);
  const csp=response.headers()['content-security-policy'];evidence.csp=csp;
  assert(csp.includes("script-src 'self' 'unsafe-inline'"));assert(!csp.includes("'unsafe-eval'"));
  await page.waitForFunction(()=>document.querySelector('.workspace')?.inert===false);
  evidence.yui=await page.evaluate(()=>({one:typeof window.Y?.one,explicitGlobal:window.YUI_config?.global===window}));
  evidence.errors=errors;fs.writeFileSync(art+'/studio-native-browser.json',JSON.stringify(evidence,null,2));
  assert.deepEqual(errors,[],'Native Moodle JS must initialise without CSP/YUI errors');
  assert.equal(evidence.yui.one,'function');assert(evidence.yui.explicitGlobal,'YUI receives window before bootstrap');
  assert.equal(await page.locator('meta[name="ustar-head-sentinel"]').getAttribute('content'),'preserved');
  assert.equal(await evalDenied(page),'EvalError','Strict parent CSP still refuses eval');
  const saved=page.waitForResponse(r=>{try{return r.url().includes('/course_studio_api.php?')&&r.request().method()==='POST'&&JSON.parse(r.request().postData()||'{}').course?.data?.pages.some(p=>p.html.includes('Native CSP lesson'));}catch{return false;}},{timeout:45000});
  await page.locator('#editor-content').evaluate(el=>{el.innerHTML='<p>Native CSP lesson</p>';el.dispatchEvent(new Event('input',{bubbles:true}));});
  const savedResponse=await saved;assert.equal(savedResponse.status(),200);const source=savedResponse.request().postDataJSON().course;
  const result=await savedResponse.json();assert(result.contentid>0&&result.revision>0);evidence.saved={code:source.data.code,revision:result.revision};
  await page.locator('#preview-button').click();await page.locator('#preview-dialog').waitFor({state:'visible'});
  const preview=await page.locator('#preview-frame').contentFrame();await preview.locator('body').waitFor();
  assert.equal(await page.locator('#preview-frame').getAttribute('sandbox'),'allow-scripts');
  const frame=page.frames().find(f=>f!==page.mainFrame()&&f.url()==='about:srcdoc');assert(frame);
  assert.equal(await frame.evaluate(()=>{try{void parent.document;return 'allowed'}catch(e){return e.name;}}),'SecurityError');
  assert.equal(await evalDenied(frame),'EvalError','Preview policy still refuses eval');
  evidence.preview={parent_access:'denied',eval:'denied'};await page.locator('[data-close="preview-dialog"]').click();
  const fresh=await browser.newContext({viewport:{width:390,height:844}});await fresh.addCookies(cookies());const reloaded=await fresh.newPage();
  reloaded.on('pageerror',e=>errors.push({message:e.message,stack:e.stack}));await reloaded.goto(url);await reloaded.waitForFunction(()=>document.querySelector('.workspace')?.inert===false);
  await reloaded.locator('#course-select').selectOption(source.data.code);assert((await reloaded.locator('#editor-content').innerText()).includes('Native CSP lesson'),'Fresh browser must reload real server save');
  const home=await fresh.newPage();await home.goto(base+'/local/ustar/home.php');
  assert.equal(await home.evaluate(()=>Object.hasOwn(window.YUI_config||{},'global')),false,'Request-local bootstrap must not persist into another page');
  assert.deepEqual(errors,[]);evidence.errors=errors;evidence.status='PASS';
  fs.writeFileSync(art+'/studio-native-browser.json',JSON.stringify(evidence,null,2));
  console.log('STUDIO_NATIVE_BROWSER=PASS actual Moodle/YUI/API, strict CSP, isolated preview, save/fresh reload');
 }finally{evidence.errors=errors;fs.writeFileSync(art+'/studio-native-browser.json',JSON.stringify(evidence,null,2));await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
