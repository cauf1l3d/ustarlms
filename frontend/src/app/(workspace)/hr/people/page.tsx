"use client";

import Link from "next/link";
import { FormEvent, useEffect, useMemo, useRef, useState } from "react";
import { ArrowLeft, Download, FileSpreadsheet, Plus, Search, ShieldCheck, Upload, UserPlus } from "lucide-react";
import { ws } from "@/lib/wsclient";
import { useWorkspace } from "@/components/WorkspaceProvider";
import { Empty, PageTitle } from "@/components/ui";

interface Person { id:number; username:string; fullname:string; email:string; suspended:boolean; lastaccess:number; positionid:string; position:string; department:string; role:string; protected?:boolean }
interface Position { id:string; name:string; department:string; level:number }
interface Dept { id:string; name:string }
interface PeopleData { people:Person[]; count:number; positions:Position[]; departments:Dept[] }
interface ImportRow { username:string; firstname?:string; lastname?:string; email?:string; positionid?:string; password?:string }
interface ImportResult { ok:boolean; created:number; updated:number; errors:{line:number;username:string;message:string}[]; credentials:{username:string;temporaryPassword:string}[] }

function parseCsvLine(line:string, delimiter:string) {
  const out:string[]=[]; let current=""; let quoted=false;
  for(let i=0;i<line.length;i++){
    const ch=line[i];
    if(ch==='"'){
      if(quoted && line[i+1]==='"'){current+='"';i++;}
      else quoted=!quoted;
    } else if(ch===delimiter && !quoted){out.push(current.trim());current="";}
    else current+=ch;
  }
  out.push(current.trim()); return out;
}
function parseCsv(text:string):ImportRow[]{
  const lines=text.replace(/^\uFEFF/,"").split(/\r?\n/).filter(l=>l.trim());
  if(lines.length<2)return [];
  const delimiter=(lines[0].match(/;/g)||[]).length>(lines[0].match(/,/g)||[]).length?";":",";
  const headers=parseCsvLine(lines[0],delimiter).map(x=>x.trim().toLowerCase());
  return lines.slice(1).map(line=>{
    const values=parseCsvLine(line,delimiter); const row:Record<string,string>={};
    headers.forEach((h,i)=>row[h]=values[i]||"");
    return {username:row.username||"",firstname:row.firstname||"",lastname:row.lastname||"",email:row.email||"",positionid:row.positionid||"",password:row.password||""};
  }).filter(r=>r.username);
}

export default function PeoplePage() {
  const { wsData } = useWorkspace();
  const [data,setData]=useState<PeopleData|null>(null);
  const [query,setQuery]=useState(""); const [dept,setDept]=useState(""); const [status,setStatus]=useState("active");
  const [showCreate,setShowCreate]=useState(false); const [showImport,setShowImport]=useState(false); const [error,setError]=useState(""); const [saving,setSaving]=useState(false);
  const [form,setForm]=useState({username:"",firstname:"",lastname:"",email:"",positionid:"",password:""});
  const [importRows,setImportRows]=useState<ImportRow[]>([]); const [importResult,setImportResult]=useState<ImportResult|null>(null);
  const fileRef=useRef<HTMLInputElement|null>(null);

  const load=()=>ws<PeopleData>("hr_people",{query,department:dept,status,limit:150}).then(setData).catch(e=>setError(e.message));
  useEffect(()=>{if(wsData?.capabilities?.hr)load();},[wsData,dept,status]);
  const filtered=useMemo(()=>data?.people.filter(p=>!query.trim()||`${p.fullname} ${p.username} ${p.email}`.toLowerCase().includes(query.toLowerCase()))||[],[data,query]);

  const create=async(e:FormEvent)=>{e.preventDefault();setSaving(true);setError("");try{await ws("hr_save_person",{userid:0,...form,suspended:false});setShowCreate(false);setForm({username:"",firstname:"",lastname:"",email:"",positionid:"",password:""});load();}catch(e){setError(e instanceof Error?e.message:"Ошибка создания");}finally{setSaving(false);}};
  const chooseCsv=async(file?:File)=>{if(!file)return;setImportResult(null);setError("");try{const rows=parseCsv(await file.text());if(!rows.length)throw new Error("В CSV нет строк данных или отсутствует колонка username");setImportRows(rows);setShowImport(true);}catch(e){setError(e instanceof Error?e.message:"Не удалось прочитать CSV");}};
  const runImport=async()=>{setSaving(true);setError("");try{const result=await ws<ImportResult>("hr_import_people",{json:JSON.stringify(importRows)});setImportResult(result);load();}catch(e){setError(e instanceof Error?e.message:"Ошибка импорта");}finally{setSaving(false);}};
  const downloadTemplate=()=>{const csv="username;firstname;lastname;email;positionid;password\nivanov.i;Иван;Иванов;ivanov@example.com;retail_seller;TempPass123!\n";const url=URL.createObjectURL(new Blob(["\uFEFF"+csv],{type:"text/csv;charset=utf-8"}));const a=document.createElement("a");a.href=url;a.download="ustar-people-template.csv";a.click();URL.revokeObjectURL(url);};

  if(wsData&&!wsData.capabilities?.hr)return <Empty title="Нет доступа" text="Нужна роль USTAR HR." mascot/>;
  if(!data)return <Empty title="Загружаем сотрудников" text="Читаем активные учетные записи Moodle и позиции USTAR…" mascot/>;
  const canManage=!!wsData?.capabilities?.hrManage;

  return <>
    <PageTitle kicker="USTAR HR · Люди" title="Сотрудники" subtitle="Поиск, карьерная позиция, оценки и состояние учётной записи. Вместо физического удаления сотрудник архивируется — история обучения и HR-след сохраняются." action={canManage?<div className="flex flex-wrap gap-2"><button className="btn btn-quiet" onClick={()=>fileRef.current?.click()}><Upload size={17}/> Импорт CSV</button><button className="btn btn-accent" onClick={()=>setShowCreate(v=>!v)}><UserPlus size={17}/> Новый сотрудник</button><input ref={fileRef} type="file" accept=".csv,text/csv" className="hidden" onChange={e=>chooseCsv(e.target.files?.[0])}/></div>:undefined}/>
    <Link href="/hr" className="mb-4 inline-flex items-center gap-1 text-xs font-bold text-mut hover:text-ink"><ArrowLeft size={14}/> HR Dashboard</Link>

    {showCreate&&canManage&&<form onSubmit={create} className="card mb-5 overflow-hidden"><div className="industrial-rule"/><div className="p-5"><div className="mb-4"><div className="eyebrow">Быстрое добавление</div><h2 className="mt-1 text-xl font-black">Новый сотрудник</h2></div><div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3"><input required className="input" placeholder="Логин" value={form.username} onChange={e=>setForm({...form,username:e.target.value})}/><input required className="input" placeholder="Имя" value={form.firstname} onChange={e=>setForm({...form,firstname:e.target.value})}/><input required className="input" placeholder="Фамилия" value={form.lastname} onChange={e=>setForm({...form,lastname:e.target.value})}/><input required type="email" className="input" placeholder="Email" value={form.email} onChange={e=>setForm({...form,email:e.target.value})}/><select className="input" value={form.positionid} onChange={e=>setForm({...form,positionid:e.target.value})}><option value="">Без должности USTAR</option>{data.positions.map(p=><option key={p.id} value={p.id}>{p.name}</option>)}</select><input required type="password" className="input" placeholder="Временный пароль" value={form.password} onChange={e=>setForm({...form,password:e.target.value})}/></div><p className="mt-3 text-xs leading-5 text-mut">Новый manual-пользователь Moodle будет обязан сменить временный пароль при первом входе.</p><div className="mt-4 flex justify-end gap-2"><button type="button" className="btn btn-quiet" onClick={()=>setShowCreate(false)}>Отмена</button><button disabled={saving} className="btn btn-primary"><Plus size={16}/>{saving?"Создаём…":"Создать"}</button></div></div></form>}

    {showImport&&canManage&&<section className="card mb-5 overflow-hidden"><div className="industrial-rule"/><div className="p-5"><div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><div className="eyebrow">Массовая операция</div><h2 className="mt-1 text-xl font-black">Импорт CSV · {importRows.length} строк</h2><p className="mt-2 max-w-3xl text-sm leading-6 text-mut">Существующий username: меняется только USTAR-должность. Новый username: создаётся manual-аккаунт. Если password пуст, USTAR сгенерирует временный пароль и покажет его один раз после импорта.</p></div><button onClick={downloadTemplate} className="btn btn-quiet shrink-0"><Download size={16}/> Шаблон</button></div><div className="mt-4 overflow-x-auto rounded-lg border border-black/10"><table className="w-full min-w-[760px] text-left text-xs"><thead className="bg-page text-[10px] uppercase tracking-wider text-mut"><tr><th className="p-3">username</th><th className="p-3">ФИО / email</th><th className="p-3">positionid</th><th className="p-3">пароль</th></tr></thead><tbody>{importRows.slice(0,8).map((r,i)=><tr key={`${r.username}-${i}`} className="border-t border-black/10"><td className="p-3 font-bold">{r.username}</td><td className="p-3">{r.firstname} {r.lastname}<br/><span className="text-mut">{r.email}</span></td><td className="p-3 font-mono">{r.positionid||"—"}</td><td className="p-3">{r.password?"задан":"сгенерировать"}</td></tr>)}</tbody></table></div>{importRows.length>8&&<div className="mt-2 text-xs text-mut">И ещё {importRows.length-8} строк.</div>}{importResult&&<div className="mt-4 rounded-xl border border-black/10 bg-page p-4"><div className="font-black">Результат: создано {importResult.created}, обновлено {importResult.updated}, ошибок {importResult.errors.length}</div>{importResult.errors.length>0&&<div className="mt-2 max-h-36 overflow-auto text-xs text-red-800">{importResult.errors.map((e,i)=><div key={i}>Строка {e.line} · {e.username}: {e.message}</div>)}</div>}{importResult.credentials.length>0&&<div className="mt-4 border-t border-black/10 pt-3"><div className="flex items-center gap-2 text-xs font-black"><ShieldCheck size={15}/> Временные пароли показаны только сейчас</div><div className="mt-2 grid gap-1 font-mono text-xs">{importResult.credentials.map(c=><div key={c.username}>{c.username}: {c.temporaryPassword}</div>)}</div></div>}</div>}<div className="mt-4 flex flex-wrap justify-end gap-2"><button className="btn btn-quiet" onClick={()=>{setShowImport(false);setImportRows([]);setImportResult(null)}}>Закрыть</button><button disabled={saving||!!importResult} onClick={runImport} className="btn btn-primary"><FileSpreadsheet size={16}/>{saving?"Импортируем…":"Выполнить импорт"}</button></div></div></section>}

    <div className="card mb-4 p-3"><div className="grid gap-2 md:grid-cols-[1fr_220px_170px]"><label className="relative"><Search size={17} className="absolute left-3 top-1/2 -translate-y-1/2 text-mut"/><input className="input pl-10" placeholder="Имя, логин или email" value={query} onChange={e=>setQuery(e.target.value)} onKeyDown={e=>e.key==="Enter"&&load()}/></label><select className="input" value={dept} onChange={e=>setDept(e.target.value)}><option value="">Все отделы</option>{data.departments.map(d=><option key={d.id} value={d.id}>{d.name}</option>)}</select><select className="input" value={status} onChange={e=>setStatus(e.target.value)}><option value="active">Активные</option><option value="suspended">Приостановленные</option><option value="all">Все</option></select></div></div>
    {error&&<div className="mb-4 rounded-lg bg-red-50 p-3 text-sm font-bold text-red-800">{error}</div>}

    <div className="card overflow-hidden"><div className="hidden grid-cols-[1.4fr_1fr_1fr_110px] gap-4 border-b border-black/10 bg-page px-4 py-3 text-[10px] font-black uppercase tracking-wider text-mut md:grid"><span>Сотрудник</span><span>Должность</span><span>Отдел</span><span>Статус</span></div>{filtered.map(p=><Link key={p.id} href={`/hr/people/${p.id}`} className="grid gap-3 border-b border-black/10 px-4 py-4 last:border-0 hover:bg-page md:grid-cols-[1.4fr_1fr_1fr_110px] md:items-center"><div><div className="flex items-center gap-2 font-black">{p.fullname}{p.protected&&<ShieldCheck size={14} className="text-orange-700"/>}</div><div className="mt-1 text-xs text-mut">{p.username} · {p.email}</div></div><div className="text-sm font-semibold">{p.position||<span className="text-orange-700">Не назначена</span>}</div><div className="text-sm text-mut">{data.departments.find(d=>d.id===p.department)?.name||"—"}</div><div><span className={`pill ${p.suspended?"bg-red-50 text-red-800":p.protected?"bg-orange-50 text-orange-800":p.role==="head"?"bg-[#DCEEF9] text-brand":"bg-page text-mut"}`}>{p.suspended?"Приост.":p.protected?"Защищён":p.role==="head"?"Head":"Активен"}</span></div></Link>)}{filtered.length===0&&<div className="p-8 text-center text-sm text-mut">По текущему фильтру никого не найдено.</div>}</div>
  </>;
}
