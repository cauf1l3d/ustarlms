/* The material source is obtained only through the author API; conversions stay local. */
(function(root){'use strict';
const M=root.RCModel;
async function convert(info,bytes){
 if(bytes.length!==info.size||bytes.length>(info.format==='scorm'?100:25)*1024*1024)throw Error('Размер исходного материала не совпал. Откройте его повторно.');
 const file={name:info.filename,size:bytes.length,arrayBuffer:async()=>bytes.buffer.slice(bytes.byteOffset,bytes.byteOffset+bytes.byteLength)};
 let courses;
 if(info.format==='word')return root.StudioWord.importFile(file);
 if(info.format==='pdf')return root.StudioPDF.importFile(file);
 if(info.format==='scorm')courses=await M.importScorm(bytes);
 else if(info.format==='project')courses=M.parseProject(new TextDecoder('utf-8',{fatal:true}).decode(bytes));
 if(courses){if(courses.length!==1)throw Error('В файле несколько курсов. Откройте его через «Файл → Открыть проект» и выберите нужный курс.');return courses[0];}
 const title=info.origin.title,code='IMPORT-'+M.uid().replace(/-/g,'').slice(0,16),assets={};let html;
 if(info.format==='image'){
  const extension=info.filename.split('.').pop().toLowerCase(),type={png:'image/png',jpg:'image/jpeg',jpeg:'image/jpeg',gif:'image/gif',webp:'image/webp'}[extension];
  if(!type)throw Error('Неподдерживаемый формат изображения.');
  const path='assets/material-image.'+(extension==='jpeg'?'jpg':extension);assets[path]={type,data:M.toBase64(bytes)};
  html='<p><img src="'+path+'" alt="'+M.escapeHTML(title)+'"></p>';
 }else if(info.format==='text'){
  let text;try{text=new TextDecoder('utf-8',{fatal:true}).decode(bytes);}catch(_){text=new TextDecoder('windows-1251').decode(bytes);}
  if(!text.trim())throw Error('В документе нет текста: файл пуст.');
  if(text.length>1000000||/[\x00-\x08\x0b\x0c\x0e-\x1f]/.test(text))throw Error('Текстовый документ слишком большой или содержит двоичные данные.');
  html=text.replace(/\r\n?/g,'\n').split('\n').map(line=>'<p>'+(M.escapeHTML(line)||'<br>')+'</p>').join('');
 }else throw Error('У этого материала нет поддерживаемого редактируемого источника.');
 if(new TextEncoder().encode(html).length>2000000)throw Error('Документ превышает размер экрана. Разделите его перед импортом.');
 return M.normalizeCourse({data:{code,title,version:'1.0',pages:[{id:code+'-1',title,kind:'lesson',chapter:'',html,source_text:'',sources:[]}]},assets});
}
root.StudioMaterial={convert};
})(typeof window!=='undefined'?window:globalThis);
