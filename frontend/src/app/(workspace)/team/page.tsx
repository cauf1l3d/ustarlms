"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { ArrowRight, UsersRound } from "lucide-react";
import { ws } from "@/lib/wsclient";
import { useWorkspace } from "@/components/WorkspaceProvider";
import { Empty, PageTitle, Progress } from "@/components/ui";

interface Member {
  id: number; fullname: string; position: string; positionid?: string;
  avgProgress: number; courseCount: number; department: string;
}

export default function TeamPage() {
  const { wsData } = useWorkspace();
  const [team, setTeam] = useState<Member[] | null>(null);
  const [error, setError] = useState("");

  useEffect(() => {
    ws<{ team: Member[] }>("team").then((d) => setTeam(d.team)).catch((e) => setError(e.message));
  }, []);

  if (wsData && wsData.role === "employee") return <Empty title="Раздел руководителя" text="Командный обзор доступен руководителю своего отдела и USTAR Superadmin." mascot />;
  if (error) return <Empty title="Нет доступа" text="USTAR не разрешил просматривать данные команды." mascot />;
  if (!team) return <Empty title="Собираем команду" text="Загружаем сотрудников и учебный прогресс…" mascot />;

  const avg = team.length ? Math.round(team.reduce((s, m) => s + m.avgProgress, 0) / team.length) : 0;
  return (
    <>
      <PageTitle kicker={wsData?.role === "superadmin" ? "Компания" : wsData?.department?.name || "Отдел"} title="Команда" subtitle="Операционный обзор для руководителя. HR-операции и изменение профилей находятся отдельно в USTAR HR." />
      <div className="mb-5 grid grid-cols-2 gap-3">
        <div className="card p-5"><div className="eyebrow">Средний прогресс</div><div className="mt-2 text-4xl font-black">{avg}%</div></div>
        <div className="card p-5"><div className="eyebrow">Сотрудники</div><div className="mt-2 flex items-center gap-2 text-4xl font-black"><UsersRound size={25}/>{team.length}</div></div>
      </div>
      <div className="grid gap-3 lg:grid-cols-2">
        {team.map((m) => <article key={m.id} className="card p-4 sm:p-5">
          <div className="flex items-start gap-3">
            <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-brand font-black text-accent">{m.fullname[0]}</div>
            <div className="min-w-0 flex-1"><div className="truncate font-black">{m.fullname}</div><div className="mt-1 truncate text-xs text-mut">{m.position} · {m.courseCount} курс(ов)</div></div>
            {wsData?.capabilities?.hr && <Link href={`/hr/people/${m.id}`} className="flex h-10 w-10 items-center justify-center rounded-lg border border-black/10 bg-white" aria-label="Открыть HR профиль"><ArrowRight size={16}/></Link>}
          </div>
          <div className="mt-5"><div className="mb-1.5 flex justify-between text-xs"><span className="font-bold text-mut">Обучение</span><strong>{m.avgProgress}%</strong></div><Progress value={m.avgProgress}/></div>
        </article>)}
        {team.length === 0 && <div className="lg:col-span-2"><Empty title="Команда не сформирована" text="В отделе пока нет активных сотрудников с валидной должностью USTAR." mascot /></div>}
      </div>
    </>
  );
}
