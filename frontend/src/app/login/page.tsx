"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { ArrowRight, LockKeyhole, ShieldCheck } from "lucide-react";

type PublicBranding = Record<string, any>;

const FALLBACK: PublicBranding = {
  brandName: "USTAR",
  tagline: "Академия",
  primary: "#2B2B2B",
  accent: "#EBC500",
  bg: "#F6F5F2",
  text: "#2B2B2B",
  muted: "#8A8A8A",
  loginHeroUrl: "/brand/ustar-banner.jpg",
  loginHeroFit: "contain",
  loginHeroPosition: "center top",
  loginHeroOverlay: 18,
  loginEyebrow: "Корпоративная академия",
  loginTitle: "USTAR АКАДЕМИЯ",
  loginSubtitle: "Обучение, карьерные ступени, навыки, игры и развитие команды — в одном рабочем пространстве.",
};

export default function LoginPage() {
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [branding, setBranding] = useState<PublicBranding>(FALLBACK);
  const router = useRouter();

  useEffect(() => {
    fetch("/api/public-branding", { cache: "no-store" })
      .then(r => r.json())
      .then(data => setBranding({ ...FALLBACK, ...(data || {}) }))
      .catch(() => {});
  }, []);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true); setError("");
    const res = await fetch("/api/auth/login", {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ username, password }),
    });
    setBusy(false);
    if (res.ok) router.push("/");
    else {
      const data = await res.json().catch(() => ({}));
      setError(data.error || "Не удалось войти. Проверьте логин и пароль.");
    }
  };

  const hero = String(branding.loginHeroUrl || FALLBACK.loginHeroUrl).replace(/"/g, "%22");
  const overlay = Math.max(0, Math.min(90, Number(branding.loginHeroOverlay ?? 18))) / 100;

  return (
    <main
      className="min-h-screen bg-brand lg:grid lg:grid-cols-[1.08fr_.92fr]"
      style={{
        ["--c-primary" as any]: branding.primary || FALLBACK.primary,
        ["--c-accent" as any]: branding.accent || FALLBACK.accent,
        ["--c-bg" as any]: branding.bg || FALLBACK.bg,
        ["--c-text" as any]: branding.text || FALLBACK.text,
        ["--c-muted" as any]: branding.muted || FALLBACK.muted,
      }}
    >
      <section className="relative hidden min-h-screen overflow-hidden border-r border-white/10 bg-brand lg:block">
        <div
          className="absolute inset-0 bg-no-repeat"
          style={{
            backgroundImage: `url("${hero}")`,
            backgroundSize: branding.loginHeroFit === "cover" ? "cover" : "contain",
            backgroundPosition: branding.loginHeroPosition || "center top",
          }}
        />
        <div className="absolute inset-0 bg-black" style={{ opacity: overlay }} />
        <div className="absolute inset-x-0 top-0 h-2 bg-accent" />
        <div className="absolute inset-x-0 bottom-0 h-[54%] bg-[linear-gradient(180deg,transparent,rgba(25,25,25,.94)_44%)]" />
        <div className="relative z-10 flex h-full min-h-screen flex-col justify-end p-12 xl:p-16">
          <div className="eyebrow !text-accent">{branding.loginEyebrow}</div>
          <h1 className="mt-4 max-w-2xl whitespace-pre-line text-6xl font-black leading-[.9] tracking-[-.06em] text-white xl:text-7xl">{branding.loginTitle}</h1>
          <p className="mt-6 max-w-xl text-base leading-7 text-white/68">{branding.loginSubtitle}</p>
          <div className="mt-9 flex gap-3 text-xs font-bold text-white/55"><span className="pill border border-white/15">Люди</span><span className="pill border border-white/15">Знания</span><span className="pill border border-white/15">Рост</span></div>
        </div>
      </section>

      <section className="flex min-h-screen flex-col bg-page lg:justify-center">
        <div
          className="relative h-[180px] shrink-0 overflow-hidden bg-brand bg-no-repeat lg:hidden"
          style={{
            backgroundImage: `url("${hero}")`,
            backgroundSize: branding.loginHeroFit === "cover" ? "cover" : "contain",
            backgroundPosition: branding.loginHeroPosition || "center top",
          }}
        >
          <div className="absolute inset-0 bg-black" style={{ opacity: overlay * .65 }} />
          <div className="absolute inset-x-0 bottom-0 h-1.5 bg-accent" />
        </div>
        <div className="flex flex-1 items-center justify-center px-4 py-10 sm:px-8">
          <div className="w-full max-w-[430px]">
            <div className="eyebrow">Единый вход</div>
            <h2 className="mt-3 text-4xl font-black tracking-[-.045em] text-ink">Войти в {branding.brandName || "USTAR"}</h2>
            <p className="mt-3 text-sm leading-6 text-mut">Используйте рабочий логин и пароль Moodle LMS. Пароль не хранится во фронтенде.</p>

            <form onSubmit={submit} className="mt-8 space-y-4">
              <label className="block"><span className="mb-1.5 block text-xs font-black uppercase tracking-wide text-mut">Логин</span><input className="input" placeholder="ivanov.i" value={username} onChange={(e) => setUsername(e.target.value)} autoComplete="username" autoFocus /></label>
              <label className="block"><span className="mb-1.5 block text-xs font-black uppercase tracking-wide text-mut">Пароль</span><div className="relative"><LockKeyhole className="absolute left-3 top-1/2 -translate-y-1/2 text-mut" size={17}/><input className="input pl-10" placeholder="••••••••" type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" /></div></label>
              {error && <div className="rounded-lg border border-red-200 bg-red-50 px-3 py-3 text-sm font-semibold leading-5 text-red-800">{error}</div>}
              <button disabled={busy || !username || !password} className="btn btn-accent w-full justify-between px-5 disabled:cursor-not-allowed disabled:opacity-50"><span>{busy ? "Проверяем доступ…" : "Войти"}</span><ArrowRight size={18}/></button>
            </form>
            <div className="mt-6 flex items-start gap-2 border-t border-black/10 pt-5 text-xs leading-5 text-mut"><ShieldCheck size={17} className="mt-0.5 shrink-0 text-brand"/><span>USTAR использует персональный Moodle web-service token в защищённой httpOnly-сессии. Токен не передаётся в браузерный JavaScript.</span></div>
          </div>
        </div>
      </section>
    </main>
  );
}
