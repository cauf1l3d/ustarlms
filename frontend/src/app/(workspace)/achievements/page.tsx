"use client";

import Image from "next/image";
import Link from "next/link";
import { useEffect, useState } from "react";
import { Award, Flame, Gamepad2, Trophy } from "lucide-react";
import { ws } from "@/lib/wsclient";
import { BrandBadge, Empty, PageTitle, Progress, StatCard } from "@/components/ui";

interface Dash {
  xp: number; gameXp?: number; level: number; nextLevelXp: number; activeDays30: number;
  completedCourses: number; badges: { name: string; dateissued?: number }[];
}

export default function AchievementsPage() {
  const [d, setD] = useState<Dash | null>(null);
  useEffect(() => { ws<Dash>("dashboard").then(setD); }, []);
  if (!d) return <Empty title="Собираем достижения" text="Загружаем XP, активность и выданные бейджи…" mascot mascotKind="success" />;
  const pct = Math.min(100, Math.round((d.xp / Math.max(1, d.nextLevelXp)) * 100));
  return <>
    <PageTitle kicker="Прогресс и признание" title="Достижения" subtitle="Фирменные награды USTAR дополняют Moodle-бейджи. Здесь виден прогресс, который сотрудник реально заработал обучением и Game Hub." />
    <section className="card mb-6 overflow-hidden bg-brand text-white"><div className="industrial-rule"/><div className="grid md:grid-cols-[1fr_230px]"><div className="p-6 sm:p-8"><div className="eyebrow !text-accent">Уровень {d.level}</div><div className="mt-2 text-4xl font-black tracking-[-.05em]">{d.xp} XP</div><div className="mt-5 max-w-xl"><div className="mb-2 flex justify-between text-xs font-bold text-white/55"><span>До следующего уровня</span><span>{pct}%</span></div><Progress value={pct}/></div></div><div className="relative hidden md:block"><Image src="/brand/mascot-success.png" alt="Маскот USTAR с наградой" fill className="object-contain p-4" sizes="230px"/></div></div></section>
    <div className="mb-7 grid grid-cols-2 gap-3 lg:grid-cols-4"><StatCard icon={<Trophy size={19}/>} label="Уровень" value={d.level} note="общий прогресс USTAR"/><StatCard icon={<Gamepad2 size={19}/>} label="Game XP" value={d.gameXp || 0} note="первое верное решение" tone="orange"/><StatCard icon={<Award size={19}/>} label="Курсы" value={d.completedCourses} note="завершено" tone="blue"/><StatCard icon={<Flame size={19}/>} label="Активность" value={d.activeDays30} note="дней за последние 30" tone="plain"/></div>
    <div className="mb-3"><div className="eyebrow">Выданные награды</div><h2 className="mt-1 text-xl font-black">Мои бейджи</h2></div>
    {d.badges.length ? <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{d.badges.map((b,i)=><BrandBadge key={`${b.name}-${i}`} title={b.name} subtitle="USTAR Academy" icon="★" />)}</div> : <Empty title="Первый бейдж ещё впереди" text="Закрывайте обязательные курсы и игровые серии. Награды появляются здесь после выдачи в Moodle или правилами USTAR." mascot mascotKind="success" action={<div className="flex flex-wrap gap-2"><Link href="/courses" className="btn btn-primary">К обучению</Link><Link href="/games" className="btn btn-accent">В Game Hub</Link></div>} />}
  </>;
}
