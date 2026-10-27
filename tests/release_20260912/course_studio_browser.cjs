// Real browser/PDF integration; synthetic data, no Moodle account or production access.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),http=require('node:http');
const {chromium}=require(process.env.USTAR_PLAYWRIGHT||'../mobile/node_modules/playwright');
const dir=path.resolve(__dirname,'../../moodle/local/ustar/course_studio');
const artifacts=process.env.USTAR_STUDIO_ARTIFACTS||path.resolve(__dirname,'../../artifacts/studio-browser');fs.mkdirSync(artifacts,{recursive:true});
let revision=0;
const server=http.createServer(async(req,res)=>{try{
 const url=new URL(req.url,'http://localhost');
 if(url.pathname==='/api'){
  let data='';for await(const part of req)data+=part;
  const route=url.searchParams.get('route');res.setHeader('content-type','application/json');
  if(route==='session')return res.end(JSON.stringify({authenticated:true,userId:'2'}));
  if(route==='courses')return res.end('{"courses":[]}');
  if(route.startsWith('courses/'))return res.end(JSON.stringify({revision:++revision,contentmodified:100+revision,fingerprint:require('node:crypto').createHash('sha256').update(JSON.stringify(JSON.parse(data).course)).digest('hex')}));
  if(route==='prepare')return res.end('{"contentid":42,"revision":1,"contentmodified":999}');
  res.statusCode=400;return res.end('{}');
 }
 if(url.pathname==='/'){
  res.setHeader('content-type','text/html');
  // Geometry fixture around the real fragment. Native Moodle shell rendering is
  // checked independently by course_studio_http_smoke.sh, not faked here.
  const shell='<style>body{margin:0;background:#f6f5f1;font-family:Inter,system-ui,sans-serif}.u-topbar{height:64px;display:flex;align-items:center;justify-content:space-between;padding:0 24px;border-bottom:1px solid #ddd;background:white}.u-rail{position:absolute;top:65px;bottom:0;width:200px;padding:20px;box-sizing:border-box;background:#fff;border-right:1px solid #ddd;color:#5b5855}.u-main{margin-left:200px}.native-control{color:rgb(20,20,20);font-size:15px;border-radius:20px;background:white}.u-bottomnav{display:none}@media(max-width:900px){.u-rail{display:none}.u-main{margin-left:0}}@media(max-width:767px){.u-bottomnav{display:block;position:fixed;bottom:0;left:0;right:0;height:62px;background:white;border-top:1px solid #ddd;z-index:90}}</style>';
  let html=fs.readFileSync(dir+'/editor.html','utf8').replaceAll('__USTAR_ASSETS__','/assets').replaceAll('__USTAR_VERSION__','fixture').replaceAll('__USTAR_MATERIALS__','/materials').replace('__USTAR_CONFIG__',JSON.stringify({api:'/api',userId:'2',sesskey:'fixture',cache:'fixture',material:'/material.php'}));
  html=html.replace('</head>',shell+'</head>').replace('<body>','<body class="u-course-studio-page"><header class="u-topbar"><strong>USTAR | Академия</strong><button class="native-control">Профиль</button></header><nav class="u-rail">Центр управления<br><br>Сотрудники<br><br><b>Материалы</b><br><br>Маршруты</nav><main class="u-main"><div class="u-container">').replace('</body>','</div></main><nav class="u-bottomnav">Главная · Обучение · Материалы</nav></body>');
  return res.end(html);
 }
 const relative=decodeURIComponent(url.pathname.replace(/^\/assets\//,'')),file=path.resolve(dir,relative);
 if(!file.startsWith(dir+path.sep)){res.statusCode=403;return res.end()}
 res.setHeader('content-type',/\.m?js$/.test(file)?'text/javascript':/\.css$/.test(file)?'text/css':'text/plain');res.end(fs.readFileSync(file));
 }catch(e){res.statusCode=404;res.end('not found')}});
(async()=>{
 await new Promise(r=>server.listen(0,'127.0.0.1',r));
 const browser=await chromium.launch({headless:true,...(process.env.USTAR_CHROMIUM?{executablePath:process.env.USTAR_CHROMIUM,args:['--no-sandbox','--disable-dev-shm-usage','--use-gl=angle','--use-angle=swiftshader','--enable-unsafe-swiftshader']}: {})});
 try{
  const context=await browser.newContext({viewport:{width:1440,height:1000}}),page=await context.newPage();
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  const fixture=await context.newPage();await fixture.setContent('<meta charset="utf-8"><h1>Учебник Академии</h1><p>Первый раздел: знания и практика.</p><div style="break-before:page"><h2>Следующая тема</h2><p>Проверка кириллицы и второго экрана.</p></div>');
  const pdf=await fixture.pdf({format:'A4'});await fixture.close();
  await page.goto('http://127.0.0.1:'+server.address().port);await page.waitForFunction(()=>document.querySelector('.workspace').inert===false);
  assert.equal(await page.locator('.native-control').evaluate(el=>getComputedStyle(el).borderRadius),'20px','editor CSS leaves shell controls unchanged');
  assert.notEqual(await page.locator('.app-header').evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(23, 47, 70)','Academy surface replaces the separate navy brand');
  await page.locator('#pdf-file').setInputFiles({name:'Учебник.pdf',mimeType:'application/pdf',buffer:pdf});
  await page.locator('#pdf-import-dialog').waitFor({state:'visible'});
  assert((await page.locator('#pdf-import-preview').innerText()).includes('Первый раздел'));
  assert.equal(await page.locator('#pdf-import-preview section').count(),2);
  await page.locator('#pdf-confirm-import').click();await page.waitForFunction(()=>document.querySelector('#header-title').textContent==='Учебник');
  assert.equal(await page.locator('#page-list button').count(),2);
  await page.locator('#prop-title').fill('Основы обучения');await page.locator('#prop-title').dispatchEvent('input');
  await page.screenshot({path:artifacts+'/studio-desktop.png',fullPage:true});
  await page.locator('#pdf-export-button').click();await page.locator('#pdf-print-button:not([disabled])').waitFor();
  const print=page.frameLocator('#pdf-print-frame');assert((await print.locator('body').innerText()).includes('Первый раздел'));
  assert.equal(await print.locator('.lesson').count(),2);assert.equal(await print.locator('script').count(),0);
  const printHTML=await page.locator('#pdf-print-frame').getAttribute('srcdoc');
  const printpage=await context.newPage();await printpage.setContent(printHTML);await printpage.pdf({path:artifacts+'/course.pdf',format:'A4',printBackground:true});await printpage.close();
  await page.locator('[data-close="pdf-export-dialog"]').click();
  await page.locator('#pdf-file').setInputFiles({name:'bad.pdf',mimeType:'application/pdf',buffer:Buffer.from('not a pdf')});
  await page.waitForFunction(()=>document.querySelector('#toast').textContent.includes('не является PDF'));
  assert.equal(await page.locator('#header-title').textContent(),'Учебник','failed import preserves active course');
  await page.locator('#more-button').click();
  const wordDownload=page.waitForEvent('download');await page.locator('#word-export-button').click();
  const word=await wordDownload;assert(word.suggestedFilename().endsWith('.docx'));await word.saveAs(artifacts+'/course.docx');
  await page.locator('#word-file').setInputFiles(artifacts+'/course.docx');await page.locator('#pdf-import-dialog').waitFor({state:'visible'});
  assert((await page.locator('#document-import-title').innerText()).includes('Word'));
  assert.equal(await page.locator('#pdf-import-preview section').count(),2);
  assert((await page.locator('#pdf-import-preview').innerText()).includes('Первый раздел'));
  await page.locator('#pdf-confirm-import').click();await page.waitForFunction(()=>document.querySelector('#course-select').value.startsWith('WORD-'));
  await page.locator('#word-file').setInputFiles({name:'bad.docx',mimeType:'application/vnd.openxmlformats-officedocument.wordprocessingml.document',buffer:Buffer.from('broken word')});
  await page.waitForFunction(()=>/DOCX|Word|архив/.test(document.querySelector('#toast').textContent));
  assert.equal(await page.locator('#header-title').textContent(),'Учебник');
  await page.setViewportSize({width:768,height:1000});
  await page.locator('#properties-button').click();assert(await page.locator('.right-panel').isVisible());
  await page.keyboard.press('Escape');assert(await page.locator('.right-panel').isHidden());
  assert(await page.locator('#properties-button').evaluate(el=>el===document.activeElement));
  await page.screenshot({path:artifacts+'/studio-tablet.png',fullPage:true});
  await page.setViewportSize({width:390,height:844});await page.screenshot({path:artifacts+'/studio-mobile.png',fullPage:true});
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'no horizontal document overflow');
  assert(await page.locator('.left-panel').isHidden(),'phone keeps full width for editing');
  assert((await page.locator('#paper').boundingBox()).width>300,'usable phone paper width');
  await page.locator('#outline-button').click();assert(await page.locator('.left-panel').isVisible());
  await page.screenshot({path:artifacts+'/studio-mobile-outline.png',fullPage:true});
  await page.keyboard.press('Escape');assert(await page.locator('.left-panel').isHidden());
  assert(await page.locator('#outline-button').evaluate(el=>el===document.activeElement));
  await page.locator('#more-button').focus();await page.keyboard.press('ArrowDown');await page.keyboard.press('Escape');
  assert(await page.locator('#file-menu').isHidden());assert(await page.locator('#more-button').evaluate(el=>el===document.activeElement));
  await page.locator('#focus-button').click();assert(await page.locator('.left-panel').isHidden());
  await page.screenshot({path:artifacts+'/studio-mobile-focus.png',fullPage:true});
  assert.equal(errors.length,0,errors.join('\n'));
  console.log('COURSE_STUDIO_BROWSER_OK actual PDF and Word Cyrillic 2 pages import edit export malformed preservation desktop mobile');
 }finally{await browser.close()}
})().catch(e=>{console.error(e);process.exitCode=1}).finally(()=>server.close());
