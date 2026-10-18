import React, { useEffect, useRef, useState } from "react";
import { createRoot } from "react-dom/client";
import "./style.css";
import {
  Activity,
  LayoutDashboard,
  GraduationCap,
  Server,
  DatabaseBackup,
  FlaskConical,
  Cpu,
  MemoryStick,
  HardDrive,
  Clock3,
  ArrowUpRight,
  ShieldCheck,
  LogOut,
} from "lucide-react";
import { TimeChart as Chart } from "./charts";
import { Operations } from "./operations";

type Check = {
  id: string;
  label: string;
  domain: string;
  status: string;
  reason_code: string;
  source: string;
  observed_at: string;
  source_observed_at?: string;
  source_age_seconds?: number;
  value?: unknown;
};
type DiskCapacity = {
  total_bytes?: number;
  available_bytes?: number;
  used_percent?: number;
  free_inode_percent?: number | null;
};
type Host = {
  hostname?: string;
  cores?: number;
  cpu_percent?: number;
  load?: number[];
  uptime_seconds?: number;
  memory?: {
    total_bytes: number;
    available_bytes: number;
    cache_bytes: number;
    swap_used_bytes: number;
  };
  disk?: DiskCapacity;
};
type Backup = {
  file?: string;
  bytes?: number;
  sha256?: string;
  completed_at?: string;
  age_seconds?: number;
  status?: string;
  attempt_phase?: string;
  recovery_marker_present?: boolean;
};
type Snapshot = {
  observed_at: string | null;
  received_at: string;
  age_seconds: number | null;
  stale: boolean;
  checks: Check[];
  host: Host;
  hdd?: DiskCapacity;
  backup: Backup;
  cron?: {
    last_attempt_at?: string;
    last_success_at?: string | null;
    worker_exit_code?: number;
    new_task_failures?: number;
    ustar_error_lines?: number;
    scheduled_failed?: number;
    scheduled_overdue_10m?: number;
    adhoc_queued?: number;
  };
  http_local?: { code: number | null; total_ms: number | null };
  containers: Record<string, unknown>[];
  units?: Record<string, unknown>[];
  lab: Record<string, unknown>;
  domains: Record<string, string>;
  database?: Record<string, number | string>;
  clock?: { source: string; offset_ms: number; jitter_ms: number };
  smart?: {
    observed_at: string | null;
    disks: {
      device: string;
      status: string;
      temperature_c?: number;
      sectors?: Record<string, number>;
      historical_error_log?: boolean;
      health_passed?: boolean;
      unavailable_attributes?: string[];
    }[];
  };
  notifications?: {
    pending: number;
    delivery_failures: number;
    polling: string;
    evaluation?: string;
    status?: string;
    observed_at?: string | null;
    rules: {
      key: string;
      state: string;
      emitted: string;
      since: number;
      last: number;
      sent: number;
    }[];
  };
};
type Incident = {
  id: number;
  at: number;
  check_id: string;
  status: string;
  reason_code: string;
};
type EventHistory = {
  events: { at: number; event: string }[];
  incidents?: Incident[];
  incidents_status?: string;
  incidents_observed_at?: string | null;
};
type Session = {
  username: string;
  role: string;
  csrf: string;
  expires_at: number;
};
type Series = {
  points: [number, number | null][];
  status: string;
  reason_code: string;
  step_seconds?: number;
  source?: string;
};
const titles: Record<string, string> = {
  overview: "Обзор",
  academy: "Академия",
  server: "Сервер",
  backups: "Бэкапы",
  labs: "Стенды",
  events: "События",
};
const domains: Record<string, string> = {
  academy: "Академия",
  server: "Сервер",
  data: "Сохранность данных",
  monitoring: "Мониторинг",
};
const statuses: Record<string, string> = {
  ok: "Норма",
  warning: "Внимание",
  critical: "Авария",
  no_data: "Нет данных",
  stopped: "Остановлен",
};
const labels: Record<string, string> = {
  host: "Ресурсы сервера",
  recovery: "Возобновление Академии после бэкапа",
  containers: "Контейнеры Академии",
  hdd: "Диск бэкапов",
  backup: "Последняя копия",
  lab: "Стенд восстановления",
  restore: "Приёмка восстановления",
  units: "Системные службы",
  http_local: "Локальный ответ Академии",
  http_external: "HTTP из независимой точки сети / VPN",
  cron: "Успех фоновых заданий",
  ssd_space: "Свободное место SSD",
  ssd_inodes: "Свободные inode SSD",
  hdd_space: "Свободное место диска бэкапов",
  hdd_inodes: "Свободные inode диска бэкапов",
  smart: "SMART дисков",
  clock: "Синхронизация времени",
  database: "PostgreSQL",
  collector: "Сборщик",
  rules: "Правила и доставка алертов",
  resource_rules: "Правила нагрузки",
  observer: "Независимый наблюдатель",
  cpu_sustained: "CPU выше 85 % в течение 10 минут",
  ram_sustained: "Мало свободной RAM",
  container_oom: "Нехватка памяти в контейнере Академии",
};
const reasons: Record<string, string> = {
  measured: "Измерение получено",
  recovery_required:
    "Сохранён маркер остановки Академии. Нужна проверка возобновления.",
  not_configured: "Проверка ещё не подключена",
  disabled: "Проверка отключена в конфигурации сборщика",
  stale: "Измерение устарело",
  source_unavailable: "Источник недоступен",
  probe_failed:
    "Проверка не выполнена: источник недоступен или данные не прошли проверку",
  backup_receipt: "По отчёту последней завершённой копии",
  controller_receipt_only:
    "Отчёт контроллера; доступность стенда отдельно не измерена",
  head_local_only:
    "Локальный HEAD-запрос; доступность снаружи проверяется отдельно",
  no_samples: "За этот период нет измерений",
  series_missing_or_ambiguous: "Нет однозначного источника метрики",
  cron_receipt: "Успех worker и отсутствие новых ошибок по отчёту cron",
  cron_worker_failed: "Последний запуск cron завершился с ошибкой",
  cron_new_errors: "В последнем запуске появились новые ошибки задач",
  cron_success_delayed:
    "С последнего подтверждённого успеха прошло больше 3 минут",
  cron_success_overdue:
    "С последнего подтверждённого успеха прошло больше 10 минут",
  cron_result_incomplete:
    "Вывод cron обрезан: отсутствие новых ошибок не подтверждено",
  cron_receipt_regressed: "Дата отчёта cron старше уже наблюдавшегося успеха",
  disk_capacity: "Текущее свободное место и inode; длительные правила отдельно",
  disk_low_space: "Свободного места меньше стартового порога",
  disk_low_inodes: "Свободных inode меньше стартового порога",
  inodes_unsupported: "Файловая система не предоставила счётчик inode",
  database_aggregates:
    "Отдельная роль БД: только агрегаты соединений, ожиданий и размера",
  outbox_status:
    "Состояние очереди и связи с Telegram; получение оператором проверяется отдельно",
  smart_measurement:
    "SMART: текущее здоровье и выбранные счётчики. Старые ошибки и vendor wear рассматриваются отдельно",
  smart_partial_attributes:
    "SMART получен частично: диск не сообщил часть атрибутов; доступные измерения сохранены",
  smart_not_collected: "Первый отчёт SMART ещё не получен",
  smart_stale: "Отчёт SMART старше 16 минут; актуальное состояние неизвестно",
  smart_clock_skew: "Время отчёта SMART находится в будущем; проверьте часы",
  smart_receipt_invalid: "Отчёт SMART недоступен или не прошёл проверку",
  smart_probe_failed: "SMART одного из накопителей не удалось прочитать",
  smart_health_failed: "Накопитель сообщил отрицательную самооценку SMART",
  smart_sector_error: "Есть ожидающие или неисправимые секторы",
  smart_reallocated: "Есть переназначенные секторы",
  clock_measurement: "Выбранный NTP peer, смещение и jitter текущего измерения",
  sustained_rules:
    "Оценка непрерывного окна CPU/RAM по свежим измерениям. Пропуск телеметрии сбрасывает окно",
};
const phase: Record<string, string> = {
  ready: "Подготовлен",
  stopped: "Остановлен",
  failed: "Ошибка",
  failed_stopped: "Остановлен после ошибки",
  cleanup_required: "Требуется проверка",
  no_data: "Нет данных",
  prepare: "Подготовка",
  capture: "Снятие копии",
  verify: "Проверка",
  readiness: "Проверка доступности",
  complete: "Завершено",
};
const events: Record<string, string> = {
  login_succeeded: "Вход в пульт",
  login_failed: "Неудачная попытка входа",
  reauth_failed: "Неудачная проверка текущего пароля",
  logout: "Выход из пульта",
  password_changed_sessions_revoked: "Пароль изменён; сессии отозваны",
  user_updated_sessions_revoked: "Учётная запись обновлена; сессии отозваны",
};
const value = (n: number | undefined | null, suffix = "", decimals = 1) =>
  n == null || !Number.isFinite(n)
    ? "—"
    : n.toLocaleString("ru-RU", { maximumFractionDigits: decimals }) + suffix;
const gib = (n?: number) => value(n == null ? n : n / 2 ** 30, " ГиБ");
const date = (s?: string | null) =>
  s && Number.isFinite(Date.parse(s))
    ? new Date(s).toLocaleString("ru-RU", {
        timeZone: "Europe/Moscow",
        day: "2-digit",
        month: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
      }) + " МСК"
    : "Нет данных";
const unixDate = (s?: number) =>
  s != null && Number.isFinite(s) && s > 0
    ? date(new Date(s * 1000).toISOString())
    : "Ещё не было";
const freshAt = (
  s: string | null | undefined,
  now: number,
  maximum = 45000,
) => {
  const measured = Date.parse(s || "");
  return (
    Number.isFinite(measured) &&
    now - measured >= -5000 &&
    now - measured <= maximum
  );
};
const age = (n?: number | null) =>
  n == null
    ? "Нет данных"
    : n < 60
      ? `${Math.max(0, Math.floor(n))} с`
      : n < 3600
        ? `${Math.floor(n / 60)} мин`
        : `${Math.floor(n / 3600)} ч`;

class ApiError extends Error {
  constructor(
    public status: number,
    public retry: string | null,
  ) {
    super("request_failed");
  }
}
async function api(path: string, options: RequestInit = {}) {
  const response = await fetch("/api/" + path, {
    ...options,
    credentials: "same-origin",
    signal: options.signal || AbortSignal.timeout(6500),
    headers: { "Content-Type": "application/json", ...options.headers },
  });
  if (!response.ok)
    throw new ApiError(response.status, response.headers.get("Retry-After"));
  return response.json();
}
function Badge({ status }: { status: string }) {
  return (
    <span className={"badge " + status}>
      <i aria-hidden="true" />
      {statuses[status] || "Нет данных"}
    </span>
  );
}
function Empty({
  title = "Нет данных",
  children,
}: {
  title?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="empty">
      <strong>{title}</strong>
      <p>{children}</p>
    </div>
  );
}
function Metric({
  label,
  number,
  detail,
  kind = "cpu",
  progress,
  status,
}: {
  label: string;
  number: string;
  detail: string;
  kind?: string;
  progress?: number;
  status?: string;
}) {
  const Icon =
    kind === "ram"
      ? MemoryStick
      : kind === "cpu"
        ? Cpu
        : kind === "http"
          ? Activity
          : kind === "disk"
            ? HardDrive
            : Clock3;
  return (
    <div className={"metric metric-" + kind} data-status={status}>
      <div className="metric-label">
        <span>{label}</span>
        <Icon size={20} aria-hidden="true" />
      </div>
      <strong>{number}</strong>
      {status && <Badge status={status} />}
      <small>{detail}</small>
      {progress != null && Number.isFinite(progress) && (
        <progress
          max={100}
          value={Math.max(0, Math.min(100, progress))}
          aria-label={label}
        />
      )}
    </div>
  );
}
function Checks({
  checks,
  onSelect,
}: {
  checks: Check[];
  onSelect: (c: Check) => void;
}) {
  return (
    <div className="checks">
      {checks.map((c) => (
        <button className="check-row" key={c.id} onClick={() => onSelect(c)}>
          <span>
            <strong>{labels[c.id] || c.label}</strong>
            <small>{reasons[c.reason_code] || "Подробности проверки"}</small>
          </span>
          <Badge status={c.status} />
          <span className="chevron" aria-hidden="true">
            ›
          </span>
        </button>
      ))}
    </div>
  );
}
function BackupDisk({
  disk,
  spaceAvailable,
  inodesAvailable,
  status,
  observedAt,
}: {
  disk?: DiskCapacity;
  spaceAvailable: boolean;
  inodesAvailable: boolean;
  status: string;
  observedAt?: string;
}) {
  return (
    <section className="panel hdd-capacity">
      <div className="section-title">
        <h2>Диск бэкапов · ёмкость</h2>
        <Badge status={status} />
      </div>
      <dl>
        <dt>Всего</dt>
        <dd>{spaceAvailable ? gib(disk?.total_bytes) : "—"}</dd>
        <dt>Свободно</dt>
        <dd>{spaceAvailable ? gib(disk?.available_bytes) : "—"}</dd>
        <dt>Занято</dt>
        <dd>{spaceAvailable ? value(disk?.used_percent, " %") : "—"}</dd>
        <dt>Свободных inode</dt>
        <dd>{inodesAvailable ? value(disk?.free_inode_percent, " %") : "—"}</dd>
      </dl>
      <p className="muted">
        {spaceAvailable || inodesAvailable
          ? `Измерение файловой системы: ${date(observedAt)}.`
          : "Свежие показания файловой системы не получены."}{" "}
        Состояние архива и SMART проверяются отдельно.
      </p>
    </section>
  );
}
function LoginScreen({
  onLogin,
  notice,
}: {
  onLogin: () => void;
  notice: string;
}) {
  const [error, setError] = useState(""),
    [pending, setPending] = useState(false);
  return (
    <main className="login">
      <form
        onSubmit={async (e) => {
          e.preventDefault();
          setPending(true);
          setError("");
          const form = new FormData(e.currentTarget);
          try {
            await api("login", {
              method: "POST",
              body: JSON.stringify(Object.fromEntries(form)),
            });
            onLogin();
          } catch (err) {
            setError(
              err instanceof ApiError && err.status === 429
                ? `Слишком много попыток. Повторите через ${err.retry || "несколько"} с.`
                : "Не удалось войти. Проверьте логин, пароль и соединение.",
            );
          } finally {
            setPending(false);
          }
        }}
      >
        <div className="brand-mark" aria-hidden="true">
          U
        </div>
        <p className="eyebrow">USTAR / ПУЛЬТ</p>
        <h1>Вход в управление</h1>
        <p>Состояние сервера и Академии в одном месте.</p>
        {notice && (
          <p className="notice" role="status">
            {notice}
          </p>
        )}
        <label>
          Логин
          <input
            name="username"
            autoComplete="username"
            autoCapitalize="none"
            required
            maxLength={48}
          />
        </label>
        <label>
          Пароль
          <input
            name="password"
            type="password"
            autoComplete="current-password"
            required
            maxLength={256}
          />
        </label>
        <label>
          Код TOTP, если подключён
          <input
            name="otp"
            inputMode="numeric"
            autoComplete="one-time-code"
            maxLength={6}
            pattern="[0-9]{6}"
          />
        </label>
        {error && (
          <p className="error-text" role="alert">
            {error}
          </p>
        )}
        <button className="primary" disabled={pending}>
          {pending ? "Проверяем…" : "Войти"}
        </button>
        <small>
          Личная учётная запись пульта. Доступ к Академии не меняется.
        </small>
      </form>
    </main>
  );
}
function App() {
  const [session, setSession] = useState<Session | null>(null),
    [initial, setInitial] = useState(true),
    [notice, setNotice] = useState("");
  const [page, setPage] = useState(
    location.hash.slice(1) in titles ? location.hash.slice(1) : "overview",
  );
  const [data, setData] = useState<Snapshot | null>(null),
    [lost, setLost] = useState(false),
    [period, setPeriod] = useState("1h");
  const [series, setSeries] = useState<Record<string, Series>>({}),
    [history, setHistory] = useState<EventHistory | null>(null),
    [historyLost, setHistoryLost] = useState(false);
  const [selected, setSelected] = useState<Check | null>(null),
    [selectedDomain, setSelectedDomain] = useState<string | null>(null),
    [profile, setProfile] = useState(false),
    [profileError, setProfileError] = useState("");
  const [tick, setTick] = useState(Date.now());
  const dialog = useRef<HTMLDialogElement>(null);
  const lastActivity = useRef(0);
  const expire = () => {
    setSession(null);
    setData(null);
    setSeries({});
    setHistory(null);
    setHistoryLost(false);
    setSelected(null);
    setSelectedDomain(null);
    setProfile(false);
    setNotice("Сессия завершилась. Войдите снова.");
  };
  const getSession = async () => {
    try {
      setSession(await api("session"));
      setNotice("");
    } catch (err) {
      if (!(err instanceof ApiError && err.status === 401))
        setNotice("Нет соединения с пультом.");
    } finally {
      setInitial(false);
    }
  };
  useEffect(() => {
    void getSession();
    const fn = () =>
      setPage(
        location.hash.slice(1) in titles ? location.hash.slice(1) : "overview",
      );
    window.addEventListener("hashchange", fn);
    return () => window.removeEventListener("hashchange", fn);
  }, []);
  useEffect(() => {
    if (!session) return;
    let live = true;
    const controller = new AbortController();
    let timer: ReturnType<typeof setTimeout>;
    const refresh = async () => {
      try {
        const next = await api("overview", {
          signal: AbortSignal.any([
            controller.signal,
            AbortSignal.timeout(6500),
          ]),
        });
        if (live) {
          setData(next);
          setLost(false);
        }
      } catch (err) {
        if (!live) return;
        if (err instanceof ApiError && err.status === 401) {
          expire();
          return;
        }
        setLost(true);
      } finally {
        if (live) timer = setTimeout(refresh, 10000);
      }
    };
    void refresh();
    const seconds = setInterval(() => setTick(Date.now()), 1000);
    return () => {
      live = false;
      controller.abort();
      clearTimeout(timer);
      clearInterval(seconds);
    };
  }, [session?.username]);
  useEffect(() => {
    if (!session) return;
    let live = true;
    const controller = new AbortController();
    let timer: ReturnType<typeof setTimeout>;
    setSeries({});
    const refresh = async () => {
      await Promise.all(
        ["cpu", "ram", "http"].map(async (metric) => {
          try {
            const value = await api(
              `history?metric=${metric}&period=${period}`,
              {
                signal: AbortSignal.any([
                  controller.signal,
                  AbortSignal.timeout(6500),
                ]),
              },
            );
            if (live) setSeries((old) => ({ ...old, [metric]: value }));
          } catch (err) {
            if (live) {
              if (err instanceof ApiError && err.status === 401) expire();
              else
                setSeries((old) => ({
                  ...old,
                  [metric]: {
                    status: "no_data",
                    reason_code: "source_unavailable",
                    points: [],
                  },
                }));
            }
          }
        }),
      );
      if (live) timer = setTimeout(refresh, 30000);
    };
    void refresh();
    return () => {
      live = false;
      controller.abort();
      clearTimeout(timer);
    };
  }, [session?.username, period]);
  useEffect(() => {
    if (page !== "events" || !session) return;
    let live = true;
    const controller = new AbortController();
    let timer: ReturnType<typeof setTimeout>;
    const refresh = async () => {
      try {
        const next = await api("events", {
          signal: AbortSignal.any([
            controller.signal,
            AbortSignal.timeout(6500),
          ]),
        });
        if (live) {
          setHistory(next);
          setHistoryLost(false);
        }
      } catch (err) {
        if (!live) return;
        if (err instanceof ApiError && err.status === 401) {
          expire();
          return;
        }
        setHistoryLost(true);
      } finally {
        if (live) timer = setTimeout(refresh, 10000);
      }
    };
    void refresh();
    return () => {
      live = false;
      controller.abort();
      clearTimeout(timer);
    };
  }, [page, session?.username]);
  useEffect(() => {
    if (!session) return;
    const fn = () => {
      if (Date.now() - lastActivity.current < 60000) return;
      lastActivity.current = Date.now();
      void api("activity", {
        method: "POST",
        headers: { "X-CSRF-Token": session.csrf },
        body: "{}",
      }).catch((err) => {
        if (err instanceof ApiError && err.status === 401) expire();
      });
    };
    document.addEventListener("pointerdown", fn);
    document.addEventListener("keydown", fn);
    return () => {
      document.removeEventListener("pointerdown", fn);
      document.removeEventListener("keydown", fn);
    };
  }, [session?.csrf]);
  useEffect(() => {
    if (selected || selectedDomain || profile) dialog.current?.showModal();
    else dialog.current?.close();
  }, [selected, selectedDomain, profile]);
  if (initial)
    return (
      <main className="login">
        <p role="status">Подключаемся к пульту…</p>
      </main>
    );
  if (!session)
    return <LoginScreen onLogin={() => void getSession()} notice={notice} />;
  const stale = !data || lost || data.stale || !freshAt(data.observed_at, tick);
  const checks = (data?.checks || []).map((c) =>
    stale || !freshAt(c.observed_at, tick)
      ? { ...c, status: "no_data", reason_code: "stale" }
      : c,
  );
  const problem = checks.filter(
    (c) => c.status === "critical" || c.status === "warning",
  );
  const selectedCheck = selected
    ? checks.find((item) => item.id === selected.id) || {
        ...selected,
        status: "no_data",
        reason_code: "source_unavailable",
      }
    : null;
  const host = data?.host,
    backup = data?.backup,
    cron = data?.cron,
    local = checks.find((c) => c.id === "http_local");
  const sourceStatus = (id: string) =>
    checks.find((c) => c.id === id)?.status || "no_data";
  const usable = (id: string) =>
    !stale &&
    ["ok", "warning", "critical", "stopped"].includes(sourceStatus(id));
  const smartAge = data?.smart?.observed_at
    ? tick - Date.parse(data.smart.observed_at)
    : Infinity;
  const smartCheck = checks.find((c) => c.id === "smart");
  const smartFresh =
    !stale &&
    !!smartCheck &&
    smartCheck.reason_code !== "stale" &&
    (smartCheck.status !== "no_data" ||
      smartCheck.reason_code === "smart_probe_failed") &&
    smartAge >= -5000 &&
    smartAge <= 960000;
  const smartUsable = (disk: NonNullable<Snapshot["smart"]>["disks"][number]) =>
    smartFresh && disk.status !== "no_data";
  const diskStatus = [
    sourceStatus("ssd_space"),
    sourceStatus("ssd_inodes"),
  ].sort(
    (a, b) =>
      ["critical", "no_data", "warning", "ok"].indexOf(a) -
      ["critical", "no_data", "warning", "ok"].indexOf(b),
  )[0];
  const hddSpace = usable("hdd") && usable("hdd_space");
  const hddInodes = usable("hdd") && usable("hdd_inodes");
  const hddStatus = !usable("hdd")
    ? "no_data"
    : [sourceStatus("hdd_space"), sourceStatus("hdd_inodes")].sort(
        (a, b) =>
          ["critical", "no_data", "warning", "stopped", "ok"].indexOf(a) -
          ["critical", "no_data", "warning", "stopped", "ok"].indexOf(b),
      )[0];
  const hddPanel = (
    <BackupDisk
      disk={data?.hdd}
      spaceAvailable={hddSpace}
      inodesAvailable={hddInodes}
      status={hddStatus}
      observedAt={checks.find((c) => c.id === "hdd_space")?.observed_at}
    />
  );
  const notifications = data?.notifications;
  const notificationsFresh =
    !stale &&
    !!notifications &&
    !["stale", "no_data"].includes(notifications.status || "no_data") &&
    freshAt(notifications.observed_at, tick);
  const rulesFresh = notificationsFresh && notifications?.evaluation === "ok";
  const since = (s?: string | null) =>
    s ? age((tick - Date.parse(s)) / 1000) : "—";
  const databaseNumber = (key: string) => {
    const number = data?.database?.[key];
    return typeof number === "number" ? number : undefined;
  };
  const go = (id: string) => {
    location.hash = id;
    setPage(id);
  };
  const profileBody = (
    <form
      onSubmit={async (e) => {
        e.preventDefault();
        setProfileError("");
        const form = new FormData(e.currentTarget);
        try {
          await api("password", {
            method: "POST",
            headers: { "X-CSRF-Token": session.csrf },
            body: JSON.stringify(Object.fromEntries(form)),
          });
          expire();
          setNotice("Пароль изменён. Войдите с новым паролем.");
        } catch {
          setProfileError(
            "Не удалось изменить пароль. Проверьте текущий пароль и соединение.",
          );
        }
      }}
    >
      <p>
        {session.username} ·{" "}
        {(
          {
            owner: "Владелец",
            operator: "Оператор",
            viewer: "Наблюдатель",
          } as Record<string, string>
        )[session.role] || session.role}
      </p>
      <label>
        Текущий пароль
        <input
          type="password"
          name="current"
          autoComplete="current-password"
          required
          maxLength={256}
        />
      </label>
      <label>
        Новый пароль
        <input
          type="password"
          name="replacement"
          autoComplete="new-password"
          required
          minLength={14}
          maxLength={256}
        />
      </label>
      <p className="muted">
        От 14 символов. После смены потребуется войти заново на всех
        устройствах.
      </p>
      <label>
        Код TOTP, если подключён
        <input
          name="otp"
          inputMode="numeric"
          autoComplete="one-time-code"
          maxLength={6}
          pattern="[0-9]{6}"
        />
      </label>
      {profileError && (
        <p role="alert" className="error-text">
          {profileError}
        </p>
      )}
      <button className="primary">Сменить пароль</button>
    </form>
  );
  return (
    <div className="app">
      <aside>
        <div className="brand">
          <b>U</b>
          <span>
            USTAR<small>Пульт управления</small>
          </span>
        </div>
        <nav aria-label="Разделы">
          {Object.entries(titles).map(([id, title], i) => {
            const Icon = [
              LayoutDashboard,
              GraduationCap,
              Server,
              DatabaseBackup,
              FlaskConical,
              Activity,
            ][i];
            return (
              <button
                key={id}
                aria-current={page === id ? "page" : undefined}
                onClick={() => go(id)}
              >
                <span className="nav-icon" aria-hidden="true">
                  <Icon size={21} />
                </span>
                {title}
              </button>
            );
          })}
        </nav>
        <div className="sidebar-foot">
          <span className="mode">
            {session.role === "viewer"
              ? "Только просмотр"
              : "Действия с подтверждением"}
          </span>
          <button onClick={() => setProfile(true)}>
            <ShieldCheck size={18} aria-hidden="true" /> Учётная запись
          </button>
          <button
            onClick={async () => {
              try {
                await api("logout", {
                  method: "POST",
                  headers: { "X-CSRF-Token": session.csrf },
                  body: "{}",
                });
                expire();
                setNotice("Вы вышли из пульта.");
              } catch {
                setNotice("Не удалось выйти.");
                setLost(true);
              }
            }}
          >
            <LogOut size={18} aria-hidden="true" /> Выйти
          </button>
        </div>
      </aside>
      <main className="workspace">
        <header>
          <div>
            <span className="eyebrow">
              SERVER1 <span className="env">PRODUCTION</span>
            </span>
            <h1>{titles[page]}</h1>
          </div>
          <div className="toolbar">
            {["overview", "academy", "server"].includes(page) && (
              <>
                <label className="sr-only" htmlFor="period">
                  Период графиков
                </label>
                <select
                  id="period"
                  value={period}
                  onChange={(e) => setPeriod(e.target.value)}
                >
                  <option value="1h">Последний час</option>
                  <option value="24h">24 часа</option>
                  <option value="7d">7 дней</option>
                  <option value="15d">15 дней</option>
                </select>
              </>
            )}
            <span
              className={"freshness " + (stale || lost ? "old" : "")}
              role="status"
            >
              {lost
                ? "Нет соединения"
                : stale
                  ? "Данные устарели"
                  : `Данные от ${data?.observed_at ? new Date(data.observed_at).toLocaleTimeString("ru-RU", { timeZone: "Europe/Moscow" }) : "—"} МСК · ${age(data?.observed_at ? (tick - Date.parse(data.observed_at)) / 1000 : null)} назад`}
            </span>
          </div>
        </header>
        {lost && (
          <div className="connection" role="alert">
            Соединение потеряно. Последние данные: {date(data?.observed_at)}.
            Повторяем подключение.
          </div>
        )}
        {stale && data && (
          <p className="notice">
            Текущее состояние не подтверждено. История и даты предыдущих отчётов
            сохранены.
          </p>
        )}
        {!data ? (
          <Empty title={lost ? "Источник недоступен" : "Получаем состояние"}>
            Пульт ждёт измерений сервера.
          </Empty>
        ) : (
          <>
            {page === "overview" && (
              <>
                <div
                  className="metrics priority-metrics"
                  aria-label="Главные показатели"
                >
                  <Metric
                    label="Успех cron"
                    kind="cron"
                    number={usable("cron") ? since(cron?.last_success_at) : "—"}
                    status={sourceStatus("cron")}
                    detail={
                      cron?.last_success_at
                        ? `Последний успех: ${date(cron.last_success_at)}`
                        : "Подтверждённый успех ещё не наблюдался"
                    }
                  />
                  <Metric
                    label="Последняя копия"
                    kind="backup"
                    number={
                      usable("backup") ? since(backup?.completed_at) : "—"
                    }
                    status={sourceStatus("backup")}
                    detail={
                      backup?.completed_at
                        ? `Завершена ${date(backup.completed_at)}`
                        : "Завершённая проверенная копия не найдена"
                    }
                  />
                  <Metric
                    label="Диск SSD"
                    kind="disk"
                    number={
                      usable("ssd_space")
                        ? value(host?.disk?.used_percent, " %")
                        : "—"
                    }
                    progress={
                      usable("ssd_space") ? host?.disk?.used_percent : undefined
                    }
                    status={diskStatus}
                    detail={
                      usable("ssd_space")
                        ? `Свободно ${gib(host?.disk?.available_bytes)} · inode: ${usable("ssd_inodes") ? value(host?.disk?.free_inode_percent, " %") : "нет данных"} свободно`
                        : "Состояние файловой системы не получено"
                    }
                  />
                  <Metric
                    label="HTTP Академии"
                    kind="http"
                    number={
                      usable("http_local")
                        ? data.http_local?.code
                          ? `HTTP ${data.http_local.code}`
                          : "Нет ответа"
                        : "—"
                    }
                    status={sourceStatus("http_local")}
                    detail={
                      usable("http_local")
                        ? `Локальный HEAD · ${value(data.http_local?.total_ms, " мс")}`
                        : local?.reason_code === "disabled"
                          ? "Локальная проверка отключена"
                          : "Локальная проверка не получена"
                    }
                  />
                </div>
                <div className="health-grid">
                  {Object.entries(domains).map(([id, title]) => (
                    <button
                      key={id}
                      className={
                        "health health-" +
                        (stale ? "no_data" : data.domains[id])
                      }
                      onClick={() => setSelectedDomain(id)}
                    >
                      <span>{title}</span>
                      <Badge status={stale ? "no_data" : data.domains[id]} />
                      <small>
                        Проверок с замечаниями:{" "}
                        {
                          checks.filter(
                            (c) => c.domain === id && c.status !== "ok",
                          ).length
                        }
                      </small>
                    </button>
                  ))}
                </div>
                <section className="attention">
                  <div className="section-title">
                    <h2>Требует внимания</h2>
                    <span>
                      {problem.length
                        ? `Проверок: ${problem.length}`
                        : "Подключение источников"}
                    </span>
                  </div>
                  <Checks
                    checks={
                      problem.length
                        ? problem
                        : checks
                            .filter((c) => c.status === "no_data")
                            .slice(0, 3)
                    }
                    onSelect={setSelected}
                  />
                </section>
                <div className="charts">
                  <Chart
                    label={
                      series.http?.source === "native_collector"
                        ? "Локальный ответ Академии"
                        : "Ответ из независимой точки сети"
                    }
                    unit="мс"
                    series={series.http}
                  />
                  <Chart
                    label="CPU и оперативная память"
                    unit="%"
                    tone="cpu"
                    series={series.cpu}
                    companion={
                      series.ram || {
                        points: [],
                        status: "no_data",
                        reason_code: "not_configured",
                      }
                    }
                  />
                </div>
                <section className="panel">
                  <div className="section-title">
                    <h2>Контейнеры Академии</h2>
                    <button
                      className="text-button"
                      onClick={() => go("server")}
                    >
                      Открыть сервер{" "}
                      <ArrowUpRight size={17} aria-hidden="true" />
                    </button>
                  </div>
                  <ContainerTable
                    rows={data.containers}
                    stale={!usable("containers")}
                  />
                </section>
              </>
            )}
            {page === "academy" && (
              <>
                <p className="intro">
                  Доступность, зависимости и фоновые задания. Локальный ответ и
                  доступ из корпоративной сети / VPN проверяются отдельно.
                </p>
                <section className="panel cron-details">
                  <h2>Фоновые задания</h2>
                  {cron?.last_attempt_at ? (
                    <dl>
                      <dt>Последний запуск</dt>
                      <dd>{date(cron.last_attempt_at)}</dd>
                      <dt>Последний наблюдавшийся успех</dt>
                      <dd>{date(cron.last_success_at)}</dd>
                      <dt>Код завершения worker</dt>
                      <dd>
                        {usable("cron")
                          ? value(cron.worker_exit_code, "", 0)
                          : "—"}
                      </dd>
                      <dt>Новые ошибки задач</dt>
                      <dd>
                        {usable("cron")
                          ? value(cron.new_task_failures, "", 0)
                          : "—"}
                      </dd>
                      <dt>Новые строки ошибок USTAR</dt>
                      <dd>
                        {usable("cron")
                          ? value(cron.ustar_error_lines, "", 0)
                          : "—"}
                      </dd>
                      <dt>Задачи с сохранённым faildelay</dt>
                      <dd>
                        {usable("cron")
                          ? value(cron.scheduled_failed, "", 0)
                          : "—"}
                      </dd>
                      <dt>Просроченные по расписанию</dt>
                      <dd>
                        {usable("cron")
                          ? value(cron.scheduled_overdue_10m, "", 0)
                          : "—"}
                      </dd>
                      <dt>В очереди adhoc</dt>
                      <dd>
                        {usable("cron") ? value(cron.adhoc_queued, "", 0) : "—"}
                      </dd>
                    </dl>
                  ) : (
                    <Empty>Отчёт cron пока не получен.</Empty>
                  )}
                  <p className="intro">
                    Сохранённый faildelay и просрочка не равны новым ошибкам:
                    задачи отключённых компонентов могут пропускаться.
                    Фактически включённые задачи требуют отдельной проверки
                    настроек Moodle.
                  </p>
                </section>
                <Checks
                  checks={checks.filter((c) => c.domain === "academy")}
                  onSelect={setSelected}
                />
                <div className="charts">
                  <Chart
                    label={
                      series.http?.source === "native_collector"
                        ? "Локальный ответ Академии"
                        : "Ответ из независимой точки сети"
                    }
                    unit="мс"
                    series={series.http}
                  />
                  <Empty title="Скорость запросов пользователей">
                    p95, RPS и число ошибок появятся после подключения
                    статистики веб-сервера. Синтетический запрос их не заменяет.
                  </Empty>
                </div>
                {data.database && (
                  <section className="panel database-details">
                    <h2>PostgreSQL</h2>
                    <dl>
                      <dt>Соединений / предел</dt>
                      <dd>
                        {usable("database")
                          ? `${value(databaseNumber("connections"), "", 0)} / ${value(databaseNumber("max_connections"), "", 0)}`
                          : "—"}
                      </dd>
                      <dt>Активных запросов</dt>
                      <dd>
                        {usable("database")
                          ? value(databaseNumber("active"), "", 0)
                          : "—"}
                      </dd>
                      <dt>Ожидают блокировку</dt>
                      <dd>
                        {usable("database")
                          ? value(databaseNumber("waiting"), "", 0)
                          : "—"}
                      </dd>
                      <dt>Idle in transaction</dt>
                      <dd>
                        {usable("database")
                          ? value(databaseNumber("idle_in_transaction"), "", 0)
                          : "—"}
                      </dd>
                      <dt>Старейшая транзакция</dt>
                      <dd>
                        {usable("database")
                          ? value(
                              databaseNumber("oldest_transaction_seconds"),
                              " с",
                            )
                          : "—"}
                      </dd>
                    </dl>
                  </section>
                )}
              </>
            )}
            {page === "server" && (
              <>
                <div className="metrics server-metrics">
                  <Metric
                    label="Свободная RAM"
                    kind="ram"
                    progress={
                      usable("host") && host?.memory
                        ? (100 * host.memory.available_bytes) /
                          host.memory.total_bytes
                        : undefined
                    }
                    number={
                      usable("host") ? gib(host?.memory?.available_bytes) : "—"
                    }
                    detail={
                      usable("host")
                        ? `всего ${gib(host?.memory?.total_bytes)}`
                        : "Свежие показания RAM не получены"
                    }
                  />
                  <Metric
                    label="CPU"
                    kind="cpu"
                    progress={usable("host") ? host?.cpu_percent : undefined}
                    number={
                      usable("host") ? value(host?.cpu_percent, " %") : "—"
                    }
                    detail={
                      usable("host")
                        ? `load 1 мин: ${value(host?.load?.[0])}`
                        : "Свежие показания CPU не получены"
                    }
                  />
                  <Metric
                    label="Свободно на SSD"
                    kind="disk"
                    progress={
                      usable("ssd_space") &&
                      host?.disk?.available_bytes != null &&
                      host.disk.total_bytes
                        ? (100 * host.disk.available_bytes) /
                          host.disk.total_bytes
                        : undefined
                    }
                    number={
                      usable("ssd_space")
                        ? gib(host?.disk?.available_bytes)
                        : "—"
                    }
                    detail={
                      usable("ssd_space")
                        ? `всего ${gib(host?.disk?.total_bytes)}`
                        : "Свежие показания SSD не получены"
                    }
                  />
                  <Metric
                    label="Swap занят"
                    kind="ram"
                    number={
                      usable("host") ? gib(host?.memory?.swap_used_bytes) : "—"
                    }
                    detail={
                      usable("host")
                        ? "Сам по себе не означает нехватку RAM"
                        : "Свежие показания swap не получены"
                    }
                  />
                </div>
                {hddPanel}
                <Checks
                  checks={checks.filter((c) => c.domain === "server")}
                  onSelect={setSelected}
                />
                {data.clock && (
                  <section className="panel">
                    <h2>Время и NTP</h2>
                    <dl>
                      <dt>Выбранный источник</dt>
                      <dd>{usable("clock") ? data.clock.source : "—"}</dd>
                      <dt>Смещение</dt>
                      <dd>
                        {usable("clock")
                          ? value(data.clock.offset_ms, " мс", 4)
                          : "—"}
                      </dd>
                      <dt>Jitter</dt>
                      <dd>
                        {usable("clock")
                          ? value(data.clock.jitter_ms, " мс", 4)
                          : "—"}
                      </dd>
                    </dl>
                  </section>
                )}
                {data.smart && (
                  <section className="panel">
                    <h2>Диски · SMART</h2>
                    <p className="muted">
                      Измерено {date(data.smart.observed_at)}. Чтение раз в 15
                      минут, без запуска теста и пробуждения диска.
                    </p>
                    {data.smart.disks.map((disk) => (
                      <div className="smart-disk" key={disk.device}>
                        <h3>
                          {disk.device}{" "}
                          <Badge
                            status={smartUsable(disk) ? disk.status : "no_data"}
                          />
                        </h3>
                        <dl>
                          <dt>Самооценка SMART</dt>
                          <dd>
                            {smartUsable(disk) &&
                            typeof disk.health_passed === "boolean"
                              ? disk.health_passed
                                ? "PASSED"
                                : "FAILED"
                              : "—"}
                          </dd>
                          <dt>Температура</dt>
                          <dd>
                            {smartUsable(disk)
                              ? value(disk.temperature_c, " °C")
                              : "—"}
                          </dd>
                          <dt>Переназначенные секторы</dt>
                          <dd>
                            {smartUsable(disk)
                              ? value(disk.sectors?.["5"], "", 0)
                              : "—"}
                          </dd>
                          <dt>Ожидающие / неисправимые</dt>
                          <dd>
                            {smartUsable(disk)
                              ? value(disk.sectors?.["197"], "", 0) +
                                " / " +
                                value(disk.sectors?.["198"], "", 0)
                              : "—"}
                          </dd>
                          <dt>История ошибок ATA</dt>
                          <dd>
                            {smartUsable(disk) &&
                            typeof disk.historical_error_log === "boolean"
                              ? disk.historical_error_log
                                ? "Есть старые записи; отдельно от текущего состояния"
                                : "Не заявлена этим чтением"
                              : "—"}
                          </dd>
                        </dl>
                        {smartUsable(disk) &&
                          !!disk.unavailable_attributes?.length && (
                            <p className="muted">
                              Неполные показания: диск не сообщил атрибуты{" "}
                              {disk.unavailable_attributes.join(", ")}.
                              Неизвестные значения показаны прочерком.
                            </p>
                          )}
                      </div>
                    ))}
                    <p className="muted">
                      Vendor wear не интерпретируется без спецификации
                      накопителя. Старый exit64 сам по себе не означает текущую
                      аварию.
                    </p>
                  </section>
                )}
                <div className="charts">
                  <Chart
                    label="Загрузка процессора"
                    unit="%"
                    tone="cpu"
                    series={series.cpu}
                  />
                  <Chart
                    label="Использование RAM"
                    unit="%"
                    tone="ram"
                    series={series.ram}
                  />
                </div>
                <section className="panel">
                  <h2>Контейнеры</h2>
                  <ContainerTable
                    rows={data.containers}
                    stale={!usable("containers")}
                  />
                </section>
                <section className="panel">
                  <h2>Службы</h2>
                  {data.units?.length ? (
                    data.units.map((row) => (
                      <div className="data-row" key={String(row.name)}>
                        <span>{String(row.name)}</span>
                        <span>
                          {!usable("units") ? "Нет данных" : String(row.state)}
                        </span>
                      </div>
                    ))
                  ) : (
                    <Empty>Состояние служб ещё не получено.</Empty>
                  )}
                </section>
              </>
            )}
            {page === "backups" && (
              <>
                <p className="intro">
                  Завершённая копия и последняя попытка учитываются отдельно.
                  Проверка создания архива и восстановление — разные результаты.
                </p>
                <Checks
                  checks={checks.filter((c) => c.domain === "data")}
                  onSelect={setSelected}
                />
                {hddPanel}
                {backup?.file ? (
                  <section className="panel backup">
                    <div className="section-title">
                      <h2>Последняя завершённая копия</h2>
                      <Badge status={sourceStatus("backup")} />
                    </div>
                    <dl>
                      <dt>Архив</dt>
                      <dd>{backup.file}</dd>
                      <dt>Завершена</dt>
                      <dd>{date(backup.completed_at)}</dd>
                      <dt>Размер</dt>
                      <dd>{gib(backup.bytes)}</dd>
                      <dt>SHA256 при создании</dt>
                      <dd>
                        <code>{backup.sha256}</code>
                      </dd>
                      <dt>Целостность сейчас</dt>
                      <dd>
                        Есть запись о проверке при создании; повторное чтение не
                        выполнялось
                      </dd>
                      <dt>Восстановление</dt>
                      <dd>Ожидается приёмка сисадмина</dd>
                      <dt>Последняя попытка</dt>
                      <dd>
                        {phase[backup.attempt_phase || ""] || "Нет данных"}
                      </dd>
                    </dl>
                    <p className="muted">
                      Скачивание ниже выдаёт этот зашифрованный архив. После
                      загрузки сверьте SHA256. Приёмка восстановления
                      учитывается отдельно.
                    </p>
                  </section>
                ) : (
                  <Empty title="Нет подтверждённой копии">
                    Не удалось получить проверенный отчёт. Это не подтверждает
                    отсутствие архивов на диске.
                  </Empty>
                )}
                <section className="panel download-guidance">
                  <h2>Защищённое скачивание</h2>
                  <p>
                    Кнопка «Скачать зашифрованный архив» ниже выдаёт последнюю
                    завершённую копию по HTTPS. Нужна роль оператора или
                    владельца и подтверждение текущим паролем; если подключён
                    TOTP, введите код.
                  </p>
                  <p className="muted">
                    Ссылка действует ограниченное время. После загрузки сверьте
                    SHA256 с записью копии. Ключ расшифрования передаётся
                    отдельно согласованным способом и в архив выдачи не
                    добавляется. Скачивание не запускает новый бэкап и не
                    подтверждает восстановление.
                  </p>
                </section>
              </>
            )}
            {page === "labs" && (
              <>
                <p className="intro">
                  Штатно остановленный стенд не считается аварией. Состояние
                  контроллера и фактическая доступность различаются.
                </p>
                {data.lab.phase && data.lab.phase !== "no_data" ? (
                  <section className="panel">
                    <div className="section-title">
                      <h2>Стенд восстановления</h2>
                      <span>
                        {phase[String(data.lab.phase)] || "Неизвестно"}
                      </span>
                    </div>
                    <dl>
                      <dt>Источник</dt>
                      <dd>Последний отчёт контроллера</dd>
                      <dt>Доступность сейчас</dt>
                      <dd>Отдельно не измерена</dd>
                      <dt>Автоостановка</dt>
                      <dd>
                        {data.lab.unlimited
                          ? "Без ограничения времени"
                          : date(data.lab.expires_at as string)}
                      </dd>
                    </dl>
                    <p className="muted">
                      Новый запуск ограничен двумя часами. Существующие данные
                      контроллер сохраняет; повторный запуск поверх них
                      отклоняется.
                    </p>
                  </section>
                ) : (
                  <Empty title="Сведения о стенде недоступны">
                    Контроллер ещё не передал поддерживаемый отчёт. Отсутствие
                    отчёта не означает, что стенд остановлен.
                  </Empty>
                )}
              </>
            )}
            {page === "events" && (
              <>
                <p className="intro">
                  Входы в пульт, оценка правил и записанные инциденты
                  мониторинга. Журналы Академии и контейнеров подключаются
                  отдельными источниками.
                </p>
                <section className="panel">
                  <div className="section-title">
                    <h2>Безопасность и вход</h2>
                    <span>Последние 100 событий</span>
                  </div>
                  {(history?.events || []).map((row, i) => (
                    <div className="data-row" key={i}>
                      <span>{events[row.event] || "Событие доступа"}</span>
                      <time>{unixDate(row.at)}</time>
                    </div>
                  ))}
                </section>
                <section className="panel notifications">
                  <h2>Уведомления</h2>
                  <p className="notification-counts">
                    В очереди:{" "}
                    {notificationsFresh
                      ? value(notifications?.pending, "", 0)
                      : "—"}
                    {" · "}Ошибок доставки:{" "}
                    {notificationsFresh
                      ? value(notifications?.delivery_failures, "", 0)
                      : "—"}
                  </p>
                  {notificationsFresh ? (
                    <>
                      <p>
                        Связь с Telegram:{" "}
                        {notifications?.polling === "ok"
                          ? "доступна"
                          : "свежий успешный опрос не подтверждён"}
                      </p>
                    </>
                  ) : (
                    <p className="muted">
                      Telegram ещё не настроен или служба уведомлений не
                      передаёт свежий статус.
                    </p>
                  )}
                  <p className="muted">
                    Отчёт службы уведомлений: {date(notifications?.observed_at)}
                    . Доставка подтверждается Bot API. Получение оператором
                    проверяется через /status. Повторы после обрыва возможны.
                  </p>
                </section>
                <section className="panel alert-rules">
                  <h2>Правила алертов</h2>
                  <p className="muted">
                    Кандидат ждёт непрерывного окна подтверждения.
                    Подтверждённое состояние сформировало событие; доставка
                    учитывается отдельно. Пропуск телеметрии сбрасывает окно.
                  </p>
                  {rulesFresh && notifications?.rules.length ? (
                    notifications.rules.map((rule) => {
                      const evaluationAge = tick - rule.last * 1000;
                      const current =
                        Number.isFinite(evaluationAge) &&
                        evaluationAge >= -5000 &&
                        evaluationAge <= 45000;
                      return (
                        <article
                          className="alert-rule"
                          data-rule={rule.key}
                          key={rule.key}
                        >
                          <h3>{labels[rule.key] || "Проверка мониторинга"}</h3>
                          {current ? (
                            <>
                              <div className="rule-state">
                                <Badge status={rule.state} />
                                <strong>
                                  {rule.state !== rule.emitted
                                    ? "Кандидат: ожидает подтверждения"
                                    : rule.sent > 0
                                      ? "Подтверждено правилом"
                                      : "Норма; события ещё не было"}
                                </strong>
                              </div>
                              <dl>
                                <dt>Состояние держится с</dt>
                                <dd>{unixDate(rule.since)}</dd>
                                <dt>Последняя оценка</dt>
                                <dd>{unixDate(rule.last)}</dd>
                                <dt>Последнее подтверждение</dt>
                                <dd>{unixDate(rule.sent)}</dd>
                                {rule.state !== rule.emitted && (
                                  <>
                                    <dt>Подтверждённое состояние</dt>
                                    <dd>
                                      {rule.sent > 0 ? (
                                        <Badge status={rule.emitted} />
                                      ) : (
                                        "Ещё не было"
                                      )}
                                    </dd>
                                  </>
                                )}
                              </dl>
                            </>
                          ) : (
                            <>
                              <Badge status="no_data" />
                              <p>Нет свежей оценки этого правила</p>
                              <p className="muted">
                                Последняя оценка (история):{" "}
                                {unixDate(rule.last)}
                              </p>
                            </>
                          )}
                        </article>
                      );
                    })
                  ) : (
                    <Empty
                      title={
                        rulesFresh
                          ? "Оценка правил ещё не получена"
                          : "Свежая оценка правил недоступна"
                      }
                    >
                      Состояния появятся после свежего успешного цикла службы
                      уведомлений. Старый отчёт не подтверждает норму или аварию
                      сейчас.
                    </Empty>
                  )}
                </section>
                <section className="panel incident-history">
                  <div className="section-title">
                    <h2>Инциденты и восстановления</h2>
                    <span>Последние 100 записей</span>
                  </div>
                  <p className="muted">
                    Записанная история; текущее состояние проверяется отдельно.
                    Сохранены подтверждённые переходы и повторы правил.
                  </p>
                  {(historyLost ||
                    history?.incidents_status !== "ok" ||
                    !freshAt(history?.incidents_observed_at, tick)) && (
                    <p className="notice">
                      Свежий источник истории недоступен. Сохранённые записи
                      могут не включать последние события.
                    </p>
                  )}
                  {history?.incidents?.length ? (
                    history.incidents.map((row) => (
                      <article className="incident-row" key={row.id}>
                        <div className="incident-title">
                          <strong>
                            {labels[row.check_id] || "Проверка мониторинга"}
                          </strong>
                          <time>{unixDate(row.at)}</time>
                        </div>
                        <div className="rule-state">
                          <span>
                            {row.status === "ok"
                              ? "Восстановление"
                              : "Инцидент"}
                          </span>
                          <Badge status={row.status} />
                        </div>
                        <p className="muted">
                          {reasons[row.reason_code] ||
                            "Подробности причины не получены"}
                        </p>
                      </article>
                    ))
                  ) : (
                    <Empty title="Записанных инцидентов нет">
                      {history?.incidents_status === "ok"
                        ? "Источник истории не передал записей. Текущее состояние смотрите в проверках и правилах."
                        : "Источник истории ещё не передал доступные записи. Отсутствие записей не подтверждает отсутствие инцидентов."}
                    </Empty>
                  )}
                </section>
                <Empty title="Логи сервера ещё не подключены">
                  Пульт не загружает сырые журналы: сначала должны быть
                  настроены источники и удаление секретов.
                </Empty>
              </>
            )}
            {["academy", "backups", "labs", "events"].includes(page) && (
              <Operations
                api={api}
                csrf={session.csrf}
                role={session.role}
                page={page}
                available={!stale && !lost}
              />
            )}
            <footer>
              Время — Москва · Последнее измерение {date(data.observed_at)} ·
              {session.role === "viewer"
                ? "Режим просмотра"
                : "Действия требуют подтверждения"}
            </footer>
          </>
        )}
      </main>
      <dialog
        ref={dialog}
        onClose={() => {
          setSelected(null);
          setSelectedDomain(null);
          setProfile(false);
          setProfileError("");
        }}
        aria-labelledby="detail-title"
      >
        <div className="dialog-head">
          <h2 id="detail-title">
            {profile
              ? "Учётная запись"
              : selectedDomain
                ? domains[selectedDomain]
                : labels[selected?.id || ""] || selected?.label}
          </h2>
          <button aria-label="Закрыть" onClick={() => dialog.current?.close()}>
            ×
          </button>
        </div>
        {profile ? (
          profileBody
        ) : selectedDomain ? (
          <div className="domain-checks">
            {checks
              .filter((c) => c.domain === selectedDomain)
              .map((c) => (
                <div className="domain-check" key={c.id}>
                  <strong>{labels[c.id] || c.label}</strong>
                  <Badge status={c.status} />
                  <p>
                    {reasons[c.reason_code] ||
                      "Подробности проверки пока недоступны"}
                  </p>
                  <small>Измерение: {date(c.observed_at)}</small>
                </div>
              ))}
            {!checks.some((c) => c.domain === selectedDomain) && (
              <Empty>Проверки пока не получены.</Empty>
            )}
          </div>
        ) : (
          selectedCheck && (
            <>
              <Badge status={selectedCheck.status} />
              <p>
                {reasons[selectedCheck.reason_code] ||
                  selectedCheck.reason_code}
              </p>
              <dl>
                <dt>Время проверки</dt>
                <dd>{date(selectedCheck.observed_at)}</dd>
                {selectedCheck.source_observed_at && (
                  <>
                    <dt>Дата отчёта источника</dt>
                    <dd>
                      {date(selectedCheck.source_observed_at)} ·{" "}
                      {since(selectedCheck.source_observed_at)} назад
                    </dd>
                  </>
                )}
                <dt>Источник</dt>
                <dd>
                  {selectedCheck.source === "not_configured"
                    ? "Не подключён"
                    : labels[selectedCheck.source] || selectedCheck.source}
                </dd>
              </dl>
              <p className="muted">
                Показания и свежесть обновляются вместе с обзором. Правила
                устойчивой нагрузки и состояние доставки доступны в «Событиях»,
                если notifier подключён.
              </p>
            </>
          )
        )}
      </dialog>
    </div>
  );
}
function ContainerTable({
  rows,
  stale,
}: {
  rows: Record<string, unknown>[];
  stale: boolean;
}) {
  return (
    <div className="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Контейнер</th>
            <th>Состояние</th>
            <th>Рестарты</th>
            <th>Политика запуска</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={String(row.name)}>
              <td data-label="Контейнер">
                <span className="container-name">
                  <Server size={18} aria-hidden="true" />
                  <strong>{String(row.name)}</strong>
                </span>
              </td>
              <td data-label="Состояние">
                {stale ? (
                  <Badge status="no_data" />
                ) : row.state === "no_data" ? (
                  <Badge status="no_data" />
                ) : (
                  <>
                    <span className={row.running ? "status-ok" : "status-bad"}>
                      {row.running ? "Работает" : "Остановлен"}
                    </span>
                    {row.oom ? " · OOM" : ""}
                  </>
                )}
              </td>
              <td data-label="Рестарты">
                {stale || row.state === "no_data" || row.restarts == null
                  ? "—"
                  : String(row.restarts)}
              </td>
              <td data-label="Автозапуск">
                {stale || row.state === "no_data" || row.policy == null
                  ? "—"
                  : String(row.policy)}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      {!rows.length && <Empty>Состояние контейнеров ещё не получено.</Empty>}
    </div>
  );
}

createRoot(document.getElementById("root")!).render(<App />);
