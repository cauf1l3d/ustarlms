// Real assets/Chromium; synthetic API is not live Telegram/Moodle acceptance.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require('playwright');
const Mustache = require('mustache');
const sass = require('sass');
const origin = 'https://connect.example.invalid';
const root = path.resolve(__dirname, '../../connect-worker/web');
const repo = path.resolve(__dirname, '../..');

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
            return reply({ok:true,csrf:'rotated-csrf',scope:'link_confirmation',academy_confirmation_required:true,telegram_id:'8727627355'});
          }
          if (pathname === '/api/status') return reply({ok:true,scope:'link_confirmation',account_state:'academy_confirmation_required'});
          if (pathname === '/api/logout') return reply({ok:true,local_session_closed:true});
          throw Error('Link-only browser must not call '+pathname);
        });
        await page.goto(origin);
        if (mode === 'claimed' || mode === 'reload') {
          await page.getByRole('heading',{name:'Подтвердите связь в Академии'}).waitFor();
          assert.ok((await page.locator('#status-detail').textContent()).includes('ещё не завершена'));
          if (mode === 'claimed') assert.ok((await page.locator('#status-detail').textContent()).includes('8727627355'));
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
      // Render the actual native owner page with actual theme CSS, not a second mockup.
      const lib = fs.readFileSync(path.join(repo,'moodle/theme/ustar/lib.php'),'utf8');
      const css = sass.compileString([...lib.matchAll(/__DIR__ \. '\/scss\/([^']+)'/g)]
        .map(m=>fs.readFileSync(path.join(repo,'moodle/theme/ustar/scss',m[1]),'utf8')).join('\n'),
        {logger:sass.Logger.silent}).css + fs.readFileSync(path.join(repo,'moodle/local/ustar/styles/ux_tools.css'),'utf8');
      for (const claimed of [false,true]) {
        const page = await browser.newPage({viewport:{width,height:900}});
        const view = {enabled:true,fullname:'Сотрудник <img src=x onerror=alert(1)>',
          url:'/local/ustar/connect.php',profileurl:'/local/ustar/profile.php',sesskey:'synthetic-csrf',haslinks:true,
          challenge:{code:'uc1_'+'A'.repeat(43),claimed,subject:'8727627355',expires_label:'19 октября 2026, 13:30',
            launch_url:'https://t.me/ustarcorp_bot?startapp=uc1_'+'A'.repeat(43)},
          links:[{id:1,providerlabel:'Telegram',tenant:'123456',subject:'8727627355',linked:true}],
          preferences:{enabled:true,quiet_start:'22:00',quiet_end:'08:00',timezone:'Europe/Moscow',interval_minutes:5,
            categories:[{value:'tasks',label:'Задачи',selected:true}],severities:[{value:'normal',label:'Все',selected:true}]},
          deliveries:[{categorylabel:'Задачи',statuslabel:'Принято Telegram',time_label:'19 октября 2026',attempts:1}]};
        const html = Mustache.render(fs.readFileSync(path.join(repo,'moodle/local/ustar/templates/connect.mustache'),'utf8'),view);
        await page.setContent('<!doctype html><html lang="ru"><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>*,*::before,*::after{box-sizing:border-box}body{margin:0;font:16px/1.5 system-ui}'+css+'</style></head><body>'+html+'</body></html>');
        assert.equal(0,await page.locator('img').count());
        assert.ok((await page.locator('h1').textContent()).includes('Мессенджеры'));
        assert.equal(claimed?1:0,await page.locator('form input[name=action][value=confirm]').count());
        await page.getByRole('heading',{name:'Уведомления в Telegram',exact:true}).waitFor();
        assert.equal(1,await page.locator('form input[name=action][value=preferences]').count());
        assert.equal('Europe/Moscow',await page.locator('[name=timezone]').inputValue());
        assert.equal(1,await page.getByText('Принято Telegram',{exact:false}).count());
        assert.ok((await page.locator('body').textContent()).includes('не означает'));
        assert.ok(await page.locator('form').evaluateAll(forms=>forms.every(f=>f.method==='post' && f.getAttribute('action')==='/local/ustar/connect.php' && f.querySelector('[name=sesskey]'))));
        assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
        scenarios++; await page.close();
      }
    }
    console.log(`CONNECT_LINK_BROWSER=PASS scenarios=${scenarios}; synthetic_API_not_live_Telegram`);
  } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
