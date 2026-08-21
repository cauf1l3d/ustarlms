"use client";

import { useEffect, useRef, useState } from "react";
import { UploadCloud, HardDrive } from "lucide-react";
import { PageTitle, Empty } from "@/components/ui";

interface FilesInfo { filecount: number; filesize: number; filesizewithoutreferences: number }

export default function FilesPage() {
  const [info, setInfo] = useState<FilesInfo | null>(null);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState("");
  const inputRef = useRef<HTMLInputElement>(null);

  const load = () =>
    fetch("/api/files/list").then((r) => r.json()).then(setInfo).catch(() => {});
  useEffect(() => { load(); }, []);

  const upload = async (file: File) => {
    setBusy(true);
    setMsg("");
    const fd = new FormData();
    fd.append("file", file);
    const res = await fetch("/api/files/upload", { method: "POST", body: fd });
    const data = await res.json();
    setBusy(false);
    setMsg(res.ok ? `Файл «${data.name}» загружен ✓` : data.error || "Ошибка загрузки");
    load();
  };

  const mb = (b: number) => (b / 1024 / 1024).toFixed(1);

  return (
    <>
      <PageTitle
        kicker="Хранилище"
        title="Мои файлы"
        subtitle="Личное файловое хранилище — работает на приватных файлах Moodle, доступно только вам."
      />

      <div
        className="card float-in mb-6 flex cursor-pointer flex-col items-center justify-center border-2 border-dashed border-black/10 p-10 text-center transition hover:border-accent"
        onClick={() => inputRef.current?.click()}
        onDragOver={(e) => e.preventDefault()}
        onDrop={(e) => {
          e.preventDefault();
          const f = e.dataTransfer.files?.[0];
          if (f) upload(f);
        }}
      >
        <UploadCloud size={36} className="mb-3 text-mut" />
        <p className="font-bold text-ink">{busy ? "Загружаем…" : "Перетащите файл или нажмите"}</p>
        <p className="mt-1 text-xs text-mut">До 50 МБ за файл</p>
        <input
          ref={inputRef} type="file" className="hidden"
          onChange={(e) => e.target.files?.[0] && upload(e.target.files[0])}
        />
      </div>
      {msg && <p className="mb-6 text-sm font-semibold">{msg}</p>}

      {info ? (
        <div className="card flex items-center gap-4 p-5">
          <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-accentSoft text-brand">
            <HardDrive size={20} />
          </div>
          <div>
            <div className="font-bold">{info.filecount} файл(ов) · {mb(info.filesize)} МБ</div>
            <div className="text-xs text-mut">
              Полный список и скачивание — в «Личных файлах» LMS (меню профиля).
            </div>
          </div>
        </div>
      ) : (
        <Empty text="Загружаем сведения о хранилище…" />
      )}
    </>
  );
}
