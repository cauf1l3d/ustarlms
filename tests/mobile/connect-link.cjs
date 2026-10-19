// Real assets/Chromium; synthetic API is not live Telegram/Moodle acceptance.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require('playwright');
const origin = 'https://connect.example.invalid';
const root = path.resolve(__dirname, '../../connect-worker/web');

(async () => {
  const browser = await chromium.launch({headless: true});
  let scenarios = 0;
  try {
    for (const width of [390, 1280]) {
      for (const mode of ['claimed', 'reload', 'uncertain', 'expired']) {
        const context = await browser.newContext({viewport: {width, height: 900}});
        const page = await context.newPage();
        const calls = [];
        const pageErrors = [];
        page.on('pageerror', error => pageErrors.push(error.message));
        await page.route('https://telegram.org/**', route => route.fulfill({contentType: 'text/javascript',
          body: 'window.Telegram={WebApp:{initData:"synthetic-signed-uc1",ready(){},expand(){}}};'}));
        await page.route(origin+'/**', async route => {
          const request = route.request(), pathname = new URL(request.url()).pathname;
          if (!pathname.startsWith('/api/')) {
            const name = pathname === '/' ? 'index.html' : pathname.slice(1);
            assert.ok(['index.html', 'onboarding.js', 'onboarding.css'].includes(name));
            return route.fulfill({path: path.join(root, name), contentType:
              name.endsWith('.js') ? 'text/javascript' : name.endsWith('.css') ? 'text/css' : 'text/html'});
          }
          calls.push(pathname);
          const reply = (body, status=200) => route.fulfill({status, contentType:'application/json', body:JSON.stringify(body)});
          if (pathname === '/api/bootstrap') return reply({ok:true, csrf:'synthetic-csrf',
            authenticated:mode === 'reload', ...(mode === 'reload' ? {scope:'link_confirmation'} : {})});
          if (pathname === '/api/launch') {
            assert.deepEqual(request.postDataJSON(), {init_data:'synthetic-signed-uc1'});
            if (mode === 'uncertain') return reply({ok:false,reason:'link_outcome_unknown'},503);
            if (mode === 'expired') return reply({ok:false,reason:'expired_challenge'},403);
            return reply({ok:true,csrf:'rotated-csrf',scope:'link_confirmation',academy_confirmation_required:true});
          }
          if (pathname === '/api/status') return reply({ok:true,scope:'link_confirmation',account_state:'academy_confirmation_required'});
          if (pathname === '/api/logout') return reply({ok:true,local_session_closed:true});
          throw Error('Link-only browser must not call '+pathname);
        });
        await page.goto(origin);
        if (mode === 'claimed' || mode === 'reload') {
          await page.getByRole('heading',{name:'Подтвердите связь в Академии'}).waitFor();
          assert.ok((await page.locator('#status-detail').textContent()).includes('ещё не завершена'));
          await page.locator('#refresh').click();
          await page.getByRole('heading',{name:'Подтвердите связь в Академии'}).waitFor();
          assert.equal(mode === 'reload' ? 0 : 1, calls.filter(p=>p==='/api/launch').length);
          scenarios++;
          await page.locator('#logout').click();
          await page.getByRole('heading',{name:'Сессия закрыта'}).waitFor();
          scenarios++;
        } else {
          const detail = mode === 'uncertain' ? 'Результат привязки неизвестен' : 'Код привязки истёк';
          await page.locator('#error').filter({hasText:detail}).waitFor();
          assert.equal(1,calls.filter(p=>p==='/api/launch').length);
          scenarios++;
        }
        assert.equal(false, await page.locator('#registration-form').isVisible());
        assert.deepEqual(pageErrors, []);
        assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
        assert.deepEqual(await page.evaluate(()=>[localStorage.length,sessionStorage.length]),[0,0]);
        scenarios++;
        await context.close();
      }
    }
    console.log(`CONNECT_LINK_BROWSER=PASS scenarios=${scenarios}; synthetic_API_not_live_Telegram`);
  } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
