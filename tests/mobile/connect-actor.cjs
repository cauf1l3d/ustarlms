// Actual browser/assets, synthetic private API; no live Telegram/Moodle assertion.
const assert=require('node:assert/strict');
const path=require('node:path');
const {chromium}=require('playwright');
const origin='https://connect.example.invalid';
const root=path.resolve(__dirname,'../../connect-worker/web');

(async()=>{
  const browser=await chromium.launch({headless:true});let scenarios=0;
  try{
    for(const width of [390,1280]){
      for(const theme of ['light','dark']){
        for(const mode of ['fresh','reload','mfa','unknown','revoked','idle']){
          const context=await browser.newContext({viewport:{width,height:900},colorScheme:theme});
          const page=await context.newPage();const calls=[];let active=mode==='reload',failure=false;
          await page.route('https://telegram.org/**',r=>r.fulfill({contentType:'text/javascript',
            body:'window.Telegram={WebApp:{initData:"synthetic-actor-launch",ready(){},expand(){}}};'}));
          await page.route(origin+'/**',async r=>{
            const req=r.request(),pathname=new URL(req.url()).pathname;
            if(!pathname.startsWith('/api/')){
              const name=pathname==='/'?'index.html':pathname.slice(1);
              assert.ok(['index.html','onboarding.js','onboarding.css'].includes(name));
              return r.fulfill({path:path.join(root,name),contentType:name.endsWith('.js')?'text/javascript':name.endsWith('.css')?'text/css':'text/html'});
            }
            calls.push(pathname);
            const reply=(body,status=200)=>r.fulfill({status,contentType:'application/json',body:JSON.stringify(body)});
            if(pathname==='/api/bootstrap')return reply({ok:true,authenticated:active,csrf:'initial',...(active?{scope:'actor_read'}:{})});
            if(pathname==='/api/launch')return reply({ok:true,scope:'onboarding',csrf:'launched'});
            if(pathname==='/api/activate'){
              assert.deepEqual(req.postDataJSON(),{});
              if(mode==='mfa')return reply({ok:false,reason:'native_auth_required'},403);
              if(mode==='unknown')return reply({ok:false,reason:'activation_outcome_unknown'},503);
              active=true;return reply({ok:true,scope:'actor_read',csrf:'rotated'});
            }
            if(pathname==='/api/status'){
              if(failure)return reply({ok:false,reason:mode==='revoked'?'invalid_actor':'source_unavailable'},mode==='revoked'?403:503);
              if(!active)return reply({ok:true,scope:'onboarding',account_state:'linked_active',registration:null});
              return reply({ok:true,scope:'actor_read',expires_at:1800028800,idle_expires_at:mode==='idle'?Math.floor(Date.now()/1000)+2:1800000900,
                features:['profile'],profile:{username:'employee',fullname:'<img src=x onerror=alert(1)>',
                  position:'Сотрудник','department':'Розница','account_state':'active'}});
            }
            if(pathname==='/api/logout')return reply({ok:true,local_session_closed:true});
            throw Error('Unexpected actor API '+pathname);
          });
          await page.goto(origin);
          if(mode!=='reload'){
            await page.getByRole('button',{name:'Открыть мой профиль'}).waitFor();
            assert.equal(0,calls.filter(x=>x==='/api/activate').length);
            await page.getByRole('button',{name:'Открыть мой профиль'}).click();scenarios++;
          }
          if(mode==='mfa'||mode==='unknown'){
            await page.locator('#error').filter({hasText:mode==='mfa'?'штатный вход':'Результат входа неизвестен'}).waitFor();
            assert.equal(false,await page.locator('#actor-profile').isVisible());
            assert.equal(false,await page.locator('#activate').isVisible());
            assert.equal(1,calls.filter(x=>x==='/api/activate').length);scenarios++;
          }else{
            await page.getByRole('heading',{name:'Мой профиль Академии',exact:true}).waitFor();
            assert.ok((await page.locator('#profile-fullname').textContent()).includes('<img'));
            assert.equal(0,await page.locator('#actor-profile img').count());
            assert.equal('employee',await page.locator('#profile-username').textContent());
            assert.equal(false,await page.locator('#registration-form').isVisible());scenarios++;
            if(mode==='idle'){
              const count=calls.filter(x=>x==='/api/status').length;
              await page.locator('#error').filter({hasText:'Откройте приложение заново'}).waitFor();
              assert.equal(count,calls.filter(x=>x==='/api/status').length);
            }else{
              failure=true;await page.locator('#refresh').click();
              await page.locator('#error').filter({hasText:mode==='revoked'?'Откройте приложение заново':'Связь недоступна'}).waitFor();
            }
            assert.equal(false,await page.locator('#actor-profile').isVisible());scenarios++;
            await page.locator('#logout').click();await page.getByRole('heading',{name:'Сессия закрыта'}).waitFor();
            assert.equal('',await page.locator('#profile-username').textContent());scenarios++;
          }
          assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
          assert.deepEqual(await page.evaluate(()=>[localStorage.length,sessionStorage.length]),[0,0]);
          scenarios++;await context.close();
        }
      }
    }
    console.log('CONNECT_ACTOR_BROWSER=PASS scenarios='+scenarios+'; synthetic_API_not_live_provider');
  }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
