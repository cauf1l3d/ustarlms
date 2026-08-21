"use client";
import { useEffect, useMemo, useState } from "react";
import { CheckCircle2, ClipboardCheck, MessageSquareText, Save } from "lucide-react";
import { ws } from "@/lib/wsclient";
import { Empty, PageTitle } from "@/components/ui";

type Item={id:string;title:string};
type Section={id:string;title:string;items:Item[]};
type Checklist={id:string;title:string;description:string;recurrence:string;sections:Section[];today:{status:string;done:number;total:number;score:number;comment:string;completedAt:number}};
type Data={date:string;positionid:string;checklists:Checklist[]};

export default function ChecklistsPage(){
  const[data,setData]=useState<Data|null>(null);const[selected,setSelected]=useState(0);const[answers,setAnswers]=useState<Record<string,{done:boolean;comment:string}>>({});const[comment,setComment]=useState("");const[saving,setSaving]=useState(false);const[msg,setMsg]=useState("");
  const load=()=>ws<Data>("checklists").then(d=>{setData(d);const c=d.checklists[selected]||d.checklists[0];if(c){const a:Record<string,{done:boolean;comment:string}>={};c.sections.flatMap(s=>s.items).forEach(i=>a[i.id]={done:false,comment:""});setAnswers(a);setComment(c.today?.comment||"");}});
  useEffect(()=>{load();},[]);
  const choose=(i:number)=>{setSelected(i);setMsg("");const c=data?.checklists[i];if(c){const a:Record<string,{done:boolean;comment:string}>={};c.sections.flatMap(s=>s.items).forEach(x=>a[x.id]={done:false,comment:""});setAnswers(a);setComment(c.today?.comment||"");}};
  if(!data)return <Empty title="Загружаем чек-листы" text="Сверяем вашу должность и ежедневные стандарты…" mascot/>;
  if(!data.positionid)return <><PageTitle kicker="Операционные стандарты" title="Чек-листы" subtitle="Чек-листы назначаются по официальной USTAR-позиции."/><Empty title="Сначала нужна должность USTAR" text="После назначения ustar_position здесь автоматически появятся актуальные чек-листы должности." mascot/></>;
  if(!data.checklists.length)return <><PageTitle kicker="Операционные стандарты" title="Чек-листы" subtitle="Ежедневные обязанности, контроль открытия/закрытия и другие повторяемые процессы."/><Empty title="Для вашей должности пока нет чек-листов" text="HR может создать и назначить их в USTAR HR → Чек-листы." mascot/></>;
  const c=data.checklists[selected]||data.checklists[0];const items=c.sections.flatMap(s=>s.items);const done=items.filter(i=>answers[i.id]?.done).length;const pct=items.length?Math.round(done*100/items.length):100;
  const submit=async()=>{setSaving(true);setMsg("");try{const r=await ws<{score:number;status:string}>("checklist_submit",{checklistid:c.id,answersjson:JSON.stringify(answers),comment});setMsg(`Сохранено · ${r.score}% · ${r.status==="completed"?"выполнено":"частично"}`);await load();}catch(e){setMsg(e instanceof Error?e.message:"Ошибка сохранения");}finally{setSaving(false)}};
  return <><PageTitle kicker="Операционные стандарты" title="Чек-листы" subtitle={`Сегодня ${data.date}. Выполнение сохраняется в USTAR DB и доступно HR для прозрачного контроля.`}/>
    <div className="grid gap-5 xl:grid-cols-[300px_1fr]">
      <aside className="space-y-2">{data.checklists.map((x,i)=><button key={x.id} onClick={()=>choose(i)} className={`card w-full p-4 text-left ${i===selected?"border-brand bg-accentSoft":""}`}><div className="flex items-start justify-between gap-3"><ClipboardCheck size={18}/>{x.today.status==="completed"&&<CheckCircle2 size={18} className="text-green-600"/>}</div><div className="mt-3 font-black">{x.title}</div><div className="mt-1 text-xs text-mut">{x.today.done}/{x.today.total} · {x.today.score}%</div></button>)}</aside>
      <section className="card overflow-hidden"><div className="industrial-rule"/><div className="p-5 sm:p-7"><div className="flex flex-wrap items-start justify-between gap-4"><div><div className="eyebrow">{c.recurrence==="daily"?"Ежедневно":c.recurrence}</div><h2 className="mt-1 text-2xl font-black">{c.title}</h2><p className="mt-2 max-w-3xl text-sm leading-6 text-mut">{c.description}</p></div><div className="min-w-32 text-right"><div className="text-4xl font-black">{pct}%</div><div className="text-[10px] font-black uppercase tracking-wider text-mut">{done}/{items.length} пунктов</div></div></div>
      <div className="mt-6 space-y-6">{c.sections.map(s=><section key={s.id}><h3 className="mb-2 border-b border-black/10 pb-2 text-xs font-black uppercase tracking-[.14em]">{s.title}</h3><div className="space-y-2">{s.items.map(item=>{const a=answers[item.id]||{done:false,comment:""};return <div key={item.id} className={`rounded-lg border p-3 ${a.done?"border-green-200 bg-green-50":"border-black/10 bg-white"}`}><label className="flex cursor-pointer items-start gap-3"><input type="checkbox" className="mt-1 h-5 w-5 accent-[#EBC500]" checked={a.done} onChange={e=>setAnswers({...answers,[item.id]:{...a,done:e.target.checked}})}/><span className="flex-1 text-sm font-bold leading-5">{item.title}</span></label><div className="mt-2 flex items-center gap-2 pl-8"><MessageSquareText size={14} className="text-mut"/><input className="w-full bg-transparent text-xs outline-none" placeholder="Комментарий при необходимости" value={a.comment} onChange={e=>setAnswers({...answers,[item.id]:{...a,comment:e.target.value}})}/></div></div>})}</div></section>)}</div>
      <textarea className="input mt-5 min-h-20" placeholder="Общий комментарий по смене / проверке" value={comment} onChange={e=>setComment(e.target.value)}/><div className="mt-4 flex items-center justify-between gap-3"><div className="text-xs font-bold text-mut">{msg||"Можно сохранять частично и вернуться позже."}</div><button className="btn btn-primary" disabled={saving} onClick={submit}><Save size={16}/>{saving?"Сохраняем…":"Сохранить выполнение"}</button></div>
      </div></section>
    </div></>;
}
