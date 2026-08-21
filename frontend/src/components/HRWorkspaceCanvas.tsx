"use client";

import Link from "next/link";
import { useEffect, useMemo, useRef, useState } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import {
  BookOpen, BriefcaseBusiness, ChevronDown, ChevronRight, ExternalLink, Focus,
  GraduationCap, Maximize2, Route, Search, UserRound, X, ZoomIn, ZoomOut,
} from "lucide-react";
import { ws } from "@/lib/wsclient";

type Person = {
  id:number; username:string; fullname:string; firstname:string; lastname:string; email:string;
  profileDepartment:string; suspended:boolean; lastaccess:number; positionid:string; position:string;
  department:string; protected:boolean;
};
type Position = { id:string; name:string; department:string; level:number; next?:string|null; ishead?:boolean; peopleCount:number; skillCount:number };
type Department = { id:string; name:string };
type Skill = { id:string; name:string; category:string; courseRefs:string[]; coursesFound:number; positions:number };
type WorkspaceData = {
  people:Person[]; positions:Position[]; departments:Department[]; skills:Skill[];
  matrix:Record<string,Record<string,number>>;
};

type PersonCourse = {id:number;name:string;idnumber:string;skills:string[];progress:number;url:string};
type PersonSkill = {id:string;name:string;category:string;progress:number;currentLevel:number;requiredLevel:number};
type PersonReview = {id:number;score:number;category:string;period:string;summary:string;reviewer:string;timecreated:number};
type PersonDetail = {
  person:{id:number;username:string;firstname:string;lastname:string;fullname:string;email:string;suspended:boolean;lastaccess:number;role:string;position:Position|null;department:Department|null;protected:boolean};
  courses:PersonCourse[]; avgProgress:number; skills:PersonSkill[]; nextPosition:Position|null; readiness:number;
  gaps:{id:string;name:string;current:number;required:number}[]; reviews:PersonReview[];
};

type Transform = {x:number;y:number;scale:number};

export default function HRWorkspaceCanvas({data,query,dept}:{data:WorkspaceData;query:string;dept:string}){
  const router=useRouter();
  const pathname=usePathname();
  const searchParams=useSearchParams();
  const viewportRef=useRef<HTMLDivElement|null>(null);
  const stageRef=useRef<HTMLDivElement|null>(null);
  const dragRef=useRef<{id:number;x:number;y:number;ox:number;oy:number}|null>(null);
  const [tr,setTr]=useState<Transform>({x:28,y:24,scale:.82});
  const [collapsed,setCollapsed]=useState<Set<string>>(new Set());
  const [showVacancies,setShowVacancies]=useState(true);
  const [personId,setPersonId]=useState<number|null>(null);
  const [positionId,setPositionId]=useState<string|null>(null);
  const [personDetail,setPersonDetail]=useState<PersonDetail|null>(null);
  const [loadingPerson,setLoadingPerson]=useState(false);

  const departments=useMemo(()=>data.departments.filter(d=>!dept||d.id===dept),[data.departments,dept]);
  const q=query.trim().toLowerCase();

  const updateRoute=(patch:Record<string,string|null>)=>{
    const qs=new URLSearchParams(searchParams.toString());
    Object.entries(patch).forEach(([k,v])=>v===null?qs.delete(k):qs.set(k,v));
    const suffix=qs.toString();
    router.replace(`${pathname}${suffix?`?${suffix}`:""}`,{scroll:false});
  };

  const openPerson=(id:number)=>{
    setPositionId(null);setPersonId(id);setPersonDetail(null);setLoadingPerson(true);
    updateRoute({person:String(id),position:null,view:"org"});
    ws<PersonDetail>("hr_person",{userid:id}).then(setPersonDetail).finally(()=>setLoadingPerson(false));
  };
  const openPosition=(id:string)=>{setPersonId(null);setPersonDetail(null);setPositionId(id);updateRoute({position:id,person:null,view:"org"});};
  const closeInspector=()=>{setPersonId(null);setPositionId(null);setPersonDetail(null);updateRoute({person:null,position:null});};

  useEffect(()=>{
    const p=Number(searchParams.get("person")||0); const pos=searchParams.get("position");
    if(p>0 && p!==personId){openPerson(p);return;}
    if(pos && pos!==positionId){setPersonId(null);setPositionId(pos);}
    // eslint-disable-next-line react-hooks/exhaustive-deps
  },[]);

  const fit=()=>{
    const vp=viewportRef.current, st=stageRef.current;if(!vp||!st)return;
    const w=st.offsetWidth,h=st.offsetHeight;if(!w||!h)return;
    const scale=Math.max(.28,Math.min(1,(vp.clientWidth-54)/w,(vp.clientHeight-54)/h));
    setTr({scale,x:Math.max(24,(vp.clientWidth-w*scale)/2),y:26});
  };
  const center=()=>setTr(t=>({...t,x:28,y:24}));
  const zoom=(factor:number)=>{
    const vp=viewportRef.current;if(!vp)return;
    setTr(t=>{const ns=Math.max(.28,Math.min(1.65,t.scale*factor));const cx=vp.clientWidth/2,cy=vp.clientHeight/2;return {scale:ns,x:cx-(cx-t.x)/t.scale*ns,y:cy-(cy-t.y)/t.scale*ns};});
  };

  useEffect(()=>{const id=requestAnimationFrame(()=>fit());return()=>cancelAnimationFrame(id);},[dept]);

  const pointerDown=(e:React.PointerEvent<HTMLDivElement>)=>{
    if((e.target as HTMLElement).closest("button,a,input,select,[data-interactive]"))return;
    e.currentTarget.setPointerCapture(e.pointerId);dragRef.current={id:e.pointerId,x:e.clientX,y:e.clientY,ox:tr.x,oy:tr.y};
    e.currentTarget.classList.add("is-dragging");
  };
  const pointerMove=(e:React.PointerEvent<HTMLDivElement>)=>{const d=dragRef.current;if(!d||d.id!==e.pointerId)return;setTr(t=>({...t,x:d.ox+e.clientX-d.x,y:d.oy+e.clientY-d.y}));};
  const pointerUp=(e:React.PointerEvent<HTMLDivElement>)=>{if(dragRef.current?.id===e.pointerId)dragRef.current=null;e.currentTarget.classList.remove("is-dragging");};
  const wheel=(e:React.WheelEvent<HTMLDivElement>)=>{
    e.preventDefault();
    if(e.ctrlKey||e.metaKey){
      const vp=viewportRef.current;if(!vp)return;const r=vp.getBoundingClientRect();const mx=e.clientX-r.left,my=e.clientY-r.top;
      setTr(t=>{const factor=e.deltaY<0?1.09:.92;const ns=Math.max(.28,Math.min(1.65,t.scale*factor));return {scale:ns,x:mx-(mx-t.x)/t.scale*ns,y:my-(my-t.y)/t.scale*ns};});
    }else setTr(t=>({...t,x:t.x-e.deltaX-(e.shiftKey?e.deltaY:0),y:t.y-(e.shiftKey?0:e.deltaY)}));
  };

  const cols=dept?1:Math.min(4,Math.max(2,Math.ceil(Math.sqrt(Math.max(departments.length,1)))));
  const position=positionId?data.positions.find(p=>p.id===positionId)||null:null;

  return <div className="hrw-canvas-wrap">
    <div className="hrw-canvas-toolbar">
      <div className="flex min-w-0 items-center gap-2"><Focus size={15}/><span className="font-black">Канвас оргструктуры</span><span className="hidden text-mut lg:inline">перетаскивание · колесо — панорама · Ctrl/⌘ + колесо — масштаб</span></div>
      <div className="ml-auto flex items-center gap-1">
        <button onClick={()=>setShowVacancies(v=>!v)} className={`hrw-tool-chip ${showVacancies?"is-on":""}`}>Вакансии</button>
        <button onClick={()=>setCollapsed(new Set(departments.map(d=>d.id)))} className="hrw-tool-chip">Свернуть</button>
        <button onClick={()=>setCollapsed(new Set())} className="hrw-tool-chip">Развернуть</button>
      </div>
    </div>

    <div ref={viewportRef} className="hrw-pan-viewport" onPointerDown={pointerDown} onPointerMove={pointerMove} onPointerUp={pointerUp} onPointerCancel={pointerUp} onWheel={wheel}>
      <div ref={stageRef} className="hrw-pan-stage" style={{transform:`translate3d(${tr.x}px,${tr.y}px,0) scale(${tr.scale})`,gridTemplateColumns:`repeat(${cols}, 320px)`}}>
        {departments.map(d=>{
          const positions=data.positions.filter(p=>p.department===d.id).sort((a,b)=>(b.ishead?1:0)-(a.ishead?1:0)||a.level-b.level||a.name.localeCompare(b.name,"ru"));
          const people=data.people.filter(p=>p.department===d.id&&!p.suspended);
          const isCollapsed=collapsed.has(d.id);
          const visiblePositions=positions.filter(pos=>{
            const ps=data.people.filter(p=>p.positionid===pos.id&&!p.suspended);
            if(!showVacancies&&!ps.length)return false;
            if(!q)return true;
            return pos.name.toLowerCase().includes(q)||ps.some(p=>`${p.fullname} ${p.username} ${p.email}`.toLowerCase().includes(q));
          });
          if(q&&!visiblePositions.length)return null;
          return <section key={d.id} className="hrw-dept-lane hrw-dept-lane-canvas">
            <button data-interactive onClick={()=>setCollapsed(prev=>{const n=new Set(prev);n.has(d.id)?n.delete(d.id):n.add(d.id);return n})} className="hrw-dept-button">
              <div><div className="text-[9px] font-black uppercase tracking-[.14em] text-mut">Отдел</div><h2>{d.name}</h2><div className="mt-1 text-[9px] font-bold text-mut">{positions.length} должн. · {people.length} чел.</div></div>
              <div className="flex items-center gap-2"><span>{people.length}</span><ChevronDown size={16} className={`transition ${isCollapsed?"-rotate-90":""}`}/></div>
            </button>
            {!isCollapsed&&<div className="hrw-lane-flow">
              {visiblePositions.map(pos=>{
                const ps=data.people.filter(p=>p.positionid===pos.id&&!p.suspended).filter(p=>!q||`${p.fullname} ${p.username} ${p.email} ${pos.name}`.toLowerCase().includes(q));
                return <div key={pos.id} className={`hrw-pos-block ${positionId===pos.id?"is-selected":""}`}>
                  <button data-interactive onClick={()=>openPosition(pos.id)} className="hrw-pos-head w-full text-left">
                    <div><div className="text-[9px] font-black uppercase tracking-[.12em] text-accent">L{pos.level}{pos.ishead?" · HEAD":""}</div><div className="mt-1 text-[13px] font-black leading-tight text-white">{pos.name}</div><div className="mt-2 text-[9px] text-white/40">{pos.skillCount} навыков {pos.next?"· есть следующий шаг":""}</div></div>
                    <div className="text-right"><strong className="text-lg text-accent">{ps.length}</strong><div className="text-[8px] text-white/40">людей</div><ChevronRight size={14} className="ml-auto mt-2 text-white/35"/></div>
                  </button>
                  <div className="bg-white p-2">
                    <div className="space-y-2">{ps.map(p=><button data-interactive key={p.id} onClick={()=>openPerson(p.id)} className={`hrw-person-badge ${personId===p.id?"is-selected":""}`}>
                      <div className="hrw-person-medal">{initials(p.fullname)}</div>
                      <div className="min-w-0 flex-1 text-left"><div className="hrw-person-field"><i>Фамилия:</i><b>{p.lastname||"—"}</b></div><div className="hrw-person-field"><i>Имя:</i><b>{p.firstname||"—"}</b></div><div className="hrw-person-post">{pos.name}</div><div className="truncate text-[8px] text-mut">{p.email||p.username}</div></div>
                      <ChevronRight size={13} className="shrink-0 text-black/25"/>
                      <div className="hrw-person-strip">L{pos.level}<span>{pos.id}</span></div>
                    </button>)}</div>
                    {!ps.length&&showVacancies&&<button data-interactive onClick={()=>openPosition(pos.id)} className="hrw-vacancy-card"><span>ВАКАНСИЯ</span><b>{pos.name}</b><small>нет назначенных сотрудников</small></button>}
                  </div>
                </div>
              })}
            </div>}
          </section>
        })}
      </div>

      <div className="hrw-zoom-controls" data-interactive>
        <button title="Приблизить" onClick={()=>zoom(1.18)}><ZoomIn size={16}/></button>
        <button title="Отдалить" onClick={()=>zoom(.84)}><ZoomOut size={16}/></button>
        <button title="Вписать структуру" onClick={fit}><Maximize2 size={16}/></button>
        <button title="Вернуть начало" onClick={center}><Focus size={16}/></button>
        <span>{Math.round(tr.scale*100)}%</span>
      </div>
      <div className="hrw-canvas-hint">Потяни пустое место · колесо = перемещение · Ctrl/⌘ + колесо = zoom</div>
    </div>

    {(personId||position)&&<div className="hrw-inspector-layer"><button className="hrw-inspector-backdrop" onClick={closeInspector} aria-label="Закрыть инспектор"/><aside className="hrw-inspector">
      <header><div><div className="eyebrow !text-accent">USTAR Inspector</div><h2>{personId?(personDetail?.person.fullname||"Карточка сотрудника"):(position?.name||"Должность")}</h2><p>{personId?(personDetail?.person.position?.name||"Загрузка данных…"):(position?depName(data,position.department):"")}</p></div><button onClick={closeInspector}><X size={18}/></button></header>
      <div className="hrw-inspector-body">
        {personId&&<PersonInspector loading={loadingPerson} detail={personDetail}/>} 
        {position&&<PositionInspector data={data} position={position}/>} 
      </div>
    </aside></div>}
  </div>;
}

function PersonInspector({loading,detail}:{loading:boolean;detail:PersonDetail|null}){
  if(loading||!detail)return <div className="p-5 text-sm font-bold text-mut">Собираем обучение, навыки, карьеру и оценки…</div>;
  const p=detail.person;
  return <div className="space-y-5">
    <section className="hrw-inspector-card"><div className="flex items-center gap-3"><div className="hrw-inspector-avatar">{initials(p.fullname)}</div><div><div className="text-lg font-black">{p.fullname}</div><div className="text-xs text-mut">{p.username} · {p.email}</div></div></div><div className="mt-4 grid grid-cols-2 gap-2"><Mini label="Должность" value={p.position?.name||"Не назначена"}/><Mini label="Отдел" value={p.department?.name||"—"}/><Mini label="Обучение" value={`${detail.avgProgress}%`}/><Mini label="Готовность дальше" value={detail.nextPosition?`${detail.readiness}%`:"—"}/></div><Link href={`/hr/people/${p.id}`} className="btn btn-primary mt-4 w-full"><UserRound size={16}/>Полная карточка сотрудника</Link></section>
    {detail.nextPosition&&<section className="hrw-inspector-card"><div className="eyebrow">Карьерный следующий шаг</div><div className="mt-2 flex items-center justify-between gap-3"><div><div className="font-black">{detail.nextPosition.name}</div><div className="mt-1 text-xs text-mut">готовность {detail.readiness}%</div></div><Route size={24}/></div>{detail.gaps.length>0&&<div className="mt-3 space-y-1">{detail.gaps.slice(0,5).map(g=><div key={g.id} className="flex justify-between rounded border border-black/10 px-2 py-1.5 text-xs"><span>{g.name}</span><b>{g.current}/{g.required}</b></div>)}</div>}</section>}
    <section className="hrw-inspector-card"><div className="eyebrow">Навыки</div><div className="mt-3 space-y-2">{detail.skills.map(s=><div key={s.id}><div className="flex items-center justify-between gap-2 text-xs"><b>{s.name}</b><span>{s.currentLevel}/{s.requiredLevel} · {s.progress}%</span></div><div className="mt-1 h-1.5 overflow-hidden rounded bg-black/10"><div className="h-full bg-accent" style={{width:`${Math.min(100,s.progress)}%`}}/></div></div>)}{!detail.skills.length&&<div className="text-xs text-mut">Для должности пока нет навыков.</div>}</div></section>
    <section className="hrw-inspector-card"><div className="eyebrow">Обучение</div><div className="mt-3 space-y-2">{detail.courses.slice(0,6).map(c=><a key={c.id} href={c.url} target="_blank" rel="noreferrer" className="group block rounded border border-black/10 p-2.5 hover:border-brand"><div className="flex items-center gap-2"><GraduationCap size={15}/><b className="min-w-0 flex-1 truncate text-xs">{c.name}</b><span className="text-xs font-black">{c.progress}%</span><ExternalLink size={12}/></div></a>)}{!detail.courses.length&&<div className="text-xs text-mut">Связанных курсов пока нет.</div>}</div></section>
  </div>;
}

function PositionInspector({data,position}:{data:WorkspaceData;position:Position}){
  const req=data.matrix[position.id]||{};
  const skills=Object.entries(req).map(([id,level])=>({skill:data.skills.find(s=>s.id===id),level})).filter(x=>x.skill);
  const people=data.people.filter(p=>p.positionid===position.id&&!p.suspended);
  const next=position.next?data.positions.find(p=>p.id===position.next):null;
  const courseCount=new Set(skills.flatMap(x=>x.skill?.courseRefs||[])).size;
  return <div className="space-y-5"><section className="hrw-inspector-card"><div className="grid grid-cols-2 gap-2"><Mini label="Уровень" value={`L${position.level}`}/><Mini label="Сотрудников" value={String(people.length)}/><Mini label="Навыков" value={String(skills.length)}/><Mini label="Курсов" value={String(courseCount)}/></div>{next&&<div className="mt-3 rounded border-l-4 border-accent bg-page p-3"><div className="text-[9px] font-black uppercase tracking-wider text-mut">Следующая ступень</div><div className="mt-1 font-black">{next.name}</div></div>}<div className="mt-3 grid gap-2"><Link href={`/hr/content?position=${encodeURIComponent(position.id)}`} className="btn btn-accent"><BookOpen size={16}/>Настроить навыки и обучение</Link><Link href={`/hr/workspace?view=matrix&position=${encodeURIComponent(position.id)}`} className="btn btn-quiet"><BriefcaseBusiness size={16}/>Показать в матрице</Link></div></section><section className="hrw-inspector-card"><div className="eyebrow">Требуемые навыки</div><div className="mt-3 space-y-2">{skills.map(({skill,level})=><Link href={`/hr/content?skill=${encodeURIComponent(skill!.id)}&position=${encodeURIComponent(position.id)}`} key={skill!.id} className="flex items-center justify-between rounded border border-black/10 p-2.5 text-xs hover:border-brand"><div><b>{skill!.name}</b><div className="mt-0.5 text-[9px] text-mut">{skill!.category} · {skill!.courseRefs.length} курсов</div></div><span className="flex h-7 w-7 items-center justify-center rounded bg-accent font-black">{level}</span></Link>)}{!skills.length&&<div className="text-xs text-mut">Матрица для этой должности пока не заполнена.</div>}</div></section><section className="hrw-inspector-card"><div className="eyebrow">Люди на должности</div><div className="mt-3 space-y-1">{people.map(p=><Link key={p.id} href={`/hr/people/${p.id}`} className="flex items-center gap-2 rounded border border-black/10 p-2.5 hover:bg-page"><div className="hrw-person-medal !h-8 !w-8">{initials(p.fullname)}</div><div className="min-w-0"><div className="truncate text-xs font-black">{p.fullname}</div><div className="truncate text-[9px] text-mut">{p.email||p.username}</div></div></Link>)}{!people.length&&<div className="text-xs text-mut">Вакансия / сотрудников нет.</div>}</div></section></div>;
}

function Mini({label,value}:{label:string;value:string}){return <div className="rounded border border-black/10 bg-page p-2.5"><div className="text-[8px] font-black uppercase tracking-wider text-mut">{label}</div><div className="mt-1 text-xs font-black">{value}</div></div>}
function depName(data:WorkspaceData,id:string){return data.departments.find(d=>d.id===id)?.name||id||"Без отдела"}
function initials(name:string){return name.split(/\s+/).filter(Boolean).slice(0,2).map(x=>x[0]).join("").toUpperCase()||"U"}
