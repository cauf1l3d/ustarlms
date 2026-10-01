// Real USTAR templates and all theme partials, synthetic data, desktop/mobile Chromium.
const fs = require('fs'), path = require('path'), http = require('http'), assert = require('assert');
const sass = require('sass'), Mustache = require('mustache'), {chromium} = require('playwright');
const repo = path.resolve(__dirname, '../..'), out = path.join(repo, 'artifacts/mobile');
fs.mkdirSync(out, {recursive:true});
const lib = fs.readFileSync(path.join(repo,'moodle/theme/ustar/lib.php'),'utf8');
const styles = [...lib.matchAll(/__DIR__ \. '\/scss\/([^']+)'/g)].map(m => fs.readFileSync(path.join(repo,'moodle/theme/ustar/scss',m[1]),'utf8')).join('\n');
const css = sass.compileString(styles, {logger:sass.Logger.silent}).css;
fs.writeFileSync(path.join(out,'theme.css'),css);
const template = name => fs.readFileSync(path.join(repo, 'moodle', name),'utf8');
let origin;
const files = '/theme/ustar/pix/brand/';
const nativeLogin = `<div class="login-container"><div class="loginform"><form class="login-form"><input type="hidden" name="logintoken" value="fixture"><div class="login-form-username"><label class="visually-hidden" for="username">Логин</label><input class="form-control" id="username" name="username" placeholder="Логин" autocomplete="username"></div><div class="login-form-password"><label class="visually-hidden" for="password">Пароль</label><div class="toggle-sensitive-wrapper"><input id="password" name="password" placeholder="Пароль" type="password" autocomplete="current-password"><button type="button" class="toggle-sensitive-btn" aria-label="Показать пароль">◉</button></div></div><div class="login-form-submit"><button type="submit" class="btn btn-primary">Вход →</button></div></form><div class="login-form-forgotpassword"><a href="#reset">Забыли пароль?</a></div><div class="login-divider"></div><h2 class="login-heading">Некоторые курсы доступны для гостей</h2><form id="guestlogin"><button class="btn btn-secondary" id="loginguestbtn">Зайти гостем</button></form><div class="login-divider"></div><div class="d-flex"><button class="btn btn-secondary">Русский (ru)</button><button class="btn btn-secondary">Используем файлы cookie</button></div></div></div>`;
function headContent() { return `<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>*,*::before,*::after{box-sizing:border-box}body{margin:0;font:16px/1.5 system-ui,sans-serif}button,input,textarea,select{font:inherit}a{color:inherit}label{display:block}.visually-hidden{position:absolute;width:1px;height:1px;padding:0;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}.btn{display:inline-block;text-align:center;cursor:pointer}</style><link rel="stylesheet" href="/theme.css"><script defer src="/local/ustar/app.js"></script>`; }
function head() { return `<!doctype html><html lang="ru"><head>${headContent()}</head>`; }
function render(page, theme) {
  const partials={'theme_boost/head':head(),'core/local/toast/wrapper':'','theme_boost/footer':'','local_ustar/messages_thread':template('local/ustar/templates/messages_thread.mustache')};
  if(page==='login'||page==='login-error'||page==='registration') {
    const register = `<div class="u-register"><h2>Регистрация в Академии</h2><p>Заявка будет направлена HRD.</p><form><div class="u-register-names"><label>Имя<input></label><label>Фамилия<input></label></div><label>Логин<input></label><label>Email<input type="email"></label><label>Пароль<input type="password"></label><label>Подразделение<select><option>Выберите подразделение</option></select></label><button class="btn btn-primary">Создать профиль</button></form></div>`;
    return Mustache.render(template('theme/ustar/templates/login.mustache'), {bodyattributes:'class="pagelayout-login ustar-auth-body"',starttitle:page==='registration'?'Создай профиль':'Начни с входа',loginreferenceurl:files+'login-reference-20260914.png',loginurl:'/login',signupurl:'/registration',signupenabled:page!=='registration',output:{main_content:page==='registration'?register:(page==='login-error'?'<div class="alert">Неверный логин или пароль</div>':'')+nativeLogin}},partials);
  }
  const nav = ['Главная','Обучение','Задачи','Команда','Сообщения','Каталог','Контроль','Настройки'].map((label,i)=>({label,short:label,url:'/home',icon:'<span aria-hidden="true">○</span>',active:i===0}));
  let content;
  if(page==='messages'||page==='messages-list'||page==='messages-disabled') {
    const current={id:10,title:'Рабочая команда магазина',isgroup:true,membercount:3,canmanage:true,members:[{id:11,fullname:'Сотрудник Один',removable:true}],cansend:true,hasmessages:true,messages:[{id:1,sender:'Сотрудник Один',text:'Обсудим обучение и рабочие вопросы.',time:'01.10 12:00'},{id:2,mine:true,sender:'Вы',text:'Медиа и документы в переписке',time:'01.10 12:01',attachments:[{name:'Фото.png',size:'24 КБ',image:true,url:files+'ustar-app-icon.png',downloadurl:files+'ustar-app-icon.png'}]}]};
    content=Mustache.render(template('local/ustar/templates/messages.mustache'),{available:page!=='messages-disabled',cancreate:true,hascurrent:page==='messages',current:page==='messages'?current:null,conversationid:page==='messages'?10:0,sesskey:'fixture',requestid:'fixture_request_000000000',apiurl:'/api',listurl:'/messages-list',maxbytes:25000000,maxsize:'25 МБ',maxpostbytes:50000000,hasconversations:true,conversations:[{id:10,title:'Рабочая команда магазина',url:'/messages',preview:'Медиа и документы',hasunread:true,unread:2},{id:11,title:'Коллега',preview:'Последнее сообщение',url:'/messages'}]},partials);
  } else {
    content='<div class="u-product-page"><header class="u-product-head"><div><h1>Моя команда</h1><p>Сводка и рабочие действия</p></div><button class="u-btn">Создать задачу</button></header><table class="generaltable"><tr><th>Сотрудник</th><th>Подразделение</th><th>Текущий маршрут</th></tr><tr><td>Синтетический сотрудник</td><td>Торговый зал</td><td>Обучение и аттестация</td></tr></table></div>';
  }
  const html=Mustache.render(template('theme/ustar/templates/shell.mustache'),{bodyattributes:`class="u-shell-page ${page.startsWith('messages')?'u-personal-wide':'u-standard-width'}"`,navitems:nav,mobilenavitems:nav.slice(0,4),preset:'yellow',productname:'Академия',initials:'СА',messagesurl:'/local/ustar/messages.php',notificationsurl:'/local/ustar/notifications.php',profilesettingsurl:'/local/ustar/profile_settings.php',guideurl:'/home',logouturl:'/login/logout.php',output:{doctype:'<!doctype html>',htmlattributes:'lang="ru"',standard_head_html:headContent(),main_content:content,standard_end_of_body_html:page.startsWith('messages')?'<script src="/local/ustar/messages.js"></script>':''}},partials);
  return html.replace('<body ','<body data-theme="'+theme+'" ');
}
const server=http.createServer((req,res)=>{
  const url=new URL(req.url,'http://localhost'), p=url.pathname;
  if(p==='/theme.css'){res.setHeader('Content-Type','text/css');return res.end(css);}
  if(['/login','/login-error','/registration','/messages','/messages-list','/messages-disabled','/team'].includes(p)){res.setHeader('Content-Type','text/html');return res.end(render(p.slice(1),url.searchParams.get('theme')||'light'));}
  if(p==='/api'){res.setHeader('Content-Type','application/json');return res.end(JSON.stringify({ok:true,html:'',signature:'fixture',cansend:true}));}
  const file=path.join(repo,'moodle',p);
  if(file.startsWith(path.join(repo,'moodle')+'/')&&fs.existsSync(file)&&fs.statSync(file).isFile()){res.setHeader('Content-Type',file.endsWith('.js')?'application/javascript':'image/png');return fs.createReadStream(file).pipe(res);}
  res.statusCode=404;res.end();
});
(async()=>{
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));origin='http://127.0.0.1:'+server.address().port;
  const extra=process.env.USTAR_CHROMIUM?{executablePath:process.env.USTAR_CHROMIUM}:{};
  const browser=await chromium.launch({...extra,headless:true,args:['--no-sandbox']});
  const evidence=[];
  try {
    for(const width of [320,390,768,1366,1920]) {
      for(const scenario of ['login','login-error','registration','messages','messages-list','messages-disabled','team', ...([390,1366].includes(width)?['login?theme=dark','registration?theme=dark','messages?theme=dark','messages-list?theme=dark','team?theme=dark']:[])]) {
        const name=scenario.split('?')[0],theme=scenario.includes('dark')?'dark':'light';
        const page=await browser.newPage({viewport:{width,height:844}}),errors=[];
        page.on('pageerror',e=>errors.push(e.message));
        if(theme==='dark') await page.addInitScript(()=>localStorage.setItem('ustar-theme','dark'));
        await page.goto(origin+'/'+scenario);await page.waitForTimeout(100);
        const sizes=await page.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth}));
        assert(sizes.scroll<=width+1,`${name} ${width} overflow ${sizes.scroll}`);
        assert.deepStrictEqual(errors,[],`${name} ${width} JS errors`);
        if(name==='messages'){
          const box=await page.locator('[data-chat-compose]').boundingBox();
          assert(box && box.x>=0 && box.x+box.width<=width+1, 'Composer width');
          assert(box.y+box.height<=844+1, `Composer out of viewport ${width}: ${box.y+box.height}`);
        }
        if(name==='login'&&width>900){
          const a=await page.locator('.ustar-auth-brand').boundingBox(),b=await page.locator('.ustar-auth-auth').boundingBox();
          assert(Math.abs(a.width-b.width)<1&&Math.abs(a.y-b.y)<1&&Math.abs(a.height-b.height)<1,'Unequal original login panels');
        }
        if(name==='team'&&width<768){await page.locator('[data-mobile-menu-open]').click();assert(await page.locator('#u-mobile-menu').isVisible());assert.strictEqual(await page.locator('#u-mobile-menu nav:first-of-type a').count(),8);await page.locator('[data-mobile-menu-close]').click();}
        await page.screenshot({path:path.join(out,`${name}-${width}${theme==='dark'?'-dark':''}.png`),fullPage:true});
        evidence.push({name,width,theme,overflow:false,js_errors:errors});
        if(name==='messages'&&width===390&&theme==='light') {
          // Model the visual viewport shrinking while the layout viewport stays tall.
          await page.evaluate(()=>{
            Object.defineProperty(window,'visualViewport',{configurable:true,value:{height:390,addEventListener(){}}});
            window.dispatchEvent(new Event('resize'));
          });
          await page.waitForTimeout(100);
          const compose=await page.locator('[data-chat-compose]').boundingBox();
          assert(compose.y+compose.height<=390,'Keyboard covers the send controls');
          assert(await page.locator('body.u-chat-keyboard').count()===1);
          await page.screenshot({path:path.join(out,'messages-390-keyboard.png'),fullPage:true});
          evidence.push({name:'messages-keyboard',width,theme,visual_height:390,composer_visible:true});
        }
        await page.close();
      }
    }
    fs.writeFileSync(path.join(out,'results.json'),JSON.stringify(evidence,null,2));
    console.log('MOBILE_TEMPLATE_CHROMIUM=PASS scenarios='+evidence.length);
  } finally {await browser.close();server.close();}
})().catch(e=>{console.error(e);server.close();process.exitCode=1;});
