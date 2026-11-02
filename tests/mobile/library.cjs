// Production Mustache templates and complete Academy SCSS, synthetic read models only.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),http=require('node:http');
const {chromium}=require('playwright'),sass=require('sass'),Mustache=require('mustache');
const repo=path.resolve(__dirname,'../..'),out=path.join(repo,'artifacts/library-browser');fs.mkdirSync(out,{recursive:true});
const root=path.join(repo,'moodle/local/ustar'),theme=path.join(repo,'moodle/theme/ustar');
const lib=fs.readFileSync(theme+'/lib.php','utf8');
const source=[...lib.matchAll(/__DIR__ \. '\/scss\/([^']+)'/g)].map(m=>fs.readFileSync(theme+'/scss/'+m[1],'utf8')).join('\n');
const css=sass.compileString(source,{logger:sass.Logger.silent}).css+fs.readFileSync(root+'/styles/library.css','utf8');
const partials={};for(const f of fs.readdirSync(root+'/templates').filter(f=>/^library.*mustache$/.test(f))){partials['local_ustar/'+f.replace('.mustache','')]=fs.readFileSync(root+'/templates/'+f,'utf8');}
const labels=['Мои материалы','Каталог товаров','Маркетплейс','Проверки знаний','Обязательный маршрут'];
const tabs=['mine','catalog','shop','checks','route'];
const materials=Array.from({length:3},(_,i)=>({title:['Вход в терминал сбора данных','Стандарты обслуживания покупателей','Работа с товарными группами'][i],summary:'Порядок действий, примеры и важные правила для ежедневной работы.',typelabel:i?'Изученные материалы':'Назначено',date:'02.11.2026',contentid:i+1,url:'#'}));
const offers=materials.map((m,i)=>({...m,id:i+1,open:i===0,isgate:i===2,ispurchase:i===1,hasprice:i===1,price:10,actionurl:'/?tab=offer'}));
const cards=Array.from({length:8},(_,i)=>({id:i+1,title:['Крепёж и метизы','Ручной инструмент','Сантехника','Профессиональная химия'][i%4],typelabel:'Категория',summary:'Рабочий справочник: свойства, применение и отличия товаров.',url:'#',hasimage:false}));
function render(tab,color){
 const d={balance:25,tabs:tabs.map((t,i)=>({label:labels[i],url:'/?tab='+t+'&theme='+color,active:t===tab})),tab,q:'',libraryurl:'/',sesskey:'fixture-only',canmanage:true,manageurl:'/?tab=manage',writable:true,materialcount:3,materials,hasmaterials:true,savedfiles:[{id:1,title:'Регламент приёмки.pdf',date:'02.11.2026',size:'240 КБ',url:'#'}],hassavedfiles:true,cards,hascards:true,catalogroot:'/?tab=catalog',offers,hasoffers:true,offertotal:3,storeenabled:false,assessmentcount:3,hasassessments:true,assessments:materials.map(m=>({title:m.title,meta:'Тема · 2 вопроса · от100%',result:'Пройдено · 100%',url:'#',action:'Пройти'})),routemirrorurl:'#'};
 d['show'+tab]=true;let name='local_ustar/library';
 if(tab==='offer') {d.showoffer=true;d.selectedoffer={id:2,title:'Продвинутые знания по инструментам',summary:'Примеры применения, сравнение и практические рекомендации.',price:10,revision:123,nonce:'fixture',canpurchase:true,lockedtext:'Откройте доступ за USCOIN.'};}
 if(tab==='manage') {name='local_ustar/library_manage';Object.assign(d,{author:true,grantor:true,admin:true,assessor:true,actionurl:'/',pricemin:1,pricemax:25,id:1,title:'Продвинутые знания',summary:'Описание',subjects:[{value:'content:1',label:'Материал · Продвинутые знания',selected:true}],policies:[{value:'paid',label:'За USCOIN',selected:true}],visibilities:[{value:'published',label:'Опубликовано',selected:true}],gates:[{value:0,label:'Не требуется'}],targets:[{value:1,label:'Сотрудник Академии'}],hastargets:true,grantoffers:[{value:1,label:'Продвинутые знания'}],grants:[],questionfields:[],offers:[]});}
 const html=Mustache.render(partials[name],d,partials);
 return '<!doctype html><html lang="ru" data-ustar-theme="'+color+'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>*{box-sizing:border-box}body{margin:0;background:var(--u-canvas);font:15px/1.5 var(--u-font-ui,system-ui,sans-serif)}main{max-width:1280px;padding:16px;margin:auto}button,input,select,textarea{font:inherit}'+css+'</style></head><body><main>'+html+'</main></body></html>';
}
const server=http.createServer((req,res)=>{const u=new URL(req.url,'http://fixture');res.setHeader('content-type','text/html;charset=utf-8');res.end(render(u.searchParams.get('tab')||'mine',u.searchParams.get('theme')||'light'));});
(async()=>{
 await new Promise(r=>server.listen(0,'127.0.0.1',r));const browser=await chromium.launch({headless:true,args:['--no-sandbox'],...(process.env.USTAR_CHROMIUM?{executablePath:process.env.USTAR_CHROMIUM}:{})});
 try{const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 for(const width of [390,768,1440]) for(const color of ['light','dark']) for(const tab of ['mine','catalog','shop','checks','offer','manage']) {
  await page.setViewportSize({width,height:900});await page.goto('http://127.0.0.1:'+server.address().port+'/?tab='+tab+'&theme='+color);
  assert.equal(await page.locator('h1').count(),1,'one title');
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'no horizontal overflow '+[width,color,tab]);
  for(const box of await page.locator('button.u-btn:visible').all()) { const rect=await box.boundingBox();if(rect)assert(rect.height>=43,'touch control at least44px '+JSON.stringify({width,color,tab,rect,text:await box.textContent()})); }
  if(tab==='manage') for(const field of await page.locator('.u-library__checksedit > .u-field:visible').all()) { const rect=await field.boundingBox();assert(rect && rect.height<180,'management fields must not reserve240px vertically '+JSON.stringify({width,color,rect})); }
  if(tab==='offer') {assert.equal(await page.locator('form [name=price]').inputValue(),'10');assert.equal(await page.locator('form [name=revision]').inputValue(),'123');assert.equal(await page.locator('button').textContent(),'Открыть за 10 USCOIN');}
  await page.keyboard.press('Tab');assert(await page.evaluate(()=>document.activeElement!==document.body),'keyboard focus');
  if(width!==768 && ['mine','catalog','offer','manage'].includes(tab)) await page.screenshot({path:out+'/library-'+tab+'-'+width+'-'+color+'.png',fullPage:true});
 }
 assert.equal(errors.length,0,errors.join('\n'));console.log('LIBRARY_BROWSER=PASS 36 views;390/768/1440;light/dark;keyboard;touch;purchase quote;real templates/tokens');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1}).finally(()=>server.close());
