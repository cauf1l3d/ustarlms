"use client";

import { useEffect, useMemo, useState } from "react";
import { ws } from "@/lib/wsclient";
import { Empty, PageTitle } from "@/components/ui";

interface MatrixData {
  role: string;
  positions: { id: string; name: string; level: number; department: string }[];
  skills: { id: string; name: string; category: string }[];
  matrix: Record<string, Record<string, number>>;
}

export default function MatrixPage() {
  const [data, setData] = useState<MatrixData | null>(null);
  const [positionId, setPositionId] = useState("");

  useEffect(() => { ws<MatrixData>("matrix").then((d) => { setData(d); setPositionId(d.positions[0]?.id || ""); }); }, []);
  const selected = useMemo(() => data?.positions.find((p) => p.id === positionId) || data?.positions[0], [data, positionId]);
  if (!data) return <Empty title="Загружаем матрицу" text="Сверяем требования по должностям…" mascot />;

  const scopeNote = data.role === "superadmin"
    ? "Полная матрица компании."
    : data.role === "head"
    ? "Матрица должностей вашего отдела."
    : "Требования вашей должности.";

  return (
    <>
      <PageTitle kicker="Компетенции" title="Матрица навыков" subtitle={scopeNote} />
      {data.positions.length === 0 ? <Empty title="Нет привязки" text="Ваша должность пока не связана с матрицей навыков." mascot /> : <>
        <div className="mb-4 md:hidden">
          <select className="input" value={selected?.id || ""} onChange={(e) => setPositionId(e.target.value)}>
            {data.positions.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </select>
        </div>

        <div className="grid gap-3 md:hidden">
          {data.skills.map((skill) => {
            const level = selected ? data.matrix[selected.id]?.[skill.id] : undefined;
            if (!level) return null;
            return <div key={skill.id} className="card p-4">
              <div className="flex items-start justify-between gap-4">
                <div><div className="font-black">{skill.name}</div><div className="mt-1 text-xs text-mut">{skill.category}</div></div>
                <span className="pill bg-accent text-brand">L{level}</span>
              </div>
              <div className="mt-4 grid grid-cols-3 gap-1.5">
                {[1,2,3].map((x) => <span key={x} className={`h-2 rounded-sm ${x <= level ? "bg-brand" : "bg-black/10"}`} />)}
              </div>
            </div>;
          })}
        </div>

        <div className="card hidden overflow-x-auto md:block">
          <table className="w-full min-w-[720px] text-sm">
            <thead><tr className="border-b border-black/10 bg-page text-left">
              <th className="p-4 text-[10px] font-black uppercase tracking-wider text-mut">Навык</th>
              {data.positions.map((p) => <th key={p.id} className="p-4 text-center text-[10px] font-black uppercase tracking-wider text-mut">{p.name}</th>)}
            </tr></thead>
            <tbody>{data.skills.map((s, i) => <tr key={s.id} className={`border-b border-black/5 last:border-0 ${i % 2 ? "bg-page/50" : ""}`}>
              <td className="p-4"><div className="font-black">{s.name}</div><div className="mt-1 text-[11px] text-mut">{s.category}</div></td>
              {data.positions.map((pos) => {
                const lvl = data.matrix[pos.id]?.[s.id];
                return <td key={pos.id} className="p-4 text-center">{lvl ? <span className={`pill ${lvl >= 3 ? "bg-accent" : lvl === 2 ? "bg-accentSoft" : "bg-page"} text-brand`}>L{lvl}</span> : <span className="text-black/15">—</span>}</td>;
              })}
            </tr>)}</tbody>
          </table>
          <div className="flex gap-5 border-t border-black/10 p-4 text-xs text-mut"><span><strong>L1</strong> базовый</span><span><strong>L2</strong> уверенный</span><span><strong>L3</strong> экспертный</span></div>
        </div>
      </>}
    </>
  );
}
