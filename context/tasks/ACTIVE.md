# Активная работа — 05.10.2026

**INF-01 в работе. Команда владельца: сначала 1 → 7 → 6, остальные пункты после них.** DOC-02 опубликован и слит PR78; прежняя остановка отменена этой новой командой. Агент готовит артефакты/CI, владелец выполняет SSH-команды на сервере.

1. Baseline: свежая сверка USTAR, core/plugins/images, host/cron/storage и условия recovery/rollback.
2. Moodle текущей ветки, production PHP/PostgreSQL matrix в CI и patch-обслуживание Ubuntu 24.04 (`INF-09`, `INF-08`, `INF-10-OS`).
3. Доверенный HTTPS на действующем Apache (`INF-07`), реальные ПК/телефоны и остальные vhosts.

Свежий manifest 05.10 02:32:35.997389 UTC: **526 matching**, различий/пропусков/ошибок нет; файлы отчёта дополнительно сверены с Git PR76 `e1d57f5bc28f6964afd20aeff7620345b34a80fe`. В 02:43:33 UTC все 468 component versions на диске совпали с readonly DB; три metadata hash совпали с официальным Moodle weekly commit. Это ещё не полная проверка core/plugin code. [Baseline и следующий шаг](../runtime/baseline_20261005.md), [dated evidence](../runtime/evidence_20261005_baseline.yaml), [STATE](../roadmap/STATE.yaml).

Следующая production-команда: read-only `scripts/infra/core_preflight.py` по immutable upstream SHA. После результата — проверить контракт существующего backup wrapper, подготовить свежую разовую копию и изолированный upgrade/rollback. До этого не менять production core/AppArmor/packages. Скрипт сравнивает объявленную область; config.php, vendor, скрытые пути и известные дополнения исключены явно.

Остальные пункты — DB/file hardening, disk cleanup, пульт, полный H5P/SMTP cycle, business acceptance и регулярные копии — отложены владельцем. Не превращать их в обязательные предварительные задачи выбранных 1/7/6. Необходимые recovery/stage/service checks входят в выбранную работу. Стабильный web, нужные почту/DNS/ISPConfig и историю сохранять; cron v3 остаётся один. Автокопии — healthy HDD, не SERVEREXPRESS и не нынешний диск с failed SMART. Mobile/Privacy/B2B/LTS остаются отдельными проектами.
