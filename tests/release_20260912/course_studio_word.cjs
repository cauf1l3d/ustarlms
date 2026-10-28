// Losing a paragraph/image/style or accepting an active/oversized ZIP must fail this test.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {JSDOM}=require('jsdom');
const dir=path.resolve(__dirname,'../../moodle/local/ustar/course_studio');
const dom=new JSDOM('',{runScripts:'outside-only',url:'https://academy.example/studio/'}),w=dom.window;
w.TextEncoder=TextEncoder;w.TextDecoder=TextDecoder;w.Uint8Array=Uint8Array;w.ArrayBuffer=ArrayBuffer;
w.JSZip=require(dir+'/vendor/jszip.min.js');
for(const f of ['model.js','rich.js','word.js'])if(fs.existsSync(dir+'/'+f))w.eval(fs.readFileSync(dir+'/'+f,'utf8'));
const png='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jZLkAAAAASUVORK5CYII=';
const ns='http://schemas.openxmlformats.org/wordprocessingml/2006/main';
const xml=`<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="${ns}" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><w:body>
<w:p><w:pPr><w:pStyle w:val="Chapter"/></w:pPr><w:r><w:t>Как пройти курс</w:t></w:r></w:p>
<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Первый экран</w:t></w:r></w:p>
<w:p><w:r><w:rPr><w:b/><w:highlight w:val="yellow"/></w:rPr><w:t>Привет &amp; мир</w:t></w:r><w:r><w:t xml:space="preserve"> — обучение</w:t></w:r><w:hyperlink r:id="link1"><w:r><w:t>Источник</w:t></w:r></w:hyperlink></w:p>
<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>Первый пункт</w:t></w:r></w:p>
<w:tbl><w:tr><w:tc><w:p><w:r><w:t>Товар</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Цена</w:t></w:r></w:p></w:tc></w:tr></w:tbl>
<w:p><w:r><w:drawing><a:blip r:embed="image1"/></w:drawing></w:r></w:p>
<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Второй экран</w:t></w:r></w:p>
<w:p><w:r><w:rPr><w:i/></w:rPr><w:t>&lt;script&gt; — это текст</w:t></w:r></w:p>
</w:body></w:document>`;
const rels=`<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="image1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/picture.png"/><Relationship Id="link1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="https://example.org/source" TargetMode="External"/></Relationships>`;
async function fixture(change={}){const z=new w.JSZip();const files={
 '[Content_Types].xml':'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="png" ContentType="image/png"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
 'word/document.xml':xml,'word/_rels/document.xml.rels':rels,
 'word/numbering.xml':`<w:numbering xmlns:w="${ns}"><w:abstractNum w:abstractNumId="0"><w:lvl w:ilvl="0"><w:numFmt w:val="bullet"/></w:lvl></w:abstractNum><w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num></w:numbering>`,
 'docProps/core.xml':'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>Продажи</dc:title></cp:coreProperties>',...change};
 for(const[p,b]of Object.entries(files))z.file(p,b);z.file('word/media/picture.png',png,{base64:true});
 return z.generateAsync({type:'uint8array',compression:'DEFLATE'});
}
const file=(bytes,name='Продажи.docx')=>({name,size:bytes.length,arrayBuffer:async()=>bytes.buffer.slice(bytes.byteOffset,bytes.byteOffset+bytes.byteLength)});
const watchdog=setTimeout(()=>{console.error('DOCX_TEST_DID_NOT_COMPLETE');dom.window.close();process.exitCode=1;},10000);
(async()=>{
 assert(w.StudioWord,'Local DOCX codec is required');const P=w.StudioWord;
 const input=await fixture(),c=await P.importFile(file(input));
 assert.equal(c.data.title,'Продажи');assert.equal(c.data.pages.length,2);
 assert.equal(c.data.pages[0].chapter,'Как пройти курс');
 assert.equal(c.data.pages[0].title,'Первый экран');assert.equal(c.data.pages[1].title,'Второй экран');
 const doc=new JSDOM(c.data.pages[0].html).window.document;
 assert.equal(doc.querySelector('strong').textContent,'Привет & мир');
 assert.equal(doc.querySelector('span[style]').style.backgroundColor,'rgb(255, 255, 0)');
 assert(doc.querySelector('ul li').textContent.includes('Первый пункт'));
 assert.equal(doc.querySelectorAll('table td').length,2);assert.equal(doc.querySelector('td').textContent,'Товар');
 assert.equal(doc.querySelector('a').href,'https://example.org/source');
 const imagePath=doc.querySelector('img').getAttribute('src');assert.equal(c.assets[imagePath].type,'image/png');assert.equal(c.assets[imagePath].data,png);
 assert(c.data.pages[1].html.includes('&lt;script&gt;'));assert(!c.data.pages[1].html.includes('<script>'));
 assert.equal(w.RCModel.validateCourse(c).length,0);
 const output=await P.exportCourse(c),z=await w.JSZip.loadAsync(output);
 if(process.env.USTAR_STUDIO_ARTIFACTS){fs.mkdirSync(process.env.USTAR_STUDIO_ARTIFACTS,{recursive:true});fs.writeFileSync(path.join(process.env.USTAR_STUDIO_ARTIFACTS,'word-with-image.docx'),output);}
 const document=await z.file('word/document.xml').async('string');assert(document.includes('Привет &amp; мир'));assert(document.includes('w:drawing'));assert.equal(new w.DOMParser().parseFromString(document,'application/xml').getElementsByTagName('w:gridCol').length,2,'Word table requires a two-column grid');
 const again=await P.importFile(file(output,'Повтор.docx'));
 assert.equal(again.data.pages[0].chapter,'Как пройти курс');
 assert.equal(again.data.title,'Продажи');assert.equal(again.data.pages.length,2);
 assert(new JSDOM(again.data.pages[0].html).window.document.querySelector('img'));
 assert.equal(Object.values(again.assets)[0].data,png);
 const repeated=w.RCModel.normalizeCourse(c);repeated.data.pages[0].html+='<p><img src=\"'+imagePath+'\" alt=\"Второе размещение\"></p>';
 const multi=await w.JSZip.loadAsync(await P.exportCourse(repeated));const multiXML=new w.DOMParser().parseFromString(await multi.file('word/document.xml').async('string'),'application/xml');
 const ids=[...multiXML.getElementsByTagName('wp:docPr')].map(n=>n.getAttribute('id'));assert.equal(ids.length,2);assert.equal(new Set(ids).size,2,'each drawing instance needs its own Word id');
 await assert.rejects(()=>P.importFile(file(new Uint8Array([1,2,3]))),/DOCX|архив|Word/);
 await assert.rejects(async()=>P.importFile(file(await fixture({'word/document.xml':'<w:document xmlns:w=\"'+ns+'\"><w:body><w:p/></w:body></w:document>'}))),/нет текста|пуст/);
 await assert.rejects(()=>P.importFile({name:'big.docx',size:26*1024*1024,arrayBuffer:()=>{throw Error('read before limit');}}),/25/);
 await assert.rejects(async()=>P.importFile(file(await fixture({'word/document.xml':'<!DOCTYPE x [<!ENTITY x "bad">]><w:document xmlns:w="'+ns+'"><w:body><w:p><w:r><w:t>&x;</w:t></w:r></w:p></w:body></w:document>'}))),/XML|DTD|ENTITY|объект/);
 await assert.rejects(async()=>P.importFile(file(await fixture({'word/_rels/document.xml.rels':rels.replace('Target="media/picture.png"','Target="https://example.org/a.png" TargetMode="External"')}))),/внешн|изображен|связ/);
 await assert.rejects(async()=>P.importFile(file(await fixture({'word/embeddings/oleObject.bin':'ACTIVE'}))),/объект|активн|макрос/);
 // Tamper the central directory size: reject before inflating a declared 101MB file.
 const large=new Uint8Array(input),v=new DataView(large.buffer);let count=0;
 for(let i=0;i<large.length-46;i++)if(v.getUint32(i,true)===0x02014b50){v.setUint32(i+24,101*1024*1024,true);count++;break;}
 assert(count);await assert.rejects(()=>P.importFile(file(large)),/размер|МБ|распаков/);
 console.log('COURSE_STUDIO_WORD_OK Cyrillic formatting lists tables images roundtrip bounded unsafe rejection');clearTimeout(watchdog);dom.window.close();
})().catch(e=>{clearTimeout(watchdog);dom.window.close();console.error(e);process.exitCode=1;});
