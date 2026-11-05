# Архитектура USTAR

Актуально05Nov: main app5cc02944/local2900/theme2700; owner-installed app719bf47c/local0200/theme0200,worker267.
[Границы](boundaries.md), [ADR](../decisions/README.md), [интеграция](../runtime/connect_feed_integration_order_20261101.json).

- Moodle5.1.8 владеет пользователями, auth/sessions, курсами, File API, SCORM/Quiz и native messaging.
- local_ustar: регистрация/HR/занятость, адаптация, маршруты, evidence/completion,
  грейды/награды, задачи, контент и общая Studio. Каждый домен имеет canonical writer;
  actor/capability/scope/revision/locks обязательны на записи.
- theme_ustar: PHP/Mustache/AMD/SCSS, parent boost, responsive shell; тема не даёт прав записи.
- connect-worker — тонкий Telegram receiver/notifier. Роли и бизнес-команды остаются
  в local_ustar; Python отвечает за transport, durable delivery и представление.
  [Карта Connect](../domains/connect.yaml). Не создавать вторую LMS/модель сотрудников.
- server-console — отдельные ops auth и allowlisted Unix RPC; не Academy login.
- scripts/infra — owner-executed инструменты с check/guards/recovery и отдельными
  журналами. Старые операторы frozen. Новая запись Git не меняет сервер автоматически.

[Карта кода](../code_map/README.md) фиксируетmain606файлов/103таблицы; installed development_source649/108;
[STATE](../roadmap/STATE.yaml) разделяет source, CI и owner receipts. Payload не
является whole-host census. frontend — неактивный legacy, вне native release.

Приняты задачи/возврат/фото/PNG/читаемость. Остальные процессы с руководством с2Nov;
публичный HTTPS/Mini App — после согласования. Аудит03–04Nov завершён; [план](../../docs/superpowers/plans/2026-11-03-project-audit-remediation.md) частично реализован: U01/U02/U05 installed5Nov. ROUTE-EDITOR-01 reviewed/planned. PERF-01 остаётся.
