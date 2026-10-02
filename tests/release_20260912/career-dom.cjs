const fs=require('fs'),path=require('path'),assert=require('assert/strict');
const {JSDOM}=require('jsdom'),Mustache=require('mustache');
const root=process.argv[2]||path.join(__dirname,'../../moodle/local/ustar');
const source=fs.readFileSync(path.join(root,'templates/route_career.mustache'),'utf8');
let n=0;function check(ok,s){assert.ok(ok,s);n++;}
const catalogue=JSON.parse(fs.readFileSync(path.join(root,'data/consultant_grades.json'),'utf8'));
const partials={'local_ustar/career_grades':fs.readFileSync(path.join(root,'templates/career_grades.mustache'),'utf8')};
const gradeView=(id)=>({hasgrade:!!id,gradepositionname:'Продавец-кассир',gradename:catalogue.grades.find(g=>g.id===id)?.name||'',grades:catalogue.grades.map(g=>({...g,current:g.id===id}))});
const base={hasposition:true,retailgrades:true,consultantcurrent:true,currentname:'Продавец-кассир',confirmedskills:0,requiredskills:4,careergrades:gradeView('consultant')};
const render=(data)=>Mustache.render(source,data,partials);
const html=render(base),dom=new JSDOM(html),d=dom.window.document;
check(d.querySelectorAll('.u-consultant-grade').length===5,'five grades');
check(d.querySelectorAll('.u-consultant-grade.is-current').length===1,'one current grade');
check(d.querySelector('.u-consultant-grade.is-current h3').textContent==='Консультант','cashier is consultant');
check(!d.querySelector('.u-career-stairs'),'no duplicate staff ladder for retail');
check(d.querySelectorAll('button[type=submit]').length===1,'single continuation retained');
for(const text of ['Мотивационные грейды в документе','Грейды для консультантов.docx','назначение на следующую должность оформляет HR','Ступени, критерии и оплата приведены'])check(!html.includes(text),'removed explanation: '+text);
for(const grade of catalogue.grades){
  check(d.body.textContent.includes(grade.name),'published grade: '+grade.id);
  check(d.body.textContent.includes(grade.pay),'published pay: '+grade.id);
  for(const item of grade.criteria)check(d.body.textContent.includes(item.text),'published criterion: '+grade.id);
}
const other=new JSDOM(render({...base,retailgrades:false,consultantcurrent:false,careergrades:gradeView('')}));
check(!other.window.document.querySelector('.u-consultant-ladder'),'unrelated roles retain existing ladder');
check(!!other.window.document.querySelector('.u-career-stairs'),'existing nonretail ladder retained');
const senior=new JSDOM(render({...base,consultantcurrent:false,careergrades:gradeView('')}));
check(!senior.window.document.querySelector('.is-current'),'no invented personal grade on unrelated retail role');
const changed=new JSDOM(render({...base,careergrades:gradeView(catalogue.grades[2].id)}));
check(changed.window.document.querySelector('.is-current h3').textContent===catalogue.grades[2].name,'selected grade follows shared view model');
console.log('PASS DOM assertions='+n);
