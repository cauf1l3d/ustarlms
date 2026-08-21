"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import {
  Activity,
  AlertTriangle,
  ArrowRight,
  BookCheck,
  Building2,
  ClipboardCheck,
  UsersRound,
} from "lucide-react";

import { ws } from "@/lib/wsclient";
import { useWorkspace } from "@/components/WorkspaceProvider";
import { Empty, PageTitle, StatCard } from "@/components/ui";

interface ExecutiveData {
  totalPeople: number;
  assignedPeople: number;
  unassignedPeople: number;
  activeLearners30: number;
  completedCourses30: number;
  reviews30: number;
  avgReviewScore: number;
  departments: {
    id: string;
    name: string;
    people: number;
  }[];
}

export default function ExecutivePage() {
  const { wsData } = useWorkspace();
  const [d, setD] = useState<ExecutiveData | null>(null);
  const [showAllDepartments, setShowAllDepartments] = useState(false);

  useEffect(() => {
    if (wsData?.capabilities?.executive) {
      ws<ExecutiveData>("executive").then(setD);
    }
  }, [wsData]);

  if (wsData && !wsData.capabilities?.executive) {
    return (
      <Empty
        title="Нет доступа"
        text="Панель предназначена для управленческого контура USTAR."
      />
    );
  }

  if (!d) {
    return (
      <Empty
        title="Готовим управленческий обзор"
        text="Собираем актуальные показатели компании…"
      />
    );
  }

  const coverage = d.totalPeople
    ? Math.round((d.assignedPeople / d.totalPeople) * 100)
    : 0;

  const departments = [...d.departments].sort(
    (a, b) => b.people - a.people
  );

  const visibleDepartments = showAllDepartments
    ? departments
    : departments.slice(0, 8);

  const maxPeople = Math.max(
    1,
    ...departments.map((department) => department.people)
  );

  const coverageState =
    coverage >= 90
      ? "Штатная карта почти полностью размечена."
      : coverage >= 70
        ? "Основная часть команды размечена. Остались профили для проверки HR."
        : "Штатная карта требует дополнительной разметки.";

  return (
    <>
      <PageTitle
        kicker="Executive"
        title="Развитие компании"
        subtitle="Управленческий обзор USTAR: люди, кадровое покрытие, обучение и состояние структуры компании."
      />

      {/* KPI */}
      <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-5">
        <StatCard
          icon={<UsersRound size={19} />}
          label="Команда"
          value={d.totalPeople}
          note="активных аккаунтов"
        />

        <StatCard
          icon={<Building2 size={19} />}
          label="Штатная карта"
          value={`${coverage}%`}
          note={`${d.assignedPeople} сотрудников размечено`}
          tone="orange"
        />

        <StatCard
          icon={<Activity size={19} />}
          label="Обучались"
          value={d.activeLearners30}
          note="за последние 30 дней"
          tone="blue"
        />

        <StatCard
          icon={<BookCheck size={19} />}
          label="Завершили курсы"
          value={d.completedCourses30}
          note="за последние 30 дней"
          tone="plain"
        />

        <StatCard
          icon={<ClipboardCheck size={19} />}
          label="HR-оценки"
          value={d.reviews30}
          note={
            d.reviews30
              ? `средняя оценка ${d.avgReviewScore}/5`
              : "за 30 дней оценок нет"
          }
          tone="yellow"
        />
      </div>

      {/* MAIN EXECUTIVE GRID */}
      <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1.5fr)_minmax(300px,.72fr)]">

        {/* DEPARTMENTS */}
        <section className="card p-5 sm:p-6">
          <div className="flex flex-wrap items-end justify-between gap-3">
            <div>
              <div className="eyebrow">Структура компании</div>
              <h2 className="mt-1 text-xl font-black">
                Численность по отделам
              </h2>
              <p className="mt-1 text-sm text-muted">
                Где сегодня сосредоточена команда USTAR
              </p>
            </div>

            <div className="text-right">
              <div className="text-2xl font-black">{departments.length}</div>
              <div className="text-xs text-muted">подразделений</div>
            </div>
          </div>

          <div className="mt-6 space-y-4">
            {visibleDepartments.map((dep) => {
              const width =
                dep.people === 0
                  ? 0
                  : Math.max(3, (dep.people / maxPeople) * 100);

              return (
                <div key={dep.id}>
                  <div className="mb-1.5 flex items-center justify-between gap-4">
                    <span className="truncate text-sm font-bold">
                      {dep.name}
                    </span>

                    <span className="shrink-0 text-sm font-black">
                      {dep.people}
                    </span>
                  </div>

                  <div className="h-2.5 overflow-hidden rounded-full bg-page">
                    {dep.people > 0 && (
                      <div
                        className="h-full rounded-full bg-brand"
                        style={{ width: `${width}%` }}
                      />
                    )}
                  </div>
                </div>
              );
            })}
          </div>

          {departments.length > 8 && (
            <button
              type="button"
              onClick={() =>
                setShowAllDepartments((current) => !current)
              }
              className="mt-5 text-sm font-black underline decoration-accent decoration-2 underline-offset-4"
            >
              {showAllDepartments
                ? "Свернуть список"
                : `Показать ещё ${departments.length - 8}`}
            </button>
          )}
        </section>

        {/* COVERAGE */}
        <aside className="card overflow-hidden">
          <div className="industrial-rule" />

          <div className="p-5 sm:p-6">
            <div className="eyebrow">Кадровое покрытие</div>

            <div className="mt-3 flex items-end gap-3">
              <div className="text-5xl font-black tracking-[-.06em]">
                {coverage}%
              </div>

              <div className="pb-1 text-xs font-bold text-muted">
                размечено
              </div>
            </div>

            <div className="mt-5 h-3 overflow-hidden rounded-full bg-page">
              <div
                className="h-full rounded-full bg-accent"
                style={{ width: `${Math.min(100, coverage)}%` }}
              />
            </div>

            <p className="mt-4 text-sm leading-6 text-muted">
              {coverageState}
            </p>

            <div className="mt-5 grid grid-cols-2 gap-2">
              <div className="rounded-lg border border-line p-3">
                <div className="text-2xl font-black">
                  {d.assignedPeople}
                </div>
                <div className="mt-1 text-xs text-muted">
                  с должностью
                </div>
              </div>

              <div className="rounded-lg border border-line p-3">
                <div className="text-2xl font-black">
                  {d.unassignedPeople}
                </div>
                <div className="mt-1 text-xs text-muted">
                  требуют внимания
                </div>
              </div>
            </div>

            {d.unassignedPeople > 0 && (
              <div className="mt-4 flex gap-3 rounded-lg bg-page p-3">
                <AlertTriangle
                  className="mt-0.5 shrink-0"
                  size={18}
                />

                <div>
                  <div className="text-sm font-black">
                    Требуется работа HR
                  </div>
                  <div className="mt-1 text-xs leading-5 text-muted">
                    Необходимо проверить сотрудников без подтверждённой
                    позиции в штатной карте.
                  </div>
                </div>
              </div>
            )}

            <Link
              href="/hr/workspace"
              className="mt-5 flex items-center justify-between rounded-lg bg-brand px-4 py-3 text-sm font-black text-white transition-opacity hover:opacity-90"
            >
              <span>Открыть HR Workspace</span>
              <ArrowRight size={17} />
            </Link>
          </div>
        </aside>
      </div>

      {/* MANAGEMENT SIGNALS */}
      <div className="mt-5 grid gap-3 md:grid-cols-3">
        <section className="card p-5">
          <div className="eyebrow">Обучение · 30 дней</div>

          <div className="mt-4 flex items-end gap-2">
            <span className="text-4xl font-black">
              {d.activeLearners30}
            </span>
            <span className="pb-1 text-sm text-muted">
              активных учащихся
            </span>
          </div>

          <div className="mt-3 border-t border-line pt-3 text-sm">
            <strong>{d.completedCourses30}</strong>
            <span className="text-muted"> завершённых курсов</span>
          </div>

          <p className="mt-3 text-xs leading-5 text-muted">
            Показатель отражает фактическую учебную активность сотрудников
            за последний месяц.
          </p>
        </section>

        <section className="card p-5">
          <div className="eyebrow">HR-контроль</div>

          <div className="mt-4 flex items-end gap-2">
            <span className="text-4xl font-black">{d.reviews30}</span>
            <span className="pb-1 text-sm text-muted">
              оценок за 30 дней
            </span>
          </div>

          <div className="mt-3 border-t border-line pt-3 text-sm">
            {d.reviews30 > 0 ? (
              <>
                Средняя оценка{" "}
                <strong>{d.avgReviewScore}/5</strong>
              </>
            ) : (
              <span className="text-muted">
                Период пока без новых HR-оценок
              </span>
            )}
          </div>

          <Link
            href="/hr/workspace"
            className="mt-4 inline-flex items-center gap-2 text-sm font-black"
          >
            HR Workspace
            <ArrowRight size={15} />
          </Link>
        </section>

        <section className="card p-5">
          <div className="eyebrow">Требует внимания</div>

          <div className="mt-4 text-4xl font-black">
            {d.unassignedPeople}
          </div>

          <div className="mt-1 text-sm font-bold">
            сотрудников без подтверждённой позиции
          </div>

          <p className="mt-3 text-xs leading-5 text-muted">
            После разметки должности USTAR сможет точнее связывать человека
            с навыками, обучением и карьерной траекторией.
          </p>

          <Link
            href="/admin/roadmap"
            className="mt-4 inline-flex items-center gap-2 text-sm font-black"
          >
            Дорожная карта
            <ArrowRight size={15} />
          </Link>
        </section>
      </div>
    </>
  );
}
