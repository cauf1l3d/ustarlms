"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useMemo, useState } from "react";
import {
  LayoutDashboard, BookOpen, Award, Grid3X3, TrendingUp, Users, FolderOpen, Settings,
  LogOut, Gamepad2, MoreHorizontal, X, UserRoundCog, BarChart3, ShieldCheck, ChevronRight, Medal,
  PanelLeftClose, PanelLeftOpen, PanelTop, Menu, ClipboardCheck, Route,
} from "lucide-react";
import { useWorkspace } from "./WorkspaceProvider";

type NavItem = { href: string; label: string; short: string; icon: any; show: boolean; group: "learn" | "manage" | "system" };
type NavMode = "side" | "compact" | "top";

export default function Shell({ children }: { children: React.ReactNode }) {
  const { wsData, loading } = useWorkspace();
  const pathname = usePathname();
  const router = useRouter();
  const [moreOpen, setMoreOpen] = useState(false);
  const [navMode,setNavMode]=useState<NavMode>("side");

  useEffect(()=>{
    const saved=window.localStorage.getItem("ustar-nav-mode") as NavMode|null;
    if(saved==="side"||saved==="compact"||saved==="top")setNavMode(saved);
  },[]);
  const chooseMode=(mode:NavMode)=>{setNavMode(mode);window.localStorage.setItem("ustar-nav-mode",mode);};

  const nav = useMemo<NavItem[]>(() => {
    if (!wsData) return [];
    const c = wsData.capabilities || { admin: false, hr: false, hrManage: false, executive: false };
    return [
      { href: "/", label: "Главная", short: "Главная", icon: LayoutDashboard, show: true, group: "learn" },
      { href: "/courses", label: "Обучение", short: "Курсы", icon: BookOpen, show: true, group: "learn" },
      { href: "/games", label: "Игры", short: "Игры", icon: Gamepad2, show: true, group: "learn" },
      { href: "/checklists", label: "Чек-листы", short: "Чек-листы", icon: ClipboardCheck, show: true, group: "learn" },
      { href: "/achievements", label: "Достижения", short: "Награды", icon: Medal, show: true, group: "learn" },
      { href: "/skills", label: "Навыки", short: "Навыки", icon: Award, show: true, group: "learn" },
      { href: "/ladder", label: "Карьера", short: "Карьера", icon: TrendingUp, show: true, group: "learn" },
      { href: "/matrix", label: "Матрица навыков", short: "Матрица", icon: Grid3X3, show: true, group: "manage" },
      { href: "/team", label: "Команда", short: "Команда", icon: Users, show: wsData.role === "head" || wsData.role === "superadmin", group: "manage" },
      { href: "/hr/workspace", label: "USTAR HR", short: "HR", icon: UserRoundCog, show: !!c.hr, group: "manage" },
      { href: "/executive", label: "Панель руководства", short: "CEO", icon: BarChart3, show: !!c.executive, group: "manage" },
      { href: "/files", label: "Файлы", short: "Файлы", icon: FolderOpen, show: true, group: "manage" },
      { href: "/admin", label: "Суперадмин", short: "Админ", icon: ShieldCheck, show: !!c.admin, group: "system" },
      { href: "/admin/roadmap", label: "Дорожная карта", short: "Roadmap", icon: Route, show: !!c.admin || !!c.executive || !!c.hr, group: "system" },
      { href: "/settings", label: "Настройки", short: "Настр.", icon: Settings, show: true, group: "system" },
    ].filter((n) => n.show) as NavItem[];
  }, [wsData]);

  if (loading) {
    return <div className="flex min-h-screen items-center justify-center bg-page"><div className="text-center"><div className="mx-auto mb-4 h-2 w-32 overflow-hidden rounded-full bg-black/10"><div className="h-full w-2/3 animate-pulse bg-accent" /></div><p className="text-sm font-bold text-mut">USTAR загружается</p></div></div>;
  }
  if (!wsData) {
    if (typeof window !== "undefined") window.location.href = "/login";
    return null;
  }

  const roleLabel = wsData.role === "superadmin" ? "USTAR Superadmin" : wsData.role === "head" ? "Руководитель" : "Сотрудник";
  const logout = async () => { await fetch("/api/auth/logout", { method: "POST" }); router.push("/login"); };
  const groups = [
    { id: "learn", label: "Развитие" }, { id: "manage", label: "Управление" }, { id: "system", label: "Система" },
  ] as const;
  const mobileMain = ["/", "/courses", "/games", "/ladder"];
  const mobileNav = nav.filter((n) => mobileMain.includes(n.href));
  const isActive=(href:string)=>href==="/"?pathname==="/":pathname.startsWith(href);
  const isCanvasRoute=pathname.startsWith("/hr/workspace");
  const mainLayout=navMode==="side"?"lg:ml-[272px] lg:px-9 xl:px-12 lg:pt-9":navMode==="compact"?"lg:ml-[76px] lg:px-8 xl:px-10 lg:pt-9":"lg:px-8 xl:px-10 lg:pt-[126px]";
  const canvasLayout=navMode==="side"?"lg:ml-[272px] lg:pt-0":navMode==="compact"?"lg:ml-[76px] lg:pt-0":"lg:pt-[106px]";

  return (
    <div className="min-h-screen bg-page">
      {navMode!=="top"&&<aside className={`fixed inset-y-0 left-0 z-30 hidden flex-col bg-brand text-white lg:flex ${navMode==="compact"?"w-[76px]":"w-[272px]"}`}>
        <div className={`relative shrink-0 overflow-hidden border-b border-white/10 bg-black/20 ${navMode==="compact"?"h-[76px]":""}`} style={navMode==="compact"?undefined:{height:`${Math.max(72,Math.min(220,Number(wsData.branding?.sidebarHeroHeight||108)))}px`,backgroundImage:`url("${String(wsData.branding?.sidebarHeroUrl||"/brand/ustar-banner.jpg").replace(/"/g,"%22")}")`,backgroundSize:wsData.branding?.sidebarHeroFit==="contain"?"contain":"cover",backgroundPosition:wsData.branding?.sidebarHeroPosition||"center",backgroundRepeat:"no-repeat"}}>
          {navMode==="compact"?<div className="flex h-full items-center justify-center"><div className="flex h-11 w-11 items-center justify-center rounded-md bg-accent text-xl font-black text-brand">ϟ</div></div>:<><div className="absolute inset-0 bg-black" style={{opacity:Math.max(0,Math.min(90,Number(wsData.branding?.sidebarHeroOverlay||0)))/100}}/><div className="absolute inset-x-0 bottom-0 h-1 bg-accent"/></>}
        </div>
        {navMode==="side"&&<div className="px-5 pb-4 pt-4"><div className="text-[23px] font-black tracking-[-0.04em]">{wsData.branding?.brandName||"USTAR"} АКАДЕМИЯ</div><div className="mt-1 text-xs font-bold uppercase tracking-[.18em] text-white/45">{wsData.branding?.tagline||"люди · знания · рост"}</div></div>}
        <nav className={`flex-1 overflow-y-auto pb-4 ${navMode==="compact"?"px-2 pt-3":"px-3"}`}>
          {groups.map(g=>{const items=nav.filter(n=>n.group===g.id);if(!items.length)return null;return <div key={g.id} className={navMode==="compact"?"mb-3":"mb-5"}>{navMode==="side"&&<div className="px-3 pb-2 text-[10px] font-black uppercase tracking-[.18em] text-white/35">{g.label}</div>}<div className="space-y-1">{items.map(({href,label,icon:Icon})=>{const active=isActive(href);return <Link title={navMode==="compact"?label:undefined} key={href} href={href} className={`flex min-h-11 items-center rounded-md text-sm font-bold transition ${navMode==="compact"?"justify-center px-2":"gap-3 px-3"} ${active?"bg-accent text-brand":"text-white/72 hover:bg-white/10 hover:text-white"}`}><Icon size={18} strokeWidth={2.2}/>{navMode==="side"&&<><span className="flex-1">{label}</span>{active&&<ChevronRight size={14}/>}</>}</Link>})}</div></div>})}
        </nav>
        <div className="border-t border-white/10 p-3">
          {navMode==="side"&&<div className="mb-3 flex items-center gap-3"><div className="flex h-10 w-10 items-center justify-center rounded-md bg-accent font-black text-brand">{wsData.user.firstname?.[0]||"U"}</div><div className="min-w-0"><div className="truncate text-sm font-bold">{wsData.user.fullname}</div><div className="truncate text-[11px] text-white/45">{wsData.position?.name||roleLabel}</div></div></div>}
          <div className={`grid gap-1 ${navMode==="compact"?"grid-cols-1":"grid-cols-3"}`}><button title="Развернуть слева" onClick={()=>chooseMode("side")} className={`shell-mode-btn ${navMode==="side"?"is-active":""}`}><PanelLeftOpen size={17}/></button><button title="Компактная панель" onClick={()=>chooseMode("compact")} className={`shell-mode-btn ${navMode==="compact"?"is-active":""}`}><PanelLeftClose size={17}/></button><button title="Навигация сверху" onClick={()=>chooseMode("top")} className="shell-mode-btn"><PanelTop size={17}/></button></div>
          {navMode==="side"&&<button onClick={logout} className="mt-2 flex min-h-10 w-full items-center gap-2 rounded-md px-3 text-sm font-semibold text-white/55 hover:bg-white/10 hover:text-white"><LogOut size={16}/> Выйти</button>}
        </div>
      </aside>}

      {navMode==="top"&&<div className="fixed inset-x-0 top-0 z-30 hidden lg:block"><header className="flex h-14 items-center gap-4 bg-brand px-5 text-white shadow-sm"><div className="flex items-center gap-3"><div className="flex h-9 w-9 items-center justify-center rounded-md bg-accent text-xl font-black text-brand">ϟ</div><div><div className="text-sm font-black tracking-[.15em]">USTAR</div><div className="text-[9px] font-bold uppercase tracking-[.1em] text-white/40">академия</div></div></div><div className="h-7 w-px bg-white/10"/><div className="min-w-0 flex-1 truncate text-xs font-bold text-white/45">{wsData.position?.name||roleLabel}</div><div className="flex items-center gap-1"><button title="Развернутая панель слева" onClick={()=>chooseMode("side")} className="shell-mode-btn"><PanelLeftOpen size={17}/></button><button title="Компактная панель слева" onClick={()=>chooseMode("compact")} className="shell-mode-btn"><PanelLeftClose size={17}/></button><button title="Навигация сверху" className="shell-mode-btn is-active"><PanelTop size={17}/></button><button title="Выйти" onClick={logout} className="shell-mode-btn"><LogOut size={16}/></button></div></header><nav className="flex h-12 items-center gap-1 overflow-x-auto border-b border-black/10 bg-white px-4 shadow-sm">{nav.map(({href,label,icon:Icon})=>{const active=isActive(href);return <Link key={href} href={href} className={`flex h-9 shrink-0 items-center gap-2 rounded-md px-3 text-xs font-black ${active?"bg-brand text-white":"text-mut hover:bg-page hover:text-brand"}`}><Icon size={15}/>{label}</Link>})}</nav></div>}

      <header className="fixed inset-x-0 top-0 z-20 border-b border-black/10 bg-brand text-white lg:hidden"><div className="brand-stripe h-1.5"/><div className="flex h-14 items-center justify-between px-4"><div><div className="text-sm font-black tracking-[-.02em]">USTAR АКАДЕМИЯ</div><div className="text-[10px] font-bold text-white/45">{wsData.position?.name||roleLabel}</div></div><button onClick={()=>setMoreOpen(true)} className="flex h-10 w-10 items-center justify-center rounded-md bg-white/10" aria-label="Открыть меню"><Menu size={19}/></button></div></header>

      <main className={isCanvasRoute?`h-screen overflow-hidden pb-[70px] pt-[62px] lg:pb-0 ${canvasLayout}`:`mobile-safe-bottom min-h-screen px-4 pb-8 pt-[82px] sm:px-6 lg:pb-12 ${mainLayout}`}><div className={isCanvasRoute?"h-full w-full":"mx-auto max-w-[1680px]"}>{children}</div></main>

      <nav className="mobile-safe-nav fixed inset-x-0 bottom-0 z-30 grid grid-cols-5 border-t border-black/10 bg-white px-1 pt-1.5 lg:hidden">{mobileNav.map(({href,short,icon:Icon})=>{const active=isActive(href);return <Link key={href} href={href} className={`flex min-h-[54px] flex-col items-center justify-center gap-1 rounded-md text-[10px] font-bold ${active?"text-brand":"text-mut"}`}><span className={`flex h-7 w-10 items-center justify-center rounded-md ${active?"bg-accent":""}`}><Icon size={19}/></span>{short}</Link>})}<button onClick={()=>setMoreOpen(true)} className="flex min-h-[54px] flex-col items-center justify-center gap-1 rounded-md text-[10px] font-bold text-mut"><span className="flex h-7 w-10 items-center justify-center rounded-md"><MoreHorizontal size={20}/></span>Ещё</button></nav>

      {moreOpen&&<div className="fixed inset-0 z-50 lg:hidden" role="dialog" aria-modal="true"><button className="absolute inset-0 bg-black/45" onClick={()=>setMoreOpen(false)} aria-label="Закрыть меню"/><div className="mobile-safe-nav absolute inset-x-0 bottom-0 max-h-[78vh] overflow-y-auto rounded-t-xl bg-page p-4 shadow-2xl"><div className="mb-4 flex items-center justify-between"><div><div className="eyebrow">USTAR</div><div className="mt-1 font-black">Все разделы</div></div><button onClick={()=>setMoreOpen(false)} className="flex h-11 w-11 items-center justify-center rounded-md border border-black/10 bg-white"><X size={20}/></button></div><div className="grid grid-cols-2 gap-2">{nav.map(({href,label,icon:Icon})=>{const active=isActive(href);return <Link key={href} href={href} onClick={()=>setMoreOpen(false)} className={`card flex min-h-[72px] items-center gap-3 p-3 text-sm font-bold ${active?"border-brand bg-accentSoft":""}`}><span className="flex h-9 w-9 items-center justify-center rounded-md bg-brand text-white"><Icon size={18}/></span>{label}</Link>})}</div><button onClick={logout} className="btn btn-quiet mt-3 w-full"><LogOut size={17}/> Выйти из аккаунта</button></div></div>}
    </div>
  );
}
