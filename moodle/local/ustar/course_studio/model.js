(function(root,factory){const api=factory(root);if(typeof module==='object'&&module.exports)module.exports=api;else root.RCModel=api})(globalThis,function(root){'use strict';
const clone=x=>JSON.parse(JSON.stringify(x)),enc=new TextEncoder(),dec=new TextDecoder();
const defaults={font:'Arial',headingFont:'Arial',fontSize:17,headingSize:34,lineHeight:1.65,ink:'#2b2b2b',accent:'#ebc500',paper:'#ffffff',background:'#ededed'};
let runtime={css:'',js:''};
const uid=()=>root.crypto?.randomUUID?.()||('id-'+Date.now().toString(36)+'-'+Math.random().toString(36).slice(2));
const escapeHTML=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function normalizeCourse(input){if(!input||typeof input!=='object')throw Error('Файл не содержит курс');const c={data:clone(input.data||input),assets:{},fonts:clone(input.fonts||[]),theme:{...defaults,...input.theme},catalogue:clone(input.catalogue||{}),revision:input.revision||0};for(const[p,a]of Object.entries(input.assets||{}))c.assets[p]={...a};if(!Array.isArray(c.data.pages))throw Error('В курсе нет списка экранов');c.data.pages.forEach((p,i)=>{if(!p.id)p.id=c.data.code+'-p-'+String(i+1).padStart(3,'0');if(!p.sources)p.sources=[];if(!p.kind)p.kind='lesson';if(!p.source_text)p.source_text='';if(!p.html)p.html='<p><br></p>'});return c}
function refs(c){const out=new Set();for(const p of c.data.pages){for(const h of [p.html,p.titleHTML||''])for(const m of h.matchAll(/\b(?:src|data-image)\s*=\s*["']([^"']+)["']/gi))out.add(m[1])}for(const f of c.fonts)out.add(f.path);return [...out]}
function unsafeHTML(h){const allowed=new Set('p div span section article button img br li figure figcaption h1 h2 h3 h4 h5 h6 details summary strong b i em u s strike del sub sup ul ol a blockquote pre code table thead tbody tfoot tr th td hr font mark small'.split(' '));if([...String(h).matchAll(/<\s*\/?\s*([a-z][a-z0-9:-]*)/gi)].some(m=>!allowed.has(m[1].toLowerCase())))return true;h=String(h).replace(/&#(?:x([0-9a-f]+)|([0-9]+));?/gi,(_,x,n)=>String.fromCodePoint(parseInt(x||n,x?16:10))).replace(/&(?:colon|Tab|NewLine);/g, x=>({'&colon;':':','&Tab;':'\t','&NewLine;':'\n'}[x]));if([...h.matchAll(/\b(?:href|src)\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+))/gi)].some(m=>/^(?:javascript:|vbscript:|data:text\/html)/i.test((m[1]??m[2]??m[3]).replace(/[\x00-\x20]/g,''))))return true;return /<\s*(?:script|iframe|object|embed|svg|form|input|textarea|style|link|meta|base)\b/i.test(h)||/(?:[\s/])on[a-z]+\s*=/i.test(h)||/\b(?:href|src)\s*=\s*["']?\s*(?:javascript:|vbscript:|data:text\/html)/i.test(h)||/\bstyle\s*=\s*["'][^"']*(?:url\s*\(|expression\s*\()/i.test(h)}
function validPath(p){return /^(?:assets|fonts)\/[a-zA-Z0-9_./-]+$/.test(p)&&!p.split('/').includes('..')&&!p.includes('//')}
function validateCourse(c){const e=[];for(const [key,min,max] of [['fontSize',12,40],['headingSize',18,72],['lineHeight',1.3,2]])if(typeof c?.theme?.[key]!=='number'||c.theme[key]<min||c.theme[key]>max)e.push('Некорректный размер '+key);for(const p of c?.data?.pages||[])if(p.titleStyle&&Object.entries(p.titleStyle).some(([k,v])=>!['textAlign','color','fontWeight','fontStyle','textDecoration','fontSize','fontFamily','backgroundColor'].includes(k)||/[<>;{}\\]|url|expression|@/i.test(String(v))))e.push('Некорректное оформление заголовка');if(!c?.data||!Array.isArray(c.data.pages))return ['Некорректная структура курса'];if(!/^[a-zA-Z0-9_-]{1,60}$/.test(c.data.code||''))e.push('Код курса должен содержать буквы, цифры, дефис или подчёркивание');if(!String(c.data.title||'').trim())e.push('Укажите название курса');if(c.data.pages.length<1||c.data.pages.length>500)e.push('В курсе должно быть от 1 до 500 экранов');const ids=new Set();for(const[p,i]of c.data.pages.map((p,i)=>[p,i])){if(!p.id||ids.has(p.id))e.push('Повторяющийся или пустой идентификатор экрана '+(i+1));ids.add(p.id);if(!String(p.title||'').trim())e.push('Укажите заголовок экрана '+(i+1));if(typeof p.html!=='string'||p.html.length>2000000)e.push('Слишком большой или некорректный экран '+(i+1));if(unsafeHTML((p.html||'')+(p.titleHTML||'')))e.push('Небезопасный HTML на экране '+(i+1))}let total=0;for(const[p,a]of Object.entries(c.assets||{})){if(!validPath(p)||!a||typeof a.data!=='string'||!/^[A-Za-z0-9+/]*={0,2}$/.test(a.data))e.push('Некорректный файл '+p);total+=(a?.data?.length||0)*.75}if(total>150*1024*1024)e.push('Изображения и шрифты курса превышают 150 МБ');for(const p of refs(c))if(!c.assets?.[p]||!validPath(p))e.push('Нет файла '+p);for(const k of ['ink','accent','paper','background','navBackground','navText','navActive','navAccent'].filter(k=>!k.startsWith('nav')||k in c.theme))if(typeof c.theme[k]!=='string'||!/^#[0-9a-f]{6}$/i.test(c.theme[k]))e.push('Некорректный цвет '+k);if(![c.theme.font,c.theme.headingFont].every(x=>/^[\w ,.-]{1,120}$/.test(x)))e.push('Некорректное имя шрифта');for(const f of c.fonts)if(!/^RCFont[a-zA-Z0-9_-]+$/.test(f.family))e.push('Некорректный загруженный шрифт');return [...new Set(e)]}
function ensure(c){const e=validateCourse(c);if(e.length)throw Error(e.join('\n'));return c}
function serializeProject(courses){courses.forEach(ensure);return JSON.stringify({format:courses.length===1?'ustar-rc-project':'ustar-rc-workspace',schemaVersion:1,savedAt:new Date().toISOString(),courses},null,0)}
function parseProject(text){let p;try{p=JSON.parse(text)}catch(_){throw Error('Не удалось прочитать JSON проекта')}if(!['ustar-rc-project','ustar-rc-workspace'].includes(p.format)||p.schemaVersion!==1||!Array.isArray(p.courses)||!p.courses.length||p.courses.length>100)throw Error('Это не поддерживаемый проект студии курсов');const courses=p.courses.map(normalizeCourse);courses.forEach(ensure);return courses}
function snapshot(c){return JSON.stringify({data:c.data,theme:c.theme,fonts:c.fonts,catalogue:c.catalogue,revision:c.revision})}
class History{constructor(c,limit=50){this.items=[snapshot(c)];this.pos=0;this.limit=limit}capture(c){const s=snapshot(c),previous=JSON.parse(this.items[this.pos]),comparable=x=>JSON.stringify({data:x.data,theme:x.theme,fonts:x.fonts,catalogue:x.catalogue});if(comparable(c)===comparable(previous))return false;this.items.splice(this.pos+1);this.items.push(s);if(this.items.length>this.limit)this.items.shift();this.pos=this.items.length-1;return true}get canUndo(){return this.pos>0}get canRedo(){return this.pos<this.items.length-1}apply(c){Object.assign(c,JSON.parse(this.items[this.pos]));return true}undo(c){if(!this.canUndo)return false;this.pos--;return this.apply(c)}redo(c){if(!this.canRedo)return false;this.pos++;return this.apply(c)}}
function toBytes(base64){if(typeof Buffer!=='undefined')return new Uint8Array(Buffer.from(base64,'base64'));const s=atob(base64),b=new Uint8Array(s.length);for(let i=0;i<s.length;i++)b[i]=s.charCodeAt(i);return b}
function toBase64(bytes){if(typeof Buffer!=='undefined')return Buffer.from(bytes).toString('base64');let s='';for(let i=0;i<bytes.length;i+=32768)s+=String.fromCharCode(...bytes.subarray(i,i+32768));return btoa(s)}
function setRuntime(css,js){runtime={css,js}}
function hash(s){let a=2166136261,b=0x9e3779b9;for(let i=0;i<s.length;i++){a=Math.imul(a^s.charCodeAt(i),16777619);b=Math.imul(b^s.charCodeAt(i),2246822519)}return(a>>>0).toString(16).padStart(8,'0')+(b>>>0).toString(16).padStart(8,'0')}
function fontCSS(c,uri=p=>p){return c.fonts.map(f=>`@font-face{font-family:"${f.family}";src:url("${uri(f.path)}");font-display:swap}`).join('\n')}
// Display defaults stay outside defaults/normalizeCourse: old shared manifests keep their exact fingerprint.
function contrastText(hex){const rgb=hex.slice(1).match(/../g).map(x=>parseInt(x,16)/255).map(x=>x<=.04045?x/12.92:((x+.055)/1.055)**2.4);return .2126*rgb[0]+.7152*rgb[1]+.0722*rgb[2]>.1791287847?'#000000':'#ffffff'}
function navigationTheme(c){const t=c.theme,n={background:t.navBackground??'#2b2b2b',text:t.navText??'#ffffff',active:t.navActive??'#474747',accent:t.navAccent??'#ebc500'};return{...n,activeText:contrastText(n.active)}}
function themeCSS(c,scope=''){const t=c.theme,n=navigationTheme(c),s=scope?scope+' ':'';return`${scope||':root'}{--ink:${t.ink};--yellow:${t.accent};--paper:${t.paper};--bg:${t.background};--accent-ink:${contrastText(t.accent)};--nav-bg:${n.background};--nav-text:${n.text};--nav-active:${n.active};--nav-accent:${n.accent};--nav-active-ink:${n.activeText}}${s}body{font-family:"${t.font}",Arial,sans-serif;font-size:${Number(t.fontSize)||17}px;line-height:${Number(t.lineHeight)||1.65};color:${t.ink};background:${t.background}}${s}.card{background:${t.paper}}${s}.card h2{font-family:"${t.headingFont}",Arial,sans-serif;font-size:${Number(t.headingSize)||34}px}.photo{margin:18px 0}.photo .picture{width:100%}.photo-caption,figcaption{display:block;font-size:14px;line-height:1.45;text-align:center;margin-top:8px}.picture img[style]{height:auto}.callout{padding:14px 18px;border-left:4px solid var(--yellow);background:#fff7cf;border-radius:0 8px 8px 0;margin:18px 0}.callout-note{border-color:#557753;background:#f0f5ed}table{border-collapse:collapse;width:100%;margin:16px 0}th,td{border:1px solid #cbd1c5;padding:10px;min-width:40px}th{background:#f1f4eb}`}
function htmlIndex(d){return`<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${escapeHTML(d.title)}</title><link rel="stylesheet" href="style.css"></head><body><a class="skip" href="#content">К материалу</a><div class="app"><aside><div class="brand">АКАДЕМИЯ / USTAR</div><h1>${escapeHTML(d.title)}</h1><p class="version">${escapeHTML(d.code)} · ${escapeHTML(d.version)}</p><details id="menu"><summary>Содержание курса</summary><nav aria-label="Разделы курса">${d.pages.map((p,i)=>`<button type="button" data-page="${i}"><span class="navnum">${String(i+1).padStart(2,'0')}</span><span>${escapeHTML(p.title)}</span><span class="mark" aria-hidden="true"></span></button>`).join('')}</nav></details></aside><main><header><p class="eyebrow" id="chapter"></p><p id="counter"></p><progress id="progress" max="100" value="0" aria-label="Изучено экранов"></progress></header><article class="card"><h2 id="title" tabindex="-1"></h2><div id="content"></div><details class="sources"><summary>Источник этого экрана</summary><p id="source"></p></details></article><div class="controls"><button id="back" type="button">Назад</button><button id="next" type="button" class="primary">Изучено — дальше</button></div><p id="status" role="status" aria-live="polite"></p><p id="lms" class="meta"></p></main></div><dialog id="zoom"><button id="closeZoom" type="button">Закрыть изображение</button><img id="zoomImage" alt="Иллюстрация к инструкции"></dialog><script src="data.js"></script><script src="course.js"></script></body></html>`}
async function buildFiles(course){const c=ensure(normalizeCourse(course));if(!runtime.js||!runtime.css)throw Error('Плеер ещё не загружен');const data=clone(c.data);data.revisionId=hash(JSON.stringify({data:c.data,theme:c.theme,fonts:c.fonts,assets:refs(c).map(p=>[p,hash(c.assets[p].data)])}));data.version=(c.data.version||'1.0')+' · редакция '+(c.revision||0);const files={};files['index.html']=enc.encode(htmlIndex(data));files['style.css']=enc.encode(runtime.css+'\n'+fontCSS(c)+'\n'+themeCSS(c));files['course.js']=enc.encode(runtime.js);files['data.js']=enc.encode('window.COURSE='+JSON.stringify(data).replace(/</g,'\\u003c')+';');const used=refs(c);for(const p of used)files[p]=toBytes(c.assets[p].data);const metadata={format:'ustar-rc-embedded-project',schemaVersion:1,course:{data:c.data,theme:c.theme,fonts:c.fonts,catalogue:c.catalogue,revision:c.revision},assets:Object.fromEntries(used.map(p=>[p,{type:c.assets[p].type}]))};files['editor.json']=enc.encode(JSON.stringify(metadata));const ident='RC_'+c.data.code.replace(/[^\w-]/g,'_')+'_'+data.revisionId;files['imsmanifest.xml']=enc.encode(`<?xml version="1.0" encoding="UTF-8"?><manifest identifier="${ident}" version="1.2" xmlns="http://www.imsproject.org/xsd/imscp_rootv1p1p2" xmlns:adlcp="http://www.adlnet.org/xsd/adlcp_rootv1p2" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><metadata><schema>ADL SCORM</schema><schemaversion>1.2</schemaversion></metadata><organizations default="ORG"><organization identifier="ORG"><title>${escapeHTML(c.data.title)}</title><item identifier="ITEM" identifierref="SCO"><title>${escapeHTML(c.data.title)}</title></item></organization></organizations><resources><resource identifier="SCO" type="webcontent" adlcp:scormtype="sco" href="index.html">${Object.keys(files).map(p=>`<file href="${escapeHTML(p)}"/>`).join('')}</resource></resources></manifest>`);return files}
async function exportScorm(c){const z=new root.JSZip();for(const[p,b]of Object.entries(await buildFiles(c)))z.file(p,b);return z.generateAsync({type:'uint8array',compression:'DEFLATE',compressionOptions:{level:3}})}
function parseDataJS(s){const m=String(s).trim().match(/^window\.COURSE\s*=\s*([\s\S]*?);?$/);if(!m)throw Error('Не найден поддерживаемый data.js курса РЦ');try{return JSON.parse(m[1].replace(/;\s*$/,''))}catch(_){throw Error('Формат данных SCORM не поддерживается. Откройте проект или архив курса РЦ.')}}
function mime(path){const e=path.split('.').pop().toLowerCase();return({png:'image/png',jpg:'image/jpeg',jpeg:'image/jpeg',webp:'image/webp',gif:'image/gif',woff2:'font/woff2',woff:'font/woff',ttf:'font/ttf',otf:'font/otf'})[e]||'application/octet-stream'}
// A static SCORM is converted to normal lesson screens; source scripts are never executed.
async function importHtmlScorm(zip,names,read){
 if(!root.DOMParser)throw Error('Для импорта HTML-курса откройте Студию в браузере.');
 const parse=(s,type='text/html')=>new root.DOMParser().parseFromString(s,type);
 const manifests=names.filter(n=>/(^|\/)imsmanifest\.xml$/i.test(n));
 if(manifests.length!==1)throw Error('Нужен один SCORM с imsmanifest.xml. Откройте отдельный архив курса.');
 const manifestName=manifests[0],xml=dec.decode(await read(manifestName,2*1024*1024));
 if(/<!DOCTYPE|<!ENTITY/i.test(xml))throw Error('Манифест SCORM содержит неподдерживаемые объявления XML.');
 const manifest=parse(xml,'application/xml');
 if(manifest.querySelector('parsererror')||manifest.doctype)throw Error('Повреждён манифест SCORM.');
 const elements=(node,tag)=>Array.from(node.getElementsByTagNameNS('*',tag));
 const direct=(node,tag)=>Array.from(node.children||[]).find(n=>n.localName===tag);
 const resolve=(base,raw)=>{
  raw=String(raw||'').trim();
  if(!raw||/[\x00-\x1f\\]/.test(raw)||/^(?:[a-z][a-z0-9+.-]*:|\/)/i.test(raw))throw Error('Небезопасный путь ресурса SCORM.');
  let path;try{path=decodeURIComponent(raw.split(/[?#]/,1)[0]);}catch(_){throw Error('Повреждён путь ресурса SCORM.');}
  if(/[\\\x00-\x1f]/.test(path)||path.startsWith('/'))throw Error('Небезопасный путь ресурса SCORM.');
  const parts=base.split('/').slice(0,-1);
  for(const part of path.split('/')){if(!part||part==='.')continue;if(part==='..'){if(!parts.length)throw Error('Ресурс выходит за пределы архива.');parts.pop();}else parts.push(part);}
  const out=parts.join('/');if(!names.includes(out))throw Error('В архиве отсутствует файл ресурса: '+out);return out;
 };
 const resources=new Map(elements(manifest,'resource').map(r=>[r.getAttribute('identifier'),r]));
 const orgs=elements(manifest,'organizations')[0],preferred=orgs?.getAttribute('default');
 const org=elements(manifest,'organization').find(o=>o.getAttribute('identifier')===preferred)||elements(manifest,'organization')[0];
 const queue=[],seen=new Set();
 const add=(ref,title)=>{const resource=resources.get(ref),href=resource?.getAttribute('href');if(!href)return;const file=resolve(manifestName,href);if(!/\.html?$/i.test(file))throw Error('Ресурс SCORM не содержит HTML для редактирования.');if(!seen.has(file)){seen.add(file);queue.push({file,title});}};
 if(org)for(const item of elements(org,'item'))add(item.getAttribute('identifierref'),direct(item,'title')?.textContent?.trim()||'');
 if(!queue.length)for(const r of resources.values())add(r.getAttribute('identifier'),'');
 if(!queue.length)throw Error('В манифесте SCORM нет HTML-материала.');
 const title=(direct(org||manifest.documentElement,'title')?.textContent||'').trim()||'Импорт SCORM';
 const code='SCORM-'+uid().replace(/[^a-zA-Z0-9]/g,'').slice(0,28),course=normalizeCourse({data:{code,title:title.slice(0,255),version:'1.0',pages:[]}});
 const allowed=new Set('p div span section article button img br li figure figcaption h1 h2 h3 h4 h5 h6 details summary strong b i em u s strike del sub sup ul ol a blockquote pre code table thead tbody tfoot tr th td hr font mark small'.split(' '));
 const properties=new Set(('color background background-color font font-size font-family font-weight font-style line-height text-align text-decoration text-decoration-line text-indent white-space vertical-align width max-width min-width height max-height min-height margin margin-left margin-right margin-top margin-bottom padding padding-left padding-right padding-top padding-bottom border border-left border-right border-top border-bottom border-color border-width border-style border-radius display object-fit float clear box-sizing letter-spacing word-break flex flex-basis flex-grow flex-shrink').split(' '));
 let assetIndex=0,totalText=0;const assetPaths=new Map();
 function cssRules(doc){
  const vars={},rules=[];let order=0;
  for(const style of doc.querySelectorAll('style')){
   const css=style.textContent.replace(/\/\*[\s\S]*?\*\//g,'');if(css.length>500000)throw Error('Стили SCORM слишком велики.');
   let start=0;
   while(start<css.length){let open=css.indexOf('{',start);if(open<0)break;let depth=1,end=open+1,quote='';
    for(;end<css.length&&depth;end++){const ch=css[end];if(quote){if(ch===quote&&css[end-1]!=='\\')quote='';continue;}if(ch==='"'||ch==="'"){quote=ch;continue;}if(ch==='{')depth++;if(ch==='}')depth--;}
    if(depth)break;const selector=css.slice(start,open).trim(),body=css.slice(open+1,end-1);start=end;
    if(selector.startsWith('@')||/[{}\\<>@]/.test(selector))continue;
    if(selector.split(',').some(s=>s.trim()===':root'))for(const m of body.matchAll(/(--[\w-]+)\s*:\s*([^;{}]+)/g))vars[m[1]]=m[2].trim();
    for(const sel of selector.split(',')){const s=sel.trim();if(!s||s===':root'||s.length>300)continue;const ids=(s.match(/#[\w-]+/g)||[]).length,classes=(s.match(/[.\[:][\w-]+/g)||[]).length;rules.push({selector:s,body,score:ids*10000+classes*100+order++/10000});}
   }
  }
  if(rules.length>2000)throw Error('Слишком много правил оформления SCORM.');
  return {vars,rules:rules.sort((a,b)=>a.score-b.score)};
 }
 function declarations(text,vars){const out={};for(const rule of text.split(';')){const colon=rule.indexOf(':');if(colon<0)continue;const key=rule.slice(0,colon).trim().toLowerCase();let value=rule.slice(colon+1).trim().replace(/\s*!important\s*$/i,'');if(!properties.has(key))continue;
  for(let i=0;i<5&&value.includes('var(');i++)value=value.replace(/var\(\s*(--[\w-]+)\s*(?:,\s*([^()]+))?\)/g,(_,name,fallback)=>vars[name]??fallback??'');
  if(!value||value.includes('var(')||/[\\<>@{}\x00-\x1f]|url|expression|behavior|binding|javascript/i.test(value))continue;
  if(key==='display'&&value==='none')continue;out[key]=value;
 }return out;}
 async function image(src,base){
  let bytes,type,key;
  const match=String(src).match(/^data:(image\/(?:png|jpeg|gif|webp));base64,([A-Za-z0-9+/=\r\n]+)$/);
  if(match){key=src;if(src.length>35*1024*1024)throw Error('Изображение SCORM слишком велико.');bytes=toBytes(match[2].replace(/[\r\n]/g,''));type=match[1];}
  else{key=resolve(base,src);if(assetPaths.has(key))return assetPaths.get(key);bytes=await read(key,25*1024*1024);type=mime(key);}
  if(assetPaths.has(key))return assetPaths.get(key);
  if(!['image/png','image/jpeg','image/gif','image/webp'].includes(type)||!bytes.length)throw Error('Изображение SCORM не поддерживается.');
  const ext={ 'image/png':'png','image/jpeg':'jpg','image/gif':'gif','image/webp':'webp'}[type];
  const path='assets/scorm-image-'+(++assetIndex)+'.'+ext;course.assets[path]={type,data:toBase64(bytes)};assetPaths.set(key,path);return path;
 }
 for(const resource of queue){
  const doc=parse(dec.decode(await read(resource.file,5*1024*1024))),css=cssRules(doc);
  if(doc.querySelectorAll('*').length>20000)throw Error('Слишком сложная HTML-страница SCORM.');
  const sections=Array.from(doc.querySelectorAll('section.page,article.page,div.page,section[data-page]')).filter(el=>!el.parentElement?.closest('section.page,article.page,div.page,section[data-page]'));
  let candidates=sections.length?sections:[doc.querySelector('main')||doc.body];
  for(const original of candidates){
   if(!original)continue;
   if(original.querySelector('video,audio,canvas,iframe,object,embed,form,input,textarea,select'))throw Error('В SCORM есть интерактивный элемент без редактируемого исходника. Сохраните исходный проект конструктора.');
   const clone=original.cloneNode(true),sourceNodes=[original,...original.querySelectorAll('*')],targetNodes=[clone,...clone.querySelectorAll('*')];
   for(let i=0;i<sourceNodes.length;i++){
    const source=sourceNodes[i],target=targetNodes[i],tag=source.localName?.toLowerCase();
    if(!allowed.has(tag)){
     if(['script','style','link','meta','base','svg','nav','header','footer'].includes(tag))target.remove();else target.replaceWith(...target.childNodes);continue;
    }
    const style={};for(const rule of css.rules){try{if(source.matches(rule.selector))Object.assign(style,declarations(rule.body,css.vars));}catch(_){}}
    Object.assign(style,declarations(source.getAttribute('style')||'',css.vars));
    for(const attr of [...target.attributes])target.removeAttribute(attr.name);
    const classes=(source.getAttribute('class')||'').replace(/[^a-zA-Z0-9_ -]/g,'').slice(0,200);if(classes)target.setAttribute('class',classes);
    if(Object.keys(style).length)target.setAttribute('style',Object.entries(style).map(([k,v])=>k+':'+v).join(';'));
    for(const name of ['colspan','rowspan','width','height','start']){const value=source.getAttribute(name);if(/^\d{1,4}$/.test(value||'')&&+value>0)target.setAttribute(name,value);}
    if(tag==='img'){target.setAttribute('src',await image(source.getAttribute('src')||'',resource.file));target.setAttribute('alt',source.getAttribute('alt')||'');target.setAttribute('loading','lazy');}
    if(tag==='a'){const href=(source.getAttribute('href')||'').trim();if(/^(https?:\/\/|mailto:|tel:|#)[^\x00-\x20]*$/i.test(href)){target.setAttribute('href',href);target.setAttribute('rel','noopener noreferrer');}}
    if(tag==='details'&&source.hasAttribute('open'))target.setAttribute('open','');
   }
   const heading=original.querySelector('h1,h2,h3,h4,h5,h6')?.textContent?.trim();
   // Keep section contents editable; the Studio player supplies screen navigation.
   const html=clone.innerHTML,total=enc.encode(html).length;totalText+=total;
   if(total>2000000||totalText>5*1024*1024||course.data.pages.length>=500)throw Error('HTML-курс превышает размер проекта Студии.');
   if(!clone.textContent.trim()&&!clone.querySelector('img'))throw Error('В SCORM нет редактируемого HTML-содержимого. Нужен исходный проект конструктора.');
   course.data.pages.push({id:code+'-'+(course.data.pages.length+1),title:(heading||resource.title||'Экран '+(course.data.pages.length+1)).slice(0,500),kind:'lesson',chapter:'',html,source_text:'',sources:[]});
  }
 }
 return ensure(course);
}
async function importScorm(bytes,depth=0,budget={bytes:0,files:0}){
 if(bytes.byteLength>300*1024*1024||depth>3)throw Error('Архив слишком велик или содержит слишком много вложенных архивов.');
 const z=await root.JSZip.loadAsync(bytes),names=Object.keys(z.files).filter(n=>!z.files[n].dir);
 budget.files+=names.length;if(budget.files>4000)throw Error('Слишком много файлов в архиве');
 let declared=0;
 for(const n of names){const entry=z.files[n],original=entry.unsafeOriginalName||n;
  if(original!==n||n.includes('..')||n.startsWith('/')||n.includes('\\')||/[\x00-\x1f]/.test(n))throw Error('Небезопасный путь в архиве');
  const size=entry._data?.uncompressedSize;if(Number.isFinite(size)){if(size<0||size>200*1024*1024)throw Error('Ресурс архива слишком велик');declared+=size;}
 }
 if(declared>200*1024*1024)throw Error('Распакованный архив превышает200МБ');
 const cache=new Map();
 const read=async(name,limit=100*1024*1024)=>{const entry=z.file(name);if(!entry)throw Error('В архиве отсутствует файл ресурса: '+name);
  if(entry._data?.uncompressedSize>limit)throw Error('Файл ресурса слишком велик: '+name);
  if(!cache.has(name))cache.set(name,entry.async('uint8array').then(raw=>{budget.bytes+=raw.length;if(raw.length>limit||budget.bytes>200*1024*1024)throw Error('Распакованный архив превышает допустимый размер');return raw;}));
  const raw=await cache.get(name);if(raw.length>limit)throw Error('Файл ресурса слишком велик: '+name);return raw;
 };
 const bundles=names.filter(n=>/^scorm\/[^/]+\.zip$/i.test(n));
 if(bundles.length&&depth===0){const all=[];for(const n of bundles)all.push(...await importScorm(await read(n),depth+1,budget));return all;}
 if(names.length===1&&/\.zip$/i.test(names[0]))return importScorm(await read(names[0]),depth+1,budget);
 const manifests=names.filter(n=>/(^|\/)imsmanifest\.xml$/i.test(n)),prefix=manifests.length===1?manifests[0].slice(0,-'imsmanifest.xml'.length):'';
 const meta=z.file(prefix+'editor.json');let c,assetTypes={};
 if(meta){let p;try{p=JSON.parse(dec.decode(await read(prefix+'editor.json',5*1024*1024)));}catch(_){throw Error('Повреждены данные редактора в архиве');}
  if(p.format!=='ustar-rc-embedded-project'||p.schemaVersion!==1)throw Error('Неподдерживаемая версия проекта в SCORM');c=normalizeCourse(p.course);assetTypes=p.assets||{};
 }else{const f=z.file(prefix+'data.js'),source=f?dec.decode(await read(prefix+'data.js',5*1024*1024)):'';if(!/^\s*window\.COURSE\s*=/.test(source))return[await importHtmlScorm(z,names,read)];c=normalizeCourse({data:parseDataJS(source)})}
 let total=0;for(const name of names.filter(n=>n.startsWith(prefix)&&validPath(n.slice(prefix.length)))){const logical=name.slice(prefix.length),b=await read(name,25*1024*1024);total+=b.length;if(total>150*1024*1024)throw Error('Ресурсы курса превышают150МБ');c.assets[logical]={type:assetTypes[logical]?.type||mime(logical),data:toBase64(b)}}
 ensure(c);return[c];
}
function makeCopy(c,existing){const out=normalizeCourse(c),base=c.data.code.slice(0,45);let n=1;while(existing.includes(base+'-copy'+n))n++;out.data.code=base+'-copy'+n;out.data.title=c.data.title+' · копия '+n;out.data.pages.forEach((p,i)=>p.id=out.data.code+'-'+i+'-'+uid().slice(0,8));out.revision=0;return out}
return{defaults,normalizeCourse,validateCourse,serializeProject,parseProject,snapshot,History,toBytes,toBase64,escapeHTML,refs,setRuntime,fontCSS,themeCSS,navigationTheme,buildFiles,exportScorm,importScorm,parseDataJS,makeCopy,uid,unsafeHTML};
});
