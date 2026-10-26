# Архитектура USTAR

Проверено26Oct по source6c56a5b6/local2300. [Границы](boundaries.md) и
[ADR](../decisions/README.md) объясняют владельцев данных.

- Moodle core: пользователи, курсы, File API, SCORM, capabilities и native sessions.
- local_ustar: регистрация/HR/занятость, адаптация, маршруты и версии точек,
  evidence/completion, грейды/награды, задачи и контент. Единый canonical writer
  каждого домена; actor/scope/revision/locks обязательны на записи.
- theme_ustar: native PHP/Mustache/AMD/SCSS, parent boost, общий responsive shell.
- connect-worker: receiver/notifier каналов; Academy bridge и permissions остаются
  в local_ustar. Telegram2300 принят владельцем; группы/reserve — deferred.
- server-console: собственная ops auth и allowlisted Unix RPC, не Academy auth.
- scripts/infra: owner-executed инструменты с guard/check/recovery и dated receipts.

Источники: [карта кода](../code_map/README.md), [STATE](../roadmap/STATE.yaml),
[prod evidence](../runtime/evidence_20261026.yaml). Main — исходники; merge не deploy.
Legacy frontend не входит в native release. Будущая студия PR107 пока отдельная.
