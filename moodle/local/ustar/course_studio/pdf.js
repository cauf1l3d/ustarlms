/* Local PDF text import and browser print export. No document scripting. */
(function(root){'use strict';
const M=root.RCModel,esc=M.escapeHTML;
const scriptBase=new URL('.',document.currentScript?.src||location.href).href;
function textLines(items){
 const lines=[];let line='',lastY=null,lastEnd=null;
 const flush=()=>{if(line.trim())lines.push(line.trim());line='';lastEnd=null};
 for(const item of items){
  if(typeof item.str!=='string')continue;
  const y=item.transform?.[5],x=item.transform?.[4];
  if(lastY!==null&&Number.isFinite(y)&&Math.abs(y-lastY)>Math.max(2,Math.abs(item.transform?.[3]||10)*.4))flush();
  if(line&&!/\s$/.test(line)&&!/^\s/.test(item.str)&&Number.isFinite(x)&&lastEnd!==null&&x-lastEnd>1)line+=' ';
  line+=item.str;lastY=y;lastEnd=Number.isFinite(x)?x+(item.width||0):null;
  if(item.hasEOL)flush();
 }
 flush();return lines;
}
function fromPages(pages,name){
 if(!pages.length||pages.length>200)throw Error('PDF должен содержать от 1 до 200 страниц.');
 if(pages.reduce((n,p)=>n+p.length,0)>2000000)throw Error('Текст PDF превышает 2 млн символов. Разделите документ.');
 if(!pages.some(p=>p.trim()))throw Error('В PDF нет текстового слоя. Распознайте скан (OCR) и импортируйте повторно.');
 const code='PDF-'+M.uid().replace(/-/g,'').slice(0,16);
 const course=M.normalizeCourse({data:{code,title:name.replace(/\.pdf$/i,'').slice(0,255)||'Курс из PDF',version:'1.0',pages:pages.map((text,i)=>({id:code+'-'+(i+1),title:'Страница '+(i+1),kind:'lesson',chapter:'',html:text.split(/\n/).filter(x=>x.trim()).map(x=>'<p>'+esc(x)+'</p>').join('')||'<p><br></p>',sources:[],source_text:name.slice(0,300)+' · страница '+(i+1)}))}});
 const encoder=new TextEncoder();
 if(course.data.pages.some(p=>encoder.encode(p.html).length>2000000))throw Error('Текст одной страницы превышает размер экрана. Разделите PDF на меньшие страницы.');
 if(encoder.encode(JSON.stringify(course)).length>5*1024*1024)throw Error('Проект из PDF превышает 5 МБ. Разделите документ.');
 return course;
}
async function importFile(file,onProgress=()=>{}){
 if(file.size>25*1024*1024)throw Error('PDF превышает 25 МБ. Разделите документ.');
 const bytes=new Uint8Array(await file.arrayBuffer());
 if(!new TextDecoder().decode(bytes.slice(0,1024)).includes('%PDF-'))throw Error('Файл не является PDF.');
 const lib=await import(scriptBase+'vendor/pdfjs/pdf.min.mjs');
 lib.GlobalWorkerOptions.workerSrc=scriptBase+'vendor/pdfjs/pdf.worker.min.mjs';
 const task=lib.getDocument({data:bytes,isEvalSupported:false,useWasm:false,useWorkerFetch:false,disableFontFace:true,stopAtErrors:true});
 let timer;const timeout=new Promise((_,reject)=>{timer=setTimeout(()=>{reject(Error('PDF обрабатывается слишком долго. Разделите документ.'));task.destroy().catch(()=>{});},60000)});
 try{return await Promise.race([timeout,(async()=>{
  const pdf=await task.promise;
  if(pdf.numPages>200)throw Error('В PDF больше 200 страниц. Разделите документ.');
  const pages=[];let total=0;
  for(let i=1;i<=pdf.numPages;i++){
   onProgress(i,pdf.numPages);const page=await pdf.getPage(i),content=await page.getTextContent();
   const text=textLines(content.items).join('\n');total+=text.length;
   if(total>2000000)throw Error('Текст PDF превышает 2 млн символов. Разделите документ.');
   pages.push(text);page.cleanup();
  }
  return fromPages(pages,file.name);
 })()]);}catch(e){
  if(e.name==='PasswordException')throw Error('PDF защищён паролем. Сохраните разрешённую для импорта копию без пароля.');
  if(['InvalidPDFException','UnknownErrorException'].includes(e.name))throw Error('Не удалось прочитать PDF. Проверьте файл или сохраните его заново.');
  throw e;
 }finally{clearTimeout(timer);await task.destroy();}
}
function printHTML(course,assetURL){
 const clean=html=>{
  const t=document.createElement('template');t.innerHTML=root.RCRich.sanitizeHTML(html);
  for(const img of t.content.querySelectorAll('img')){const path=img.getAttribute('src');if(course.assets[path])img.src=assetURL(path);else img.remove();}
  for(const details of t.content.querySelectorAll('details'))details.open=true;
  return t.innerHTML;
 };
 const title=esc(course.data.title);
 return '<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>'+title+'</title>'+
 '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src blob: data:; style-src \'unsafe-inline\'; font-src blob: data:; base-uri \'none\'; form-action \'none\'">'+
 '<style>@page{size:A4;margin:18mm}*{box-sizing:border-box}body{margin:0;color:#192f46;background:white;font:12pt/1.6 Arial,sans-serif;overflow-wrap:anywhere}h1,h2,h3{font-family:Georgia,serif;line-height:1.25}h1{font-size:30pt}h2{font-size:22pt}h3{font-size:16pt}a{color:#19496d}p{margin:0 0 12pt}img{max-width:100%;height:auto;max-height:230mm;object-fit:contain}table{border-collapse:collapse;max-width:100%;width:100%;table-layout:fixed}td,th{border:1px solid #b8c4cd;padding:7pt}tr,figure{break-inside:avoid}thead{display:table-header-group}h2,h3{break-after:avoid}.cover{padding-top:35mm}.eyebrow,.source{font:10pt/1.5 Arial,sans-serif;color:#536678}.eyebrow{letter-spacing:2px}.lesson{break-before:page}.source{margin-top:24pt;padding-top:10pt;border-top:1px solid #cbd3d9;white-space:pre-line}.toc li{margin:8pt 0}.key,blockquote,.callout-note{border-left:3pt solid #b7934b;padding:10pt 14pt;background:#f6f3ec}button{background:none;border:0;font:inherit;color:inherit;white-space:normal;max-width:100%}summary{font-weight:bold}figure{margin:14pt 0}figcaption{font-size:10pt}@media screen{body{max-width:210mm;padding:18mm;margin:auto}.lesson{margin-top:28mm;border-top:1px solid #cbd3d9;padding-top:10mm}}@media print{body{width:auto}.lesson{padding:0}a{text-decoration:none}}</style></head><body>'+
 '<section class="cover"><p class="eyebrow">USTAR АКАДЕМИЯ · УЧЕБНЫЙ МАТЕРИАЛ</p><h1>'+title+'</h1><p>'+esc(course.data.code)+' · редакция '+esc(course.revision)+'</p><p>Материал для самостоятельного изучения</p><h2>Содержание</h2><ol class="toc">'+course.data.pages.map((p,i)=>'<li><a href="#lesson-'+i+'">'+esc(p.title)+'</a></li>').join('')+'</ol></section>'+
 course.data.pages.map((p,i)=>'<section class="lesson" id="lesson-'+i+'"><p class="eyebrow">'+(i+1)+' / '+course.data.pages.length+(p.chapter?' · '+esc(p.chapter):'')+'</p><h2>'+esc(p.title)+'</h2>'+clean(p.html)+(p.source_text?'<p class="source">Источник: '+esc(p.source_text)+'</p>':'')+'</section>').join('')+'</body></html>';
}
root.StudioPDF={textLines,fromPages,importFile,printHTML};
})(globalThis);
