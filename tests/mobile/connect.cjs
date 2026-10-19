// Browser-only acceptance of real assets; API responses here are synthetic.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require('playwright');
const root = path.resolve(__dirname, '../../connect-worker/web');
assert.ok(fs.existsSync(path.join(root, 'index.html')), 'onboarding UI is missing');
const origin = 'https://connect.example.invalid';
const FORM = {firstname:'Тест',lastname:'Сотрудник',username:'synthetic',email:'synthetic@example.invalid',password:'Fixture-Only-Qx9!2026'};

(async () => {
  const browser = await chromium.launch({headless:true});
  let scenarios = 0;
  try {
    for (const width of [390, 1280]) {
      const context = await browser.newContext({viewport:{width,height:900}});
      const page = await context.newPage();
      let state='unlinked', registration=null, fail=null, submits=0, submitted=null;
      await page.route('https://telegram.org/**', route => route.fulfill({contentType:'text/javascript',
        body:'window.Telegram={WebApp:{initData:"synthetic-signed-launch",ready(){},expand(){}}};'}));
      await page.route(origin+'/**', async route => {
        const request=route.request(), pathname=new URL(request.url()).pathname;
        if (!pathname.startsWith('/api/')) {
          const name=pathname==='/'?'index.html':pathname.slice(1);
          if (!['index.html','onboarding.js','onboarding.css'].includes(name)) return route.abort();
          return route.fulfill({path:path.join(root,name),contentType:name.endsWith('.js')?'text/javascript':name.endsWith('.css')?'text/css':'text/html'});
        }
        assert.equal('1', request.headers()['x-ustar-connect']);
        const reply = body => route.fulfill({contentType:'application/json',body:JSON.stringify(body)});
        if (fail==='offline') return route.abort('failed');
        if (pathname==='/api/bootstrap') return reply({ok:true,csrf:'synthetic-csrf',authenticated:false});
        assert.ok(request.headers()['x-csrf-token']);
        if (pathname==='/api/launch') return reply({ok:true,csrf:'rotated-csrf',scope:'onboarding'});
        if (pathname==='/api/options') return reply({ok:true,departments:[{id:'retail',name:'<img src=x onerror=alert(1)>'}]});
        if (pathname==='/api/register') {
          submits++; submitted=request.postDataJSON();
          assert.deepEqual(Object.keys(submitted).sort(), ['departmentid','email','firstname','lastname','password','username']);
          if (fail==='validation') return route.fulfill({status:422,contentType:'application/json',body:JSON.stringify({ok:false,reason:'validation_failed',field:'email'})});
          state='pending_approval'; registration={status:'pending',submitted_at:1792380000,reviewed_at:null};
          return reply({ok:true,outcome:'registered',replayed:false,registration});
        }
        if (pathname==='/api/status') {
          if(fail==='expired') return route.fulfill({status:403,contentType:'application/json',body:'{"ok":false,"reason":"session_expired"}'});
          return reply({ok:true,scope:'onboarding',account_state:state,registration});
        }
        return reply({ok:true,local_session_closed:true});
      });
      await page.goto(origin);
      await page.locator('#registration-form').waitFor({state:'visible'});
      assert.ok(await page.locator('#departmentid').textContent().then(t=>t.includes('<img')));
      assert.equal(0,await page.locator('#departmentid img').count()); scenarios++;
      assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)); scenarios++;
      await page.locator('#theme').click();
      assert.equal('dark',await page.locator('html').getAttribute('data-theme')); scenarios++;
      for(const [name,value] of Object.entries(FORM)) await page.locator('#'+name).fill(value);
      await page.locator('#departmentid').selectOption('retail');
      fail='validation'; await page.locator('#submit').click();
      await page.locator('#email[aria-invalid="true"]').waitFor();
      assert.equal('',await page.locator('#password').inputValue()); scenarios++;
      fail=null; await page.locator('#password').fill(FORM.password); await page.locator('#submit').click();
      await page.getByRole('heading',{name:'Заявка на рассмотрении'}).waitFor();
      assert.equal(2,submits); assert.equal(FORM.password,submitted.password); scenarios++;
      assert.equal(false,await page.locator('#registration-form').isVisible());
      assert.equal('',await page.locator('#password').inputValue()); scenarios++;
      state='linked_active'; registration={status:'approved',submitted_at:1792380000,reviewed_at:1792380300};
      await page.locator('#refresh').click();
      await page.getByRole('heading',{name:'Учётная запись одобрена'}).waitFor();
      assert.ok((await page.locator('#status-detail').textContent()).includes('учебный вход')); scenarios++;
      fail='offline'; await page.locator('#refresh').click();
      await page.locator('#error').filter({hasText:'Связь недоступна'}).waitFor(); scenarios++;
      fail='expired'; await page.locator('#refresh').click();
      await page.locator('#error').filter({hasText:'Откройте приложение заново'}).waitFor(); scenarios++;
      assert.deepEqual(await page.evaluate(()=>[localStorage.length,sessionStorage.length]),[0,0]); scenarios++;
      await context.close();
    }
    console.log(`CONNECT_ONBOARDING_BROWSER=PASS scenarios=${scenarios}; synthetic_API_not_live_Telegram`);
  } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
