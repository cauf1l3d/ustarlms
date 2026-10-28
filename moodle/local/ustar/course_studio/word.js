/* Local, bounded WordprocessingML conversion. Imported document code never runs. */
(function(root){'use strict';
const M=root.RCModel,esc=M.escapeHTML,MB=1024*1024;
const W='http://schemas.openxmlformats.org/wordprocessingml/2006/main';
const R='http://schemas.openxmlformats.org/officeDocument/2006/relationships';
const PKG='http://schemas.openxmlformats.org/package/2006/relationships';
const MIME='application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml';
const fail=message=>{throw Error(message);};
const val=(node,name='val')=>node?.getAttributeNS(W,name)||node?.getAttribute('w:'+name)||node?.getAttribute(name)||'';
const relid=(node,name)=>node?.getAttributeNS(R,name)||node?.getAttribute('r:'+name)||'';
const child=(node,name)=>Array.from(node?.children||[]).find(n=>n.localName===name);
const descendants=(node,name)=>Array.from(node?.getElementsByTagName('*')||[]).filter(n=>n.localName===name);
const safeLink=href=>root.RCRich.safeLink(href)&&!/^(?:file|javascript|data):/i.test(href);
function xml(bytes){
 if(!bytes||bytes.length>10*MB)fail('XML документа Word превышает 10 МБ.');
 const encoding=bytes[0]===255&&bytes[1]===254?'utf-16le':bytes[0]===254&&bytes[1]===255?'utf-16be':'utf-8';
 let text;try{text=new TextDecoder(encoding,{fatal:true}).decode(bytes);}catch(_){fail('Повреждена кодировка XML документа Word.');}
 if(/<!\s*(?:DOCTYPE|ENTITY)\b/i.test(text))fail('XML с DTD/ENTITY не поддерживается.');
 const doc=new DOMParser().parseFromString(text,'application/xml');
 if(doc.getElementsByTagName('parsererror').length||doc.getElementsByTagName('*').length>100000)fail('Некорректный или слишком сложный XML документа Word.');
 return doc;
}
function inspectZip(bytes){
 if(bytes.length<22||bytes.length>25*MB)fail('Выберите DOCX размером до 25 МБ.');
 const v=new DataView(bytes.buffer,bytes.byteOffset,bytes.byteLength);let end=-1;
 for(let p=bytes.length-22;p>=Math.max(0,bytes.length-65557);p--)if(v.getUint32(p,true)===0x06054b50&&p+22+v.getUint16(p+20,true)===bytes.length){end=p;break;}
 if(end<0||v.getUint16(end+4,true)||v.getUint16(end+6,true))fail('Word: повреждённый или многотомный ZIP-архив.');
 const count=v.getUint16(end+10,true),size=v.getUint32(end+12,true),start=v.getUint32(end+16,true);
 if(count<1||count>1000||count!==v.getUint16(end+8,true)||start+size!==end)fail('Word: слишком много файлов или неподдерживаемый размер архива.');
 const out=new Map(),seen=new Set(),ranges=[];let at=start,total=0;
 for(let i=0;i<count;i++){
  if(at+46>end||v.getUint32(at,true)!==0x02014b50)fail('Word: повреждённый каталог ZIP.');
  const flags=v.getUint16(at+8,true),method=v.getUint16(at+10,true),compressed=v.getUint32(at+20,true),length=v.getUint32(at+24,true);
  const n=v.getUint16(at+28,true),extra=v.getUint16(at+30,true),comment=v.getUint16(at+32,true),local=v.getUint32(at+42,true);
  if(at+46+n+extra+comment>end)fail('Word: повреждённое имя файла ZIP.');
  let name;try{name=new TextDecoder('utf-8',{fatal:true}).decode(bytes.subarray(at+46,at+46+n));}catch(_){fail('Word: некорректное имя файла ZIP.');}
  const parts=name.replace(/\/$/,'').split('/');
  if(!name||/^[\/]|[\\\x00-\x1f]/.test(name)||parts.some(p=>!p||p==='.'||p==='..')||seen.has(name.toLowerCase()))fail('Word: небезопасный или повторяющийся путь в архиве.');
  seen.add(name.toLowerCase());
  if(flags&1||![0,8].includes(method)||v.getUint16(at+34,true))fail('Word: зашифрованный или неподдерживаемый ZIP.');
  if(/(?:^|\/)(?:vbaProject\.bin|embeddings|activeX)(?:\/|$)/i.test(name))fail('Word содержит макросы или активные встроенные объекты.');
  total+=length;if(total>100*MB||length>25*MB||(/\.(?:xml|rels)$/i.test(name)&&length>10*MB))fail('Размер данных Word после распаковки превышает допустимый размер.');
  if(local+30>start||v.getUint32(local,true)!==0x04034b50||v.getUint16(local+8,true)!==method||v.getUint16(local+6,true)&1)fail('Word: повреждённая запись ZIP.');
  const ln=v.getUint16(local+26,true),lx=v.getUint16(local+28,true),data=local+30+ln+lx;
  if(data+compressed>start||new TextDecoder().decode(bytes.subarray(local+30,local+30+ln))!==name)fail('Word: пути или размеры ZIP не совпадают.');
  ranges.push([local,data+compressed]);
  out.set(name,{size:length,crc:v.getUint32(at+16,true),directory:name.endsWith('/')});at+=46+n+extra+comment;
 }
 if(at!==end)fail('Word: лишние данные в каталоге ZIP.');
 ranges.sort((a,b)=>a[0]-b[0]);for(let i=1;i<ranges.length;i++)if(ranges[i][0]<ranges[i-1][1])fail('Word: пересекающиеся записи ZIP.');
 if(!out.has('[Content_Types].xml')||!out.has('word/document.xml'))fail('Архив не является документом Word DOCX.');
 return out;
}
const crcTable=Array.from({length:256},(_,i)=>{let c=i;for(let b=0;b<8;b++)c=(c&1)?0xedb88320^(c>>>1):c>>>1;return c>>>0;});
function crc32(bytes){let crc=0xffffffff;for(const b of bytes)crc=crcTable[(crc^b)&255]^(crc>>>8);return(crc^0xffffffff)>>>0;}
function boundedRead(entry,meta){return new Promise((resolve,reject)=>{
 const chunks=[];let length=0,stopped=false;
 const stream=entry.internalStream('uint8array');
 stream.on('data',chunk=>{if(stopped)return;length+=chunk.length;if(length>meta.size||length>25*MB){stopped=true;stream.pause();reject(Error('Размер Word при распаковке не совпал.'));return;}chunks.push(chunk);});
 stream.on('error',reject);stream.on('end',()=>{if(stopped)return;const bytes=new Uint8Array(length);let offset=0;for(const c of chunks){bytes.set(c,offset);offset+=c.length;}
  if(length!==meta.size||crc32(bytes)!==meta.crc){reject(Error('Word: контрольная сумма или размер файла не совпали.'));return;}resolve(bytes);
 });stream.resume();
});}
function targetPath(target){
 if(!target||/^[a-z][a-z0-9+.-]*:|^\/\/|[\\\x00-\x1f]/i.test(target))fail('Word: небезопасная связь документа.');
 let decoded;try{decoded=decodeURIComponent(target);}catch(_){fail('Word: повреждённая связь документа.');}
 const parts=target.startsWith('/')?[]:['word'];
 for(const p of decoded.split('/')){if(!p||p==='.')continue;if(p==='..'){if(!parts.length)fail('Word: связь выходит за пределы документа.');parts.pop();}else parts.push(p);}
 return parts.join('/');
}
function relationships(bytes){
 const links=new Map();if(!bytes)return links;
 for(const n of descendants(xml(bytes),'Relationship')){
  const id=n.getAttribute('Id'),type=n.getAttribute('Type')||'',target=n.getAttribute('Target')||'',external=n.getAttribute('TargetMode')==='External';
  if(!id||links.has(id))fail('Word: повторяющаяся связь документа.');
  if(/\/(?:oleObject|package|control|aFChunk)$/i.test(type))fail('Word содержит неподдерживаемый активный объект.');
  if(external&&(!type.endsWith('/hyperlink')||!safeLink(target)))fail('Word: внешние изображения или небезопасные связи не импортируются.');
  links.set(id,{type,target:external?target:targetPath(target),external});
 }return links;
}
function styleMap(bytes){
 const map=new Map();if(!bytes)return map;
 for(const s of descendants(xml(bytes),'style')){const id=val(s,'styleId');map.set(id,{name:val(child(s,'name')),base:val(child(s,'basedOn')),outline:val(child(child(s,'pPr'),'outlineLvl'))});}
 return map;
}
function heading(style,styles){
 for(let depth=0;style&&depth<12;depth++){
  const row=styles.get(style),name=(row?.name||style).replace(/\s+/g,'');
  const m=name.match(/^(?:heading|заголовок)([1-6])$/i);if(m)return +m[1];
  if(row?.outline!==''&&row?.outline!==undefined&&+row.outline<6)return +row.outline+1;
  style=row?.base;
 }return 0;
}
function numbering(bytes){
 const types=new Map(),abstract=new Map();if(!bytes)return types;const doc=xml(bytes);
 for(const n of descendants(doc,'abstractNum')){const levels=new Map();for(const l of Array.from(n.children).filter(x=>x.localName==='lvl'))levels.set(+val(l,'ilvl'),val(child(l,'numFmt'))==='bullet'?'ul':'ol');abstract.set(val(n,'abstractNumId'),levels);}
 for(const n of descendants(doc,'num'))types.set(val(n,'numId'),abstract.get(val(child(n,'abstractNumId')))||new Map());return types;
}
function imageInfo(bytes){
 const v=new DataView(bytes.buffer,bytes.byteOffset,bytes.byteLength);
 if(bytes.length>=24&&v.getUint32(0)===0x89504e47&&v.getUint32(4)===0x0d0a1a0a)return{type:'image/png',ext:'png',width:v.getUint32(16),height:v.getUint32(20)};
 if(bytes.length>=10&&String.fromCharCode(...bytes.subarray(0,3))==='GIF')return{type:'image/gif',ext:'gif',width:v.getUint16(6,true),height:v.getUint16(8,true)};
 if(bytes[0]===255&&bytes[1]===216){let p=2;while(p+4<bytes.length){if(bytes[p]!==255)break;const tag=bytes[p+1],size=v.getUint16(p+2);if(size<2)break;if([192,193,194,195,197,198,199,201,202,203,205,206,207].includes(tag)&&p+9<bytes.length)return{type:'image/jpeg',ext:'jpg',width:v.getUint16(p+7),height:v.getUint16(p+5)};p+=2+size;}}
 fail('Word: изображение должно быть PNG, JPEG или GIF.');
}
function dimensions(info){if(info.width<1||info.height<1||info.width*info.height>80000000)fail('Word: некорректный или слишком большой размер изображения.');return info;}
async function importFile(file){
 if(file.size>25*MB)fail('Word превышает 25 МБ. Разделите документ.');
 if(!/\.docx$/i.test(file.name))fail('Выберите документ Word .docx.');
 const bytes=new Uint8Array(await file.arrayBuffer()),metadata=inspectZip(bytes),zip=await root.JSZip.loadAsync(bytes),files=new Map();
 for(const[name,meta]of metadata){if(meta.directory)continue;const entry=zip.file(name);if(!entry)fail('Word: отсутствует файл архива.');const raw=await boundedRead(entry,meta);if(/\.(?:xml|rels)$/i.test(name))xml(raw);files.set(name,raw);}
 const content=xml(files.get('[Content_Types].xml'));
 if(!descendants(content,'Override').some(n=>n.getAttribute('PartName')==='/word/document.xml'&&n.getAttribute('ContentType')===MIME))fail('Выберите DOCX без макросов.');
 const doc=xml(files.get('word/document.xml')),body=child(doc.documentElement,'body');
 if(!['http://schemas.openxmlformats.org/wordprocessingml/2006/main','http://purl.oclc.org/ooxml/wordprocessingml/main'].includes(doc.documentElement.namespaceURI)||!body)fail('Некорректный документ Word.');
 if(descendants(body,'altChunk').length||descendants(body,'object').length||descendants(body,'oMath').length)fail('Word содержит сложные объекты. Преобразуйте их в текст или изображения.');
 if(body.textContent.length>2000000)fail('Текст Word превышает 2 млн символов.');
 const links=relationships(files.get('word/_rels/document.xml.rels')),styles=styleMap(files.get('word/styles.xml')),numbers=numbering(files.get('word/numbering.xml'));
 const assets={},imagePaths=new Map(),pages=[];let title=file.name.replace(/\.docx$/i,'').slice(0,255)||'Курс из Word';
 if(files.has('docProps/core.xml')){const t=descendants(xml(files.get('docProps/core.xml')),'title')[0]?.textContent.trim();if(t)title=t.slice(0,255);}
 function image(n){
  const id=relid(n,'embed')||relid(n,'id'),rel=links.get(id);
  if(!rel||rel.external||!rel.type.endsWith('/image')||!files.has(rel.target))fail('Word: изображение отсутствует или является внешним.');
  if(!imagePaths.has(rel.target)){const raw=files.get(rel.target),info=dimensions(imageInfo(raw)),path='assets/word-image-'+(imagePaths.size+1)+'.'+info.ext;assets[path]={type:info.type,data:M.toBase64(raw)};imagePaths.set(rel.target,path);}
  let parent=n;while(parent.parentElement&&parent.localName!=='drawing'&&parent.localName!=='pict')parent=parent.parentElement;
  const alt=descendants(parent,'docPr')[0]?.getAttribute('descr')||'';
  return '<img src="'+imagePaths.get(rel.target)+'" alt="'+esc(alt)+'">';
 }
 function inline(n,depth=0){
  if(depth>40)fail('Word: слишком сложное вложенное оформление.');
  if(n.nodeType!==1)return '';const tag=n.localName;
  if(['rPr','pPr','proofErr','bookmarkStart','bookmarkEnd','del','instrText'].includes(tag))return '';
  if(tag==='t')return esc(n.textContent);if(tag==='tab')return '    ';if(tag==='br'||tag==='cr')return '<br>';
  if(tag==='blip'||tag==='imagedata')return image(n);
  let text=Array.from(n.children).map(x=>inline(x,depth+1)).join('');
  if(tag==='hyperlink'){const href=links.get(relid(n,'id'))?.target||(val(n,'anchor')?'#'+val(n,'anchor'):'');if(href&&safeLink(href))text='<a href="'+esc(href)+'">'+text+'</a>';}
  if(tag==='r'){
   const props=child(n,'rPr'),enabled=name=>{const e=child(props,name);return e&&!['0','false','off','none'].includes(val(e));};
   if(enabled('b'))text='<strong>'+text+'</strong>';if(enabled('i'))text='<em>'+text+'</em>';if(enabled('u'))text='<u>'+text+'</u>';if(enabled('strike'))text='<s>'+text+'</s>';
   const color=val(child(props,'color')),size=+val(child(props,'sz')),vertical=val(child(props,'vertAlign')),style=[];
   if(/^[a-f0-9]{6}$/i.test(color))style.push('color:#'+color);if(size>=16&&size<=144)style.push('font-size:'+size/2+'pt');
   const highlights={yellow:'ffff00',green:'00ff00',cyan:'00ffff',magenta:'ff00ff',blue:'0000ff',red:'ff0000',darkYellow:'808000',lightGray:'c0c0c0'},background=val(child(props,'shd'),'fill')||highlights[val(child(props,'highlight'))];
   if(/^[a-f0-9]{6}$/i.test(background||''))style.push('background-color:#'+background);
   if(style.length)text='<span style="'+style.join(';')+'">'+text+'</span>';
   if(vertical==='superscript')text='<sup>'+text+'</sup>';if(vertical==='subscript')text='<sub>'+text+'</sub>';
  }return text;
 }
 function paragraph(n){
  const props=child(n,'pPr'),style=val(child(props,'pStyle')),num=child(props,'numPr'),level=Math.max(0,Math.min(8,+val(child(num,'ilvl'))||0)),numId=val(child(num,'numId'));
  let text=Array.from(n.children).map(x=>inline(x)).join('');const align=val(child(props,'jc')),alignStyle=['left','center','right','both'].includes(align)?' style="text-align:'+(align==='both'?'justify':align)+'"':'';
  const h=heading(style,styles);return{html:'<'+(h?'h'+h:'p')+alignStyle+'>'+(text||'<br>')+'</'+(h?'h'+h:'p')+'>',text:n.textContent.trim(),heading:h,style,
   list:numId?{tag:numbers.get(numId)?.get(level)||'ul',level,id:numId,html:text||'<br>'}:null,
   before:!!child(props,'pageBreakBefore'),after:descendants(n,'br').some(b=>val(b,'type')==='page')};
 }
 function renderBlocks(blocks){let out='',list=null;for(const b of blocks){const key=b.list?b.list.tag+':'+b.list.level+':'+b.list.id:null;if(list?.key!==key&&list){out+='</'+list.tag+'>';list=null;}if(b.list){if(!list){list={key,tag:b.list.tag};out+='<'+list.tag+' style="margin-left:'+b.list.level*20+'px">';}out+='<li>'+b.list.html+'</li>';}else out+=b.html;}return out+(list?'</'+list.tag+'>':'');}
 function table(n,depth=0){
  if(depth>8)fail('Word: слишком много вложенных таблиц.');const rows=[],anchors=new Map();
  for(const row of Array.from(n.children).filter(x=>x.localName==='tr')){const cells=[],continued=new Set();let column=0;
   for(const cell of Array.from(row.children).filter(x=>x.localName==='tc')){const props=child(cell,'tcPr'),span=Math.max(1,Math.min(50,+val(child(props,'gridSpan'))||1)),merge=child(props,'vMerge');
    if(merge&&val(merge)!=='restart'&&anchors.has(column)){const original=anchors.get(column);if(!continued.has(original)){original.rowspan++;continued.add(original);}column+=span;continue;}
    const blocks=Array.from(cell.children).filter(x=>['p','tbl'].includes(x.localName)).map(x=>x.localName==='p'?paragraph(x):{html:table(x,depth+1)});
    const item={html:renderBlocks(blocks),colspan:span,rowspan:1};cells.push(item);for(let i=0;i<span;i++){if(merge)anchors.set(column+i,item);else anchors.delete(column+i);}column+=span;
   }rows.push(cells);if(rows.length>1000)fail('Word: таблица превышает 1000 строк.');
  }return '<table><tbody>'+rows.map(row=>'<tr>'+row.map(c=>'<td'+(c.colspan>1?' colspan="'+c.colspan+'"':'')+(c.rowspan>1?' rowspan="'+c.rowspan+'"':'')+'>'+c.html+'</td>').join('')+'</tr>').join('')+'</tbody></table>';
 }
 let screen={title:'Материал',chapter:'',blocks:[],source_text:''};
 function flush(){if(!screen.blocks.length)return;pages.push({title:screen.title,kind:'lesson',chapter:screen.chapter,html:renderBlocks(screen.blocks),sources:[],source_text:screen.source_text});screen={title:'Материал',chapter:'',blocks:[],source_text:''};if(pages.length>200)fail('Word превышает 200 экранов.');}
 const top=[];for(const n of Array.from(body.children)){if(n.localName==='sdt')top.push(...Array.from(child(n,'sdtContent')?.children||[]));else top.push(n);}
 for(const n of top){if(n.localName==='tbl'){screen.blocks.push({html:table(n)});continue;}if(n.localName!=='p')continue;const b=paragraph(n);
  if(b.style==='Title'&&!pages.length&&!screen.blocks.length){if(b.text)title=b.text.slice(0,255);continue;}
  if(b.style==='Chapter'){flush();screen.chapter=b.text;continue;}
  if(b.style==='Source'){screen.source_text+=(screen.source_text?'\n':'')+b.text;continue;}
  if(b.heading===1){flush();screen.title=b.text.slice(0,500)||'Материал';continue;}
  if(b.before&&screen.blocks.length){flush();screen.title='Экран '+(pages.length+1);}
  screen.blocks.push(b);if(b.after)flush();
 }flush();if(!pages.length||!pages.some(p=>{const t=document.createElement('template');t.innerHTML=p.html;return t.content.textContent.trim()||t.content.querySelector('img');}))fail('В документе Word нет текста или изображений для импорта.');
 const code='WORD-'+M.uid().replace(/-/g,'').slice(0,16);pages.forEach((p,i)=>p.id=code+'-'+(i+1));
 const course=M.normalizeCourse({data:{code,title,version:'1.0',pages},assets});
 const encoder=new TextEncoder(),source={...course,assets:Object.fromEntries(Object.entries(assets).map(([p,a])=>[p,{type:a.type}]))};
 if(pages.some(p=>encoder.encode(p.html).length>2000000)||encoder.encode(JSON.stringify(source)).length>5*MB)fail('Проект Word превышает размер экрана или 5 МБ текста. Разделите документ.');
 const errors=M.validateCourse(course);if(errors.length)fail(errors.slice(0,3).join('\n'));return course;
}
async function exportCourse(input){
 const course=M.normalizeCourse(input),errors=M.validateCourse(course);if(errors.length)fail(errors.slice(0,3).join('\n'));
 if(course.data.pages.length>200)fail('Word: разделите курс на документы до 200 экранов.');
 const z=new root.JSZip(),rels=[],images=new Map();let imageCount=0,drawingCount=0,assetSize=0;
 function relationship(type,target,external=false){const id='rId'+(rels.length+1);rels.push({id,type,target,external});return id;}
 for(const path of M.refs(course).filter(p=>course.assets[p]?.type.startsWith('image/'))){
  let bytes=M.toBytes(course.assets[path].data);
  if(course.assets[path].type==='image/webp'){
   const bitmap=await createImageBitmap(new Blob([bytes],{type:'image/webp'}));
   try{if(bitmap.width*bitmap.height>25000000)fail('Для Word уменьшите изображение WebP.');const canvas=document.createElement('canvas');canvas.width=bitmap.width;canvas.height=bitmap.height;canvas.getContext('2d').drawImage(bitmap,0,0);bytes=await new Promise((resolve,reject)=>canvas.toBlob(async blob=>blob?resolve(new Uint8Array(await blob.arrayBuffer())):reject(Error('Не удалось подготовить изображение Word.')),'image/png'));}finally{bitmap.close();}
  }
  const info=dimensions(imageInfo(bytes)),name='media/image'+(++imageCount)+'.'+info.ext;assetSize+=bytes.length;if(assetSize>90*MB)fail('Изображения Word превышают 90 МБ.');
  z.file('word/'+name,bytes);images.set(path,{...info,id:relationship('image',name),number:imageCount});
 }
 function run(text,style={}){
  if(!text)return '';let props='';if(style.bold)props+='<w:b/>';if(style.italic)props+='<w:i/>';if(style.underline)props+='<w:u w:val="single"/>';if(style.strike)props+='<w:strike/>';if(style.vertical)props+='<w:vertAlign w:val="'+style.vertical+'"/>';if(style.color)props+='<w:color w:val="'+style.color+'"/>';if(style.background)props+='<w:shd w:val=\"clear\" w:fill=\"'+style.background+'\"/>';if(style.size)props+='<w:sz w:val="'+style.size+'"/>';
  return '<w:r>'+(props?'<w:rPr>'+props+'</w:rPr>':'')+'<w:t xml:space="preserve">'+esc(text)+'</w:t></w:r>';
 }
 function inline(n,style={},depth=0){
  if(depth>40)fail('Слишком сложное оформление для Word.');if(n.nodeType===3)return run(n.textContent,style);if(n.nodeType!==1)return '';const tag=n.tagName.toLowerCase(),s={...style};
  if(['strong','b','th'].includes(tag)||+n.style.fontWeight>=600)s.bold=true;if(['em','i'].includes(tag)||n.style.fontStyle==='italic')s.italic=true;
  if(tag==='u'||n.style.textDecoration.includes('underline'))s.underline=true;if(['s','strike','del'].includes(tag))s.strike=true;if(tag==='sup')s.vertical='superscript';if(tag==='sub')s.vertical='subscript';
  const color=n.style.color.match(/^rgb\((\d+),\s*(\d+),\s*(\d+)\)$/);if(color)s.color=color.slice(1).map(x=>(+x).toString(16).padStart(2,'0')).join('');
  const background=n.style.backgroundColor.match(/^rgb\((\d+),\s*(\d+),\s*(\d+)\)$/);if(background)s.background=background.slice(1).map(x=>(+x).toString(16).padStart(2,'0')).join('');
  if(n.style.fontSize){const size=parseFloat(n.style.fontSize);if(size>=8&&size<=72)s.size=Math.round(size*(n.style.fontSize.endsWith('pt')?2:1.5));}
  if(tag==='br')return '<w:r><w:br/></w:r>';
  if(tag==='img'){
   const im=images.get(n.getAttribute('src'));if(!im)fail('Не найдено изображение для Word.');const ratio=Math.min(1,600/im.width,800/im.height),cx=Math.round(im.width*ratio*9525),cy=Math.round(im.height*ratio*9525),drawing=++drawingCount;
   return '<w:r><w:drawing><wp:inline><wp:extent cx="'+cx+'" cy="'+cy+'"/><wp:docPr id="'+drawing+'" name="Image'+drawing+'" descr="'+esc(n.alt||'')+'"/><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic><pic:nvPicPr><pic:cNvPr id="0" name="Image"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="'+im.id+'"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'+cx+'" cy="'+cy+'"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
  }
  const text=Array.from(n.childNodes).map(x=>inline(x,s,depth+1)).join('');if(tag==='a'&&safeLink(n.getAttribute('href')||'')){const id=relationship('hyperlink',n.getAttribute('href'),true);return '<w:hyperlink r:id="'+id+'">'+text+'</w:hyperlink>';}return text;
 }
 function paragraph(content,style='',extra=''){return '<w:p>'+((style||extra)?'<w:pPr>'+(style?'<w:pStyle w:val="'+style+'"/>':'')+extra+'</w:pPr>':'')+content+'</w:p>';}
 function blocks(parent,depth=0,list=null){
  if(depth>30)fail('Слишком сложная структура для Word.');let out='',pending='';const flush=()=>{if(pending){out+=paragraph(pending);pending='';}};
  for(const n of Array.from(parent.childNodes)){
   if(n.nodeType===3){if(n.textContent.trim())pending+=run(n.textContent);continue;}if(n.nodeType!==1)continue;const tag=n.tagName.toLowerCase();
   if(tag==='table'){
    flush();const occupied=new Map(),columns=Math.max(1,...Array.from(n.rows||[]).map(row=>Array.from(row.cells).reduce((sum,cell)=>sum+(cell.colSpan||1),0)));let table='<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/><w:tblBorders>'+['top','left','bottom','right','insideH','insideV'].map(x=>'<w:'+x+' w:val="single" w:sz="4" w:color="D8D6D0"/>').join('')+'</w:tblBorders></w:tblPr><w:tblGrid>'+Array.from({length:columns},()=>'<w:gridCol w:w=\"'+Math.floor(9000/columns)+'\"/>').join('')+'</w:tblGrid>';
    for(const row of Array.from(n.rows||[])){let col=0;table+='<w:tr>';const continuation=()=>{while(occupied.has(col)){const item=occupied.get(col);table+='<w:tc><w:tcPr>'+(item.span>1?'<w:gridSpan w:val="'+item.span+'"/>':'')+'<w:vMerge/></w:tcPr><w:p/></w:tc>';if(--item.left===0)occupied.delete(col);col+=item.span;}};
     for(const cell of Array.from(row.cells)){continuation();const span=Math.max(1,Math.min(50,cell.colSpan||1)),rows=Math.max(1,Math.min(1000,cell.rowSpan||1));table+='<w:tc><w:tcPr>'+(span>1?'<w:gridSpan w:val="'+span+'"/>':'')+(rows>1?'<w:vMerge w:val="restart"/>':'')+'</w:tcPr>'+(blocks(cell,depth+1)||'<w:p/>')+'</w:tc>';if(rows>1)occupied.set(col,{span,left:rows-1,new:true});col+=span;}
     continuation();for(const item of occupied.values())delete item.new;table+='</w:tr>';
    }out+=table+'</w:tbl>';continue;
   }
   if(tag==='ul'||tag==='ol'){flush();out+=blocks(n,depth+1,{id:tag==='ul'?1:2,level:Math.min(8,(list?.level??-1)+1)});continue;}
   if(tag==='li'){flush();const shallow=n.cloneNode(false);for(const c of Array.from(n.childNodes))if(c.nodeType!==1||!['ul','ol'].includes(c.tagName.toLowerCase()))shallow.append(c.cloneNode(true));out+=paragraph(Array.from(shallow.childNodes).map(c=>inline(c)).join(''),'','<w:numPr><w:ilvl w:val="'+(list?.level||0)+'"/><w:numId w:val="'+(list?.id||1)+'"/></w:numPr>');for(const sub of Array.from(n.children).filter(x=>['ul','ol'].includes(x.tagName.toLowerCase())))out+=blocks(sub,depth+1,{id:sub.tagName.toLowerCase()==='ul'?1:2,level:Math.min(8,(list?.level||0)+1)});continue;}
   if(/^h[1-6]$/.test(tag)||['p','blockquote','pre','figcaption'].includes(tag)){
    flush();const align=n.style.textAlign,extra=['left','center','right','justify'].includes(align)?'<w:jc w:val="'+(align==='justify'?'both':align)+'"/>':'';
    out+=paragraph(Array.from(n.childNodes).map(c=>inline(c)).join(''),/^h[1-6]$/.test(tag)?'Heading'+Math.max(2,+tag[1]):'',extra);continue;
   }
   if(['div','section','article','figure','button','details'].includes(tag)){flush();out+=blocks(n,depth+1);continue;}
   if(tag==='hr'){flush();out+=paragraph(run('────────────────'));continue;}pending+=inline(n);
  }flush();return out;
 }
 let body=paragraph(run(course.data.title),'Title');
 course.data.pages.forEach((p,i)=>{if(p.chapter)body+=paragraph(run(p.chapter),'Chapter');body+=paragraph(run(p.title),'Heading1',i?'<w:pageBreakBefore/>':'');const template=document.createElement('template');template.innerHTML=root.RCRich.sanitizeHTML(p.html);body+=blocks(template.content);if(p.source_text)body+=paragraph(run(p.source_text),'Source');});
 body+='<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134"/></w:sectPr>';
 const documentXML='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="'+W+'" xmlns:r="'+R+'" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><w:body>'+body+'</w:body></w:document>';
 if(new TextEncoder().encode(documentXML).length>10*MB)fail('Word: XML превышает 10 МБ. Разделите курс.');
 relationship('styles','styles.xml');relationship('numbering','numbering.xml');
 z.file('word/document.xml',documentXML);
 z.file('word/_rels/document.xml.rels','<?xml version="1.0"?><Relationships xmlns="'+PKG+'">'+rels.map(x=>'<Relationship Id="'+x.id+'" Type="'+R+'/'+x.type+'" Target="'+esc(x.target)+'"'+(x.external?' TargetMode="External"':'')+'/>').join('')+'</Relationships>');
 z.file('_rels/.rels','<Relationships xmlns="'+PKG+'"><Relationship Id="rId1" Type="'+R+'/officeDocument" Target="word/document.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>');
 z.file('docProps/core.xml','<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>'+esc(course.data.title)+'</dc:title><dc:creator>USTAR Academy</dc:creator></cp:coreProperties>');
 z.file('word/styles.xml','<w:styles xmlns:w="'+W+'"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/><w:sz w:val="24"/></w:rPr></w:rPrDefault></w:docDefaults>'+[['Normal','24'],['Title','40'],['Heading1','32'],['Heading2','28'],['Heading3','26'],['Source','20'],['Chapter','20']].map(([name,size])=>'<w:style w:type="paragraph" w:styleId="'+name+'"'+(name==='Normal'?' w:default="1"':'')+'><w:name w:val="'+name+'"/><w:pPr><w:spacing w:after="160"/>'+(name.startsWith('Heading')?'<w:keepNext/>':'')+'</w:pPr><w:rPr><w:sz w:val="'+size+'"/>'+(name.startsWith('Heading')||name==='Title'?'<w:b/>':'')+'</w:rPr></w:style>').join('')+'</w:styles>');
 z.file('word/numbering.xml','<w:numbering xmlns:w="'+W+'">'+[1,2].map(id=>'<w:abstractNum w:abstractNumId="'+id+'">'+Array.from({length:9},(_,level)=>'<w:lvl w:ilvl="'+level+'"><w:start w:val="1"/><w:numFmt w:val="'+(id===1?'bullet':'decimal')+'"/><w:lvlText w:val="'+(id===1?'•':'%'+(level+1)+'.')+'"/></w:lvl>').join('')+'</w:abstractNum><w:num w:numId="'+id+'"><w:abstractNumId w:val="'+id+'"/></w:num>').join('')+'</w:numbering>');
 const types=[...new Set([...images.values()].map(x=>x.ext))].map(ext=>'<Default Extension="'+ext+'" ContentType="image/'+(ext==='jpg'?'jpeg':ext)+'"/>').join('');
 z.file('[Content_Types].xml','<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'+types+'<Override PartName="/word/document.xml" ContentType="'+MIME+'"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/><Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>');
 const bytes=await z.generateAsync({type:'uint8array',compression:'DEFLATE',compressionOptions:{level:3}});if(bytes.length>25*MB)fail('Word превышает 25 МБ. Разделите курс.');return bytes;
}
root.StudioWord={importFile,exportCourse,inspectZip};
})(typeof window!=='undefined'?window:globalThis);
