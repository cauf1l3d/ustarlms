const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
const dir=path.resolve(__dirname,'../../moodle/local/ustar/course_studio');
global.JSZip=require(dir+'/vendor/jszip.min.js');const M=require(dir+'/model.js');
require(dir+'/cloud.js');
const legacy={font:'Arial',headingFont:'Arial',fontSize:17,headingSize:34,lineHeight:1.65,ink:'#2b2b2b',accent:'#ebc500',paper:'#ffffff',background:'#ededed'};
const course=()=>M.normalizeCourse({data:{code:'COLORS',title:'Оформление',version:'1.0',pages:[{id:'one',title:'Экран',html:'<p>Материал</p>'}]},theme:{...legacy}});
(async()=>{
 const old=course();old.theme={...legacy};const before=JSON.stringify(old);
 const sync=new global.RCCloud.CloudSync({model:M,getCourses:()=>[old]});
 assert.equal((await sync.manifest(old)).fingerprint,crypto.createHash('sha256').update(before).digest('hex'),'legacy server fingerprint must survive normalization');
 assert.equal(typeof M.navigationTheme,'function','course navigation palette resolver is required');
 assert.deepEqual(M.navigationTheme(old),{background:'#2b2b2b',text:'#ffffff',active:'#474747',accent:'#ebc500',activeText:'#ffffff'});
 assert.equal(JSON.stringify(old),before,'display defaults must not mutate legacy projects');
 const c=course();Object.assign(c.theme,{navBackground:'#112233',navText:'#ddeeff',navActive:'#fafafa',navAccent:'#aa5500'});
 assert.deepEqual(M.validateCourse(c),[]);
 assert.equal(M.navigationTheme(c).activeText,'#000000','light active item needs dark text');
 const history=new M.History(c);c.theme.navBackground='#334455';history.capture(c);history.undo(c);assert.equal(c.theme.navBackground,'#112233');
 const portable=M.parseProject(M.serializeProject([c]))[0];assert.equal(portable.theme.navAccent,'#aa5500');
 for(const key of ['navBackground','navText','navActive','navAccent'])for(const bad of ['red','#123',null,17,'#fff;}</style><script>bad()</script>']){
  const invalid=course();invalid.theme[key]=bad;assert(M.validateCourse(invalid).length,key+' must reject non-HEX input');
 }
 const runtime={};require('node:vm').runInNewContext(fs.readFileSync(dir+'/runtime.js','utf8'),{window:runtime});
 M.setRuntime(runtime.RC_RUNTIME.css,runtime.RC_RUNTIME.js);
 const files=await M.buildFiles(c),css=new TextDecoder().decode(files['style.css']);
 for(const value of ['--nav-bg:#112233','--nav-text:#ddeeff','--nav-active:#fafafa','--nav-accent:#aa5500','--nav-active-ink:#000000'])assert(css.includes(value),value+' in final course');
 const imported=(await M.importScorm(await M.exportScorm(c)))[0];assert.equal(imported.theme.navBackground,'#112233');
 console.log('COURSE_STUDIO_THEME_OK legacy fingerprint optional validation history projects SCORM palette');
})().catch(e=>{console.error(e);process.exitCode=1;});
