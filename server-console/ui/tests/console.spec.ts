import { test, expect, Page } from "@playwright/test";
import { graphFixture } from "./graph-fixtures";

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
    disk: { total_bytes: 110 * 2 ** 30, available_bytes: 61 * 2 ** 30 },
  },
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
test.beforeEach(async ({ page }) => {
  await page.route("**/api/overview", (route) =>
    route.fulfill({ json: fixture() }),
  );
});
test("real login, six sections, dialog keyboard, logout", async ({ page }) => {
  const errors: string[] = [];
  page.on("pageerror", (err) => errors.push(err.message));
  await login(page);
  await expect(page.getByText("4,6 ГиБ", { exact: true })).toBeVisible();
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
    page.getByText(/Нулевые значения не подставляются/).first(),
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

test("readable text, contrast and responsive plot labels", async ({ page }) => {
  await page.route("**/api/history?*", (route) =>
    route.fulfill({
      json: graphFixture(
        new URL(route.request().url()).searchParams.get("metric") || "cpu",
      ),
    }),
  );
  await login(page);
  const sizes = await page
    .locator(".metric-label,.check-row strong,.container-name strong")
    .evaluateAll((nodes) =>
      nodes.map((node) => parseFloat(getComputedStyle(node).fontSize)),
    );
  expect(Math.min(...sizes)).toBeGreaterThanOrEqual(14);
  const color = await page
    .locator(".container-name strong")
    .first()
    .evaluate((node) => getComputedStyle(node).color);
  expect(color).toBe("rgb(240, 245, 252)");
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
    12,
  );
  expect(Math.min(...labels.map((label) => label.scale))).toBeGreaterThan(0.95);
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBeTruthy();
  await page.setViewportSize({ width: 320, height: 844 });
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBeTruthy();
});
