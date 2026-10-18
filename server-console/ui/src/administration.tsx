import React, { useEffect, useRef, useState } from "react";

type Request = (path: string, options?: RequestInit) => Promise<any>;
type Common = { request: Request; csrf: string; onUnauthorized: () => void };
type User = {
  username: string;
  role: string;
  enabled: boolean;
  totp: boolean;
  sessions: number;
};
type Preferences = {
  enabled: boolean;
  check_ids: string[];
  statuses: string[];
  repeat_minutes: number;
  quiet_start: string;
  quiet_end: string;
  timezone: string;
  recovery: boolean;
  cpu_percent: number;
  ram_available_percent: number;
  hold_seconds: number;
};
type Telegram = {
  demo_read_only?: boolean;
  linked: boolean;
  telegram_id?: number | string;
  preferences: Preferences;
  catalog: { id: string; label: string }[];
  transport_status:
    { status: string; polling?: string; delivery_failures?: number } | string;
};
const roles: Record<string, string> = {
  viewer: "Наблюдатель",
  operator: "Оператор",
  owner: "Владелец",
};
const statuses: Record<string, string> = {
  warning: "Внимание",
  critical: "Авария",
  no_data: "Нет данных",
};
const errorMessages: Record<string, string> = {
  prototype_read_only:
    "Это автономный макет: изменения не сохраняются и серверные права не проверяются. Не вводите реальные пароли.",
  last_owner:
    "Нельзя отключить или понизить роль последнего активного владельца.",
  last_active_owner:
    "Нельзя отключить или понизить роль последнего активного владельца.",
  cannot_change_own_owner:
    "Нельзя отключить себя или понизить собственную роль владельца. Изменение должен выполнить другой владелец.",
  user_exists: "Такой логин уже занят. Выберите другой.",
  user_not_found: "Учётная запись уже отсутствует. Обновите список.",
  credentials_rejected:
    "Текущий пароль или код TOTP не принят. Введите их заново.",
  csrf_rejected:
    "Защита запроса не приняла подтверждение. Обновите страницу и повторите действие.",
  owner_required: "Управление пользователями доступно только владельцу.",
  invalid_request:
    "Параметры не приняты. Проверьте логин, пароль и выбранные значения.",
  notification_revocation_unavailable:
    "Служба уведомлений не подтвердила отзыв привязки. Изменение доступа не подтверждено. Повторите после восстановления связи.",
  notification_request_refused:
    "Служба уведомлений отклонила запрос. Проверьте настройки и текущую привязку.",
  notifier_unavailable:
    "Служба уведомлений недоступна. Изменение не подтверждено. Повторите позже.",
  telegram_already_linked: "Этот Telegram уже связан с другой учётной записью.",
};
function errorMessage(error: unknown) {
  const value = error as { status?: number; code?: string };
  if (value.code && errorMessages[value.code]) return errorMessages[value.code];
  if (value.status === 429)
    return "Слишком много попыток. Подождите и повторите с новым подтверждением.";
  if (value.status === 403)
    return "Действие отклонено. Проверьте текущий пароль, код TOTP и права доступа.";
  if (value.status === 409)
    return "Изменение отклонено: состояние могло измениться. Обновите данные и повторите.";
  if (value.status === 422) return errorMessages.invalid_request;
  return "Не удалось подтвердить результат. Проверьте соединение и обновите данные перед повтором.";
}
function clearSecrets(form: HTMLFormElement) {
  form
    .querySelectorAll<HTMLInputElement>(
      'input[type="password"],input[name="otp"]',
    )
    .forEach((input) => {
      input.value = "";
    });
}
function ActionDialog({
  title,
  children,
  onCancel,
  onConfirm,
  onUnauthorized,
}: {
  title: string;
  children?: React.ReactNode;
  onCancel: () => void;
  onConfirm: (form: FormData) => Promise<void>;
  onUnauthorized: () => void;
}) {
  const dialog = useRef<HTMLDialogElement>(null);
  const [pending, setPending] = useState(false),
    [error, setError] = useState("");
  const active = useRef(true);
  useEffect(() => {
    active.current = true;
    const element = dialog.current;
    const previousFocus = document.activeElement;
    element?.showModal();
    return () => {
      active.current = false;
      element?.close();
      if (previousFocus instanceof HTMLElement && previousFocus.isConnected)
        previousFocus.focus();
    };
  }, []);
  return (
    <dialog
      ref={dialog}
      aria-labelledby="account-action-title"
      onCancel={(event) => {
        event.preventDefault();
        if (!pending) onCancel();
      }}
    >
      <div className="dialog-head">
        <h2 id="account-action-title">{title}</h2>
      </div>
      <form
        onSubmit={async (event) => {
          event.preventDefault();
          if (pending) return;
          const form = event.currentTarget;
          const values = new FormData(form);
          clearSecrets(form);
          setPending(true);
          setError("");
          try {
            await onConfirm(values);
          } catch (error) {
            if (!active.current) return;
            if ((error as { status?: number }).status === 401) onUnauthorized();
            else setError(errorMessage(error));
          } finally {
            // FormData is local to this request, never retained in component state.
            values.delete("password");
            values.delete("new_password");
            values.delete("otp");
            if (active.current) setPending(false);
          }
        }}
      >
        <fieldset disabled={pending} className="account-fields">
          {children}
          <p className="muted">
            Подтвердите действие текущим паролем своей учётной записи. Если
            подключён TOTP, введите свежий код.
          </p>
          <label>
            Текущий пароль
            <input
              type="password"
              name="password"
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
        </fieldset>
        {error && (
          <p className="error-text" role="alert">
            {error}
          </p>
        )}
        {pending && <p role="status">Подтверждаем действие…</p>}
        <div className="account-actions">
          <button type="submit" className="primary" disabled={pending}>
            Подтвердить
          </button>
          <button type="button" onClick={onCancel} disabled={pending}>
            Отмена
          </button>
        </div>
      </form>
    </dialog>
  );
}
const write = (
  request: Request,
  csrf: string,
  path: string,
  method: string,
  body: unknown,
) =>
  request(path, {
    method,
    headers: { "X-CSRF-Token": csrf },
    body: JSON.stringify(body),
  });
export function Users({
  request,
  csrf,
  onUnauthorized,
  username,
}: Common & { username: string }) {
  const [users, setUsers] = useState<User[]>([]),
    [loading, setLoading] = useState(true),
    [error, setError] = useState("");
  const [revision, setRevision] = useState(0),
    [notice, setNotice] = useState("");
  const [readOnly, setReadOnly] = useState(false);
  const [action, setAction] = useState<{
    kind: "create" | "access" | "password";
    user?: User;
  } | null>(null);
  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError("");
    void request("admin/users", {
      signal: AbortSignal.any([controller.signal, AbortSignal.timeout(6500)]),
    })
      .then((value) => {
        if (!controller.signal.aborted) {
          setUsers(value.users || []);
          setReadOnly(Boolean(value.demo_read_only));
        }
      })
      .catch((error) => {
        if (controller.signal.aborted) return;
        setUsers([]);
        if (error.status === 401) onUnauthorized();
        else setError(errorMessage(error));
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [request, revision]);
  return (
    <section
      className="panel administration"
      aria-label="Управление пользователями"
    >
      <div className="section-title">
        <h2>Локальные пользователи</h2>
        <button
          type="button"
          onClick={() => setRevision((value) => value + 1)}
          disabled={loading}
        >
          Обновить список
        </button>
      </div>
      {readOnly && (
        <p className="notice">
          Это автономный макет. Пользователи показаны для примера; изменения не
          сохраняются. Не вводите реальные пароли.
        </p>
      )}
      <p className="muted">
        Владелец создаёт учётные записи и меняет доступ. Изменение роли,
        отключение и сброс пароля отзывают сессии пользователя. Последний
        активный владелец защищён сервером.
      </p>
      <div className="account-actions">
        <button
          type="button"
          disabled={loading || readOnly}
          onClick={() => {
            setNotice("");
            setAction({ kind: "create" });
          }}
        >
          Создать пользователя
        </button>
      </div>
      {notice && <p role="status">{notice}</p>}
      {loading ? (
        <p role="status">Получаем пользователей…</p>
      ) : error ? (
        <p className="error-text" role="alert">
          {error}
        </p>
      ) : (
        <div className="table-wrap">
          <table aria-label="Локальные пользователи">
            <thead>
              <tr>
                <th>Логин</th>
                <th>Роль</th>
                <th>Доступ</th>
                <th>TOTP</th>
                <th>Сессии</th>
                <th>Действия</th>
              </tr>
            </thead>
            <tbody>
              {users.map((user) => (
                <tr key={user.username}>
                  <td data-label="Логин">
                    <strong>{user.username}</strong>
                  </td>
                  <td data-label="Роль">{roles[user.role] || "Неизвестна"}</td>
                  <td data-label="Доступ">
                    {user.enabled ? "Разрешён" : "Отключён"}
                  </td>
                  <td data-label="TOTP">
                    {user.totp ? "Подключён" : "Не подключён"}
                  </td>
                  <td data-label="Сессии">{user.sessions}</td>
                  <td data-label="Действия">
                    <div className="account-actions">
                      <button
                        type="button"
                        disabled={readOnly}
                        onClick={() => {
                          setNotice("");
                          setAction({ kind: "access", user });
                        }}
                      >
                        Изменить доступ
                      </button>
                      <button
                        type="button"
                        disabled={readOnly}
                        onClick={() => {
                          setNotice("");
                          setAction({ kind: "password", user });
                        }}
                      >
                        Сбросить пароль
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {!users.length && (
            <p>Локальных пользователей в ответе нет. Обновите список.</p>
          )}
        </div>
      )}
      {action && (
        <ActionDialog
          title={
            action.kind === "create"
              ? "Создать пользователя"
              : `${action.kind === "access" ? "Изменить доступ" : "Сбросить пароль"}: ${action.user!.username}`
          }
          onCancel={() => setAction(null)}
          onUnauthorized={onUnauthorized}
          onConfirm={async (values) => {
            const credentials = {
              password: String(values.get("password") || ""),
              otp: String(values.get("otp") || ""),
            };
            const body =
              action.kind === "create"
                ? {
                    username: String(values.get("username") || ""),
                    role: String(values.get("role")),
                    new_password: String(values.get("new_password")),
                    ...credentials,
                  }
                : action.kind === "access"
                  ? {
                      role: String(values.get("role")),
                      enabled: values.get("enabled") === "on",
                      ...credentials,
                    }
                  : {
                      new_password: String(values.get("new_password")),
                      ...credentials,
                    };
            await write(
              request,
              csrf,
              action.kind === "create"
                ? "admin/users"
                : `admin/users/${encodeURIComponent(action.user!.username)}`,
              action.kind === "create" ? "POST" : "PATCH",
              body,
            );
            setAction(null);
            if (action.user?.username === username) {
              onUnauthorized();
              return;
            }
            setNotice(
              action.kind === "create"
                ? "Учётная запись создана."
                : "Учётная запись обновлена; её сессии отозваны.",
            );
            setRevision((value) => value + 1);
          }}
        >
          {action.kind === "create" && (
            <label>
              Логин нового пользователя
              <input
                name="username"
                aria-label="Логин нового пользователя"
                aria-describedby="new-username-help"
                autoComplete="off"
                required
                maxLength={48}
                pattern="[a-z][a-z0-9_.-]{0,47}"
              />
              <small id="new-username-help">
                До 48 символов: первая — строчная латинская буква, далее
                строчные буквы, цифры, точка, дефис или подчёркивание.
              </small>
            </label>
          )}
          {action.kind !== "password" && (
            <>
              <label htmlFor="account-role">Роль</label>
              <select id="account-role" name="role" defaultValue={action.user?.role || "viewer"}>
                {Object.entries(roles).map(([role, label]) => (
                  <option value={role} key={role}>
                    {label}
                  </option>
                ))}
              </select>
            </>
          )}
          {action.kind === "access" && (
            <label className="account-checkbox">
              <input
                type="checkbox"
                name="enabled"
                defaultChecked={action.user!.enabled}
              />
              <span>Доступ разрешён</span>
            </label>
          )}
          {action.kind !== "access" && (
            <>
              <label>
                Новый пароль
                <input
                  type="password"
                  name="new_password"
                  autoComplete="new-password"
                  required
                  minLength={14}
                  maxLength={256}
                />
              </label>
              <p className="muted">
                От 14 символов. Передайте пароль пользователю согласованным
                способом.
              </p>
            </>
          )}
        </ActionDialog>
      )}
    </section>
  );
}
function validatePreferences(preferences: Preferences) {
  if (Boolean(preferences.quiet_start) !== Boolean(preferences.quiet_end))
    return "Для тихих часов заполните начало и конец или очистите оба поля.";
  try {
    new Intl.DateTimeFormat("ru-RU", {
      timeZone: preferences.timezone,
    }).format();
  } catch {
    return "Укажите часовой пояс IANA, например Europe/Moscow или UTC.";
  }
  return "";
}
export function Notifications({ request, csrf, onUnauthorized }: Common) {
  const [account, setAccount] = useState<Telegram | null>(null),
    [draft, setDraft] = useState<Preferences | null>(null);
  const [loading, setLoading] = useState(true),
    [error, setError] = useState(""),
    [notice, setNotice] = useState("");
  const [formError, setFormError] = useState("");
  const [revision, setRevision] = useState(0),
    [action, setAction] = useState<"link" | "unlink" | "preferences" | null>(
      null,
    );
  const [link, setLink] = useState<{ code: string; expires_at: number } | null>(
      null,
    ),
    [now, setNow] = useState(Date.now());
  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError("");
    void request("account/telegram", {
      signal: AbortSignal.any([controller.signal, AbortSignal.timeout(6500)]),
    })
      .then((value: Telegram) => {
        if (controller.signal.aborted) return;
        setAccount(value);
        setDraft(value.preferences);
        if (value.linked) setLink(null);
      })
      .catch((error) => {
        if (controller.signal.aborted) return;
        setAccount(null);
        setDraft(null);
        if (error.status === 401) onUnauthorized();
        else setError(errorMessage(error));
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [request, revision]);
  useEffect(() => {
    if (!link) return;
    const timer = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(timer);
  }, [link]);
  const update = <K extends keyof Preferences>(key: K, value: Preferences[K]) =>
    setDraft((previous) => previous && { ...previous, [key]: value });
  const toggle = (
    key: "check_ids" | "statuses",
    id: string,
    checked: boolean,
  ) =>
    setDraft(
      (previous) =>
        previous && {
          ...previous,
          [key]: checked
            ? [...previous[key], id]
            : previous[key].filter((value) => value !== id),
        },
    );
  const transport =
    typeof account?.transport_status === "string"
      ? account.transport_status
      : account?.transport_status?.status;
  const linkCurrent = link && link.expires_at * 1000 > now;
  return (
    <div className="administration">
      <section className="panel" aria-label="Мой Telegram">
        <div className="section-title">
          <h2>Мой Telegram</h2>
          <button
            type="button"
            onClick={() => {
              setNotice("");
              setRevision((value) => value + 1);
            }}
            disabled={loading}
          >
            Обновить статус
          </button>
        </div>
        {account?.demo_read_only && (
          <p className="notice">
            Это автономный макет. Привязка и настройки показаны для примера;
            изменения не сохраняются. Не вводите реальные пароли.
          </p>
        )}
        <p className="muted">
          Привязка и настройки относятся только к вашей учётной записи. Каждый
          пользователь входит под своим логином и связывает свой Telegram.
        </p>
        {loading ? (
          <p role="status">Получаем привязку и настройки…</p>
        ) : error ? (
          <p className="error-text" role="alert">
            {error}
          </p>
        ) : (
          account && (
            <>
              <p>
                <strong>
                  {account.linked
                    ? "Telegram привязан"
                    : "Telegram не привязан"}
                </strong>
                {account.linked && account.telegram_id != null && (
                  <span> · ID {account.telegram_id}</span>
                )}
              </p>
              <p className="muted">
                Служба уведомлений:{" "}
                {transport === "ok"
                  ? "доступна"
                  : transport === "warning"
                    ? "требует внимания"
                    : "свежий успешный статус не подтверждён"}
                . Состояние службы не подтверждает получение уведомления
                человеком.
              </p>
              <div className="account-actions">
                {account.linked ? (
                  <button
                    type="button"
                    disabled={account.demo_read_only}
                    onClick={() => {
                      setNotice("");
                      setAction("unlink");
                    }}
                  >
                    Отвязать Telegram
                  </button>
                ) : (
                  <button
                    type="button"
                    disabled={account.demo_read_only}
                    onClick={() => {
                      setNotice("");
                      setAction("link");
                    }}
                  >
                    Получить код привязки
                  </button>
                )}
              </div>
              {link && (
                <div className="telegram-link" role="status">
                  {linkCurrent ? (
                    <>
                      <p>
                        Отправьте личное сообщение боту этого пульта из своего
                        Telegram:
                      </p>
                      <code>/link {link.code}</code>
                      <p>
                        Код одноразовый. Осталось{" "}
                        {Math.max(
                          1,
                          Math.ceil((link.expires_at * 1000 - now) / 60000),
                        )}{" "}
                        мин. После отправки нажмите «Обновить статус».
                      </p>
                    </>
                  ) : (
                    <p>Код привязки истёк. Получите новый код.</p>
                  )}
                </div>
              )}
            </>
          )
        )}
        {notice && <p role="status">{notice}</p>}
      </section>
      {account && draft && !loading && (
        <section
          className="panel"
          aria-label="Персональные настройки уведомлений"
        >
          <h2>Мои уведомления</h2>
          <p className="muted">
            Настройки действуют для вашего получателя. Отключение уведомлений и
            отвязка отменяют ожидающие алерты. Отключение персональных алертов
            сохраняет команду /status. Отвязка удаляет личный доступ к боту;
            доступ, отдельно заданный администратором, сохраняется.
          </p>
          {!account.linked && (
            <p className="notice">
              Настройки можно сохранить заранее. Для доставки свяжите свой
              Telegram.
            </p>
          )}
          <form
            onSubmit={(event) => {
              event.preventDefault();
              const problem = validatePreferences(draft);
              setFormError(problem);
              if (!problem) {
                setNotice("");
                setAction("preferences");
              }
            }}
          >
            <label className="account-checkbox">
              <input
                type="checkbox"
                checked={draft.enabled}
                onChange={(event) => update("enabled", event.target.checked)}
              />
              <span>Уведомления включены</span>
            </label>
            <fieldset className="account-choices">
              <legend>Проверки</legend>
              <div className="account-check-grid">
                {account.catalog.map((item) => (
                  <label className="account-checkbox" key={item.id}>
                    <input
                      type="checkbox"
                      checked={draft.check_ids.includes(item.id)}
                      onChange={(event) =>
                        toggle("check_ids", item.id, event.target.checked)
                      }
                    />
                    <span>{item.label}</span>
                  </label>
                ))}
              </div>
            </fieldset>
            <fieldset className="account-choices">
              <legend>Состояния</legend>
              <div className="account-check-grid">
                {Object.entries(statuses).map(([id, label]) => (
                  <label className="account-checkbox" key={id}>
                    <input
                      type="checkbox"
                      checked={draft.statuses.includes(id)}
                      onChange={(event) =>
                        toggle("statuses", id, event.target.checked)
                      }
                    />
                    <span>{label}</span>
                  </label>
                ))}
              </div>
            </fieldset>
            <div className="account-form-grid">
              <label>
                Повтор, минут
                <input
                  type="number"
                  min={15}
                  max={1440}
                  step={1}
                  required
                  value={draft.repeat_minutes}
                  onChange={(event) =>
                    update("repeat_minutes", Number(event.target.value))
                  }
                />
              </label>
              <label>
                Часовой пояс IANA
                <input
                  required
                  maxLength={64}
                  value={draft.timezone}
                  onChange={(event) => update("timezone", event.target.value)}
                  placeholder="Europe/Moscow"
                  autoComplete="off"
                />
              </label>
              <label>
                Начало тихих часов
                <input
                  type="time"
                  value={draft.quiet_start}
                  onChange={(event) =>
                    update("quiet_start", event.target.value)
                  }
                />
              </label>
              <label>
                Конец тихих часов
                <input
                  type="time"
                  value={draft.quiet_end}
                  onChange={(event) => update("quiet_end", event.target.value)}
                />
              </label>
            </div>
            <p className="muted">
              Пустые часы отключают тихий период. Период может проходить через
              полночь. После него отправляется актуальное состояние, без очереди
              устаревших событий.
            </p>
            <label className="account-checkbox">
              <input
                type="checkbox"
                checked={draft.recovery}
                onChange={(event) => update("recovery", event.target.checked)}
              />
              <span>Сообщать о восстановлении</span>
            </label>
            <fieldset className="account-choices">
              <legend>Личные пороги CPU и RAM</legend>
              <p className="muted">
                Порог CPU срабатывает выше выбранного значения; RAM — ниже доли
                доступной памяти. Нужны выбранные проверки нагрузки. Учитываются
                свежие измерения; пропуск сбрасывает удержание.
              </p>
              <div className="account-form-grid">
                <label>
                  CPU, %
                  <input
                    type="number"
                    min={1}
                    max={100}
                    required
                    value={draft.cpu_percent}
                    onChange={(event) =>
                      update("cpu_percent", Number(event.target.value))
                    }
                  />
                </label>
                <label>
                  Свободная RAM, %
                  <input
                    type="number"
                    min={1}
                    max={99}
                    required
                    value={draft.ram_available_percent}
                    onChange={(event) =>
                      update(
                        "ram_available_percent",
                        Number(event.target.value),
                      )
                    }
                  />
                </label>
                <label>
                  Удержание порога, секунд
                  <input
                    type="number"
                    min={0}
                    max={3600}
                    step={1}
                    required
                    value={draft.hold_seconds}
                    onChange={(event) =>
                      update("hold_seconds", Number(event.target.value))
                    }
                  />
                </label>
              </div>
            </fieldset>
            {formError && (
              <p role="alert" className="error-text">
                {formError}
              </p>
            )}
            <div className="account-actions">
              <button
                type="submit"
                className="primary"
                disabled={account.demo_read_only}
              >
                Сохранить настройки
              </button>
            </div>
          </form>
        </section>
      )}
      {action && (
        <ActionDialog
          title={
            action === "link"
              ? "Привязать мой Telegram"
              : action === "unlink"
                ? "Отвязать мой Telegram"
                : "Сохранить мои уведомления"
          }
          onCancel={() => setAction(null)}
          onUnauthorized={onUnauthorized}
          onConfirm={async (values) => {
            const credentials = {
              password: String(values.get("password") || ""),
              otp: String(values.get("otp") || ""),
            };
            if (action === "link") {
              setLink(null);
              const result = await write(
                request,
                csrf,
                "account/telegram/link",
                "POST",
                credentials,
              );
              setNow(Date.now());
              setLink({
                code: result.code,
                expires_at: Number(result.expires_at),
              });
            } else if (action === "unlink") {
              await write(
                request,
                csrf,
                "account/telegram/unlink",
                "POST",
                credentials,
              );
              setLink(null);
              setNotice("Telegram отвязан; ожидающие уведомления отменены.");
              setRevision((value) => value + 1);
            } else {
              await write(request, csrf, "account/notifications", "PUT", {
                preferences: draft,
                ...credentials,
              });
              setNotice("Персональные настройки сохранены.");
            }
            setAction(null);
          }}
        >
          <p>
            {action === "link"
              ? "Будет выдан одноразовый код на 10 минут. Отправьте его боту в личном сообщении."
              : action === "unlink"
                ? "Личная привязка будет удалена, ожидающие уведомления отменены."
                : "Изменения относятся только к вашим уведомлениям."}
          </p>
        </ActionDialog>
      )}
    </div>
  );
}
