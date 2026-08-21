"use client";

import { useRef, useState } from "react";
import { Check, Copy, ImagePlus, Monitor, PanelLeft, RotateCcw, Save, Upload } from "lucide-react";
import { ws } from "@/lib/wsclient";

const DEFAULTS: Record<string, any> = {
  brandName: "USTAR",
  tagline: "Академия",
  primary: "#2B2B2B",
  accent: "#EBC500",
  accentSoft: "#FFF7DA",
  bg: "#F6F5F2",
  surface: "#FFFFFF",
  text: "#2B2B2B",
  muted: "#8A8A8A",
  success: "#3BB273",
  warning: "#F2994A",
  radius: 16,
  logoUrl: "/brand/hozmagia-wordmark.png",
  sidebarHeroUrl: "/brand/ustar-banner.jpg",
  sidebarHeroFit: "cover",
  sidebarHeroPosition: "center",
  sidebarHeroHeight: 108,
  sidebarHeroOverlay: 8,
  loginHeroUrl: "/brand/ustar-banner.jpg",
  loginHeroFit: "contain",
  loginHeroPosition: "center top",
  loginHeroOverlay: 18,
  loginEyebrow: "Корпоративная академия",
  loginTitle: "USTAR АКАДЕМИЯ",
  loginSubtitle: "Обучение, карьерные ступени, навыки, игры и развитие команды — в одном рабочем пространстве.",
};

const LIBRARY = [
  { name: "USTAR banner", url: "/brand/ustar-banner.jpg" },
  { name: "Маскот · админ", url: "/brand/mascot-admin.png" },
  { name: "Маскот · успех", url: "/brand/mascot-success.png" },
  { name: "Маскот · поддержка", url: "/brand/mascot-support.png" },
  { name: "Маскот · думает", url: "/brand/mascot-thinking.png" },
  { name: "Маскот · круг", url: "/brand/mascot-round.png" },
];

const POSITIONS = [
  ["center", "По центру"],
  ["center top", "Сверху"],
  ["center bottom", "Снизу"],
  ["left center", "Слева"],
  ["right center", "Справа"],
  ["left top", "Слева сверху"],
  ["right top", "Справа сверху"],
  ["left bottom", "Слева снизу"],
  ["right bottom", "Справа снизу"],
];

export function BrandStudio({
  branding,
  setBranding,
  save,
  saving,
}: {
  branding: Record<string, any>;
  setBranding: (b: Record<string, any>) => void;
  save: () => void;
  saving: boolean;
}) {
  const b = { ...DEFAULTS, ...branding };
  const [uploading, setUploading] = useState<string>("");
  const [notice, setNotice] = useState("");

  const patch = (values: Record<string, any>) => setBranding({ ...b, ...values });

  async function upload(target: "logoUrl" | "sidebarHeroUrl" | "loginHeroUrl", file?: File) {
    if (!file) return;
    if (!/image\/(png|jpeg|webp)/.test(file.type)) {
      setNotice("Нужен PNG, JPEG или WebP.");
      return;
    }
    if (file.size > 3 * 1024 * 1024) {
      setNotice("Изображение должно быть не больше 3 МБ.");
      return;
    }
    setUploading(target);
    setNotice("");
    try {
      const base64 = await fileToBase64(file);
      const result = await ws<{ url: string; width: number; height: number }>("admin_upload_brand", {
        filename: file.name,
        data: base64,
      });
      patch({ [target]: result.url });
      setNotice(`Загружено ${result.width}×${result.height}. Не забудьте опубликовать бренд.`);
    } catch (e) {
      setNotice(e instanceof Error ? e.message : "Не удалось загрузить изображение");
    } finally {
      setUploading("");
    }
  }

  const colors = [
    ["primary", "Графит"], ["accent", "Акцент"], ["accentSoft", "Мягкий акцент"],
    ["bg", "Фон"], ["surface", "Поверхность"], ["text", "Текст"],
    ["muted", "Вторичный текст"], ["success", "Успех"], ["warning", "Предупреждение"],
  ];

  return (
    <div className="space-y-5">
      <div className="grid gap-5 2xl:grid-cols-[minmax(0,1fr)_440px]">
        <div className="space-y-5">
          <section className="card p-5">
            <SectionTitle kicker="Brand Studio" title="Образ продукта" text="Меняйте фирменные изображения, тексты и кадрирование без пересборки Docker. Загруженные файлы хранятся в Moodle." />
            <div className="mt-5 grid gap-4 md:grid-cols-2">
              <Field label="Название продукта"><input className="input" value={b.brandName} onChange={e => patch({ brandName: e.target.value })} /></Field>
              <Field label="Подзаголовок"><input className="input" value={b.tagline} onChange={e => patch({ tagline: e.target.value })} /></Field>
              <Field label="Логотип / wordmark" wide>
                <AssetField value={b.logoUrl} target="logoUrl" uploading={uploading} onChange={v => patch({ logoUrl: v })} onUpload={upload} compact />
              </Field>
            </div>
          </section>

          <HeroEditor
            title="Верх боковой панели"
            text="Это первое, что видит сотрудник после входа. Для широкого USTAR-баннера можно выбрать кадрирование и точку фокуса."
            icon={PanelLeft}
            url={b.sidebarHeroUrl}
            fit={b.sidebarHeroFit}
            position={b.sidebarHeroPosition}
            overlay={Number(b.sidebarHeroOverlay)}
            height={Number(b.sidebarHeroHeight)}
            target="sidebarHeroUrl"
            uploading={uploading}
            onUpload={upload}
            patch={patch}
            prefix="sidebarHero"
            showHeight
          />

          <HeroEditor
            title="Экран входа"
            text="Публичный login использует те же настройки Brand Studio ещё до авторизации. Можно поставить ровно тот же визуал, что и в основном Moodle."
            icon={Monitor}
            url={b.loginHeroUrl}
            fit={b.loginHeroFit}
            position={b.loginHeroPosition}
            overlay={Number(b.loginHeroOverlay)}
            target="loginHeroUrl"
            uploading={uploading}
            onUpload={upload}
            patch={patch}
            prefix="loginHero"
            extra={
              <div className="grid gap-3 md:grid-cols-2">
                <Field label="Надзаголовок"><input className="input" value={b.loginEyebrow} onChange={e => patch({ loginEyebrow: e.target.value })} /></Field>
                <Field label="Заголовок"><input className="input" value={b.loginTitle} onChange={e => patch({ loginTitle: e.target.value })} /></Field>
                <Field label="Описание" wide><textarea className="input min-h-24" value={b.loginSubtitle} onChange={e => patch({ loginSubtitle: e.target.value })} /></Field>
              </div>
            }
          />

          <section className="card p-5">
            <SectionTitle kicker="Токены" title="Цвета и геометрия" text="Оставляем палитру фирменной и управляемой. Никаких случайных градиентов и независимых цветов по страницам." />
            <div className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
              {colors.map(([key, label]) => <ColorField key={key} label={label} value={b[key]} onChange={v => patch({ [key]: v })} />)}
              <Field label={`Радиус карточек · ${b.radius}px`}><input type="range" min={8} max={24} value={b.radius} onChange={e => patch({ radius: Number(e.target.value) })} className="w-full accent-[var(--c-accent)]" /></Field>
            </div>
          </section>
        </div>

        <aside className="space-y-4 2xl:sticky 2xl:top-6 2xl:self-start">
          <div className="card overflow-hidden">
            <div className="border-b border-black/10 px-4 py-3"><div className="eyebrow">Live preview</div><div className="mt-1 font-black">Sidebar</div></div>
            <SidebarPreview b={b} />
          </div>
          <div className="card overflow-hidden">
            <div className="border-b border-black/10 px-4 py-3"><div className="eyebrow">Live preview</div><div className="mt-1 font-black">Login</div></div>
            <LoginPreview b={b} />
          </div>
          <div className="card p-4">
            <button className="btn btn-quiet w-full" onClick={() => patch({
              loginHeroUrl: b.sidebarHeroUrl,
              loginHeroFit: b.sidebarHeroFit,
              loginHeroPosition: b.sidebarHeroPosition,
            })}><Copy size={16}/>Скопировать sidebar → login</button>
            <button className="btn btn-quiet mt-2 w-full" onClick={() => setBranding({ ...DEFAULTS })}><RotateCcw size={16}/>Фирменный пресет USTAR</button>
          </div>
        </aside>
      </div>

      {notice && <div className="rounded-xl border border-black/10 bg-white px-4 py-3 text-sm font-bold">{notice}</div>}
      <div className="sticky bottom-20 z-20 flex justify-end rounded-xl border border-black/10 bg-white/95 p-3 shadow-lg backdrop-blur lg:bottom-4">
        <button onClick={save} disabled={saving || !!uploading} className="btn btn-primary min-w-52"><Save size={16}/>{saving ? "Публикуем…" : "Опубликовать бренд"}</button>
      </div>
    </div>
  );
}

function HeroEditor({ title, text, icon: Icon, url, fit, position, overlay, height = 120, target, uploading, onUpload, patch, prefix, showHeight, extra }:{
  title:string; text:string; icon:any; url:string; fit:string; position:string; overlay:number; height?:number;
  target:"sidebarHeroUrl"|"loginHeroUrl"; uploading:string;
  onUpload:(target:any,file?:File)=>void; patch:(v:Record<string,any>)=>void; prefix:"sidebarHero"|"loginHero"; showHeight?:boolean; extra?:React.ReactNode;
}) {
  const fitKey = `${prefix}Fit`, positionKey = `${prefix}Position`, overlayKey = `${prefix}Overlay`, heightKey = `${prefix}Height`;
  return <section className="card p-5">
    <div className="flex items-start gap-3"><span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand text-white"><Icon size={19}/></span><div><h3 className="text-lg font-black">{title}</h3><p className="mt-1 max-w-3xl text-sm leading-6 text-mut">{text}</p></div></div>
    <div className="mt-5 grid gap-4 xl:grid-cols-[1.05fr_.95fr]">
      <AssetField value={url} target={target} uploading={uploading} onChange={v=>patch({[target]:v})} onUpload={onUpload}/>
      <div className="space-y-3">
        <div className="grid grid-cols-2 gap-3">
          <Field label="Масштаб"><select className="input" value={fit} onChange={e=>patch({[fitKey]:e.target.value})}><option value="cover">Заполнить</option><option value="contain">Показать целиком</option></select></Field>
          <Field label="Фокус"><select className="input" value={position} onChange={e=>patch({[positionKey]:e.target.value})}>{POSITIONS.map(([v,l])=><option key={v} value={v}>{l}</option>)}</select></Field>
        </div>
        <Field label={`Затемнение · ${overlay}%`}><input type="range" min={0} max={90} value={overlay} onChange={e=>patch({[overlayKey]:Number(e.target.value)})} className="w-full"/></Field>
        {showHeight && <Field label={`Высота · ${height}px`}><input type="range" min={72} max={220} value={height} onChange={e=>patch({[heightKey]:Number(e.target.value)})} className="w-full"/></Field>}
      </div>
    </div>
    {extra && <div className="mt-5 border-t border-black/10 pt-5">{extra}</div>}
  </section>;
}

function AssetField({ value, target, uploading, onChange, onUpload, compact=false }:{value:string;target:any;uploading:string;onChange:(v:string)=>void;onUpload:(t:any,f?:File)=>void;compact?:boolean}){
  const input=useRef<HTMLInputElement|null>(null);
  return <div className="space-y-3">
    {!compact && <div className="relative h-40 overflow-hidden rounded-xl border border-black/10 bg-brand"><div className="absolute inset-0 bg-center bg-no-repeat" style={{backgroundImage:`url("${String(value || "").replace(/"/g, "%22")}")`,backgroundSize:"contain"}}/><div className="absolute inset-0 ring-1 ring-inset ring-white/10"/></div>}
    <Field label="URL изображения"><input className="input font-mono text-xs" value={value||""} onChange={e=>onChange(e.target.value)} placeholder="/brand/... или https://..."/></Field>
    <div className="flex flex-wrap gap-2">
      <input ref={input} type="file" accept="image/png,image/jpeg,image/webp" className="hidden" onChange={e=>{onUpload(target,e.target.files?.[0]);e.currentTarget.value=""}}/>
      <button type="button" className="btn btn-accent" onClick={()=>input.current?.click()} disabled={uploading===target}><Upload size={16}/>{uploading===target?"Загрузка…":"Загрузить"}</button>
      <details className="relative"><summary className="btn btn-quiet cursor-pointer list-none"><ImagePlus size={16}/>Библиотека</summary><div className="absolute left-0 top-12 z-30 grid w-[320px] grid-cols-2 gap-2 rounded-xl border border-black/10 bg-white p-2 shadow-xl">{LIBRARY.map(a=><button key={a.url} type="button" onClick={()=>onChange(a.url)} className="overflow-hidden rounded-lg border border-black/10 text-left"><div className="h-20 bg-brand bg-center bg-contain bg-no-repeat" style={{backgroundImage:`url(${a.url})`}}/><div className="p-2 text-[11px] font-bold">{a.name}</div></button>)}</div></details>
    </div>
  </div>;
}

function SidebarPreview({b}:{b:Record<string,any>}){return <div className="relative mx-auto my-4 h-[430px] w-[220px] overflow-hidden rounded-xl bg-brand text-white shadow-xl"><div className="relative overflow-hidden" style={{height:`${Math.round(Number(b.sidebarHeroHeight)*.75)}px`,backgroundImage:`url(${b.sidebarHeroUrl})`,backgroundSize:b.sidebarHeroFit,backgroundPosition:b.sidebarHeroPosition,backgroundRepeat:"no-repeat"}}><div className="absolute inset-0 bg-black" style={{opacity:Number(b.sidebarHeroOverlay)/100}}/></div><div className="px-4 pb-3 pt-4"><div className="text-lg font-black">{b.brandName} АКАДЕМИЯ</div><div className="mt-1 text-[9px] font-bold uppercase tracking-[.18em] text-white/45">{b.tagline}</div></div><div className="space-y-1 px-3">{["Главная","Обучение","Игры","Навыки","Карьера"].map((x,i)=><div key={x} className={`rounded-lg px-3 py-2 text-[11px] font-bold ${i===0?"bg-[var(--c-accent)] text-brand":"text-white/65"}`}>{x}</div>)}</div></div>}
function LoginPreview({b}:{b:Record<string,any>}){return <div className="grid min-h-[250px] grid-cols-[1.05fr_.95fr] bg-brand"><div className="relative overflow-hidden"><div className="absolute inset-0 bg-center bg-no-repeat" style={{backgroundImage:`url(${b.loginHeroUrl})`,backgroundSize:b.loginHeroFit,backgroundPosition:b.loginHeroPosition}}/><div className="absolute inset-0 bg-black" style={{opacity:Number(b.loginHeroOverlay)/100}}/><div className="absolute inset-x-0 bottom-0 p-4"><div className="text-[9px] font-black uppercase tracking-[.18em] text-[var(--c-accent)]">{b.loginEyebrow}</div><div className="mt-1 text-lg font-black leading-4 text-white">{b.loginTitle}</div></div></div><div className="bg-[var(--c-bg)] p-4"><div className="mt-8 text-[9px] font-black uppercase tracking-[.15em] text-mut">Единый вход</div><div className="mt-2 h-7 rounded-md border border-black/10 bg-white"/><div className="mt-2 h-7 rounded-md border border-black/10 bg-white"/><div className="mt-3 h-7 rounded-md bg-[var(--c-accent)]"/></div></div>}
function SectionTitle({kicker,title,text}:{kicker:string;title:string;text:string}){return <div><div className="eyebrow">{kicker}</div><h2 className="mt-1 text-xl font-black">{title}</h2><p className="mt-1 max-w-3xl text-sm leading-6 text-mut">{text}</p></div>}
function Field({label,children,wide=false}:{label:string;children:React.ReactNode;wide?:boolean}){return <label className={wide?"md:col-span-2":""}><span className="mb-2 block text-[10px] font-black uppercase tracking-[.16em] text-mut">{label}</span>{children}</label>}
function ColorField({label,value,onChange}:{label:string;value:string;onChange:(v:string)=>void}){return <Field label={label}><div className="flex gap-2"><input type="color" value={value||"#000000"} onChange={e=>onChange(e.target.value)} className="h-11 w-14 rounded-lg border border-black/10 bg-white p-1"/><input className="input font-mono text-xs" value={value||""} onChange={e=>onChange(e.target.value)}/></div></Field>}
async function fileToBase64(file:File){return await new Promise<string>((resolve,reject)=>{const r=new FileReader();r.onerror=()=>reject(new Error("Не удалось прочитать файл"));r.onload=()=>{const s=String(r.result||"");resolve(s.includes(",")?s.split(",")[1]:s)};r.readAsDataURL(file)})}
