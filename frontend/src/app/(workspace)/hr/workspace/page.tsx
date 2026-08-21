"use client";

import Link from "next/link";
import { useEffect, useMemo, useState } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import {
  BarChart3, BookOpen, ChevronRight, CircleAlert, Database, Gamepad2, Grid3X3,
  Layers3, Search, SlidersHorizontal, Sparkles, UserRoundCog, UsersRound, X,
} from "lucide-react";
import { ws } from "@/lib/wsclient";
import { useWorkspace } from "@/components/WorkspaceProvider";
import { Empty } from "@/components/ui";
import HRWorkspaceCanvas from "@/components/HRWorkspaceCanvas";

type Person = {
  id:number; username:string; fullname:string; firstname:string; lastname:string; email:string;
  profileDepartment:string; suspended:boolean; lastaccess:number; positionid:string; position:string;
  department:string; protected:boolean;
};
type Position = { id:string; name:string; department:string; level:number; next?:string|null; ishead?:boolean; peopleCount:number; skillCount:number };
type Department = { id:string; name:string };
type Skill = { id:string; name:string; category:string; courseRefs:string[]; coursesFound:number; positions:number };
type Course = { id:number; idnumber:string; name:string; shortname:string; visible:boolean; modules:number };
type Game = { id:number; code:string; title:string; type:string; department:string; active:boolean; questions:number };
type WorkspaceData = {
  people:Person[]; positions:Position[]; departments:Department[]; skills:Skill[]; matrix:Record<string,Record<string,number>>;
  courses:Course[]; games:Game[];
  stats:{people:number;assigned:number;unassigned:number;positions:number;heads:number;skills:number;linkedCourses:number;linkedModules:number;games:number;activeGames:number;questions:number;attempts30:number;gameAccuracy:number};
  completeness:{academy:number;peopleAssigned:number;positionsMatrix:number;skillsLearning:number;coursesResolved:number;gamesReady:number};
  gaps:{unassignedPeople:Person[];positionsWithoutMatrix:{id:string;name:string}[];skillsWithoutLearning:{id:string;name:string}[];missingCourses:string[];gamesWithoutQuestions:{id:number;title:string}[]};
};

type Tab = "org"|"people"|"skills"|"matrix"|"ladder"|"learning"|"games"|"data";
const TABS:{id:Tab;label:string;icon:any}[] = [
  {id:"org",label:"Оргструктура",icon:Layers3}, {id:"people",label:"Люди",icon:UsersRound},
  {id:"skills",label:"Навыки",icon:Sparkles}, {id:"matrix",label:"Матрица",icon:Grid3X3},
  {id:"ladder",label:"Лестницы",icon:BarChart3}, {id:"learning",label:"Обучение",icon:BookOpen},
  {id:"games",label:"Игры",icon:Gamepad2}, {id:"data",label:"Наполнение",icon:Database},
];

export default function HRWorkspacePage(){
  const {wsData}=useWorkspace();
  const router=useRouter();
  const pathname=usePathname();
  const searchParams=useSearchParams();
  const requestedView=searchParams.get("view") as Tab|null;
  const validTabs=new Set<Tab>(TABS.map(t=>t.id));
  const [data,setData]=useState<WorkspaceData|null>(null);
  const [tab,setTab]=useState<Tab>(requestedView&&validTabs.has(requestedView)?requestedView:"org");
  const [query,setQuery]=useState("");
  const [dept,setDept]=useState("");
  const [quick,setQuick]=useState(false);
  const [saving,setSaving]=useState(false);
  const [message,setMessage]=useState("");
  const [draft,setDraft]=useState<Record<number,string>>({});

  const load=()=>ws<WorkspaceData>("hr_workspace").then(d=>{setData(d);setDraft(Object.fromEntries(d.people.filter(p=>!p.positionid&&!p.protected).map(p=>[p.id,""])));});
  useEffect(()=>{if(wsData?.capabilities?.hr)load();},[wsData]);
  useEffect(()=>{if(requestedView&&validTabs.has(requestedView)&&requestedView!==tab)setTab(requestedView);},[requestedView]);
  const changeTab=(id:Tab)=>{setTab(id);const qs=new URLSearchParams(searchParams.toString());qs.set("view",id);router.replace(`${pathname}?${qs.toString()}`,{scroll:false});};
  if(wsData&&!wsData.capabilities?.hr)return <Empty title="Нет доступа" text="USTAR HR Workspace доступен только системной роли USTAR HR." mascot/>;
  if(!data)return <Empty title="Собираем живую модель компании" text="Читаем сотрудников Moodle, позиции USTAR, навыки, курсы и Game Hub…" mascot/>;

  const depName=(id:string)=>data.departments.find(d=>d.id===id)?.name||id||"Без отдела";
  const filteredPeople=data.people.filter(p=>(!dept||p.department===dept)&&(!query||`${p.fullname} ${p.username} ${p.email} ${p.position}`.toLowerCase().includes(query.toLowerCase())));
  const filteredPositions=data.positions.filter(p=>!dept||p.department===dept);
  const unassigned=data.people.filter(p=>!p.positionid&&!p.protected&&!p.suspended);

  const saveAssignments=async()=>{
    const assignments=Object.entries(draft).filter(([,positionid])=>positionid).map(([userid,positionid])=>({userid:Number(userid),positionid}));
    if(!assignments.length){setMessage("Выберите хотя бы одну должность");return;}
    setSaving(true);setMessage("");
    try{const r=await ws<{updated:number;skipped:number;errors:any[]}>("hr_bulk_assign",{assignmentsjson:JSON.stringify(assignments)});setMessage(`Сохранено: ${r.updated}${r.skipped?` · пропущено ${r.skipped}`:""}${r.errors?.length?` · ошибок ${r.errors.length}`:""}`);await load();}
    catch(e){setMessage(e instanceof Error?e.message:"Ошибка сохранения");}
    finally{setSaving(false);}
  };

  return <div className="hr-workspace-shell">
    <section className="hrw-topbar">
      <div className="flex min-w-0 items-center gap-3"><div className="hrw-bolt">ϟ</div><div className="min-w-0"><div className="text-[17px] font-black tracking-[.16em]">USTAR</div><div className="truncate text-[10px] font-bold text-white/45">Организационный workspace · LIVE</div></div></div>
      <div className="ml-auto flex items-center gap-2"><Link href="/hr/content" className="hrw-top-btn">Навыки и обучение</Link><Link href="/hr/checklists" className="hrw-top-btn">Чек-листы</Link><button onClick={()=>setQuick(true)} className="hrw-top-btn hrw-top-btn-accent"><UserRoundCog size={15}/> Быстро назначить должности</button><Link href="/hr/people" className="hrw-top-btn">Карточки сотрудников</Link></div>
    </section>

    <section className="hrw-tabs">
      <div className="flex min-w-max gap-1">{TABS.map(({id,label,icon:Icon})=><button key={id} onClick={()=>changeTab(id)} className={`hrw-tab ${tab===id?"is-active":""}`}><Icon size={14}/>{label}</button>)}</div>
      <div className="ml-auto hidden items-center gap-2 xl:flex"><span className="hrw-live-dot"/> <span className="text-[11px] font-bold text-mut">данные из Moodle / USTAR DB</span></div>
    </section>

    <section className="hrw-subbar">
      <label className="hrw-search"><Search size={15}/><input value={query} onChange={e=>setQuery(e.target.value)} placeholder="Поиск: имя, должность, логин, навык"/></label>
      <span className="hrw-divider"/>
      <label className="hrw-filter"><span>Отдел</span><select value={dept} onChange={e=>setDept(e.target.value)}><option value="">Все отделы</option>{data.departments.map(d=><option key={d.id} value={d.id}>{d.name}</option>)}</select></label>
      <div className="ml-auto hidden gap-2 lg:flex"><span className="hrw-chip">{data.stats.people} людей</span><span className={`hrw-chip ${data.stats.unassigned?"is-warn":""}`}>{data.stats.unassigned} без позиции</span><span className="hrw-chip is-dark">Готовность {data.completeness.academy}%</span></div>
    </section>

    <section className="hrw-stage">
      {tab==="org"&&<HRWorkspaceCanvas data={data} query={query} dept={dept}/>} 
      {tab==="people"&&<PeopleView data={data} people={filteredPeople}/>} 
      {tab==="skills"&&<SkillsView data={data}/>} 
      {tab==="matrix"&&<MatrixView data={data} positions={filteredPositions}/>} 
      {tab==="ladder"&&<LadderView data={data} positions={filteredPositions}/>} 
      {tab==="learning"&&<LearningView data={data}/>} 
      {tab==="games"&&<GamesView data={data}/>} 
      {tab==="data"&&<DataView data={data} setTab={changeTab} openQuick={()=>setQuick(true)}/>} 
    </section>

    {quick&&<div className="fixed inset-0 z-[80]" role="dialog" aria-modal="true"><button className="absolute inset-0 bg-black/45" onClick={()=>setQuick(false)} aria-label="Закрыть"/><aside className="absolute inset-y-0 right-0 flex w-full max-w-[560px] flex-col bg-white shadow-2xl"><header className="flex items-start justify-between bg-brand p-5 text-white"><div><div className="eyebrow !text-accent">Быстрое наполнение</div><h2 className="mt-1 text-xl font-black">Назначить ustar_position</h2><p className="mt-1 text-xs text-white/55">Только реальные Moodle-пользователи без позиции. Сохранение идёт одним транзакционным запросом.</p></div><button onClick={()=>setQuick(false)} className="flex h-10 w-10 items-center justify-center rounded-md border border-white/15"><X size={18}/></button></header><div className="flex-1 overflow-y-auto p-4"><div className="mb-3 rounded-lg border-l-4 border-accent bg-page p-3 text-xs leading-5"><strong>{unassigned.length} активных сотрудников</strong> пока не имеют USTAR-позиции. Защищённые системные аккаунты сюда не попадают.</div><div className="space-y-2">{unassigned.map(p=><div key={p.id} className="rounded-lg border border-black/10 p-3"><div className="mb-2 flex items-start justify-between gap-3"><div><div className="font-black">{p.fullname}</div><div className="mt-0.5 text-[11px] text-mut">{p.username}{p.profileDepartment?` · профиль: ${p.profileDepartment}`:""}</div></div><span className="text-[10px] font-bold text-mut">#{p.id}</span></div><select className="input !min-h-10 !py-1.5 text-sm" value={draft[p.id]||""} onChange={e=>setDraft({...draft,[p.id]:e.target.value})}><option value="">— выбрать должность —</option>{data.departments.map(d=><optgroup key={d.id} label={d.name}>{data.positions.filter(x=>x.department===d.id).sort((a,b)=>a.level-b.level).map(pos=><option key={pos.id} value={pos.id}>{pos.name}</option>)}</optgroup>)}</select></div>)}{!unassigned.length&&<div className="p-8 text-center text-sm text-mut">У всех активных сотрудников уже есть USTAR-позиция.</div>}</div></div><footer className="border-t border-black/10 p-4"><div className="mb-2 min-h-5 text-xs font-bold text-mut">{message}</div><button onClick={saveAssignments} disabled={saving||!unassigned.length} className="btn btn-accent w-full">{saving?"Сохраняем…":`Сохранить выбранные позиции`}</button></footer></aside></div>}
  </div>;
}

function OrgView({data,query,dept}:{data:WorkspaceData;query:string;dept:string}){
  const departments=data.departments.filter(d=>!dept||d.id===dept);
  const q=query.trim().toLowerCase();
  return <div className="hrw-canvas"><div className="min-w-[980px] p-7"><div className="mb-5 flex items-center justify-between"><div><div className="eyebrow">Живая структура</div><h1 className="mt-1 text-2xl font-black">Люди → должности → отделы</h1></div><div className="text-right text-xs text-mut">Карточка сотрудника открывает реальные результаты<br/>Изменения позиции сразу отражаются в USTAR</div></div><div className="grid gap-5" style={{gridTemplateColumns:`repeat(${Math.min(Math.max(departments.length,1),4)}, minmax(250px,1fr))`}}>{departments.map(d=>{const positions=data.positions.filter(p=>p.department===d.id).sort((a,b)=>(b.ishead?1:0)-(a.ishead?1:0)||b.level-a.level);const people=data.people.filter(p=>p.department===d.id);return <section key={d.id} className="hrw-dept-lane"><header><div><div className="text-[10px] font-black uppercase tracking-[.14em] text-mut">Отдел</div><h2>{d.name}</h2></div><span>{people.length}</span></header><div className="space-y-3 p-3">{positions.map(pos=>{const ps=data.people.filter(p=>p.positionid===pos.id).filter(p=>!q||`${p.fullname} ${pos.name}`.toLowerCase().includes(q));if(q&&!ps.length&&!pos.name.toLowerCase().includes(q))return null;return <div key={pos.id} className="hrw-pos-block"><div className="hrw-pos-head"><div><div className="text-[10px] font-black uppercase tracking-[.12em] text-accent">L{pos.level}{pos.ishead?" · HEAD":""}</div><div className="mt-1 text-sm font-black text-white">{pos.name}</div></div><div className="text-right"><strong className="text-lg text-accent">{ps.length}</strong><div className="text-[9px] text-white/40">людей</div></div></div><div className="divide-y divide-black/10 bg-white">{ps.map(p=><Link key={p.id} href={`/hr/people/${p.id}`} className="group flex items-center gap-3 p-2.5 hover:bg-accentSoft"><div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-black/10 bg-page text-xs font-black">{initials(p.fullname)}</div><div className="min-w-0 flex-1"><div className="truncate text-xs font-black">{p.fullname}</div><div className="mt-0.5 truncate text-[10px] text-mut">{p.email||p.username}</div></div><ChevronRight size={14} className="text-black/25 group-hover:text-brand"/></Link>)}{!ps.length&&<div className="flex items-center gap-2 p-3 text-[11px] font-bold text-mut"><span className="h-2 w-2 rounded-full bg-accent"/> Вакансия / нет назначенных сотрудников</div>}</div></div>})}</div></section>})}</div></div></div>;
}

function PeopleView({data,people}:{data:WorkspaceData;people:Person[]}){return <div className="p-5 sm:p-7"><div className="mb-4 flex items-end justify-between"><div><div className="eyebrow">Реальная база людей</div><h2 className="mt-1 text-2xl font-black">Сотрудники</h2></div><Link href="/hr/people" className="btn btn-quiet">Полное HR управление</Link></div><div className="overflow-x-auto border border-line bg-white"><table className="hrw-table min-w-[900px]"><thead><tr><th>Сотрудник</th><th>Должность</th><th>Отдел</th><th>Последний вход</th><th>Состояние</th></tr></thead><tbody>{people.map(p=><tr key={p.id}><td><Link href={`/hr/people/${p.id}`} className="font-black hover:underline">{p.fullname}</Link><div className="text-[10px] text-mut">{p.username} · {p.email}</div></td><td>{p.position||<span className="font-bold text-orange-700">Не назначена</span>}</td><td>{data.departments.find(d=>d.id===p.department)?.name||"—"}</td><td>{p.lastaccess?new Date(p.lastaccess*1000).toLocaleDateString("ru-RU"):"—"}</td><td>{p.protected?"Защищён":p.suspended?"Приостановлен":"Активен"}</td></tr>)}</tbody></table></div></div>}

function SkillsView({data}:{data:WorkspaceData}){return <div className="p-5 sm:p-7"><div className="mb-5"><div className="eyebrow">Skill graph</div><h2 className="mt-1 text-2xl font-black">Навыки и покрытие обучением</h2></div><div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">{data.skills.map(s=><Link href={`/hr/content?skill=${encodeURIComponent(s.id)}`} key={s.id} className="hrw-skill-card card-hover"><div className="flex items-start justify-between gap-3"><div><div className="text-[10px] font-black uppercase tracking-[.12em] text-mut">{s.category}</div><h3>{s.name}</h3></div><span className={s.courseRefs.length?"is-ok":"is-gap"}>{s.coursesFound}/{s.courseRefs.length}</span></div><div className="mt-4 grid grid-cols-2 gap-2 text-xs"><div><b>{s.positions}</b><span>должностей</span></div><div><b>{s.courseRefs.length}</b><span>курсов</span></div></div>{s.courseRefs.length>0&&<div className="mt-3 flex flex-wrap gap-1">{s.courseRefs.map(c=><span key={c} className="hrw-mini-chip">{c}</span>)}</div>}<div className="mt-3 flex items-center gap-1 text-[9px] font-black uppercase tracking-wider text-mut">Открыть в Content Studio <ChevronRight size={12}/></div></Link>)}</div></div>}

function MatrixView({data,positions}:{data:WorkspaceData;positions:Position[]}){return <div className="overflow-auto"><table className="hrw-matrix"><thead><tr><th>Должность</th>{data.skills.map(s=><th key={s.id}><div>{s.name}</div></th>)}</tr></thead><tbody>{positions.map(p=><tr key={p.id}><th><Link href={`/hr/workspace?view=org&position=${encodeURIComponent(p.id)}`} className="block hover:underline">{p.name}<small>{data.departments.find(d=>d.id===p.department)?.name}</small></Link></th>{data.skills.map(s=>{const level=data.matrix[p.id]?.[s.id]||0;return <td key={s.id} className={level?`lvl-${Math.min(level,5)}`:""}>{level||""}</td>})}</tr>)}</tbody></table><div className="sticky bottom-0 border-t border-line bg-white/95 p-3 text-xs text-mut backdrop-blur">Матрица отражает production-структуру USTAR. Редактирование требований остаётся в Суперадмин → Матрица, чтобы HR случайно не менял модель доступа.</div></div>}

function LadderView({data,positions}:{data:WorkspaceData;positions:Position[]}){const byDept=data.departments.filter(d=>positions.some(p=>p.department===d.id));return <div className="p-5 sm:p-7"><div className="mb-5"><div className="eyebrow">Career architecture</div><h2 className="mt-1 text-2xl font-black">Карьерные цепочки</h2></div><div className="space-y-6">{byDept.map(d=>{const rows=positions.filter(p=>p.department===d.id).sort((a,b)=>a.level-b.level);return <section key={d.id}><h3 className="mb-3 text-sm font-black uppercase tracking-[.1em]">{d.name}</h3><div className="flex min-w-max items-stretch overflow-x-auto pb-2">{rows.map((p,i)=><div key={p.id} className="flex items-center"><Link href={`/hr/workspace?view=org&position=${encodeURIComponent(p.id)}`} className="hrw-ladder-card card-hover"><div className="text-3xl font-black text-accent">{p.level}</div><div className="mt-2 font-black text-white">{p.name}</div><div className="mt-3 border-t border-white/10 pt-2 text-[10px] text-white/50">{p.skillCount} навыков · {p.peopleCount} сотрудников</div><div className="mt-2 text-[9px] font-black uppercase tracking-wider text-accent">Открыть должность →</div></Link>{i<rows.length-1&&<div className="h-[3px] w-8 bg-accent"/>}</div>)}</div></section>})}</div></div>}

function LearningView({data}:{data:WorkspaceData}){return <div className="p-5 sm:p-7"><div className="mb-5 flex items-end justify-between"><div><div className="eyebrow">Moodle content</div><h2 className="mt-1 text-2xl font-black">База обучения</h2></div><div className="text-right"><div className="text-3xl font-black">{data.stats.linkedModules}</div><div className="text-[10px] font-black uppercase tracking-wider text-mut">модулей в связанных курсах</div></div></div><div className="overflow-x-auto border border-line bg-white"><table className="hrw-table min-w-[800px]"><thead><tr><th>Курс</th><th>IDNUMBER</th><th>Уроков / активностей</th><th>Видимость</th></tr></thead><tbody>{data.courses.map(c=><tr key={c.id}><td className="font-black">{c.name}</td><td className="font-mono text-xs">{c.idnumber}</td><td><strong>{c.modules}</strong></td><td>{c.visible?"Опубликован":"Скрыт"}</td></tr>)}{!data.courses.length&&<tr><td colSpan={4}>Нет Moodle-курсов, связанных с навыками USTAR.</td></tr>}</tbody></table></div>{data.gaps.missingCourses.length>0&&<div className="mt-4 rounded-lg border-l-4 border-orange-500 bg-orange-50 p-4 text-sm"><strong>Ссылки на отсутствующие idnumber:</strong> {data.gaps.missingCourses.join(", ")}</div>}</div>}

function GamesView({data}:{data:WorkspaceData}){return <div className="p-5 sm:p-7"><div className="mb-5 grid gap-3 sm:grid-cols-3"><Metric label="Игры" value={data.stats.games} sub={`${data.stats.activeGames} активных`}/><Metric label="Вопросы" value={data.stats.questions} sub="в production Game Hub"/><Metric label="Точность · 30 дней" value={`${data.stats.gameAccuracy}%`} sub={`${data.stats.attempts30} ответов`}/></div><div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">{data.games.map(g=><Link href="/games" key={g.id} className="hrw-game-card card-hover"><div className="flex items-start justify-between"><div><div className="text-[10px] font-black uppercase tracking-[.12em] text-white/40">{g.type}</div><h3>{g.title}</h3></div><span className={g.active?"is-live":""}>{g.active?"LIVE":"DRAFT"}</span></div><div className="mt-5 flex items-end justify-between"><div><div className="text-3xl font-black text-accent">{g.questions}</div><div className="text-[10px] text-white/45">вопросов</div></div><div className="text-right text-xs text-white/50">{g.department||"Все отделы"}</div></div></Link>)}</div></div>}

function DataView({data,setTab,openQuick}:{data:WorkspaceData;setTab:(t:Tab)=>void;openQuick:()=>void}){const rows=[
  ["Люди с USTAR-позицией",data.completeness.peopleAssigned,"people",`${data.stats.assigned} / ${data.stats.people}`],
  ["Должности с матрицей",data.completeness.positionsMatrix,"matrix",`${data.stats.positions-data.gaps.positionsWithoutMatrix.length} / ${data.stats.positions}`],
  ["Навыки с обучением",data.completeness.skillsLearning,"skills",`${data.stats.skills-data.gaps.skillsWithoutLearning.length} / ${data.stats.skills}`],
  ["Связанные Moodle-курсы найдены",data.completeness.coursesResolved,"learning",`${data.stats.linkedCourses} курса`],
  ["Игровая база готова",data.completeness.gamesReady,"games",`${data.stats.questions} вопросов`],
] as [string,number,Tab,string][];return <div className="p-5 sm:p-7"><div className="mb-7 grid gap-5 lg:grid-cols-[300px_1fr]"><div className="hrw-readiness"><div className="eyebrow !text-white/40">Готовность USTAR Academy</div><div className="mt-5 text-7xl font-black text-accent">{data.completeness.academy}%</div><div className="mt-2 text-sm text-white/55">Единый показатель наполнения людей, матрицы, обучения и игр.</div></div><div className="space-y-3">{rows.map(([label,value,target,detail])=><button key={label} onClick={()=>target==="people"?openQuick():setTab(target)} className="hrw-fill-row"><div className="flex items-center justify-between gap-3"><strong>{label}</strong><span>{detail}</span></div><div className="mt-2 h-2 overflow-hidden rounded-full bg-black/10"><div className="h-full bg-accent" style={{width:`${value}%`}}/></div><div className="mt-1 text-right text-xs font-black">{value}%</div></button>)}</div></div><div className="grid gap-4 xl:grid-cols-2"><Gap title="Люди без позиции" count={data.stats.unassigned} items={data.gaps.unassignedPeople.map(p=>p.fullname)} action="Назначить" onClick={openQuick}/><Gap title="Должности без матрицы" count={data.gaps.positionsWithoutMatrix.length} items={data.gaps.positionsWithoutMatrix.map(x=>x.name)} action="Открыть матрицу" onClick={()=>setTab("matrix")}/><Gap title="Навыки без обучения" count={data.gaps.skillsWithoutLearning.length} items={data.gaps.skillsWithoutLearning.map(x=>x.name)} action="Открыть навыки" onClick={()=>setTab("skills")}/><Gap title="Игры без вопросов" count={data.gaps.gamesWithoutQuestions.length} items={data.gaps.gamesWithoutQuestions.map(x=>x.title)} action="Открыть игры" onClick={()=>setTab("games")}/></div></div>}

function Metric({label,value,sub}:{label:string;value:string|number;sub:string}){return <div className="hrw-metric"><span>{label}</span><b>{value}</b><small>{sub}</small></div>}
function Gap({title,count,items,action,onClick}:{title:string;count:number;items:string[];action:string;onClick:()=>void}){return <div className="border border-line bg-white p-4"><div className="flex items-start justify-between gap-3"><div><div className="eyebrow">Контроль качества</div><h3 className="mt-1 text-lg font-black">{title}</h3></div><span className={`hrw-gap-count ${count?"is-warn":""}`}>{count}</span></div><div className="mt-3 min-h-12 text-xs leading-5 text-mut">{items.length?items.slice(0,5).join(" · "):"Проблем не найдено"}{items.length>5?` · ещё ${items.length-5}`:""}</div><button onClick={onClick} className="btn btn-quiet mt-3 !min-h-9 !px-3 !text-xs">{action}</button></div>}
function initials(name:string){return name.split(/\s+/).filter(Boolean).slice(0,2).map(x=>x[0]).join("").toUpperCase()||"U"}
