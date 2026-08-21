"use client";

import Image from "next/image";
import Link from "next/link";
import { useEffect, useState } from "react";
import { ArrowRight, BookOpen, Check, Flame, Gamepad2, Plus, Target, Trophy } from "lucide-react";
import { ws } from "@/lib/wsclient";
import { useWorkspace } from "@/components/WorkspaceProvider";
import { Empty, PageTitle, Progress, StatCard } from "@/components/ui";

interface Dash {
  courses: { id: number; name: string; skills: string[]; progress: number; url: string }[];
  avgProgress: number; completedCourses: number; xp: number; gameXp?: number; level: number; nextLevelXp: number;
  badges: { name: string }[]; goals: { id: number; title: string; completed: boolean }[]; activeDays30: number;
}

export default function Dashboard() {
  const { wsData } = useWorkspace();
  const [d, setD] = useState<Dash | null>(null);
  const [goalTitle, setGoalTitle] = useState("");
  const load = () => ws<Dash>("dashboard").then(setD);
  useEffect(() => { load(); }, []);

  const addGoal = async () => { if (!goalTitle.trim()) return; await ws("save_goal", { action: "create", title: goalTitle.trim() }); setGoalTitle(""); load(); };
  const completeGoal = async (id: number) => { await ws("save_goal", { action: "complete", id }); load(); };
  if (!d) return <Empty title="Собираем данные" text="Загружаем прогресс, курсы и цели…" mascot />;

  const xpPct = Math.min(100, Math.round((d.xp / Math.max(1, d.nextLevelXp)) * 100));
  const inProgress = d.courses.filter((c) => c.progress < 100).sort((a, b) => b.progress - a.progress).slice(0, 3);
  const focusCourse = inProgress[0];

  return (
    <>
      <PageTitle kicker={wsData?.department?.name || "USTAR Академия"} title={`Добро пожаловать, ${wsData?.user.firstname || ""}`} subtitle="Не каталог курсов, а рабочая карта развития: что продолжить сегодня и что приблизит к следующей ступени." />

      <section className="card relative mb-6 overflow-hidden bg-brand text-white">
        <div className="industrial-rule" />
        <div className="grid min-h-[250px] md:grid-cols-[1fr_260px]">
          <div className="relative z-10 p-6 sm:p-8">
            <div className="eyebrow !text-white/45">Фокус на сегодня</div>
            <h2 className="mt-3 max-w-2xl text-3xl font-black leading-tight tracking-[-.04em] sm:text-4xl">{focusCourse ? focusCourse.name : "Вы закрыли все доступные курсы"}</h2>
            {focusCourse ? <><div className="mt-6 max-w-xl"><div className="mb-2 flex justify-between text-xs font-bold text-white/60"><span>Прогресс</span><span>{focusCourse.progress}%</span></div><Progress value={focusCourse.progress}/></div><a href={focusCourse.url} target="_blank" rel="noreferrer" className="btn btn-accent mt-6">Продолжить обучение <ArrowRight size={17}/></a></> : <Link href="/games" className="btn btn-accent mt-6">Перейти в Game Hub <Gamepad2 size={17}/></Link>}
          </div>
          <div className="relative hidden overflow-hidden md:block"><Image src="/brand/mascot-support.png" alt="Маскот USTAR" fill className="object-contain p-5" sizes="260px"/></div>
        </div>
      </section>

      {!wsData?.position && <div className="mb-6"><Empty title="Не назначена должность USTAR" text="Без должности система не может построить вашу карьерную лестницу и подобрать матрицу навыков. HR должен назначить позицию в профиле сотрудника." mascot action={<Link href="/ladder" className="btn btn-quiet">Подробнее о карьере</Link>} /></div>}

      <div className="mb-8 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <StatCard icon={<Target size={19}/>} label="Прогресс" value={`${d.avgProgress}%`} note="средний по доступным курсам" tone="yellow" />
        <StatCard icon={<Check size={19}/>} label="Завершено" value={d.completedCourses} note={`из ${d.courses.length} курсов`} tone="plain" />
        <StatCard icon={<Trophy size={19}/>} label="XP / уровень" value={`${d.xp} / ${d.level}`} note={`игры: ${d.gameXp || 0} XP`} tone="orange" />
        <StatCard icon={<Flame size={19}/>} label="Активность" value={d.activeDays30} note="активных дней за 30" tone="blue" />
      </div>

      <div className="grid gap-6 xl:grid-cols-[1.5fr_.8fr]">
        <section>
          <div className="mb-3 flex items-end justify-between"><div><div className="eyebrow">Обучение</div><h2 className="mt-1 text-xl font-black">Продолжить</h2></div><Link href="/courses" className="text-sm font-bold underline decoration-accent decoration-2 underline-offset-4">Все курсы</Link></div>
          <div className="space-y-3">{inProgress.length ? inProgress.map((c) => <a key={c.id} href={c.url} target="_blank" rel="noreferrer" className="card card-hover block p-4 sm:p-5"><div className="flex items-start justify-between gap-4"><div className="min-w-0"><div className="font-black text-ink">{c.name}</div><div className="mt-2 flex flex-wrap gap-1.5">{c.skills.slice(0,3).map((s) => <span key={s} className="pill bg-page text-mut">{s}</span>)}</div></div><span className="text-lg font-black">{c.progress}%</span></div><Progress value={c.progress} className="mt-4"/></a>) : <Empty title="Учебный план закрыт" text="Сейчас нет незавершённых курсов. Можно закрепить знания в Game Hub." />}</div>
        </section>

        <section>
          <div className="mb-3"><div className="eyebrow">Личный план</div><h2 className="mt-1 text-xl font-black">Мои цели</h2></div>
          <div className="card p-4 sm:p-5"><div className="flex gap-2"><input value={goalTitle} onChange={(e) => setGoalTitle(e.target.value)} onKeyDown={(e) => e.key === "Enter" && addGoal()} className="input" placeholder="Например: пройти CRM до пятницы"/><button onClick={addGoal} className="btn btn-primary !px-3" aria-label="Добавить цель"><Plus size={18}/></button></div><div className="mt-4 space-y-2">{d.goals.length === 0 && <p className="py-5 text-center text-sm text-mut">Добавьте одну конкретную цель на ближайшее время.</p>}{d.goals.map((g) => <button key={g.id} disabled={g.completed} onClick={() => completeGoal(g.id)} className={`flex w-full items-center gap-3 rounded-lg border px-3 py-3 text-left text-sm ${g.completed ? "border-black/5 bg-page text-mut" : "border-black/10 bg-white"}`}><span className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-md ${g.completed ? "bg-[#DDEFE5] text-[#256A45]" : "bg-accent"}`}>{g.completed ? <Check size={14}/> : <Target size={14}/>}</span><span className={`font-semibold ${g.completed ? "line-through" : ""}`}>{g.title}</span></button>)}</div><div className="mt-4 border-t border-black/10 pt-4"><Link href="/games" className="flex items-center justify-between text-sm font-bold"><span className="flex items-center gap-2"><Gamepad2 size={17}/> 5 минут на закрепление</span><ArrowRight size={16}/></Link></div></div>
        </section>
      </div>
    </>
  );
}
