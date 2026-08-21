"use client";

import { useEffect, useMemo, useState } from "react";
import { ArrowUpRight, BookOpen, CheckCircle2, PlayCircle } from "lucide-react";
import { ws } from "@/lib/wsclient";
import { Empty, PageTitle, Progress } from "@/components/ui";

interface Course { id:number; name:string; skills:string[]; progress:number; url:string }

export default function CoursesPage(){
  const[courses,setCourses]=useState<Course[]|null>(null); const[filter,setFilter]=useState<"all"|"active"|"done">("all");
  useEffect(()=>{ws<{courses:Course[]}>("dashboard").then(d=>setCourses(d.courses));},[]);
  const shown=useMemo(()=>courses?.filter(c=>filter==="all"?true:filter==="done"?c.progress>=100:c.progress<100)||[],[courses,filter]);
  if(!courses)return <Empty title="Загружаем обучение" text="Собираем курсы, связанные с навыками вашей должности…" mascot/>;
  const active=courses.filter(c=>c.progress<100).length,done=courses.filter(c=>c.progress>=100).length;
  return <>
    <PageTitle kicker="Обучение" title="Мой учебный план" subtitle="Курсы появляются из матрицы навыков вашей должности. Здесь видно, что начать, что продолжить и что уже закрыто."/>
    <div className="mb-5 flex flex-wrap items-center gap-2">{([['all',`Все · ${courses.length}`],['active',`В работе · ${active}`],['done',`Завершено · ${done}`]] as const).map(([k,label])=><button key={k} onClick={()=>setFilter(k)} className={`btn !min-h-9 !px-3 ${filter===k?'btn-primary':'btn-quiet'}`}>{label}</button>)}</div>
    {shown.length===0?<Empty title="В этой категории пусто" text="Попробуйте другой фильтр или проверьте назначение навыков для вашей должности."/>:<div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{shown.map((c,index)=><a key={c.id} href={c.url} target="_blank" rel="noreferrer" className="card card-hover group overflow-hidden"><div className={`relative h-28 p-4 ${c.progress>=100?'bg-[#DDEFE5]':'bg-brand text-white'}`}><div className="flex items-start justify-between"><span className={`pill ${c.progress>=100?'bg-white text-[#256A45]':'bg-accent text-brand'}`}>{c.progress>=100?<><CheckCircle2 size={13}/> закрыт</>:c.progress>0?<><PlayCircle size={13}/> в работе</>:<><BookOpen size={13}/> новый</>}</span><ArrowUpRight size={18} className={c.progress>=100?'text-[#256A45]':'text-white/45'}/></div><div className={`absolute bottom-3 right-4 text-4xl font-black tracking-[-.06em] ${c.progress>=100?'text-[#256A45]/15':'text-white/10'}`}>{String(index+1).padStart(2,'0')}</div></div><div className="p-5"><h2 className="min-h-12 text-lg font-black leading-6 tracking-[-.02em]">{c.name}</h2><div className="mt-4 flex flex-wrap gap-1.5">{c.skills.length?c.skills.slice(0,3).map(s=><span key={s} className="pill bg-page text-mut">{s}</span>):<span className="text-xs text-mut">Общий курс</span>}</div><div className="mt-5"><div className="mb-1.5 flex justify-between text-xs"><span className="font-bold text-mut">Прогресс</span><strong>{c.progress}%</strong></div><Progress value={c.progress}/></div></div></a>)}</div>}
  </>;
}
