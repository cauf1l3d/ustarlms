"use client";

import React, { createContext, useContext, useEffect, useState } from "react";
import { ws } from "@/lib/wsclient";

export type Role = "employee" | "head" | "superadmin";

export interface Workspace {
  user: { id: number; fullname: string; firstname: string; email: string };
  role: Role;
  position: { id: string; name: string; level: number; department: string; next?: string | null } | null;
  department: { id: string; name: string } | null;
  branding: Record<string, any>;
  prefs: Record<string, any>;
  capabilities: { admin: boolean; hr: boolean; hrManage: boolean; executive: boolean };
}

const Ctx = createContext<{
  wsData: Workspace | null;
  loading: boolean;
  setAccent: (hex: string) => void;
  reload: () => void;
}>({ wsData: null, loading: true, setAccent: () => {}, reload: () => {} });

export const useWorkspace = () => useContext(Ctx);

function applyTheme(branding: Record<string, any>, prefs: Record<string, any>) {
  const r = document.documentElement;
  const set = (k: string, v?: string) => v && r.style.setProperty(k, v);
  set("--c-primary", branding.primary);
  set("--c-accent", prefs.accent || branding.accent);
  set("--c-accent-soft", branding.accentSoft);
  set("--c-bg", branding.bg);
  set("--c-surface", branding.surface);
  set("--c-text", branding.text);
  set("--c-muted", branding.muted);
  set("--c-success", branding.success);
  set("--c-warning", branding.warning);
  if (branding.radius) r.style.setProperty("--radius-card", `${Math.min(18, Math.max(8, branding.radius))}px`);
}

export function WorkspaceProvider({ children }: { children: React.ReactNode }) {
  const [wsData, setWsData] = useState<Workspace | null>(null);
  const [loading, setLoading] = useState(true);

  const load = () => {
    setLoading(true);
    ws<Workspace>("workspace")
      .then((data) => {
        setWsData({ ...data, capabilities: data.capabilities || { admin: false, hr: false, hrManage: false, executive: false } });
        applyTheme(data.branding || {}, data.prefs || {});
      })
      .catch(() => setWsData(null))
      .finally(() => setLoading(false));
  };

  useEffect(load, []);

  const setAccent = (hex: string) => {
    document.documentElement.style.setProperty("--c-accent", hex);
    setWsData((d) => (d ? { ...d, prefs: { ...d.prefs, accent: hex } } : d));
    ws("save_prefs", { prefs: JSON.stringify({ accent: hex }) }).catch(() => {});
  };

  return <Ctx.Provider value={{ wsData, loading, setAccent, reload: load }}>{children}</Ctx.Provider>;
}
