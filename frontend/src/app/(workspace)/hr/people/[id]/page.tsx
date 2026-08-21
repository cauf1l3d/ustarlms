"use client";

import Link from "next/link";
import { FormEvent, useEffect, useState } from "react";
import { ArrowLeft, BookOpen, ClipboardCheck, Save, ShieldAlert, Star, Target, UserRoundX } from "lucide-react";
import { ws } from "@/lib/wsclient";
import { useWorkspace } from "@/components/WorkspaceProvider";
import { Empty, PageTitle, Progress, Readiness } from "@/components/ui";

interface Position { id: string; name: string; department: string; level: number }
interface Review { id:number; score:number; category:string; period:string; summary:string; reviewer:string; timecreated:number }
interface PersonData {
  person: { id:number; username:string; firstname:string; lastname:string; fullname:string; email:string; suspended:boolean; role:string; protected?:boolean; position: Position | null; department:{id:string;name:string}|null };
  courses:{id:number;name:string;progress:number;url:string}[]; avgProgress:number;
  skills:{id:string;name:string;category:string;progress:number;currentLevel:number;requiredLevel:number}[];
  nextPosition:Position|null; readiness:number; gaps:{id:string;name:string;current:number;required:number}[];
  reviews:Review[]; positions:Position[];
}

export default function PersonPage({ params }: { params: { id: string } }) {
  const { wsData } = useWorkspace();
  const [d, setD] = useState<PersonData|null>(null);
  const [saving,setSaving]=useState(false);
  const [msg,setMsg]=useState("");
  const [reviewMsg,setReviewMsg]=useState("");
  const [form,setForm]=useState({username:"",firstname:"",lastname:"",email:"",positionid:"",suspended:false});
  const [review,setReview]=useState({score:4,category:"performance",period:"",summary:""});

  const load=()=>ws<PersonData>("hr_person",{userid:Number(params.id)}).then((x)=>{
    setD(x);
    setForm({username:x.person.username,firstname:x.person.firstname,lastname:x.person.lastname,email:x.person.email,positionid:x.person.position?.id||"",suspended:x.person.suspended});
  });
  useEffect(()=>{if(wsData?.capabilities?.hr) load();},[wsData,params.id]);

  const save=async(e:FormEvent)=>{
    e.preventDefault(); if(d?.person.protected)return;
    setSaving(true);setMsg("");
    try{await ws("hr_save_person",{userid:Number(params.id),...form,password:""});setMsg("Профиль сохранён");load();}
    catch(e){setMsg(e instanceof Error?e.message:"Ошибка");}
    finally{setSaving(false);}
  };
  const saveReview=async(e:FormEvent)=>{
    e.preventDefault(); if(!review.summary.trim() || d?.person.protected)return;
    setSaving(true);setReviewMsg("");
    try{
      await ws("hr_save_review",{userid:Number(params.id),...review,summary:review.summary.trim()});
      setReview({score:4,category:"performance",period:"",summary:""});
      setReviewMsg("Оценка добавлена в историю"); load();
    }catch(e){setReviewMsg(e instanceof Error?e.message:"Ошибка");}
    finally{setSaving(false);}
  };

  if(wsData&&!wsData.capabilities?.hr)return <Empty title="Нет доступа" text="Нужна роль USTAR HR." mascot/>;
  if(!d)return <Empty title="Открываем профиль" text="Собираем обучение, навыки, оценки и карьерный маршрут…" mascot/>;
  const protectedPerson=!!d.person.protected;
  const avgReview=d.reviews.length ? (d.reviews.reduce((a,r)=>a+r.score,0)/d.reviews.length).toFixed(1) : "—";

  return <>
    <PageTitle kicker="Карточка сотрудника" title={d.person.fullname} subtitle={`${d.person.position?.name || "Должность USTAR не назначена"}${d.person.department ? ` · ${d.person.department.name}` : ""}`} />
    <Link href="/hr/people" className="mb-4 inline-flex items-center gap-1 text-xs font-bold text-mut"><ArrowLeft size={14}/> Сотрудники</Link>

    {protectedPerson&&<div className="mb-5 flex items-start gap-3 rounded-xl border border-orange-200 bg-orange-50 p-4 text-sm"><ShieldAlert className="mt-0.5 shrink-0 text-orange-800"/><div><strong className="block">Защищённая системная учётная запись</strong><span className="text-mut">HR может видеть учебные результаты, но не менять профиль, должность, статус или оценки USTAR/Site Administrator.</span></div></div>}

    <div className="grid gap-6 xl:grid-cols-[1fr_360px]">
      <div className="space-y-6">
        <section className="card p-5 sm:p-6">
          <div className="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4"><div><div className="eyebrow">Прогресс</div><div className="mt-2 text-3xl font-black">{d.avgProgress}%</div></div><div><div className="eyebrow">Курсы</div><div className="mt-2 text-3xl font-black">{d.courses.length}</div></div><div><div className="eyebrow">Оценка HR</div><div className="mt-2 text-3xl font-black">{avgReview}</div></div><div><div className="eyebrow">Роль</div><div className="mt-2 text-lg font-black">{d.person.role}</div></div></div>
          {d.nextPosition ? <div className="border-t border-black/10 pt-5"><div className="mb-2 flex items-center justify-between"><div><div className="eyebrow">Следующая ступень</div><h3 className="mt-1 text-xl font-black">{d.nextPosition.name}</h3></div><Target className="text-mut"/></div><Readiness value={d.readiness}/>{d.gaps.length>0&&<div className="mt-4 flex flex-wrap gap-2">{d.gaps.map((g)=><span key={g.id} className="pill bg-[#FFE6C8] text-brand">{g.name}: {g.current}/{g.required}</span>)}</div>}</div> : <div className="border-t border-black/10 pt-5 text-sm font-bold text-mut">Следующая карьерная ступень не задана.</div>}
        </section>

        <section><div className="mb-3"><div className="eyebrow">Компетенции</div><h2 className="mt-1 text-xl font-black">Навыки</h2></div><div className="grid gap-3 md:grid-cols-2">{d.skills.map((s)=><div key={s.id} className="card p-4"><div className="flex justify-between gap-3"><div><div className="font-black">{s.name}</div><div className="mt-1 text-xs text-mut">{s.category}</div></div><strong>{s.currentLevel}/{s.requiredLevel}</strong></div><Progress value={s.progress} className="mt-4"/></div>)}{d.skills.length===0&&<Empty title="Нет навыков" text="Сначала назначьте корректную должность USTAR."/>}</div></section>

        <section>
          <div className="mb-3 flex items-end justify-between"><div><div className="eyebrow">Оценка результатов</div><h2 className="mt-1 text-xl font-black">HR review</h2></div><span className="pill bg-page text-mut">история сохраняется</span></div>
          {!protectedPerson&&<form onSubmit={saveReview} className="card mb-3 p-4 sm:p-5"><div className="grid gap-3 md:grid-cols-[160px_1fr]"><label><span className="eyebrow">Оценка</span><select className="input mt-2" value={review.score} onChange={e=>setReview({...review,score:Number(e.target.value)})}>{[5,4,3,2,1].map(x=><option key={x} value={x}>{x} / 5</option>)}</select></label><label><span className="eyebrow">Период</span><input className="input mt-2" placeholder="Например: август 2026" value={review.period} onChange={e=>setReview({...review,period:e.target.value})}/></label></div><div className="mt-3 flex gap-2 overflow-x-auto">{[["performance","Результативность"],["development","Развитие"],["service","Сервис"],["leadership","Лидерство"]].map(([id,label])=><button type="button" key={id} onClick={()=>setReview({...review,category:id})} className={`btn shrink-0 !min-h-9 !px-3 !text-xs ${review.category===id?"btn-primary":"btn-quiet"}`}>{label}</button>)}</div><textarea className="input mt-3 min-h-28" placeholder="Конкретный результат, наблюдение, сильная сторона и зона развития…" value={review.summary} onChange={e=>setReview({...review,summary:e.target.value})}/>{reviewMsg&&<div className="mt-2 text-xs font-bold text-mut">{reviewMsg}</div>}<button disabled={saving||!review.summary.trim()} className="btn btn-accent mt-3"><ClipboardCheck size={16}/>Зафиксировать оценку</button></form>}
          <div className="space-y-3">{d.reviews.map(r=><article key={r.id} className="card p-4 sm:p-5"><div className="flex flex-wrap items-start justify-between gap-3"><div><div className="flex items-center gap-2"><div className="flex gap-0.5">{[1,2,3,4,5].map(x=><Star key={x} size={15} className={x<=r.score?"fill-accent text-accent":"text-black/15"}/>)}</div><strong>{r.score}/5</strong></div><div className="mt-2 text-xs font-bold uppercase tracking-wider text-mut">{r.category}{r.period?` · ${r.period}`:""}</div></div><div className="text-right text-xs text-mut">{r.reviewer}<br/>{new Date(r.timecreated*1000).toLocaleDateString("ru-RU")}</div></div><p className="mt-3 whitespace-pre-wrap text-sm leading-6">{r.summary||"Без комментария"}</p></article>)}{d.reviews.length===0&&<div className="card p-6 text-sm text-mut">Оценок HR пока нет.</div>}</div>
        </section>

        <section><div className="mb-3"><div className="eyebrow">Учебный след</div><h2 className="mt-1 text-xl font-black">Курсы</h2></div><div className="card divide-y divide-black/10">{d.courses.map((c)=><a key={c.id} href={c.url} target="_blank" rel="noreferrer" className="flex items-center gap-3 p-4 hover:bg-page"><BookOpen size={17}/><div className="min-w-0 flex-1"><div className="truncate font-bold">{c.name}</div><Progress value={c.progress} className="mt-2"/></div><strong>{c.progress}%</strong></a>)}{d.courses.length===0&&<div className="p-6 text-sm text-mut">Нет доступных курсов.</div>}</div></section>
      </div>

      <aside>
        <form onSubmit={save} className="card sticky top-6 overflow-hidden"><div className="industrial-rule"/><div className="p-5"><div className="eyebrow">HR управление</div><h2 className="mt-1 text-xl font-black">Профиль</h2><div className="mt-5 space-y-3"><input disabled={protectedPerson} className="input" value={form.username} onChange={(e)=>setForm({...form,username:e.target.value})}/><input disabled={protectedPerson} className="input" value={form.firstname} onChange={(e)=>setForm({...form,firstname:e.target.value})}/><input disabled={protectedPerson} className="input" value={form.lastname} onChange={(e)=>setForm({...form,lastname:e.target.value})}/><input disabled={protectedPerson} className="input" type="email" value={form.email} onChange={(e)=>setForm({...form,email:e.target.value})}/><select disabled={protectedPerson} className="input" value={form.positionid} onChange={(e)=>setForm({...form,positionid:e.target.value})}><option value="">Без должности USTAR</option>{d.positions.map((p)=><option key={p.id} value={p.id}>{p.name}</option>)}</select><label className="flex items-start gap-3 rounded-lg border border-black/10 p-3 text-sm"><input disabled={protectedPerson} type="checkbox" className="mt-1" checked={form.suspended} onChange={(e)=>setForm({...form,suspended:e.target.checked})}/><span><strong className="block">Приостановить аккаунт</strong><span className="text-xs text-mut">История обучения сохраняется. Это безопаснее удаления.</span></span></label></div>{msg&&<div className="mt-3 text-sm font-bold text-mut">{msg}</div>}<button disabled={saving||protectedPerson} className="btn btn-primary mt-4 w-full"><Save size={16}/>{saving?"Сохраняем…":"Сохранить"}</button>{!form.suspended&&!protectedPerson&&<button type="button" onClick={()=>setForm({...form,suspended:true})} className="btn btn-danger mt-2 w-full"><UserRoundX size={16}/>Архивировать сотрудника</button>}</div></form>
      </aside>
    </div>
  </>;
}
