"use client";

import { useEffect, useState } from "react";
import { Users } from "lucide-react";
import { ws } from "@/lib/wsclient";
import { PageTitle, Progress, Empty, LevelDots } from "@/components/ui";

interface Skill {
  id: string; name: string; category: string;
  requiredLevel: number; currentLevel: number; progress: number;
  courses: string[]; sharedWith: string[];
}

export default function SkillsPage() {
  const [skills, setSkills] = useState<Skill[] | null>(null);

  useEffect(() => {
    ws<{ skills: Skill[] }>("skills").then((d) => setSkills(d.skills));
  }, []);

  if (!skills) return <Empty text="Загружаем навыки…" />;

  const byCategory = skills.reduce<Record<string, Skill[]>>((acc, s) => {
    (acc[s.category] ||= []).push(s);
    return acc;
  }, {});

  return (
    <>
      <PageTitle
        kicker="Развитие"
        title="Мои навыки"
        subtitle="Уровень навыка растёт автоматически по мере прохождения связанных курсов."
      />
      {skills.length === 0 && (
        <Empty text="Для вашей должности пока не назначены навыки. Обратитесь к руководителю." />
      )}
      <div className="space-y-8">
        {Object.entries(byCategory).map(([cat, list]) => (
          <section key={cat}>
            <h2 className="mb-4 text-lg font-black">{cat}</h2>
            <div className="grid gap-4 md:grid-cols-2">
              {list.map((s) => (
                <div key={s.id} className="card card-hover p-5">
                  <div className="mb-3 flex items-start justify-between gap-3">
                    <h3 className="font-bold text-ink">{s.name}</h3>
                    <LevelDots current={s.currentLevel} required={s.requiredLevel} />
                  </div>
                  <div className="mb-1.5 flex justify-between text-xs">
                    <span className="text-mut">
                      Уровень {s.currentLevel} из {s.requiredLevel}
                    </span>
                    <strong>{s.progress}%</strong>
                  </div>
                  <Progress value={s.progress} />
                  {s.sharedWith.length > 0 && (
                    <div className="mt-3 flex items-center gap-1.5 text-[11px] text-mut">
                      <Users size={12} />
                      Общий навык с: {s.sharedWith.join(", ")}
                    </div>
                  )}
                </div>
              ))}
            </div>
          </section>
        ))}
      </div>
    </>
  );
}
