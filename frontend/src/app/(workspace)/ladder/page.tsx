"use client";

import { useEffect, useState } from "react";
import { CheckCircle2, ChevronRight, LockKeyhole, MapPin, Sparkles } from "lucide-react";
import { ws } from "@/lib/wsclient";
import { Empty, PageTitle, Readiness } from "@/components/ui";

interface SkillGap { id: string; name: string; requiredLevel: number; currentLevel: number; progress: number; gap: number }
interface Step { id: string; name: string; level: number; isCurrent: boolean; isPast: boolean; readiness: number; skills: SkillGap[] }
interface Ladder { department: { id: string; name: string }; steps: Step[] }
interface LadderData { status: "ok" | "position_missing"; currentPositionId: string | null; ladders: Ladder[] }

export default function LadderPage() {
  const [data, setData] = useState<LadderData | null>(null);
  useEffect(() => { ws<LadderData>("ladder").then(setData); }, []);
  if (!data) return <Empty title="Строим маршрут" text="Сверяем должность, навыки и учебный прогресс…" mascot />;

  if (data.status === "position_missing") {
    return <><PageTitle kicker="Карьера" title="Ваша карьерная карта" subtitle="Карьерная лестница строится из официальной должности USTAR и матрицы навыков."/><Empty title="HR ещё не назначил должность USTAR" text="Это не означает, что лестница не настроена. В вашем Moodle-профиле отсутствует значение поля «Должность USTAR». После назначения позиции маршрут появится автоматически." mascot /></>;
  }

  return (
    <>
      <PageTitle kicker="Карьера" title="Карьерная карта" subtitle="Каждая ступень показывает не только требования, но и фактическую готовность по связанным курсам и навыкам." />
      <div className="space-y-8">
        {data.ladders.map((l) => (
          <section key={l.department.id}>
            <div className="mb-4 flex items-center justify-between"><div><div className="eyebrow">Маршрут</div><h2 className="mt-1 text-xl font-black">{l.department.name}</h2></div><span className="pill bg-white text-mut">{l.steps.length} ступени</span></div>
            <div className="grid gap-3 xl:grid-cols-3">
              {l.steps.map((s, i) => {
                const completed = s.isPast || s.readiness >= 100;
                return <article key={s.id} className={`card relative overflow-hidden ${s.isCurrent ? "border-brand" : ""}`}>
                  <div className={`h-1.5 ${s.isCurrent ? "bg-accent" : completed ? "bg-[#2D8F5B]" : "bg-black/10"}`} />
                  <div className="p-5">
                    <div className="mb-4 flex items-center justify-between"><span className="eyebrow">Ступень {s.level}</span>{s.isCurrent ? <span className="pill bg-accent text-brand"><MapPin size={12}/> сейчас</span> : completed ? <CheckCircle2 size={18} className="text-[#2D8F5B]"/> : <LockKeyhole size={17} className="text-mut"/>}</div>
                    <h3 className="text-xl font-black tracking-[-.025em]">{s.name}</h3>
                    <div className="mt-5"><Readiness value={s.readiness} label={s.isCurrent ? "Освоение текущей ступени" : "Готовность по навыкам"}/></div>
                    <div className="mt-5 space-y-2 border-t border-black/10 pt-4">
                      {s.skills.length === 0 && <p className="text-xs text-mut">Для этой ступени требования ещё не описаны.</p>}
                      {s.skills.map((skill) => <div key={skill.id} className="flex items-center justify-between gap-4 text-xs"><span className="font-semibold text-ink">{skill.name}</span><span className={skill.gap === 0 ? "font-black text-[#2D8F5B]" : "font-bold text-mut"}>{skill.currentLevel}/{skill.requiredLevel}</span></div>)}
                    </div>
                    {s.isCurrent && i < l.steps.length - 1 && <div className="mt-5 flex items-center gap-2 rounded-lg bg-accentSoft px-3 py-3 text-xs font-bold"><Sparkles size={15}/> Следующая цель: {l.steps[i + 1].name}</div>}
                  </div>
                  {i < l.steps.length - 1 && <ChevronRight className="absolute -right-[15px] top-1/2 z-10 hidden -translate-y-1/2 rounded-full bg-page p-1 text-mut xl:block" size={30}/>}                
                </article>;
              })}
            </div>
          </section>
        ))}
      </div>
    </>
  );
}
