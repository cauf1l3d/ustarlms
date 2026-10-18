import React, { useEffect, useRef, useState } from "react";

type Api = (path: string, options?: RequestInit) => Promise<any>;
type Plan = {
  id: string;
  action: string;
  target: string;
  effect: string;
  archive?: string;
  expires: number;
};
const actions: Record<string, string> = {
  manual_backup: "Создать копию",
  academy_restart: "Перезапустить Академию",
  lab_start: "Запустить стенд на 2 часа",
  lab_stop: "Остановить стенд",
};
const states: Record<string, string> = {
  queued: "В очереди",
  running: "Выполняется",
  complete: "Завершено",
  failed: "Ошибка",
  refused: "Отклонено",
  interrupted: "Прервано — проверьте восстановление",
};

export function Operations({
  api,
  csrf,
  role,
  page,
  available,
  requestedAction,
  onRequestedHandled,
}: {
  api: Api;
  csrf: string;
  role: string;
  page: string;
  available: boolean;
  requestedAction?: string | null;
  onRequestedHandled?: () => void;
}) {
  const [jobs, setJobs] = useState<any[]>([]),
    [plan, setPlan] = useState<Plan | null>(null);
  const [error, setError] = useState(""),
    [pending, setPending] = useState(false),
    [grant, setGrant] = useState<any>(null);
  const [download, setDownload] = useState(false),
    [tick, setTick] = useState(Date.now());
  const dialog = useRef<HTMLDialogElement>(null);
  const idempotency = useRef("");
  const allowed = role === "owner" || role === "operator";
  useEffect(() => {
    if (!allowed) return;
    let live = true;
    const refresh = () =>
      void api("operations")
        .then((v) => {
          if (live) setJobs(v.jobs || []);
        })
        .catch(() => {});
    refresh();
    const timer = setInterval(refresh, 10000);
    return () => {
      live = false;
      clearInterval(timer);
    };
  }, [allowed, api]);
  useEffect(() => {
    if (plan || download) dialog.current?.showModal();
    else dialog.current?.close();
  }, [plan, download]);
  useEffect(() => {
    const timer = setInterval(() => setTick(Date.now()), 1000);
    return () => clearInterval(timer);
  }, []);
  useEffect(() => {
    if (!requestedAction) return;
    onRequestedHandled?.();
    if (!allowed || !available || !(requestedAction in actions)) return;
    setPending(true);
    setError("");
    void api("operations/plan", { method: "POST", headers: { "X-CSRF-Token": csrf },
      body: JSON.stringify({ action: requestedAction }) }).then(value => {
      idempotency.current = crypto.randomUUID();
      setPlan(value);
    }).catch(() => setError("План недоступен: проверьте состояние, блокировку и версию серверного helper. Действие не запущено."))
      .finally(() => setPending(false));
  }, [requestedAction]);
  if (!allowed)
    return (
      <section className="panel">
        <h2>Операции</h2>
        <p className="muted">
          Для изменений и скачивания архива нужна роль оператора или владельца.
        </p>
      </section>
    );
  const choices =
    page === "backups"
      ? ["manual_backup"]
      : page === "academy"
        ? ["academy_restart"]
        : page === "labs"
          ? ["lab_start", "lab_stop"]
          : [];
  const post = (path: string, body: unknown) =>
    api(path, {
      method: "POST",
      headers: { "X-CSRF-Token": csrf },
      body: JSON.stringify(body),
    });
  const close = () => {
    if (pending) return;
    setPlan(null);
    setDownload(false);
    setError("");
  };
  return (
    <section className="panel operations">
      <h2>Операции и выдача</h2>
      <p className="muted">
        Сначала проверка цели и план. Запуск требует текущего пароля и кода
        TOTP, если он подключён. Задание продолжится после закрытия браузера.
      </p>
      <div className="operation-actions">
        {choices.map((action) => (
          <button
            key={action}
            disabled={!available || pending}
            onClick={async () => {
              setPending(true);
              setError("");
              setGrant(null);
              try {
                const value = await post("operations/plan", { action });
                idempotency.current = crypto.randomUUID();
                setPlan(value);
              } catch {
                setError(
                  "План недоступен: проверьте состояние, блокировку и версию серверного helper. Действие не запущено.",
                );
              } finally {
                setPending(false);
              }
            }}
          >
            {actions[action]}
          </button>
        ))}
        {page === "backups" && (
          <button
            className="primary"
            disabled={!available || pending}
            onClick={() => {
              setGrant(null);
              setError("");
              setDownload(true);
            }}
          >
            Скачать зашифрованный архив
          </button>
        )}
      </div>
      {error && !plan && !download && (
        <p className="error-text" role="alert">
          {error}
        </p>
      )}
      {grant && tick < grant.expires * 1000 && (
        <p className="notice">
          {grant.file}
          <br />
          <a href={"/api/backup/archive/" + grant.token}>
            Скачать · ссылка действует{" "}
            {Math.max(0, Math.ceil(grant.expires - tick / 1000))} с
          </a>
          <br />
          SHA256: <code>{grant.sha256}</code>
        </p>
      )}
      {grant && tick >= grant.expires * 1000 && (
        <p className="muted">
          Срок ссылки истёк. Создайте новую выдачу архива.
        </p>
      )}
      {jobs.length > 0 && (
        <div className="job-list" aria-live="polite">
          {jobs.slice(0, 10).map((job) => (
            <div className="data-row" key={job.id}>
              <span>
                <strong>{actions[job.action] || job.action}</strong>
                <small>
                  {job.actor} · {job.id.slice(0, 8)}
                </small>
              </span>
              <span>{states[job.state] || job.state}</span>
            </div>
          ))}
        </div>
      )}
      <dialog
        ref={dialog}
        onCancel={(e) => {
          if (pending) e.preventDefault();
        }}
        onClose={close}
        aria-labelledby="operation-title"
      >
        <div className="dialog-head">
          <h2 id="operation-title">
            {download ? "Выдача последней копии" : "Проверка плана"}
          </h2>
          <button
            type="button"
            aria-label="Закрыть план"
            disabled={pending}
            onClick={close}
          >
            ×
          </button>
        </div>
        {plan && (
          <>
            <dl>
              <dt>Цель</dt>
              <dd>{plan.target}</dd>
              <dt>Что произойдёт</dt>
              <dd>{plan.effect}</dd>
              {plan.archive && (
                <>
                  <dt>Архив</dt>
                  <dd>{plan.archive}</dd>
                </>
              )}
              <dt>План действителен</dt>
              <dd>{Math.max(0, Math.ceil(plan.expires - tick / 1000))} с</dd>
            </dl>
          </>
        )}
        {download && (
          <p>
            Последний completed архив с записью проверки SHA256 при создании.
            Нужна действующая сессия оператора. Ключ восстановления передаётся
            отдельно. Новая копия не создаётся.
          </p>
        )}
        <form
          key={plan?.id || "download"}
          onSubmit={async (e) => {
            e.preventDefault();
            setPending(true);
            setError("");
            const form = e.currentTarget;
            const fields = new FormData(form);
            try {
              if (download) {
                setGrant(
                  await post("backup/download", {
                    password: fields.get("password"),
                    otp: fields.get("otp") || "",
                  }),
                );
                setDownload(false);
              } else if (plan) {
                await post("operations/confirm", {
                  plan_id: plan.id,
                  idempotency: idempotency.current,
                  confirmation: fields.get("confirmation"),
                  password: fields.get("password"),
                  otp: fields.get("otp") || "",
                });
                setPlan(null);
                setJobs((await api("operations")).jobs || []);
              }
              form.reset();
            } catch {
              setError(
                "Запрос отклонён. Проверьте пароль/TOTP, срок плана и состояние цели. Повтор отправки с тем же планом не создаёт второе задание.",
              );
            } finally {
              setPending(false);
            }
          }}
        >
          {plan && (
            <label>
              Для подтверждения введите {plan.action}
              <input
                name="confirmation"
                required
                autoComplete="off"
                pattern={plan.action}
              />
            </label>
          )}
          <label>
            Текущий пароль
            <input
              name="password"
              type="password"
              required
              autoComplete="current-password"
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
          <button
            className="primary"
            disabled={pending || (!!plan && tick >= plan.expires * 1000)}
          >
            {pending
              ? "Проверяем…"
              : download
                ? "Разрешить скачивание"
                : "Подтвердить и запустить"}
          </button>
        </form>
      </dialog>
    </section>
  );
}
