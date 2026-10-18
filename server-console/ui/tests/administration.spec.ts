import { test, expect, Page } from "@playwright/test";
import { expectReadable } from "./readability";
import { resolve } from "node:path";
import { pathToFileURL } from "node:url";
const preferences = () => ({
  enabled: true,
  check_ids: ["host", "cpu_sustained"],
  statuses: ["warning", "critical", "no_data"],
  repeat_minutes: 240,
  quiet_start: "",
  quiet_end: "",
  timezone: "Europe/Moscow",
  recovery: true,
  cpu_percent: 85,
  ram_available_percent: 15,
  hold_seconds: 600,
});
async function account(page: Page, role = "owner") {
  await page.route("**/api/activity", (route) =>
    route.fulfill({ json: { status: "active" } }),
  );
  await page.route("**/api/history?*", (route) =>
    route.fulfill({
      json: { status: "no_data", reason_code: "no_samples", points: [] },
    }),
  );
  await page.route("**/api/events", (route) =>
    route.fulfill({
      json: {
        events: [],
        incidents: [],
        incidents_status: "ok",
        incidents_observed_at: new Date().toISOString(),
      },
    }),
  );
  await page.route("**/api/session", (r) =>
    r.fulfill({
      json: {
        username: role,
        role,
        csrf: "test-csrf",
        expires_at: Date.now() / 1000 + 3600,
      },
    }),
  );
  await page.route("**/api/overview", (r) =>
    r.fulfill({
      json: {
        observed_at: new Date().toISOString(),
        received_at: new Date().toISOString(),
        stale: false,
        age_seconds: 0,
        host: {},
        backup: {},
        checks: [],
        containers: [],
        lab: {},
        domains: {},
      },
    }),
  );
  await page.route("**/api/account/telegram", (r) =>
    r.fulfill({
      json: {
        linked: false,
        preferences: preferences(),
        catalog: [
          { id: "host", label: "Ресурсы сервера" },
          { id: "cpu_sustained", label: "Длительная нагрузка CPU" },
        ],
        transport_status: { status: "no_data" },
      },
    }),
  );
  await page.route("**/api/admin/users", (r) =>
    r.fulfill({
      json: {
        users: [
          {
            username: "owner",
            role: "owner",
            enabled: true,
            totp: true,
            sessions: 2,
          },
          {
            username: "tech",
            role: "viewer",
            enabled: true,
            totp: false,
            sessions: 0,
          },
        ],
      },
    }),
  );
  await page.goto("/");
}
const nav = (page: Page, name: string) =>
  page.getByRole("navigation").getByRole("button", { name, exact: true });
test("owner creates user with fresh credentials and CSRF; cancellation makes no write", async ({
  page,
}) => {
  await account(page);
  await nav(page, "Пользователи").click();
  await expect(
    page.getByRole("table", { name: "Локальные пользователи" }),
  ).toContainText("tech");
  await page
    .getByRole("button", { name: "Создать пользователя", exact: true })
    .click();
  const dialog = page.getByRole("dialog");
  await dialog
    .getByLabel("Логин нового пользователя", { exact: true })
    .fill("sysadmin");
  await dialog.getByLabel("Роль", { exact: true }).selectOption("operator");
  await dialog
    .getByLabel("Новый пароль", { exact: true })
    .fill("new-secure-password");
  await dialog
    .getByLabel("Текущий пароль", { exact: true })
    .fill("owner-password");
  await dialog
    .getByLabel("Код TOTP, если подключён", { exact: true })
    .fill("123456");
  const written: any[] = [];
  await page.route("**/api/admin/users", async (r) => {
    if (r.request().method() === "POST") {
      written.push({
        body: r.request().postDataJSON(),
        csrf: r.request().headers()["x-csrf-token"],
      });
      await r.fulfill({ status: 201, json: { ok: true } });
    } else await r.fulfill({ json: { users: [] } });
  });
  await dialog
    .getByRole("button", { name: "Подтвердить", exact: true })
    .click();
  await expect(dialog).not.toBeVisible();
  expect(written).toEqual([
    {
      body: {
        username: "sysadmin",
        role: "operator",
        new_password: "new-secure-password",
        password: "owner-password",
        otp: "123456",
      },
      csrf: "test-csrf",
    },
  ]);
  await page
    .getByRole("button", { name: "Создать пользователя", exact: true })
    .click();
  await expect(
    dialog.getByLabel("Текущий пароль", { exact: true }),
  ).toHaveValue("");
  await expect(dialog.getByLabel("Новый пароль", { exact: true })).toHaveValue(
    "",
  );
  await dialog.getByRole("button", { name: "Отмена", exact: true }).click();
  expect(written).toHaveLength(1);
});
test("last owner refusal is clear and clears submitted secrets; password reset is separate", async ({
  page,
}) => {
  await account(page);
  await page.route("**/api/admin/users/owner", (r) =>
    r.fulfill({ status: 409, json: { detail: "last_active_owner" } }),
  );
  await nav(page, "Пользователи").click();
  await page
    .getByRole("row")
    .filter({ has: page.getByRole("cell", { name: "owner", exact: true }) })
    .getByRole("button", { name: "Изменить доступ" })
    .click();
  const dialog = page.getByRole("dialog");
  await dialog.getByLabel("Роль", { exact: true }).selectOption("viewer");
  await dialog
    .getByLabel("Текущий пароль", { exact: true })
    .fill("owner-password");
  await dialog
    .getByRole("button", { name: "Подтвердить", exact: true })
    .click();
  await expect(dialog.getByRole("alert")).toContainText(
    "последнего активного владельца",
  );
  await expect(
    dialog.getByLabel("Текущий пароль", { exact: true }),
  ).toHaveValue("");
  await dialog.getByRole("button", { name: "Отмена", exact: true }).click();
  await page
    .getByRole("row")
    .filter({ has: page.getByRole("cell", { name: "tech", exact: true }) })
    .getByRole("button", { name: "Сбросить пароль" })
    .click();
  await expect(
    dialog.getByLabel("Новый пароль", { exact: true }),
  ).toBeVisible();
});
test("viewer links only personal Telegram then refreshes and unlinks with fresh credentials", async ({
  page,
}) => {
  await account(page, "viewer");
  await expect(nav(page, "Пользователи")).toHaveCount(0);
  await nav(page, "Мои уведомления").click();
  await page.getByRole("button", { name: "Получить код привязки" }).click();
  await page.route("**/api/account/telegram/link", (r) =>
    r.fulfill({
      json: {
        code: "ABCD-1234",
        expires_at: Date.now() / 1000 + 600,
        instruction: "Send /link CODE",
      },
    }),
  );
  const dialog = page.getByRole("dialog");
  await dialog
    .getByLabel("Текущий пароль", { exact: true })
    .fill("viewer-password");
  await dialog
    .getByRole("button", { name: "Подтвердить", exact: true })
    .click();
  await expect(
    page.getByText("/link ABCD-1234", { exact: true }),
  ).toBeVisible();
  await expect(
    page.getByText("личное сообщение боту", { exact: false }),
  ).toBeVisible();
  await page.route("**/api/account/telegram", (r) =>
    r.fulfill({
      json: {
        linked: true,
        telegram_id: 123456,
        preferences: preferences(),
        catalog: [{ id: "host", label: "Ресурсы сервера" }],
        transport_status: { status: "ok" },
      },
    }),
  );
  await page.getByRole("button", { name: "Обновить статус" }).click();
  await expect(
    page.getByText("Telegram привязан", { exact: true }),
  ).toBeVisible();
  await expect(page.getByText("/link ABCD-1234", { exact: true })).toHaveCount(
    0,
  );
  let unlink: any;
  await page.route("**/api/account/telegram/unlink", (r) => {
    unlink = r.request().postDataJSON();
    return r.fulfill({ json: { ok: true } });
  });
  await page.getByRole("button", { name: "Отвязать Telegram" }).click();
  await expect(
    dialog.getByLabel("Текущий пароль", { exact: true }),
  ).toHaveValue("");
  await dialog
    .getByLabel("Текущий пароль", { exact: true })
    .fill("viewer-password");
  await dialog
    .getByRole("button", { name: "Подтвердить", exact: true })
    .click();
  await expect
    .poll(() => unlink)
    .toEqual({ password: "viewer-password", otp: "" });
});
test("personal check selection, quiet hours and thresholds require fresh password", async ({
  page,
}) => {
  await account(page, "operator");
  await nav(page, "Мои уведомления").click();
  await page.getByLabel("Повтор, минут", { exact: true }).fill("30");
  await page.getByLabel("Начало тихих часов", { exact: true }).fill("22:30");
  await page.getByLabel("Конец тихих часов", { exact: true }).fill("07:00");
  await page.getByLabel("CPU, %", { exact: true }).fill("91");
  await page.getByLabel("Свободная RAM, %", { exact: true }).fill("10");
  await page
    .getByLabel("Удержание порога, секунд", { exact: true })
    .fill("120");
  await page.getByLabel("Внимание", { exact: true }).uncheck();
  await page.getByRole("button", { name: "Сохранить настройки" }).click();
  const dialog = page.getByRole("dialog");
  let saved: any;
  await page.route("**/api/account/notifications", (r) => {
    saved = {
      body: r.request().postDataJSON(),
      csrf: r.request().headers()["x-csrf-token"],
    };
    return r.fulfill({ json: { ok: true } });
  });
  await dialog
    .getByLabel("Текущий пароль", { exact: true })
    .fill("operator-password");
  await dialog
    .getByRole("button", { name: "Подтвердить", exact: true })
    .click();
  await expect(dialog).not.toBeVisible();
  expect(saved).toEqual({
    body: {
      preferences: {
        ...preferences(),
        statuses: ["critical", "no_data"],
        repeat_minutes: 30,
        quiet_start: "22:30",
        quiet_end: "07:00",
        cpu_percent: 91,
        ram_available_percent: 10,
        hold_seconds: 120,
      },
      password: "operator-password",
      otp: "",
    },
    csrf: "test-csrf",
  });
  await expectReadable(page);
});
test("expired account session returns to login; direct users hash reveals no admin controls", async ({
  page,
}) => {
  await account(page, "viewer");
  await page.goto("/#users");
  await expect(
    page.getByRole("button", { name: "Создать пользователя", exact: true }),
  ).toHaveCount(0);
  await page.route("**/api/account/telegram", (r) =>
    r.fulfill({ status: 401, json: { detail: "session_expired" } }),
  );
  await nav(page, "Мои уведомления").click();
  await expect(
    page.getByRole("button", { name: "Войти", exact: true }),
  ).toBeVisible();
});

test("owner disables another user and separately resets their password", async ({
  page,
}) => {
  await account(page);
  const changes: any[] = [];
  await page.route("**/api/admin/users/tech", (r) => {
    changes.push({
      body: r.request().postDataJSON(),
      method: r.request().method(),
      csrf: r.request().headers()["x-csrf-token"],
    });
    return r.fulfill({
      json: {
        user: {
          username: "tech",
          role: "operator",
          enabled: false,
          totp: false,
          sessions: 0,
        },
      },
    });
  });
  await nav(page, "Пользователи").click();
  const row = page
    .getByRole("row")
    .filter({ has: page.getByRole("cell", { name: "tech", exact: true }) });
  await row.getByRole("button", { name: "Изменить доступ" }).click();
  const dialog = page.getByRole("dialog");
  await dialog.getByLabel("Роль", { exact: true }).selectOption("operator");
  await dialog.getByLabel("Доступ разрешён", { exact: true }).uncheck();
  await dialog
    .getByLabel("Текущий пароль", { exact: true })
    .fill("owner-password");
  await dialog
    .getByRole("button", { name: "Подтвердить", exact: true })
    .click();
  await expect(dialog).not.toBeVisible();
  await row.getByRole("button", { name: "Сбросить пароль" }).click();
  await expect(
    dialog.getByLabel("Текущий пароль", { exact: true }),
  ).toHaveValue("");
  await dialog
    .getByLabel("Новый пароль", { exact: true })
    .fill("new-secure-password");
  await dialog
    .getByLabel("Текущий пароль", { exact: true })
    .fill("owner-password");
  await dialog
    .getByRole("button", { name: "Подтвердить", exact: true })
    .click();
  await expect(dialog).not.toBeVisible();
  expect(changes).toEqual([
    {
      body: {
        role: "operator",
        enabled: false,
        password: "owner-password",
        otp: "",
      },
      method: "PATCH",
      csrf: "test-csrf",
    },
    {
      body: {
        new_password: "new-secure-password",
        password: "owner-password",
        otp: "",
      },
      method: "PATCH",
      csrf: "test-csrf",
    },
  ]);
});

test("credentials rejection clears secrets and cancel restores focus; invalid quiet hours make no write", async ({
  page,
}) => {
  await account(page, "operator");
  await nav(page, "Мои уведомления").click();
  await expect(nav(page, "Пользователи")).toHaveCount(0);
  await page.route("**/api/account/telegram/link", (r) =>
    r.fulfill({ status: 403, json: { detail: "credentials_rejected" } }),
  );
  const trigger = page.getByRole("button", { name: "Получить код привязки" });
  await trigger.click();
  const dialog = page.getByRole("dialog");
  await dialog
    .getByLabel("Текущий пароль", { exact: true })
    .fill("wrong-password");
  await dialog
    .getByLabel("Код TOTP, если подключён", { exact: true })
    .fill("123456");
  await dialog
    .getByRole("button", { name: "Подтвердить", exact: true })
    .click();
  await expect(dialog.getByRole("alert")).toContainText("не принят");
  await expect(
    dialog.getByLabel("Текущий пароль", { exact: true }),
  ).toHaveValue("");
  await expect(
    dialog.getByLabel("Код TOTP, если подключён", { exact: true }),
  ).toHaveValue("");
  await page.keyboard.press("Escape");
  await expect(dialog).not.toBeVisible();
  await expect(trigger).toBeFocused();
  let writes = 0;
  await page.route("**/api/account/notifications", (r) => {
    writes++;
    return r.fulfill({ json: { ok: true } });
  });
  await page.getByLabel("Начало тихих часов", { exact: true }).fill("22:00");
  await page.getByRole("button", { name: "Сохранить настройки" }).click();
  await expect(page.getByRole("alert")).toContainText("начало и конец");
  await expect(dialog).not.toBeVisible();
  expect(writes).toBe(0);
});

for (const theme of ["light", "dark"]) {
  test(`${theme} account forms stay readable at 320px without server measurements`, async ({
    page,
  }) => {
    await page.addInitScript(
      (value) => localStorage.setItem("ustar-console-theme", value),
      theme,
    );
    await account(page);
    await page.route("**/api/overview", (r) =>
      r.fulfill({ status: 503, json: { detail: "source_unavailable" } }),
    );
    await page.setViewportSize({ width: 320, height: 844 });
    await nav(page, "Мои уведомления").click();
    await expect(page.getByLabel("CPU, %", { exact: true })).toHaveValue("85");
    await expectReadable(page);
    await nav(page, "Пользователи").click();
    await page
      .getByRole("button", { name: "Создать пользователя", exact: true })
      .click();
    await expectReadable(page);
    await page.keyboard.press("Escape");
    await expect(
      page.getByRole("button", { name: "Создать пользователя", exact: true }),
    ).toBeFocused();
  });
}

test("offline prototype account tabs have readable demo data and disabled writes without network", async ({
  page,
}) => {
  const remote: string[] = [];
  page.on("request", (r) => {
    if (/^https?:/.test(r.url())) remote.push(r.url());
  });
  await page.goto(pathToFileURL(resolve("../prototype/index.html")).href);
  await nav(page, "Пользователи").click();
  await expect(
    page.getByRole("table", { name: "Локальные пользователи" }),
  ).toContainText("demo-operator");
  await expect(
    page.getByRole("button", { name: "Создать пользователя", exact: true }),
  ).toBeDisabled();
  await expect(
    page.getByRole("button", { name: "Изменить доступ" }).first(),
  ).toBeDisabled();
  await nav(page, "Мои уведомления").click();
  await expect(
    page.getByText("изменения не сохраняются", { exact: false }),
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Получить код привязки" }),
  ).toBeDisabled();
  await expect(
    page.getByRole("button", { name: "Сохранить настройки" }),
  ).toBeDisabled();
  await expectReadable(page);
  expect(remote).toEqual([]);
});
