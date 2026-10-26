import { test, expect, Page } from "@playwright/test";
import { expectReadable } from "./readability";

const sourceCommit = "7f8a614a".padEnd(40, "a");
const overview = (stale = false) => ({
  version: 1,
  observed_at: new Date().toISOString(),
  received_at: new Date().toISOString(),
  age_seconds: 0,
  stale,
  checks: [],
  host: {},
  backup: {},
  containers: [],
  lab: { phase: "stopped", ready: false },
  domains: {
    academy: "no_data",
    server: "no_data",
    data: "no_data",
    monitoring: "no_data",
  },
});
const catalog = () => ({
  source_commit: sourceCommit,
  generated_at: "2026-10-18T12:00:00Z",
  scripts: [
    {
      id: "backup",
      title: "Холодная резервная копия",
      category: "Бэкапы и восстановление",
      purpose: "Создаёт зашифрованную копию Академии.",
      effects: "Останавливает контейнеры на время снятия копии.",
      success: "Проверен SHA256 и Академия снова отвечает.",
      server_path: "/usr/local/sbin/ustar-backup",
      source_path: "infra/scripts/backup.sh",
      sha256: "a".repeat(64),
      action: "manual_backup",
    },
    {
      id: "audit",
      title: "Диагностика диска",
      category: "Диагностика",
      purpose: "Собирает температуру и SMART накопителя.",
      effects: "Читает счётчики без изменения данных.",
      success: "Создан проверенный отчёт о накопителе.",
      server_path: "/usr/local/sbin/ustar-disk-audit",
      source_path: "infra/scripts/disk audit.sh",
      sha256: "b".repeat(64),
      installed: {
        status: "present",
        sha256: "c".repeat(64),
        matches_source: false,
      },
    },
    {
      id: "unknown",
      title: "Служебная миграция",
      category: "Обслуживание",
      purpose: "Выполняется по отдельному регламенту.",
      effects: "Изменяет служебную конфигурацию.",
      success: "Проверки регламента завершены.",
      server_path: "/usr/local/sbin/ustar-migrate",
      source_path: "infra/scripts/migrate.sh",
      sha256: "d".repeat(64),
      action: "arbitrary_shell",
      installed: { status: "missing" },
    },
    {
      id: "native-only",
      title: "USTAR_native_only.sh",
      category: "Дополнительные серверные скрипты",
      purpose: "Обнаружен на сервере; назначение не сопоставлено.",
      effects: "Не запускается до проверки назначения.",
      success: "Не определён для этого выпуска.",
      server_path: "/usr/local/sbin/USTAR_native_only.sh",
      source_path: "",
      sha256: "",
      installed: { status: "present", sha256: "9".repeat(64) },
    },
  ],
  documents: [
    {
      id: "infra",
      title: "Инфраструктура сервера",
      category: "Инфраструктура",
      summary: "Контейнеры, сети и хранилища.",
      source_path: "context/infra/server.md",
      sha256: "e".repeat(64),
      content:
        "# Инфраструктура\n\nСеть и резервное хранилище.\n\n## Проверка\n\n### Подробности\n\n#### Дополнительная проверка\n\n```sh\nprintf '%s\\n' 'status'\n  preserved indentation\n```\n\n<script>window.catalogExecuted = true</script>\n\n[опасная ссылка](javascript:alert(1))",
    },
    {
      id: "architecture",
      title: "Границы компонентов",
      category: "Архитектура",
      summary: "Как разделены части приложения.",
      source_path: "context/architecture/boundaries.md",
      sha256: "f".repeat(64),
      content:
        "# Компоненты\n\nУникальный поисковый маркер: capability-boundary.\n\nПервый абзац.\n\nВторой абзац.",
    },
  ],
});

async function login(page: Page, role = "owner") {
  if (role !== "owner") {
    await page.route("**/api/session", async (route) => {
      const response = await route.fetch();
      if (!response.ok()) return route.fulfill({ response });
      return route.fulfill({
        response,
        json: { ...(await response.json()), role },
      });
    });
  }
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

async function openKnowledge(page: Page, name: "Скрипты" | "Документация") {
  const tab = page
    .getByRole("navigation")
    .getByRole("button", { name, exact: true });
  await expect(tab).toBeVisible();
  await tab.click();
  await expect(page.locator(".knowledge-view")).toBeVisible();
}

test.beforeEach(async ({ page }) => {
  await page.route("**/api/overview", (route) =>
    route.fulfill({ json: overview() }),
  );
  await page.route("**/api/knowledge", (route) =>
    route.fulfill({ json: catalog() }),
  );
});

test("scripts describe purpose, effects and success while distinguishing source from installed evidence", async ({
  page,
}) => {
  await login(page);
  await openKnowledge(page, "Скрипты");
  const backup = page.getByRole("article", {
    name: "Холодная резервная копия",
    exact: true,
  });
  await expect(backup).toContainText("Создаёт зашифрованную копию Академии.");
  await expect(backup).toContainText(
    "Останавливает контейнеры на время снятия копии.",
  );
  await expect(backup).toContainText(
    "Проверен SHA256 и Академия снова отвечает.",
  );
  await expect(backup).toContainText("SHA256 исходника");
  await expect(backup).toContainText("Установка не проверена");
  await expect(backup).not.toContainText("Установлен на сервере");
  const audit = page.getByRole("article", {
    name: "Диагностика диска",
    exact: true,
  });
  await expect(audit).toContainText("SHA256 на сервере");
  await expect(audit).toContainText("Отличается от исходника");
  await expect(
    audit.getByRole("button", { name: "Запуск недоступен" }),
  ).toBeDisabled();
  const unknown = page.getByRole("article", {
    name: "Служебная миграция",
    exact: true,
  });
  await expect(unknown).toContainText("Не найден на сервере");
  await expect(
    unknown.getByRole("button", { name: "Запуск недоступен" }),
  ).toBeDisabled();
  const native = page.getByRole("article", {
    name: "USTAR_native_only.sh",
    exact: true,
  });
  await expect(native).toContainText("Исходник не сопоставлен");
  await expect(native).toContainText("SHA256 исходника не указан");
  await expect(native.getByRole("link")).toHaveCount(0);
  await expect(
    page.getByRole("link", {
      name: "Исходник: infra/scripts/disk audit.sh",
      exact: true,
    }),
  ).toHaveAttribute(
    "href",
    `https://github.com/cauf1l3d/ustarlms/blob/${sourceCommit}/infra/scripts/disk%20audit.sh`,
  );
  await expectReadable(page);
  await page
    .getByRole("combobox", { name: "Тема оформления" })
    .selectOption("dark");
  await expectReadable(page);
});

test("script search and categories filter the catalog without making a path request", async ({
  page,
}) => {
  const requests: string[] = [];
  page.on("request", (request) => {
    if (request.url().includes("/api/")) requests.push(request.url());
  });
  await login(page);
  await openKnowledge(page, "Скрипты");
  await page
    .getByRole("searchbox", { name: "Поиск по скриптам" })
    .fill("SMART");
  await expect(page.getByRole("article")).toHaveCount(1);
  await expect(page.getByRole("article")).toContainText("Диагностика диска");
  await page.getByRole("searchbox", { name: "Поиск по скриптам" }).fill("");
  await page
    .getByRole("combobox", { name: "Категория", exact: true })
    .selectOption("Бэкапы и восстановление");
  await expect(page.getByRole("article")).toHaveCount(1);
  await expect(page.getByRole("article")).toContainText(
    "Холодная резервная копия",
  );
  await page
    .getByRole("searchbox", { name: "Поиск по скриптам" })
    .fill("../../etc/passwd");
  await expect(
    page.getByText("Ничего не найдено", { exact: true }),
  ).toBeVisible();
  expect(requests.some((url) => url.includes("passwd"))).toBeFalsy();
  await expectReadable(page);
});

test("mapped script opens the existing operation plan and never confirms execution immediately", async ({
  page,
}) => {
  let plans = 0;
  let confirms = 0;
  await page.route("**/api/operations/plan", async (route) => {
    plans++;
    expect(route.request().postDataJSON()).toEqual({ action: "manual_backup" });
    await route.fulfill({
      json: {
        id: "knowledge-test-plan",
        action: "manual_backup",
        target: "Академия",
        effect: "Создать проверенную резервную копию",
        expires: Date.now() / 1000 + 300,
      },
    });
  });
  await page.route("**/api/operations/confirm", async (route) => {
    confirms++;
    await route.fulfill({
      status: 500,
      json: { error: "unexpected_confirmation" },
    });
  });
  await login(page);
  await openKnowledge(page, "Скрипты");
  await page
    .getByRole("article", { name: "Холодная резервная копия", exact: true })
    .getByRole("button", { name: "Создать копию", exact: true })
    .click();
  await expect(page.getByRole("dialog")).toContainText("Проверка плана");
  await expect(page.getByRole("dialog")).toContainText(
    "Создать проверенную резервную копию",
  );
  expect(plans).toBe(1);
  expect(confirms).toBe(0);
});

test("viewer can read scripts but mapped actions explain the required role", async ({
  page,
}) => {
  let plans = 0;
  await page.route("**/api/operations/plan", async (route) => {
    plans++;
    await route.abort();
  });
  await login(page, "viewer");
  await openKnowledge(page, "Скрипты");
  const backup = page.getByRole("article", {
    name: "Холодная резервная копия",
    exact: true,
  });
  await expect(
    backup.getByRole("button", { name: "Создать копию", exact: true }),
  ).toBeDisabled();
  await expect(backup).toContainText("Нужна роль оператора или владельца");
  expect(plans).toBe(0);
  await expectReadable(page);
});

test("stale measurements keep the script catalog readable and disable operation plans", async ({
  page,
}) => {
  await page.route("**/api/overview", (route) =>
    route.fulfill({ json: overview(true) }),
  );
  await login(page);
  await openKnowledge(page, "Скрипты");
  const backup = page.getByRole("article", {
    name: "Холодная резервная копия",
    exact: true,
  });
  await expect(
    backup.getByRole("button", { name: "Создать копию", exact: true }),
  ).toBeDisabled();
  await expect(backup).toContainText("Дождитесь свежих данных");
  await expect(backup).toContainText("Создаёт зашифрованную копию Академии.");
});

test("documentation searches full text and renders headings and code as safe readable content", async ({
  page,
}) => {
  await login(page);
  await openKnowledge(page, "Документация");
  await page
    .getByRole("searchbox", { name: "Поиск по документации" })
    .fill("capability-boundary");
  await expect(page.getByRole("article")).toHaveCount(1);
  await expect(page.getByRole("article")).toContainText("Границы компонентов");
  await page
    .getByRole("button", { name: "Читать: Границы компонентов", exact: true })
    .click();
  const reading = page.getByRole("region", {
    name: "Текст документа",
    exact: true,
  });
  await expect(reading).toContainText("Второй абзац.");
  await page
    .getByRole("button", { name: "Закрыть документ", exact: true })
    .click();
  await page.getByRole("searchbox", { name: "Поиск по документации" }).fill("");
  await page
    .getByRole("combobox", { name: "Категория", exact: true })
    .selectOption("Инфраструктура");
  await page
    .getByRole("button", {
      name: "Читать: Инфраструктура сервера",
      exact: true,
    })
    .click();
  await expect(
    reading.getByRole("heading", { name: "Проверка", exact: true }),
  ).toBeVisible();
  await expect(reading.locator("pre")).toContainText("  preserved indentation");
  await expect(reading).toContainText(
    "<script>window.catalogExecuted = true</script>",
  );
  await expect(
    reading.locator("script, iframe, a[href^='javascript:']"),
  ).toHaveCount(0);
  expect(
    await page.evaluate(
      () =>
        (window as unknown as { catalogExecuted?: boolean }).catalogExecuted,
    ),
  ).toBeUndefined();
  await expectReadable(page);
  await page
    .getByRole("combobox", { name: "Тема оформления" })
    .selectOption("dark");
  await expectReadable(page);
});

test("catalog failure is explicit and can be retried", async ({ page }) => {
  let unavailable = true;
  await page.route("**/api/knowledge", (route) =>
    unavailable
      ? route.fulfill({ status: 503, json: { error: "catalog_unavailable" } })
      : route.fulfill({ json: catalog() }),
  );
  await login(page);
  await openKnowledge(page, "Скрипты");
  await expect(page.getByRole("alert")).toContainText("Каталог недоступен");
  unavailable = false;
  await page
    .getByRole("button", { name: "Повторить загрузку", exact: true })
    .click();
  await expect(
    page.getByRole("article", {
      name: "Холодная резервная копия",
      exact: true,
    }),
  ).toBeVisible();
});
