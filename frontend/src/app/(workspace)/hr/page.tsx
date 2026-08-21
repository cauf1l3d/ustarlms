"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Activity, ArrowRight, BookCheck, ClipboardCheck, Gamepad2, Layers3, UserRoundCheck, UsersRound } from "lucide-react";
import { ws } from "@/lib/wsclient";
import { useWorkspace } from "@/components/WorkspaceProvider";
import { Empty, PageTitle, StatCard } from "@/components/ui";

interface HRDashboard {
  totalPeople: number; assignedPeople: number; unassignedPeople: number; heads: number;
  activeLearners30: number; courseCompletions30: number; gameAttempts30: number; gameAccuracy: number; reviews30:number; avgReviewScore:number;
  departments: { id: string; name: string; people: number }[]; recentActions:{id:number;action:string;actor:string;target:string;timecreated:number}[];
}

export default function HRPage() {
  const { wsData } = useWorkspace();
  const [d, setD] = useState<HRDashboard | null>(null);
  useEffect(() => { if (wsData?.capabilities?.hr) ws<HRDashboard>("hr_dashboard").then(setD); }, [wsData]);
  if (wsData && !wsData.capabilities?.hr) return <Empty title="Нет доступа" text="Раздел виден только пользователям с системной ролью USTAR HR." mascot />;
  if (!d) return <Empty title="Собираем People Analytics" text="Считаем сотрудников, активность и завершённое обучение…" mascot />;
  const maxDept = Math.max(1, ...d.departments.map((x) => x.people));

  return <>
    <PageTitle kicker="USTAR HR" title="Люди и развитие" subtitle="Операционная панель HR: кадровая структура, активность обучения, сотрудники без назначенной позиции и быстрые действия." action={<div className="flex flex-wrap gap-2"><Link href="/hr/workspace" className="btn btn-accent"><Layers3 size={17}/> Workspace</Link><Link href="/hr/people" className="btn btn-quiet">Сотрудники <ArrowRight size={17}/></Link></div>} />

    <div className="mb-6 grid grid-cols-2 gap-3 xl:grid-cols-5">
      <StatCard icon={<UsersRound size={19}/>} label="Сотрудники" value={d.totalPeople} note={`${d.assignedPeople} с должностью USTAR`} />
      <StatCard icon={<UserRoundCheck size={19}/>} label="Без должности" value={d.unassignedPeople} note="нужно назначить карьерный профиль" tone={d.unassignedPeople ? "orange" : "plain"} />
      <StatCard icon={<Activity size={19}/>} label="Активны в обучении" value={d.activeLearners30} note="за последние 30 дней" tone="blue" />
      <StatCard icon={<BookCheck size={19}/>} label="Завершено курсов" value={d.courseCompletions30} note="за последние 30 дней" tone="plain" />
      <StatCard icon={<ClipboardCheck size={19}/>} label="HR оценки" value={d.reviews30} note={d.reviews30 ? `средняя ${d.avgReviewScore}/5` : "за 30 дней нет"} tone="yellow" />
    </div>

    <div className="grid gap-6 xl:grid-cols-[1.25fr_.75fr]">
      <section className="card p-5 sm:p-6">
        <div className="mb-5 flex items-center justify-between"><div><div className="eyebrow">Структура</div><h2 className="mt-1 text-xl font-black">Сотрудники по отделам</h2></div><span className="pill bg-page text-mut">назначенные позиции</span></div>
        <div className="space-y-4">{d.departments.map((dept) => <div key={dept.id}><div className="mb-1.5 flex justify-between text-sm"><span className="font-bold">{dept.name}</span><strong>{dept.people}</strong></div><div className="h-7 overflow-hidden rounded-md bg-page"><div className="flex h-full items-center bg-brand px-2 text-[10px] font-black text-white" style={{ width: `${Math.max(3, dept.people / maxDept * 100)}%` }}>{dept.people > 0 ? dept.id.toUpperCase() : ""}</div></div></div>)}</div>
      </section>

      <section className="space-y-4">
        <div className="card overflow-hidden bg-brand text-white"><div className="industrial-rule"/><div className="p-5 sm:p-6"><div className="eyebrow !text-white/45">Game Hub · 30 дней</div><div className="mt-4 flex items-end justify-between"><div><div className="text-4xl font-black">{d.gameAttempts30}</div><div className="text-xs font-bold text-white/45">ответов</div></div><div className="text-right"><div className="text-4xl font-black text-accent">{d.gameAccuracy}%</div><div className="text-xs font-bold text-white/45">точность</div></div></div><Link href="/games" className="mt-6 flex items-center justify-between border-t border-white/10 pt-4 text-sm font-bold">Посмотреть Game Hub <Gamepad2 size={18}/></Link></div></div>
        <div className="card p-5"><div className="eyebrow">Контроль качества данных</div><h3 className="mt-2 text-lg font-black">{d.unassignedPeople ? `${d.unassignedPeople} сотрудников без карьерной привязки` : "Карьерные позиции назначены"}</h3><p className="mt-2 text-sm leading-6 text-mut">Без `ustar_position` сотрудник не увидит корректную лестницу, персональную матрицу и набор навыков.</p><Link href="/hr/people" className="btn btn-quiet mt-4 w-full">Открыть сотрудников</Link></div>
      </section>
    </div>

    <section className="mt-6">
      <div className="mb-3"><div className="eyebrow">Аудит действий</div><h2 className="mt-1 text-xl font-black">Последние HR-изменения</h2></div>
      <div className="card divide-y divide-black/10">{d.recentActions?.length?d.recentActions.map(a=><div key={a.id} className="grid gap-1 px-4 py-3 text-sm sm:grid-cols-[1fr_auto] sm:items-center"><div><strong>{a.target||"Система"}</strong><span className="ml-2 text-mut">{a.action}</span><div className="mt-1 text-xs text-mut">HR: {a.actor}</div></div><time className="text-xs text-mut">{new Date(a.timecreated*1000).toLocaleString("ru-RU")}</time></div>):<div className="p-5 text-sm text-mut">HR-действий пока нет.</div>}</div>
    </section>
  </>;
}
