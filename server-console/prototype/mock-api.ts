// Synthetic demo state only. No credentials, persistence or network requests.
import { graphFixture } from "../ui/tests/graph-fixtures";
export function createPrototypeApi(
  ApiError: new (status: number, retry: string | null, code?: string) => Error,
) {
  let previewAuthenticated = true;
  const started = Date.now() / 1000;
  const demoIncidents = [
    {
      id: 2,
      at: started - 3600,
      check_id: "hdd_space",
      status: "ok",
      reason_code: "disk_capacity",
    },
    {
      id: 1,
      at: started - 7200,
      check_id: "hdd_space",
      status: "critical",
      reason_code: "disk_low_space",
    },
  ];
  const fixture = () => ({
    version: 1,
    observed_at: new Date().toISOString(),
    received_at: new Date().toISOString(),
    age_seconds: 0,
    stale: false,
    domains: {
      academy: "no_data",
      server: "no_data",
      data: "warning",
      monitoring: "no_data",
    },
    host: {
      cpu_percent: 88.4,
      cores: 4,
      load: [0.6, 0.5, 0.5],
      memory: {
        total_bytes: 8 * 2 ** 30,
        available_bytes: 4.6 * 2 ** 30,
        cache_bytes: 1.5 * 2 ** 30,
        swap_used_bytes: 0,
      },
      disk: {
        total_bytes: 110 * 2 ** 30,
        available_bytes: 61 * 2 ** 30,
        used_percent: 44.5,
        free_inode_percent: 92.4,
      },
    },
    hdd: {
      total_bytes: 1863 * 2 ** 30,
      available_bytes: 1521 * 2 ** 30,
      used_percent: 18.4,
      free_inode_percent: 98.7,
    },
    cron: {
      last_attempt_at: new Date(Date.now() - 60000).toISOString(),
      last_success_at: new Date(Date.now() - 60000).toISOString(),
      worker_exit_code: 0,
      new_task_failures: 0,
      ustar_error_lines: 0,
      scheduled_failed: 2,
      scheduled_overdue_10m: 18,
      adhoc_queued: 0,
    },
    http_local: { code: 200, total_ms: 127 },
    backup: {
      file: "ustar-recovery-20261015T003000Z-1234abcd.tar.gz.age",
      bytes: 2 ** 30,
      sha256: "a".repeat(64),
      completed_at: "2026-10-15T00:31:00Z",
      age_seconds: 100000,
      status: "warning",
      attempt_phase: "complete",
    },
    checks: [
      {
        id: "cron",
        label: "Успех фоновых заданий",
        domain: "academy",
        status: "ok",
        reason_code: "cron_receipt",
        source: "cron",
        observed_at: new Date().toISOString(),
        source_observed_at: new Date(Date.now() - 60000).toISOString(),
      },
      {
        id: "http_local",
        label: "Локальный ответ Академии",
        domain: "academy",
        status: "ok",
        reason_code: "head_local_only",
        source: "http_local",
        observed_at: new Date().toISOString(),
      },
      {
        id: "ssd_space",
        label: "Свободное место SSD",
        domain: "server",
        status: "ok",
        reason_code: "disk_capacity",
        source: "host",
        observed_at: new Date().toISOString(),
      },
      {
        id: "ssd_inodes",
        label: "Свободные inode SSD",
        domain: "server",
        status: "ok",
        reason_code: "disk_capacity",
        source: "host",
        observed_at: new Date().toISOString(),
      },
      {
        id: "backup",
        label: "Бэкап",
        domain: "data",
        status: "warning",
        reason_code: "backup_receipt",
        source: "backup",
        observed_at: new Date().toISOString(),
      },
      {
        id: "http_external",
        label: "Академия снаружи",
        domain: "academy",
        status: "no_data",
        reason_code: "not_configured",
        source: "not_configured",
        observed_at: new Date().toISOString(),
      },
      {
        id: "resource_rules",
        label: "Правила нагрузки",
        domain: "server",
        status: "ok",
        reason_code: "sustained_rules",
        source: "notifications",
        observed_at: new Date().toISOString(),
      },
    ],
    notifications: {
      pending: 1,
      delivery_failures: 0,
      polling: "ok",
      evaluation: "ok",
      status: "ok",
      observed_at: new Date().toISOString(),
      rules: [
        {
          key: "cpu_sustained",
          state: "warning",
          emitted: "ok",
          since: Date.now() / 1000 - 300,
          last: Date.now() / 1000,
          sent: 0,
        },
        {
          key: "ram_sustained",
          state: "ok",
          emitted: "ok",
          since: started - 3600,
          last: Date.now() / 1000,
          sent: started - 3600,
        },
      ],
    },
    containers: [
      {
        name: "ustar_moodle",
        state: "running",
        running: true,
        restarts: 0,
        policy: "unless-stopped",
      },
      {
        name: "ustar_postgres",
        state: "running",
        running: true,
        restarts: 0,
        policy: "unless-stopped",
      },
    ],
    lab: { phase: "stopped", ready: false, unlimited: false, expires_at: null },
    units: [],
  });
  return async function api(path: string, options: RequestInit = {}) {
    if (path === "session") {
      if (!previewAuthenticated) throw new ApiError(401, null);
      return {
        username: "owner",
        role: "owner",
        csrf: "preview-only",
        expires_at: Date.now() / 1000 + 43200,
      };
    }
    if (path === "login") {
      previewAuthenticated = true;
      return { status: "authenticated" };
    }
    if (path === "logout") {
      previewAuthenticated = false;
      return { status: "signed_out" };
    }
    if (path === "admin/users" && (!options.method || options.method === "GET")) return {
      demo_read_only: true,
      users: [
        { username: "owner", role: "owner", enabled: true, totp: true, sessions: 1 },
        { username: "demo-operator", role: "operator", enabled: true, totp: false, sessions: 0 },
        { username: "demo-viewer", role: "viewer", enabled: false, totp: false, sessions: 0 },
      ],
    };
    if (path === "account/telegram" && (!options.method || options.method === "GET")) return {
      demo_read_only: true, linked: false,
      preferences: {
        enabled: true, check_ids: ["host", "backup", "cpu_sustained", "ram_sustained"],
        statuses: ["warning", "critical", "no_data"], repeat_minutes: 240,
        quiet_start: "", quiet_end: "", timezone: "Europe/Moscow", recovery: true,
        cpu_percent: 85, ram_available_percent: 15, hold_seconds: 600,
      },
      catalog: [
        { id: "host", label: "Ресурсы сервера" }, { id: "backup", label: "Последняя копия" },
        { id: "cpu_sustained", label: "Длительная нагрузка CPU" },
        { id: "ram_sustained", label: "Мало свободной RAM" },
      ],
      transport_status: { status: "no_data" },
    };
    if (path.startsWith("admin/users") || path.startsWith("account/"))
      throw new ApiError(403, null, "prototype_read_only");
    if (path === "password") throw new ApiError(403, null);
    if (path === "activity") return { status: "active" };
    if (path === "knowledge") return {
      source_commit: "7f8a614aeeec62a6f1f455331b78b6f1f1d81528",
      generated_at: "2026-10-18T14:00:00Z",
      scripts: [{ id: "demo-backup", title: "Резервная копия · демо", category: "Бэкапы и восстановление",
        purpose: "Пример карточки скрипта. Это автономный макет, без подключения к серверу.",
        effects: "Действия на сервере из макета не выполняются.", success: "Пример описания успешной проверки.",
        server_path: "", source_path: "scripts/infra/hdd_backup.py", sha256: "" }],
      documents: [{ id: "demo-doc", title: "Документация · демо", category: "Эксплуатация",
        summary: "В установленном пульте здесь находятся документы из проверенного выпуска.",
        source_path: "server-console/README.md", sha256: "", content: "# Документация\n\nЭто демонстрационный экран. В установленном пульте доступен поиск по документам конкретного выпуска." }]
    };
    if (path === "overview") {
      const state = fixture();
      state.backup.completed_at = new Date(
        Date.now() - 100000000,
      ).toISOString();
      state.checks.push(
        {
          id: "host",
          label: "Ресурсы сервера",
          domain: "server",
          status: "ok",
          reason_code: "measured",
          source: "host",
          observed_at: new Date().toISOString(),
        },
        {
          id: "containers",
          label: "Контейнеры",
          domain: "academy",
          status: "ok",
          reason_code: "measured",
          source: "containers",
          observed_at: new Date().toISOString(),
        },
        {
          id: "hdd",
          label: "Диск бэкапов",
          domain: "data",
          status: "ok",
          reason_code: "measured",
          source: "hdd",
          observed_at: new Date().toISOString(),
        },
        ...["hdd_space", "hdd_inodes"].map((id) => ({
          id,
          label:
            id === "hdd_space"
              ? "Свободное место диска бэкапов"
              : "Свободные inode диска бэкапов",
          domain: "data",
          status: "ok",
          reason_code: "disk_capacity",
          source: "hdd",
          observed_at: new Date().toISOString(),
        })),
        {
          id: "database",
          label: "PostgreSQL: отдельный мониторинг",
          domain: "academy",
          status: "no_data",
          reason_code: "not_configured",
          source: "not_configured",
          observed_at: new Date().toISOString(),
        },
        {
          id: "restore",
          label: "Приёмка восстановления",
          domain: "data",
          status: "no_data",
          reason_code: "not_configured",
          source: "not_configured",
          observed_at: new Date().toISOString(),
        },
        {
          id: "collector",
          label: "Сборщик",
          domain: "monitoring",
          status: "ok",
          reason_code: "measured",
          source: "collector",
          observed_at: new Date().toISOString(),
        },
        {
          id: "rules",
          label: "Правила и доставка алертов",
          domain: "monitoring",
          status: "ok",
          reason_code: "outbox_status",
          source: "notifications",
          observed_at: new Date().toISOString(),
        },
        {
          id: "observer",
          label: "Независимый наблюдатель",
          domain: "monitoring",
          status: "no_data",
          reason_code: "not_configured",
          source: "not_configured",
          observed_at: new Date().toISOString(),
        },
      );
      state.domains.data = "no_data";
      return state;
    }
    if (path.startsWith("history?")) {
      const url = new URL("https://preview.local/" + path);
      const metric = url.searchParams.get("metric") || "cpu";
      const series = graphFixture(
        metric,
        url.searchParams.get("period") || "1h",
      );
      if (metric === "cpu") {
        for (const point of series.points) {
          if (point[0] >= Date.now() / 1000 - 301) point[1] = 88.4;
        }
      }
      return series;
    }
    if (path === "events")
      return {
        events: [{ at: started, event: "login_succeeded" }],
        scope: "console_security_and_monitoring",
        host_logs: "not_connected",
        incidents: demoIncidents,
        incidents_status: "ok",
        incidents_observed_at: new Date().toISOString(),
      };
    throw new ApiError(404, null);
  };
}
