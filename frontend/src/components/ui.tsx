"use client";

import Image from "next/image";

export function PageTitle({ kicker, title, subtitle, action }: { kicker?: string; title: string; subtitle?: string; action?: React.ReactNode }) {
  return (
    <div className="float-in mb-7 flex flex-col gap-4 border-b border-black/10 pb-5 sm:flex-row sm:items-end sm:justify-between">
      <div>
        {kicker && <p className="eyebrow mb-2">{kicker}</p>}
        <h1 className="max-w-4xl text-3xl font-black leading-[1.04] tracking-[-0.035em] text-ink sm:text-[38px]">{title}</h1>
        {subtitle && <p className="mt-2 max-w-3xl text-sm leading-6 text-mut">{subtitle}</p>}
      </div>
      {action && <div className="shrink-0">{action}</div>}
    </div>
  );
}

export function StatCard({ icon, label, value, note, tone = "yellow" }: {
  icon: React.ReactNode; label: string; value: string | number; note?: string; tone?: "yellow" | "blue" | "orange" | "plain";
}) {
  const tones = {
    yellow: "bg-accent",
    blue: "bg-[#DCEEF9]",
    orange: "bg-[#FFE6C8]",
    plain: "bg-page",
  };
  return (
    <div className="card p-4 sm:p-5">
      <div className={`mb-5 flex h-9 w-9 items-center justify-center rounded-lg ${tones[tone]} text-brand`}>{icon}</div>
      <div className="eyebrow">{label}</div>
      <div className="mt-2 text-3xl font-black tracking-[-0.04em] text-ink">{value}</div>
      {note && <div className="mt-1 text-xs leading-5 text-mut">{note}</div>}
    </div>
  );
}

export function Progress({ value, className = "" }: { value: number; className?: string }) {
  return <div className={`progress-track ${className}`}><div className="progress-fill" style={{ width: `${Math.max(0, Math.min(100, value))}%` }} /></div>;
}

export function Ring({ value, size = 92 }: { value: number; size?: number }) {
  const r = (size - 10) / 2;
  const c = 2 * Math.PI * r;
  return (
    <svg width={size} height={size} className="rotate-[-90deg]" aria-label={`${value}%`}>
      <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="#deddd7" strokeWidth="8" />
      <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="var(--c-accent)" strokeWidth="8" strokeLinecap="round"
        strokeDasharray={c} strokeDashoffset={c * (1 - Math.max(0, Math.min(100, value)) / 100)} />
    </svg>
  );
}

export function Empty({ text, title = "Пока пусто", mascot = false, mascotKind = "thinking", action }: {
  text: string; title?: string; mascot?: boolean; mascotKind?: "thinking" | "success" | "support" | "admin"; action?: React.ReactNode;
}) {
  const mascotSrc = {
    thinking: "/brand/mascot-thinking.png",
    success: "/brand/mascot-success.png",
    support: "/brand/mascot-support.png",
    admin: "/brand/mascot-admin.png",
  }[mascotKind];
  return (
    <div className="card overflow-hidden">
      <div className="industrial-rule" />
      <div className="flex flex-col items-center gap-5 p-8 text-center sm:flex-row sm:text-left">
        {mascot && (
          <div className="relative flex h-28 w-28 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-[#F9F8F3]">
            <Image src={mascotSrc} alt="Маскот USTAR" fill className="object-contain p-1" sizes="112px" />
          </div>
        )}
        <div className="max-w-xl">
          <div className="text-lg font-black text-ink">{title}</div>
          <p className="mt-1 text-sm leading-6 text-mut">{text}</p>
          {action && <div className="mt-4">{action}</div>}
        </div>
      </div>
    </div>
  );
}

export function LevelDots({ current, required }: { current: number; required: number }) {
  return (
    <div className="flex gap-1.5" aria-label={`Уровень ${current} из ${required}`}>
      {Array.from({ length: Math.max(required, 3) }).map((_, i) => (
        <span key={i} className="h-2.5 w-2.5 rounded-sm border border-black/10" style={{
          background: i < current ? "var(--c-accent)" : i < required ? "#e5e4de" : "transparent",
        }} />
      ))}
    </div>
  );
}

export function Readiness({ value, label = "Готовность" }: { value: number; label?: string }) {
  return (
    <div>
      <div className="mb-1.5 flex items-center justify-between text-xs"><span className="font-bold text-mut">{label}</span><strong>{value}%</strong></div>
      <Progress value={value} />
    </div>
  );
}


export function BrandBadge({ title, subtitle, icon = "★", earned = true }: {
  title: string; subtitle?: string; icon?: string; earned?: boolean;
}) {
  return (
    <div className={`brand-badge ${earned ? "" : "brand-badge-muted"}`}>
      <div className="brand-badge-strip"><Image src="/brand/hozmagia-wordmark.png" alt="ХОЗМАГия" width={580} height={110} className="h-6 w-auto" /></div>
      <div className="brand-badge-body">
        <div className="brand-badge-medallion" aria-hidden="true">{icon}</div>
        <div className="min-w-0">
          <div className="brand-badge-title">{title}</div>
          {subtitle && <div className="brand-badge-subtitle">{subtitle}</div>}
        </div>
      </div>
    </div>
  );
}
