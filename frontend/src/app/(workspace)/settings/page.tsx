"use client";

import { useWorkspace } from "@/components/WorkspaceProvider";
import { PageTitle } from "@/components/ui";
import { Check } from "lucide-react";

const ACCENTS = ["#FBC502", "#FC9617", "#5CA3D4", "#2D8F5B"];

export default function SettingsPage() {
  const { wsData, setAccent } = useWorkspace();
  const current = wsData?.prefs?.accent || wsData?.branding?.accent || "#FBC502";

  return (
    <>
      <PageTitle
        kicker="Персонализация"
        title="Настройки"
        subtitle="Выберите свой акцентный цвет — он сохранится в вашем профиле."
      />
      <div className="card max-w-md p-6">
        <div className="mb-3 text-xs font-bold uppercase text-mut">Акцентный цвет</div>
        <div className="flex gap-3">
          {ACCENTS.map((hex) => (
            <button key={hex} onClick={() => setAccent(hex)}
              className="flex h-11 w-11 items-center justify-center rounded-full transition hover:scale-110"
              style={{ background: hex }}>
              {current.toLowerCase() === hex.toLowerCase() && <Check size={18} className="text-white" />}
            </button>
          ))}
        </div>
      </div>
    </>
  );
}
