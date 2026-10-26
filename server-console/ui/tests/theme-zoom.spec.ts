import { test, expect, Page } from "@playwright/test";
import { graphFixture } from "./graph-fixtures";
import { expectReadable } from "./readability";

const snapshot = () => ({
  observed_at: new Date().toISOString(),
  received_at: new Date().toISOString(),
  age_seconds: 0,
  stale: false,
  domains: {
    academy: "ok",
    server: "warning",
    data: "critical",
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
  backup: {
    file: "fixture.tar.gz.age",
    bytes: 2 ** 30,
    sha256: "a".repeat(64),
    completed_at: new Date().toISOString(),
    age_seconds: 30,
    status: "warning",
  },
  checks: [
    { id: "host", label: "Нагрузка сервера", domain: "server", status: "ok" },
    {
      id: "ssd_space",
      label: "Свободное место SSD",
      domain: "server",
      status: "warning",
    },
    {
      id: "http_local",
      label: "Ответ Академии",
      domain: "academy",
      status: "ok",
    },
    { id: "backup", label: "Бэкап", domain: "data", status: "critical" },
  ].map((c) => ({
    ...c,
    reason_code: "measured",
    source: c.id,
    observed_at: new Date().toISOString(),
  })),
  http_local: { code: 200, total_ms: 127 },
  containers: [],
  lab: {},
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
    route.fulfill({ json: snapshot() }),
  );
  await page.route("**/api/history?*", (route) => {
    const url = new URL(route.request().url());
    return route.fulfill({
      json: graphFixture(
        url.searchParams.get("metric") || "cpu",
        url.searchParams.get("period") || "1h",
        1792332000,
      ),
    });
  });
});

test("theme follows system on login and persists an explicit choice through login, reload and logout", async ({
  page,
}) => {
  await page.emulateMedia({ colorScheme: "light" });
  await page.goto("/");
  const theme = page.getByLabel("Тема оформления", { exact: true });
  await expect(theme).toHaveValue("system");
  await expect(page.locator("html")).toHaveAttribute("data-theme", "light");
  await expectReadable(page);
  await page.emulateMedia({ colorScheme: "dark" });
  await expect(page.locator("html")).toHaveAttribute("data-theme", "dark");
  await theme.selectOption("light");
  await expect
    .poll(() =>
      page.evaluate(() => localStorage.getItem("ustar-console-theme")),
    )
    .toBe("light");
  await login(page);
  await expect(page.locator("html")).toHaveAttribute("data-theme", "light");
  await page.reload();
  await expect(theme).toHaveValue("light");
  await expect(page.locator("html")).toHaveAttribute("data-theme", "light");
  await page.getByRole("button", { name: "Выйти", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Вход в управление" }),
  ).toBeVisible();
  await expect(theme).toHaveValue("light");
  await theme.selectOption("system");
  await expect(page.locator("html")).toHaveAttribute("data-theme", "dark");
});

test("theme remains usable when storage is denied", async ({ page }) => {
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  await page.addInitScript(() => {
    Storage.prototype.getItem = () => {
      throw new DOMException("Denied", "SecurityError");
    };
    Storage.prototype.setItem = () => {
      throw new DOMException("Denied", "SecurityError");
    };
  });
  await page.goto("/");
  await page
    .getByLabel("Тема оформления", { exact: true })
    .selectOption("light");
  await expect(page.locator("html")).toHaveAttribute("data-theme", "light");
  await page
    .getByLabel("Тема оформления", { exact: true })
    .selectOption("dark");
  await expect(page.locator("html")).toHaveAttribute("data-theme", "dark");
  expect(errors).toEqual([]);
});

for (const theme of ["light", "dark"]) {
  test(`${theme} theme keeps sections, fields, dialogs and charts readable at 320px`, async ({
    page,
  }, info) => {
    await page.addInitScript(
      (value) => localStorage.setItem("ustar-console-theme", value),
      theme,
    );
    await login(page);
    await expect(page.locator('svg[role="img"]')).toHaveCount(2);
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
      await expectReadable(page);
    }
    await page.setViewportSize({ width: 320, height: 844 });
    await page
      .getByRole("navigation")
      .getByRole("button", { name: "Обзор", exact: true })
      .click();
    await expectReadable(page);
    const resource = page.locator("section.chart").filter({
      has: page.getByRole("heading", {
        name: "CPU и оперативная память",
        exact: true,
      }),
    });
    await resource.getByRole("img").hover({ position: { x: 120, y: 80 } });
    await expect(resource.getByRole("tooltip")).toBeVisible();
    await expectReadable(page);
    await page.mouse.move(0, 0);
    await page
      .getByRole("button", { name: "Учётная запись", exact: true })
      .click();
    await expect(
      page.getByLabel("Текущий пароль", { exact: true }),
    ).toBeVisible();
    await expectReadable(page);
    await page.keyboard.press("Escape");
    await page.getByRole("button", { name: /Увеличить.*CPU/ }).click();
    await expect(
      page.getByRole("dialog", { name: "CPU и оперативная память" }),
    ).toBeVisible();
    await expectReadable(page);
    await page.screenshot({ path: info.outputPath(`${theme}-zoom.png`) });
  });
}

test("enlarged graph preserves selected period, source gaps and legend and restores focus on close", async ({
  page,
}) => {
  await login(page);
  await page.getByLabel("Период графиков").selectOption("7d");
  const chart = page.locator("section.chart").filter({
    has: page.getByRole("heading", {
      name: "CPU и оперативная память",
      exact: true,
    }),
  });
  await expect(chart.locator(".series-cpu .chart-line")).toHaveCount(2);
  const summary = await chart.locator(".chart-legend").innerText();
  const axes = await chart.locator(".axis-text").allTextContents();
  const samples = await chart.getByRole("img").getAttribute("aria-label");
  const button = chart.getByRole("button", { name: /Увеличить/ });
  await button.click();
  const dialog = page.getByRole("dialog", { name: "CPU и оперативная память" });
  await expect(dialog).toBeVisible();
  await expect(dialog.getByRole("img")).toHaveAttribute("aria-label", samples!);
  await expect(dialog.locator(".series-cpu .chart-line")).toHaveCount(2);
  await expect(dialog.locator(".series-ram .chart-line")).toHaveCount(2);
  expect(await dialog.locator(".chart-legend").innerText()).toBe(summary);
  const expandedAxes = await dialog.locator(".axis-text").allTextContents();
  expect(expandedAxes.slice(0, 5)).toEqual(axes.slice(0, 5));
  expect(expandedAxes.at(-1)).toBe(axes.at(-1));
  await expect(dialog.getByRole("button", { name: /Увеличить/ })).toHaveCount(
    0,
  );
  await dialog.getByRole("button", { name: "RAM", exact: true }).click();
  await expect(dialog.locator(".series-ram .chart-line")).toHaveCount(0);
  await expectReadable(page);
  expect(
    await dialog
      .getByRole("img")
      .evaluate((el) => el.getBoundingClientRect().height),
  ).toBeGreaterThan(250);
  await page.keyboard.press("Escape");
  await expect(dialog).not.toBeVisible();
  await expect(button).toBeFocused();
  await button.click();
  await dialog.getByRole("button", { name: "Закрыть", exact: true }).click();
  await expect(dialog).not.toBeVisible();
  await expect(button).toBeFocused();
  expect(await page.evaluate(() => document.body.style.overflow)).not.toBe(
    "hidden",
  );
  await expectReadable(page);
});

test("missing chart history stays missing when enlarged", async ({ page }) => {
  await page.route("**/api/history?*", (route) =>
    route.fulfill({
      json: {
        status: "no_data",
        reason_code: "source_unavailable",
        points: [],
      },
    }),
  );
  await login(page);
  await page.getByRole("button", { name: /Увеличить.*CPU/ }).click();
  const dialog = page.getByRole("dialog", { name: "CPU и оперативная память" });
  await expect(dialog.getByRole("img")).toHaveCount(0);
  await expect(dialog).toContainText("Не удалось получить историю");
  await expectReadable(page);
});
