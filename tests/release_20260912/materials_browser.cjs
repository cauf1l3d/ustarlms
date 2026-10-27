// Real rendering/keyboard checks of the catalogue, with synthetic catalogue data.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),http=require('node:http');
const mustache=require('mustache'),{chromium}=require(process.env.USTAR_PLAYWRIGHT||'../mobile/node_modules/playwright');
const root=path.resolve(__dirname,'../../moodle/local/ustar'),theme=path.resolve(__dirname,'../../moodle/theme/ustar/scss');
const artifacts=process.env.USTAR_MATERIALS_ARTIFACTS||path.resolve(__dirname,'../../artifacts/materials-browser');fs.mkdirSync(artifacts,{recursive:true});
const materials=[['folder','ПАПКА','Инструкции и регламенты'],['scorm','SCORM','Работа с терминалом сбора данных'],['doc','DOCX','Инструкция по приёмке и размещению товара на складе'],['pdf','PDF','Правила безопасной работы'],['quiz','ТЕСТ','Проверка знаний'],['page','СТАТЬЯ','Первые шаги в академии']].map(([visualclass,visualtype,title],i)=>({
 id:i+1,title,visualclass,visualtype,visualcategory:'Учебный материал',isfolder:i===0,summary:i?'Понятный порядок действий, примеры и важные правила для ежедневной работы.':'',
 filename:i===2?'Инструкция_по_приёмке_товара.docx':'',status:i===2?'draft':'published',statuslabel:i===2?'Черновик':'Опубликован',canmove:true,candelete:true,canopenstudio:[1,2,3,5].includes(i),
 openstudiourl:'/course_studio.php?materialid='+(i+1),url:'/material.php?id='+(i+1),timemodified:123,folderoptions:[{id:0,name:'Корень'},{id:7,name:'Регламенты склада'}],
}));
const data={canmanage:true,viewkey:'browser:materials:2',total:125,visiblecount:6,filteredtotal:125,rangefrom:1,rangeto:6,currentfolder:'Все материалы',currentparentid:0,hasmaterials:true,
 studiourl:'/course_studio.php',createurl:'/material_create.php',assessmentstudiourl:'/assessment_studio.php',bulkurl:'/material_bulk.php',sesskey:'fixture',materials,
 statusoptions:[{id:'all',label:'Все статусы',selected:true},{id:'draft',label:'Черновики'}],
 typefilters:[{id:'all',label:'Все',count:125,selected:true},{id:'document',label:'Документы',count:54},{id:'scorm',label:'Курсы',count:36},{id:'quiz',label:'Тесты',count:15}],
 haspagination:true,pagination:[{number:1,current:true},{number:2,url:'?page=1'},{number:3,url:'?page=2'}]};
const html='<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'+fs.readFileSync(theme+'/_tokens.scss','utf8')+fs.readFileSync(theme+'/_materials.scss','utf8')+'body{margin:0;background:var(--u-canvas);font:14px/1.5 var(--u-font-ui,Inter,system-ui,sans-serif)}*{box-sizing:border-box}.u-topbar{padding:18px 24px;background:var(--u-surface);border-bottom:1px solid var(--u-border)}.u-main{padding:24px;max-width:1440px;margin:auto}.u-bottomnav{display:none}@media(max-width:767px){.u-bottomnav{display:block;position:fixed;bottom:0;left:0;right:0;height:62px;z-index:100;background:var(--u-surface)}}.sr-only{position:absolute;width:1px;height:1px;clip:rect(0,0,0,0);overflow:hidden}</style><style>'+fs.readFileSync(root+'/materials_fullwidth.css','utf8')+'</style></head><body class="u-materials-fullwidth-page"><header class="u-topbar"><b>USTAR | Академия</b></header><main class="u-main"><div class="u-container">'+mustache.render(fs.readFileSync(root+'/templates/materials.mustache','utf8'),data)+'</div></main><nav class="u-bottomnav">Навигация Академии</nav><script>window.define=(d,f)=>f().init();</script><script>'+fs.readFileSync(root+'/amd/build/materials.min.js','utf8')+'</script></body></html>';
const server=http.createServer((req,res)=>{res.setHeader('content-type','text/html; charset=utf-8');res.end(html)});
(async()=>{
 await new Promise(r=>server.listen(0,'127.0.0.1',r));const browser=await chromium.launch({headless:true,...(process.env.USTAR_CHROMIUM?{executablePath:process.env.USTAR_CHROMIUM,args:['--no-sandbox','--disable-dev-shm-usage']}: {})});
 try{
  const context=await browser.newContext(),page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));await page.goto('http://127.0.0.1:'+server.address().port);
  for(const width of [1440,768,390]){
   await page.setViewportSize({width,height:width===390?844:1000});
   for(const view of ['cards','blocks','list']){
    await page.locator('[data-u-material-view="'+view+'"]').click();
    assert.equal(await page.locator('[data-materials-view]').getAttribute('data-materials-view'),view);
    assert.equal(await page.locator('.u-material-row-shell').count(),6);
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'no horizontal overflow at '+width+'/'+view);
    await page.screenshot({path:artifacts+'/materials-'+width+'-'+view+'.png',fullPage:true});
    const menu=page.locator('.u-material-row-menu').last();await menu.locator('summary').click();
    await menu.locator('.u-material-row-menu__panel').waitFor({state:'visible'});
    const box=await menu.locator('.u-material-row-menu__panel').boundingBox();assert(box && box.x>=0 && box.x+box.width<=width+1,'menu stays within viewport width');
    assert(box.y>=0 && box.y+box.height<=(width===390?844:1000)+1,'menu stays within viewport height');
    await page.keyboard.press('Escape');assert.equal(await menu.getAttribute('open'),null);assert(await menu.locator('summary').evaluate(el=>el===document.activeElement));
   }
  }
  await page.setViewportSize({width:390,height:644});
  const middle=page.locator('.u-material-row-menu').nth(2);
  await middle.locator('summary').evaluate(el=>window.scrollBy(0,el.getBoundingClientRect().top-310));
  await middle.locator('summary').click();await middle.locator('.u-material-row-menu__panel').waitFor({state:'visible'});
  const panel=await middle.locator('.u-material-row-menu__panel').boundingBox(),footer=await page.locator('.u-bottomnav').boundingBox();
  assert(panel.y>=0 && panel.y+panel.height<=footer.y,'menu must remain usable above Academy mobile navigation: '+JSON.stringify({panel,footer}));
  await page.keyboard.press('Escape');await page.setViewportSize({width:390,height:844});
  await page.locator('.u-materials-folder-create summary').click();
  const folder=await page.locator('.u-materials-folder-create__form').boundingBox();assert(folder.x>=0 && folder.x+folder.width<=390,'folder creation stays inside phone viewport');
  await page.keyboard.press('Escape');
  await page.reload();assert.equal(await page.locator('[data-materials-view]').getAttribute('data-materials-view'),'list','selected view survives navigation');
  const first=page.locator('.u-material-open-studio').first();assert((await first.getAttribute('href')).includes('materialid=2'));
  assert.equal(errors.length,0,errors.join('\n'));console.log('MATERIALS_BROWSER_OK three views 390 768 1440 keyboard menu bounds persistence Studio action');
 }finally{await browser.close()}
})().catch(e=>{console.error(e);process.exitCode=1}).finally(()=>server.close());
