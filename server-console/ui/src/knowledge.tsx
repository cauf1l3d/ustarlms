import React, { useEffect, useId, useRef, useState } from "react";

type Action = "manual_backup" | "academy_restart" | "lab_start" | "lab_stop";
type Script = {
  id: string;
  title: string;
  category: string;
  purpose: string;
  effects: string;
  success: string;
  server_path: string;
  source_path: string;
  sha256: string;
  action?: string;
  installed?: {
    status: "present" | "missing" | "unavailable";
    sha256?: string;
    matches_source?: boolean;
  };
};
type Document = {
  id: string;
  title: string;
  category: string;
  summary: string;
  source_path: string;
  sha256: string;
  content: string;
};
type Catalog = {
  source_commit: string;
  generated_at: string;
  inventory_observed_at?: string | null;
  scripts: Script[];
  documents: Document[];
};
const actions: Record<Action, string> = {
  manual_backup: "Создать копию",
  academy_restart: "Перезапустить Академию",
  lab_start: "Запустить стенд на 2 часа",
  lab_stop: "Остановить стенд",
};
function knownAction(value?: string): value is Action {
  return !!value && Object.hasOwn(actions, value);
}

function sourceLink(commit: string, path: string) {
  const parts = path.split("/");
  if (
    !/^[a-f0-9]{7,40}$/i.test(commit) ||
    path.includes("\\") ||
    parts.some((part) => !part || part === "." || part === "..")
  )
    return null;
  return `https://github.com/cauf1l3d/ustarlms/blob/${commit}/${parts.map(encodeURIComponent).join("/")}`;
}

function Source({
  item,
  commit,
}: {
  item: { source_path: string; sha256: string };
  commit: string;
}) {
  const href = sourceLink(commit, item.source_path);
  return (
    <div className="knowledge-meta">
      <p>
        {!item.source_path ? (
          "Исходник не сопоставлен."
        ) : href ? (
          <a href={href} target="_blank" rel="noopener noreferrer">
            Исходник: {item.source_path}
          </a>
        ) : (
          <>
            Исходник: <code>{item.source_path}</code>
          </>
        )}
      </p>
      <p>
        {item.sha256 ? (
          <>
            SHA256 исходника: <code>{item.sha256}</code>
          </>
        ) : (
          "SHA256 исходника не указан."
        )}
      </p>
    </div>
  );
}

/** Only React text nodes are rendered: document HTML and links stay inert. */
function SafeContent({ content, path }: { content: string; path: string }) {
  if (!/\.(?:md|markdown|txt)$/i.test(path))
    return (
      <pre className="knowledge-code">
        <code>{content}</code>
      </pre>
    );
  const blocks: React.ReactNode[] = [];
  let paragraph: string[] = [];
  let code: string[] | null = null;
  let fence = "";
  const flushParagraph = () => {
    if (!paragraph.length) return;
    blocks.push(<p key={blocks.length}>{paragraph.join("\n")}</p>);
    paragraph = [];
  };
  const flushCode = () => {
    blocks.push(
      <pre className="knowledge-code" key={blocks.length}>
        <code>{code?.join("\n")}</code>
      </pre>,
    );
    code = null;
  };
  for (const line of content.replace(/\r\n?/g, "\n").split("\n")) {
    if (code !== null) {
      const end = line.trim();
      if (
        end.length >= fence.length &&
        [...end].every((char) => char === fence[0])
      )
        flushCode();
      else code.push(line);
      continue;
    }
    const opening = /^\s{0,3}(`{3,}|~{3,})/.exec(line);
    if (opening) {
      flushParagraph();
      fence = opening[1];
      code = [];
    } else if (!line.trim()) flushParagraph();
    else {
      const heading = /^(#{1,6})\s+(.+)$/.exec(line);
      if (heading) {
        flushParagraph();
        blocks.push(
          React.createElement(
            `h${Math.min(heading[1].length + 2, 6)}`,
            { key: blocks.length },
            heading[2],
          ),
        );
      } else paragraph.push(line);
    }
  }
  if (code !== null) flushCode();
  flushParagraph();
  return (
    <>{blocks.length ? blocks : <p className="muted">Документ пуст.</p>}</>
  );
}

function Installation({ script }: { script: Script }) {
  const installed = script.installed;
  return (
    <div className="knowledge-meta">
      <p>
        Путь на сервере: <code>{script.server_path || "Не указан"}</code>
      </p>
      {!installed || installed.status === "unavailable" ? (
        <p className="muted">
          Установка не проверена.{" "}
          {installed
            ? "Проверка сервера недоступна."
            : "Каталог исходников не подтверждает наличие и версию на сервере."}
        </p>
      ) : installed.status === "missing" ? (
        <p>Не найден на сервере по результату проверки.</p>
      ) : (
        <>
          <p>Найден на сервере по результату проверки.</p>
          {installed.sha256 ? (
            <p>
              SHA256 на сервере: <code>{installed.sha256}</code>
            </p>
          ) : (
            <p className="muted">SHA256 на сервере не проверен.</p>
          )}
          <p>
            {installed.matches_source === true
              ? "Совпадает с исходником."
              : installed.matches_source === false
                ? "Отличается от исходника."
                : "Сопоставление с исходником не проверено."}
          </p>
        </>
      )}
    </div>
  );
}

export function Knowledge({
  kind,
  request,
  onAction,
  role = "viewer",
  available = false,
}: {
  kind: "scripts" | "docs";
  request: (path: string) => Promise<any>;
  onAction: (action: string) => void;
  role?: string;
  available?: boolean;
}) {
  const [catalog, setCatalog] = useState<Catalog | null>(null);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [attempt, setAttempt] = useState(0);
  const [query, setQuery] = useState("");
  const [category, setCategory] = useState("");
  const [selected, setSelected] = useState<Document | null>(null);
  const reader = useRef<HTMLElement>(null);
  const opener = useRef<HTMLButtonElement | null>(null);
  const search = useRef<HTMLInputElement>(null);
  const prefix = useId();
  const canOperate = role === "owner" || role === "operator";

  useEffect(() => {
    let live = true;
    setLoading(true);
    setFailed(false);
    void request("knowledge")
      .then((next) => {
        if (
          !next ||
          typeof next.source_commit !== "string" ||
          typeof next.generated_at !== "string" ||
          !Array.isArray(next.scripts) ||
          !Array.isArray(next.documents)
        )
          throw new Error("invalid_catalog");
        if (live) setCatalog(next);
      })
      .catch(() => {
        if (live) {
          setCatalog(null);
          setFailed(true);
        }
      })
      .finally(() => {
        if (live) setLoading(false);
      });
    return () => {
      live = false;
    };
  }, [request, attempt]);
  useEffect(() => {
    setQuery("");
    setCategory("");
    setSelected(null);
  }, [kind]);
  useEffect(() => {
    if (selected) reader.current?.focus();
  }, [selected]);

  if (loading)
    return (
      <section className="panel knowledge-view">
        <p role="status">Загружаем каталог…</p>
      </section>
    );
  if (failed || !catalog)
    return (
      <section className="panel knowledge-view">
        <p role="alert">
          Каталог недоступен. Проверьте соединение и повторите загрузку.
        </p>
        <button type="button" onClick={() => setAttempt((value) => value + 1)}>
          Повторить загрузку
        </button>
      </section>
    );

  const items = kind === "scripts" ? catalog.scripts : catalog.documents;
  const categories = [...new Set(items.map((item) => item.category))].sort(
    (a, b) => a.localeCompare(b, "ru"),
  );
  const needle = query.trim().toLocaleLowerCase("ru");
  const filtered = items.filter((item) => {
    const body =
      "purpose" in item
        ? `${item.purpose}\n${item.effects}\n${item.success}\n${item.server_path}`
        : `${item.summary}\n${item.content}`;
    return (
      (!category || item.category === category) &&
      `${item.title}\n${item.category}\n${item.source_path}\n${body}`
        .toLocaleLowerCase("ru")
        .includes(needle)
    );
  });
  const generated = new Date(catalog.generated_at);
  const searchLabel =
    kind === "scripts" ? "Поиск по скриптам" : "Поиск по документации";
  return (
    <div className="knowledge-view">
      <section className="panel">
        <div className="knowledge-toolbar">
          <label className="knowledge-search" htmlFor={`${prefix}-search`}>
            {searchLabel}
            <input
              ref={search}
              id={`${prefix}-search`}
              type="search"
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              placeholder={
                kind === "scripts"
                  ? "Название, назначение или результат"
                  : "Название, описание или текст"
              }
            />
          </label>
          <label htmlFor={`${prefix}-category`}>
            Категория
            <select
              id={`${prefix}-category`}
              value={category}
              onChange={(event) => setCategory(event.target.value)}
            >
              <option value="">Все категории</option>
              {categories.map((value) => (
                <option key={value} value={value}>
                  {value}
                </option>
              ))}
            </select>
          </label>
        </div>
        <p role="status" className="muted">
          Найдено: {filtered.length} из {items.length}
        </p>
        <div className="knowledge-provenance muted">
          <p>
            Версия исходников: <code>{catalog.source_commit}</code>
          </p>
          <p>
            Каталог сформирован:{" "}
            {Number.isNaN(generated.getTime())
              ? "дата недоступна"
              : `${generated.toLocaleString("ru-RU", { timeZone: "Europe/Moscow" })} МСК`}
            .
          </p>
          {kind === "scripts" && (
            <><p>
              Наличие и версия скрипта на сервере подтверждаются только
              отдельной проверкой установки.
            </p><p>Состояние файлов — снимок на:{" "}
              {catalog.inventory_observed_at && Number.isFinite(Date.parse(catalog.inventory_observed_at))
                ? new Date(catalog.inventory_observed_at).toLocaleString("ru-RU",{timeZone:"Europe/Moscow"}) + " МСК"
                : "время проверки не получено"}.
              {!available && " Свежесть сейчас не подтверждена."}
            </p><button type="button" onClick={()=>setAttempt(value=>value+1)}>Обновить сведения о файлах</button></>
          )}
        </div>
      </section>
      {selected && kind === "docs" && (
        <section
          className="panel knowledge-reading"
          ref={reader}
          tabIndex={-1}
          aria-label="Текст документа"
        >
          <div className="section-title">
            <h2>{selected.title}</h2>
            <button
              type="button"
              onClick={() => {
                setSelected(null);
                if (opener.current?.isConnected) opener.current.focus();
                else search.current?.focus();
              }}
            >
              Закрыть документ
            </button>
          </div>
          <p className="muted">{selected.category}</p>
          <Source item={selected} commit={catalog.source_commit} />
          <div className="knowledge-content">
            <SafeContent
              content={selected.content}
              path={selected.source_path}
            />
          </div>
        </section>
      )}
      {!filtered.length && (
        <section className="panel">
          <h2>Ничего не найдено</h2>
          <p className="muted">
            Измените запрос или выберите другую категорию.
          </p>
        </section>
      )}
      <div className="knowledge-groups">
        {categories
          .filter((value) => filtered.some((item) => item.category === value))
          .map((value) => (
            <section className="knowledge-group" key={value}>
              <h2>{value}</h2>
              {filtered
                .filter((item) => item.category === value)
                .map((item) => (
                  <article
                    className="panel knowledge-card"
                    key={item.id}
                    aria-label={item.title}
                  >
                    <h3>{item.title}</h3>
                    {"purpose" in item ? (
                      <>
                        <dl className="knowledge-facts">
                          <dt>Назначение</dt>
                          <dd>{item.purpose}</dd>
                          <dt>Что произойдёт</dt>
                          <dd>{item.effects}</dd>
                          <dt>Успешный результат</dt>
                          <dd>{item.success}</dd>
                        </dl>
                        <Source item={item} commit={catalog.source_commit} />
                        <Installation script={item} />
                        <div className="knowledge-actions">
                          {knownAction(item.action) ? (
                            <>
                              <button
                                type="button"
                                disabled={!canOperate || !available}
                                onClick={() => {
                                  if (
                                    canOperate &&
                                    available &&
                                    knownAction(item.action)
                                  )
                                    onAction(item.action);
                                }}
                              >
                                {actions[item.action]}
                              </button>
                              <p className="muted">
                                {!canOperate
                                  ? "Нужна роль оператора или владельца."
                                  : !available
                                    ? "Дождитесь свежих данных сервера для проверки плана."
                                    : "Откроется проверка плана. Запуск требует отдельного подтверждения."}
                              </p>
                            </>
                          ) : (
                            <>
                              <button type="button" disabled>
                                Запуск недоступен
                              </button>
                              <p className="muted">
                                Для этого скрипта нет разрешённого действия в
                                пульте. Используйте его регламент.
                              </p>
                            </>
                          )}
                        </div>
                      </>
                    ) : (
                      <>
                        <p>{item.summary}</p>
                        <Source item={item} commit={catalog.source_commit} />
                        <button
                          type="button"
                          aria-label={`Читать: ${item.title}`}
                          aria-expanded={selected?.id === item.id}
                          onClick={(event) => {
                            opener.current = event.currentTarget;
                            setSelected(item);
                          }}
                        >
                          Читать документ
                        </button>
                      </>
                    )}
                  </article>
                ))}
            </section>
          ))}
      </div>
    </div>
  );
}
