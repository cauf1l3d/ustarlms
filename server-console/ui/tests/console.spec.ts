import { test, expect, Page } from "@playwright/test";
import { graphFixture } from "./graph-fixtures";
import { expectReadable } from "./readability";
import { resolve } from "node:path";
import { pathToFileURL } from "node:url";

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
    cpu_percent: 12.4,
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
    ...["host", "containers", "hdd", "hdd_space", "hdd_inodes"].map((id) => ({
      id,
      label: id,
      domain: id.startsWith("hdd") ? "data" : "server",
      status: "ok",
      reason_code: "measured",
      source: id.startsWith("hdd") ? "hdd" : id,
      observed_at: new Date().toISOString(),
    })),
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
      status: "no_data",
      reason_code: "not_configured",
      source: "not_configured",
      observed_at: new Date().toISOString(),
    },
  ],
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
  clock: { source: "194.58.206.20", offset_ms: -0.2024, jitter_ms: 0.169 },
  smart: {
    observed_at: new Date().toISOString(),
    disks: [
      {
        device: "/dev/sda",
        status: "ok",
        temperature_c: 50,
        sectors: { "5": 0, "197": 0, "198": 0, "199": 0 },
        historical_error_log: true,
      },
    ],
  },
});

async function login(page: Page) {
  await page.goto("/");
  await page.getByLabel("Логин", { exact: true }).fill("owner");
  await page
    .getByLabel("Пароль", { exact: true })
    .fill("browser-fixture-password");
  await page.getByRole("button", { name: "Войти", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Обзор", exact: true }),
  ).toBeVisible();
}

const notificationFixture = () => ({
  pending: 7,
  delivery_failures: 2,
  polling: "ok",
  evaluation: "ok",
  status: "warning",
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
      state: "critical",
      emitted: "critical",
      since: Date.now() / 1000 - 200,
      last: Date.now() / 1000,
      sent: Date.now() / 1000 - 20,
    },
  ],
});

const incidentFixture = () => ({
  events: [{ at: Date.now() / 1000, event: "login_succeeded" }],
  scope: "console_security_and_monitoring",
  host_logs: "not_connected",
  incidents_status: "ok",
  incidents_observed_at: new Date().toISOString(),
  incidents: [
    {
      id: 2,
      at: Date.now() / 1000 - 60,
      check_id: "hdd_space",
      status: "ok",
      reason_code: "disk_capacity",
    },
    {
      id: 1,
      at: Date.now() / 1000 - 120,
      check_id: "hdd_space",
      status: "critical",
      reason_code: "disk_low_space",
    },
  ],
});

test("backup disk capacity and inode figures use the measured source on server and backups", async ({
  page,
}, info) => {
  await page.setViewportSize({
    width: info.project.name === "desktop" ? 1440 : 375,
    height: 950,
  });
  await login(page);
  for (const name of ["Сервер", "Бэкапы"]) {
    await page
      .getByRole("navigation")
      .getByRole("button", { name, exact: true })
      .click();
    const disk = page.locator(".hdd-capacity");
    await expect(
      disk.getByRole("heading", { name: "Диск бэкапов · ёмкость" }),
    ).toBeVisible();
    await expect(disk.locator("dd")).toHaveText([
      "1 863 ГиБ",
      "1 521 ГиБ",
      "18,4 %",
      "98,7 %",
    ]);
    await expect(disk.locator(".badge")).toHaveText("Норма");
    await expectReadable(page);
    await page.screenshot({
      path: info.outputPath(
        `capacity-${name === "Сервер" ? "server" : "backups"}.png`,
      ),
      fullPage: true,
    });
  }
});

for (const mode of ["no_data", "check_expired", "snapshot_expired"] as const) {
  test(`current storage and host readings disappear when ${mode}`, async ({
    page,
  }) => {
    const original = fixture();
    const state = {
      ...original,
      stale: mode === "snapshot_expired",
      checks: original.checks.map((check) =>
        [
          "host",
          "ssd_space",
          "ssd_inodes",
          "hdd",
          "hdd_space",
          "hdd_inodes",
        ].includes(check.id)
          ? {
              ...check,
              status: mode === "no_data" ? "no_data" : check.status,
              observed_at:
                mode === "check_expired"
                  ? new Date(Date.now() - 60000).toISOString()
                  : check.observed_at,
            }
          : check,
      ),
    };
    await page.route("**/api/overview", (route) =>
      route.fulfill({ json: state }),
    );
    await login(page);
    await page
      .getByRole("navigation")
      .getByRole("button", { name: "Сервер", exact: true })
      .click();
    await expect(page.locator(".server-metrics .metric > strong")).toHaveText([
      "—",
      "—",
      "—",
      "—",
    ]);
    await expect(page.locator(".server-metrics progress")).toHaveCount(0);
    await expect(page.locator(".hdd-capacity dd")).toHaveText([
      "—",
      "—",
      "—",
      "—",
    ]);
    await expect(page.locator(".hdd-capacity .badge")).toHaveText("Нет данных");
  });
}

test("alert rules show candidates, confirmed states and their observation times", async ({
  page,
}, info) => {
  await page.route("**/api/overview", (route) =>
    route.fulfill({
      json: { ...fixture(), notifications: notificationFixture() },
    }),
  );
  await page.route("**/api/events", (route) =>
    route.fulfill({ json: incidentFixture() }),
  );
  await login(page);
  await page
    .getByRole("navigation")
    .getByRole("button", { name: "События", exact: true })
    .click();
  const cpu = page.locator('.alert-rule[data-rule="cpu_sustained"]');
  const ram = page.locator('.alert-rule[data-rule="ram_sustained"]');
  await expect(cpu).toContainText("CPU выше 85 % в течение 10 минут");
  await expect(cpu).toContainText("Кандидат: ожидает подтверждения");
  await expect(cpu).toContainText("Состояние держится с");
  await expect(cpu).toContainText("Последняя оценка");
  await expect(cpu).toContainText("Ещё не было");
  await expect(ram).toContainText("Подтверждено правилом");
  await expect(ram).toContainText("Последнее подтверждение");
  await expect(page.locator(".notification-counts")).toContainText(
    "В очереди: 7",
  );
  await expect(page.locator(".notification-counts")).toContainText(
    "Ошибок доставки: 2",
  );
  await expectReadable(page);
  await page.screenshot({
    path: info.outputPath("alert-rules-and-incidents.png"),
    fullPage: true,
  });
});

test("notifier readings expire independently while the snapshot is still fresh", async ({
  page,
}) => {
  await page.clock.install();
  const notifications = {
    ...notificationFixture(),
    observed_at: new Date(Date.now() - 40000).toISOString(),
  };
  await page.route("**/api/overview", (route) =>
    route.fulfill({ json: { ...fixture(), notifications } }),
  );
  await login(page);
  await page
    .getByRole("navigation")
    .getByRole("button", { name: "События", exact: true })
    .click();
  await expect(page.locator(".alert-rule")).toHaveCount(2);
  await page.clock.fastForward(7000);
  await expect(page.locator(".alert-rule")).toHaveCount(0);
  await expect(page.locator(".notification-counts")).toHaveText(
    "В очереди: — · Ошибок доставки: —",
  );
  await expect(
    page.getByText("Свежая оценка правил недоступна", { exact: true }),
  ).toBeVisible();
  await expect(page.locator(".freshness")).not.toContainText("Данные устарели");
});

test("unavailable cron and database checks hide their current counts", async ({
  page,
}) => {
  const original = fixture();
  const state = {
    ...original,
    database: {
      connections: 99,
      max_connections: 100,
      active: 11,
      waiting: 3,
      idle_in_transaction: 5,
      oldest_transaction_seconds: 77,
    },
    checks: [
      ...original.checks.map((check) =>
        check.id === "cron" ? { ...check, status: "no_data" } : check,
      ),
      {
        id: "database",
        label: "PostgreSQL",
        domain: "academy",
        status: "no_data",
        reason_code: "probe_failed",
        source: "database",
        observed_at: new Date().toISOString(),
      },
    ],
  };
  await page.route("**/api/overview", (route) =>
    route.fulfill({ json: state }),
  );
  await login(page);
  await page
    .getByRole("navigation")
    .getByRole("button", { name: "Академия", exact: true })
    .click();
  await expect(page.locator(".database-details dd")).toHaveText([
    "—",
    "—",
    "—",
    "—",
    "—",
  ]);
  await expect(page.locator(".cron-details dd:nth-of-type(n+3)")).toHaveText([
    "—",
    "—",
    "—",
    "—",
    "—",
    "—",
  ]);
  await expect(page.locator(".cron-details")).toContainText(
    "Последний наблюдавшийся успех",
  );
});

test("a fresh notifier export does not refresh an old individual rule evaluation", async ({
  page,
}) => {
  const notifications = notificationFixture();
  notifications.rules[0].last -= 60;
  await page.route("**/api/overview", (route) =>
    route.fulfill({ json: { ...fixture(), notifications } }),
  );
  await login(page);
  await page
    .getByRole("navigation")
    .getByRole("button", { name: "События", exact: true })
    .click();
  const cpu = page.locator('.alert-rule[data-rule="cpu_sustained"]');
  await expect(cpu.locator(".badge")).toHaveText("Нет данных");
  await expect(cpu).toContainText("Нет свежей оценки этого правила");
  await expect(cpu).not.toContainText("Кандидат: ожидает подтверждения");
  await expect(
    page.locator('.alert-rule[data-rule="ram_sustained"]'),
  ).toContainText("Подтверждено правилом");
});

test("incident history refreshes with unchanged snapshot time and stays explicitly historical after source expiry", async ({
  page,
}) => {
  await page.clock.install();
  const state = fixture();
  let requests = 0;
  await page.route("**/api/overview", (route) =>
    route.fulfill({ json: state }),
  );
  await page.route("**/api/events", (route) => {
    requests += 1;
    const history = incidentFixture();
    if (requests > 1)
      history.incidents.unshift({
        id: 3,
        at: Date.now() / 1000,
        check_id: "cron",
        status: "warning",
        reason_code: "cron_success_delayed",
      });
    return route.fulfill({ json: history });
  });
  await login(page);
  await page
    .getByRole("navigation")
    .getByRole("button", { name: "События", exact: true })
    .click();
  await expect(page.locator(".incident-row")).toHaveCount(2);
  await expect(page.locator(".incident-row").first()).toContainText(
    "Восстановление",
  );
  await expect(page.locator(".incident-history")).toContainText(
    "Записанная история; текущее состояние проверяется отдельно",
  );
  await page.clock.fastForward(11000);
  await expect(page.locator(".incident-row")).toHaveCount(3);
  expect(requests).toBeGreaterThan(1);
  await page.clock.fastForward(46000);
  await expect(page.locator(".freshness")).toContainText("Данные устарели");
  await expect(page.locator(".incident-row")).toHaveCount(3);
  await page.getByRole("button", { name: "Выйти", exact: true }).click();
  await expect(page.locator(".incident-row")).toHaveCount(0);
});

for (const mode of ["partial", "unavailable", "stale"] as const) {
  test(`SMART ${mode} preserves per-disk evidence without inventing values`, async ({
    page,
  }) => {
    const original = fixture();
    const state = {
      ...original,
      checks: [
        ...original.checks,
        {
          id: "smart",
          label: "SMART дисков",
          domain: "server",
          source: "smart",
          observed_at: new Date().toISOString(),
          status: mode === "partial" ? "warning" : "no_data",
          reason_code:
            mode === "partial"
              ? "smart_partial_attributes"
              : mode === "stale"
                ? "smart_stale"
                : "smart_probe_failed",
        },
      ],
      smart: {
        observed_at: new Date(
          Date.now() - (mode === "stale" ? 1000000 : 0),
        ).toISOString(),
        disks: [
          mode === "unavailable"
            ? { device: "/dev/sda", status: "no_data" }
            : {
                device: "/dev/sda",
                status: "warning",
                temperature_c: 50,
                health_passed: true,
                sectors: { "5": 0, "198": 0 },
                unavailable_attributes: ["197", "199"],
              },
          {
            device: "/dev/sdb",
            status: "ok",
            temperature_c: 48,
            health_passed: true,
            sectors: { "5": 0, "197": 0, "198": 0, "199": 0 },
          },
        ],
      },
    };
    await page.route("**/api/overview", (route) =>
      route.fulfill({ json: state }),
    );
    await login(page);
    await page
      .getByRole("navigation")
      .getByRole("button", { name: "Сервер", exact: true })
      .click();
    const ssd = page.locator(".smart-disk").filter({ hasText: "/dev/sda" });
    const hdd = page.locator(".smart-disk").filter({ hasText: "/dev/sdb" });
    if (mode === "stale") {
      await expect(hdd.locator(".badge")).toHaveText("Нет данных");
      await expect(hdd.getByText("48 °C", { exact: true })).toHaveCount(0);
    } else {
      await expect(hdd.getByText("48 °C", { exact: true })).toBeVisible();
      await expect(hdd.locator(".badge")).toHaveText("Норма");
    }
    if (mode === "partial") {
      await expect(ssd.getByText("50 °C", { exact: true })).toBeVisible();
      await expect(
        ssd.getByText(/диск не сообщил атрибуты 197, 199/),
      ).toBeVisible();
      await expect(ssd.getByText("— / 0", { exact: true })).toBeVisible();
      await expect(ssd.locator(".badge")).toHaveText("Внимание");
    }
  });
}
test.beforeEach(async ({ page }) => {
  await page.route("**/api/overview", (route) =>
    route.fulfill({ json: fixture() }),
  );
});
test("real login, six sections, dialog keyboard, logout", async ({ page }) => {
  const errors: string[] = [];
  page.on("pageerror", (err) => errors.push(err.message));
  await login(page);
  await expect(page.locator(".priority-metrics .metric")).toHaveCount(4);
  await expect(page.locator(".metric-cron strong")).toHaveText("1 мин");
  await page
    .getByRole("button", { name: "Последняя копия", exact: false })
    .click();
  await expect(page.getByRole("dialog")).toBeVisible();
  await page.keyboard.press("Escape");
  await expect(page.getByRole("dialog")).not.toBeVisible();
  for (const name of ["Академия", "Сервер", "Бэкапы", "Стенды", "События"]) {
    await page
      .getByRole("navigation")
      .getByRole("button", { name, exact: true })
      .click();
    await expect(
      page.getByRole("heading", { name, exact: true }),
    ).toBeVisible();
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= window.innerWidth,
      ),
    ).toBeTruthy();
  }
  await expect(
    page.getByText("Вход в пульт", { exact: true }).first(),
  ).toBeVisible();
  await page.getByRole("button", { name: "Выйти", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Вход в управление" }),
  ).toBeVisible();
  expect(errors).toEqual([]);
});
test("stale data and missing history do not become healthy or zero", async ({
  page,
}, info) => {
  await page.route("**/api/overview", (route) =>
    route.fulfill({
      json: { ...fixture(), stale: true, observed_at: "2026-01-01T00:00:00Z" },
    }),
  );
  await login(page);
  await expect(
    page.getByText("Данные устарели", { exact: true }),
  ).toBeVisible();
  await expect(page.locator(".health-grid .ok")).toHaveCount(0);
  await expect(
    page.getByText(/График появится после получения измерений/).first(),
  ).toBeVisible();
  await page.screenshot({ path: info.outputPath("stale.png"), fullPage: true });
});
test("network loss retains timestamp and session expiry returns to login", async ({
  page,
}) => {
  await login(page);
  await page.route("**/api/overview", (route) => route.abort());
  await expect(page.getByRole("alert")).toContainText("Соединение потеряно", {
    timeout: 17000,
  });
  await expect(page.locator(".priority-metrics .metric > strong")).toHaveText([
    "—",
    "—",
    "—",
    "—",
  ]);
  await page.route("**/api/overview", (route) =>
    route.fulfill({ status: 401, json: { detail: "session_expired" } }),
  );
  await expect(
    page.getByRole("heading", { name: "Вход в управление" }),
  ).toBeVisible({ timeout: 17000 });
  await expect(page.getByRole("status")).toContainText("Сессия завершилась");
});
test("bounded graphs, shared period, no console errors and screenshots", async ({
  page,
}, info) => {
  await page.route("**/api/history?*", (route) => {
    const url = new URL(route.request().url());
    return route.fulfill({
      json: graphFixture(
        url.searchParams.get("metric") || "cpu",
        url.searchParams.get("period") || "1h",
      ),
    });
  });
  await login(page);
  await expect(page.locator('svg[role="img"]')).toHaveCount(2);
  await page.getByLabel("Период графиков").selectOption("24h");
  await expect(page.locator('svg[role="img"]')).toHaveCount(2);
  const resource = page.locator(".chart").filter({
    has: page.getByRole("heading", { name: "CPU и оперативная память" }),
  });
  await resource.getByRole("button", { name: "RAM", exact: true }).click();
  await expect(resource.locator(".series-ram .chart-line")).toHaveCount(0);
  await resource.getByRole("button", { name: "RAM", exact: true }).click();
  expect(
    await resource.locator(".series-ram .chart-line").count(),
  ).toBeGreaterThan(0);
  const svg = resource.locator('svg[role="img"]');
  await svg.hover({ position: { x: 150, y: 80 } });
  await expect(resource.getByRole("tooltip")).toContainText("RAM");
  await page.mouse.move(0, 0);
  await page.screenshot({
    path: info.outputPath("overview.png"),
    fullPage: true,
  });
  await page.getByRole("button", { name: "Учётная запись" }).click();
  await expect(page.getByLabel("Текущий пароль")).toBeVisible();
  await page.keyboard.press("Escape");
  await expect(page.getByRole("dialog")).not.toBeVisible();
});

test("all sections and dialogs keep readable text and fit compact screens", async ({
  page,
}) => {
  await page.route("**/api/history?*", (route) =>
    route.fulfill({
      json: graphFixture(
        new URL(route.request().url()).searchParams.get("metric") || "cpu",
      ),
    }),
  );
  await login(page);
  const original = page.viewportSize()!;
  for (const width of [...new Set([original.width, 1024, 320])]) {
    await page.setViewportSize({ width, height: original.height });
    for (const name of [
      "Обзор",
      "Академия",
      "Сервер",
      "Бэкапы",
      "Стенды",
      "События",
    ]) {
      await page
        .getByRole("navigation")
        .getByRole("button", { name, exact: true })
        .click();
      await expect(
        page.getByRole("heading", { name, exact: true }),
      ).toBeVisible();
      await expectReadable(page);
      if (["Бэкапы", "Стенды", "События"].includes(name))
        await expect(page.getByLabel("Период графиков")).toHaveCount(0);
      if (name === "Сервер")
        await expect(
          page.getByText("Состояние служб ещё не получено."),
        ).toBeVisible();
    }
    await page
      .getByRole("button", { name: "Учётная запись", exact: true })
      .click();
    await expectReadable(page);
    await page.keyboard.press("Escape");
  }
  await page
    .getByRole("navigation")
    .getByRole("button", { name: "Обзор", exact: true })
    .click();
  await expect(page.locator('svg[role="img"]')).toHaveCount(2);
  const labels = await page.locator(".axis-text").evaluateAll((nodes) =>
    nodes.map((node) => ({
      size: parseFloat(getComputedStyle(node).fontSize),
      scale:
        node.getBoundingClientRect().width /
        (node as SVGGraphicsElement).getBBox().width,
    })),
  );
  expect(Math.min(...labels.map((label) => label.size))).toBeGreaterThanOrEqual(
    14,
  );
  expect(Math.min(...labels.map((label) => label.scale))).toBeGreaterThan(0.95);
  await page.getByRole("button", { name: "RAM", exact: true }).click();
  await expectReadable(page);
  await expect(
    page.locator('.legend-value[data-label="Среднее"]').last(),
  ).toBeVisible();
});

test("health opens every check in the selected domain", async ({ page }) => {
  const data = fixture();
  data.checks.push({
    id: "db",
    label: "База данных",
    domain: "academy",
    status: "no_data",
    reason_code: "not_configured",
    source: "not_configured",
    observed_at: new Date().toISOString(),
  });
  await page.route("**/api/overview", (route) => route.fulfill({ json: data }));
  await login(page);
  await page.locator(".health").filter({ hasText: "Академия" }).click();
  await expect(page.getByRole("dialog").locator(".domain-check")).toHaveCount(
    4,
  );
  await expectReadable(page);
  await page.keyboard.press("Escape");
  await expect(
    page.locator(".health").filter({ hasText: "Академия" }),
  ).toBeFocused();
});

test("audit priorities precede graphs and historical cron counts are explicit", async ({
  page,
}) => {
  await login(page);
  const cards = page.locator(".priority-metrics .metric-label span");
  await expect(cards).toHaveText([
    "Успех cron",
    "Последняя копия",
    "Диск SSD",
    "HTTP Академии",
  ]);
  await expect(page.locator(".metric-http strong")).toHaveText("HTTP 200");
  await expect(page.locator(".metric-http small")).toContainText("127 мс");
  await expect(page.locator(".metric-disk small")).toContainText("92,4 %");
  await expect(page.locator(".freshness")).toContainText("МСК");
  await page
    .getByRole("navigation")
    .getByRole("button", { name: "Академия", exact: true })
    .click();
  await expect(page.locator(".cron-details")).toContainText(
    "Просроченные по расписанию",
  );
  await expect(page.locator(".cron-details")).toContainText("18");
  await expect(page.locator(".cron-details")).toContainText(
    "не равны новым ошибкам",
  );
  await expectReadable(page);
});

test("fresh snapshot does not refresh an old cron source", async ({ page }) => {
  const state = fixture();
  state.checks.find((c) => c.id === "cron")!.observed_at = new Date(
    Date.now() - 90000,
  ).toISOString();
  await page.route("**/api/overview", (route) =>
    route.fulfill({ json: state }),
  );
  await login(page);
  await expect(page.locator(".metric-cron .badge")).toHaveText("Нет данных");
  await expect(page.locator(".metric-cron strong")).toHaveText("—");
  await expect(page.locator(".metric-backup .badge")).toHaveText("Внимание");
  await expect(page.locator(".freshness")).not.toContainText("Данные устарели");
});

test("HTTP history never invents current HTTP status for a disabled probe", async ({
  page,
}) => {
  const state = fixture();
  const checks = state.checks.map((c) =>
    c.id === "http_local"
      ? { ...c, status: "no_data", reason_code: "disabled" }
      : c,
  );
  await page.route("**/api/overview", (route) =>
    route.fulfill({
      json: { ...state, checks, http_local: { code: null, total_ms: null } },
    }),
  );
  await page.route("**/api/history?*", (route) =>
    route.fulfill({ json: graphFixture("http") }),
  );
  await login(page);
  await expect(page.locator(".metric-http strong")).toHaveText("—");
  await expect(page.locator(".metric-http .badge")).toHaveText("Нет данных");
  await expect(page.locator(".metric-http small")).toHaveText(
    "Локальная проверка отключена",
  );
});

test("offline prototype preserves audit priorities without network requests", async ({
  page,
}, info) => {
  const remote: string[] = [];
  page.on("request", (request) => {
    if (/^https?:/.test(request.url())) remote.push(request.url());
  });
  await page.goto(pathToFileURL(resolve("../prototype/index.html")).href);
  await expect(
    page.getByRole("heading", { name: "Обзор", exact: true }),
  ).toBeVisible();
  await expect(page.locator(".priority-metrics .metric")).toHaveCount(4);
  await expect(page.locator(".metric-cron strong")).toHaveText("1 мин");
  await expect(page.locator(".metric-http strong")).toHaveText("HTTP 200");
  await expect(page.locator(".env")).toHaveText("ДЕМО");
  await expectReadable(page);
  await page.screenshot({
    path: info.outputPath("prototype-overview.png"),
    fullPage: true,
  });
  for (const name of ["Академия", "Сервер", "Бэкапы", "Стенды", "События"]) {
    await page
      .getByRole("navigation")
      .getByRole("button", { name, exact: true })
      .click();
    await expect(
      page.getByRole("heading", { name, exact: true }),
    ).toBeVisible();
    await expectReadable(page);
    if (["Сервер", "Бэкапы"].includes(name)) {
      await expect(page.locator(".hdd-capacity dd")).toHaveText([
        "1 863 ГиБ",
        "1 521 ГиБ",
        "18,4 %",
        "98,7 %",
      ]);
    }
    if (name === "События") {
      await expect(page.locator(".alert-rule")).toHaveCount(2);
      await expect(page.locator(".incident-row")).toHaveCount(2);
      await expect(page.locator(".incident-history")).toContainText(
        "Записанная история",
      );
    }
  }
  expect(remote).toEqual([]);
});

test("operation plan, target, credentials and idempotency on mobile", async ({
  page,
}, info) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.route("**/api/operations", (route) =>
    route.fulfill({ json: { jobs: [] } }),
  );
  await page.route("**/api/operations/plan", (route) =>
    route.fulfill({
      json: {
        id: "a".repeat(32),
        action: "manual_backup",
        target: "Академия → копия на HDD",
        effect: "Краткая остановка Академии. Почта и DNS не меняются.",
        expires: Date.now() / 1000 + 90,
      },
    }),
  );
  const submitted: any[] = [];
  await page.route("**/api/operations/confirm", async (route) => {
    submitted.push(route.request().postDataJSON());
    await route.fulfill({ json: { id: "b".repeat(32), state: "queued" } });
  });
  await login(page);
  await page
    .getByRole("navigation")
    .getByRole("button", { name: "Бэкапы", exact: true })
    .click();
  await expect(
    page.getByRole("heading", { name: "Защищённое скачивание", exact: true }),
  ).toBeVisible();
  await expect(page.locator(".download-guidance")).toContainText("HTTPS");
  await expect(page.locator(".download-guidance")).toContainText("SHA256");
  await expect(
    page.getByRole("heading", { name: "SFTP и передача", exact: true }),
  ).toHaveCount(0);
  await page
    .getByRole("button", { name: "Создать копию", exact: true })
    .click();
  const dialog = page.getByRole("dialog");
  await expect(dialog).toContainText("Академия → копия на HDD");
  await expectReadable(page);
  await page.screenshot({
    path: info.outputPath("operation-plan-mobile.png"),
    fullPage: true,
  });
  await dialog
    .getByLabel("Для подтверждения введите manual_backup")
    .fill("manual_backup");
  await dialog
    .getByLabel("Текущий пароль", { exact: true })
    .fill("browser-fixture-password");
  await dialog
    .getByRole("button", { name: "Подтвердить и запустить", exact: true })
    .click();
  await expect(dialog).not.toBeVisible();
  expect(submitted).toHaveLength(1);
  expect(submitted[0].idempotency).toMatch(/^[0-9a-f-]{36}$/);
  expect(submitted[0].confirmation).toBe("manual_backup");
});

test("archive grant does not embed a key or start a backup", async ({
  page,
}) => {
  await page.route("**/api/operations", (route) =>
    route.fulfill({ json: { jobs: [] } }),
  );
  const capture: any[] = [];
  await page.route("**/api/backup/download", async (route) => {
    capture.push(route.request().postDataJSON());
    await route.fulfill({
      json: {
        token: "c".repeat(43),
        file: "fixture.tar.gz.age",
        sha256: "a".repeat(64),
        expires: Date.now() / 1000 + 120,
      },
    });
  });
  await login(page);
  await page
    .getByRole("navigation")
    .getByRole("button", { name: "Бэкапы", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Скачать зашифрованный архив", exact: true })
    .click();
  const dialog = page.getByRole("dialog");
  await dialog
    .getByLabel("Текущий пароль", { exact: true })
    .fill("browser-fixture-password");
  await dialog
    .getByRole("button", { name: "Разрешить скачивание", exact: true })
    .click();
  await expect(
    page.getByRole("link", { name: /Скачать · ссылка/ }),
  ).toHaveAttribute("href", "/api/backup/archive/" + "c".repeat(43));
  expect(capture).toHaveLength(1);
});
