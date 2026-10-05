# Runtime и эксплуатация

Текущая сводка 05.10 — [dated owner evidence](evidence_20261005.yaml) и [RELEASE_LEDGER](RELEASE_LEDGER.md). Команды/правила — [OPERATIONS](OPERATIONS.md); специальные контракты — [MONITORING](MONITORING.md), [BACKUP_RESTORE](BACKUP_RESTORE.md), [SECURITY_BASELINE](SECURITY_BASELINE.md).

Нет прямого подключения этого агента к production. Предоставленные измерения показывают Apache/HTTP/LAN, общий Ubuntu host с необходимыми почтой/DNS/ISPConfig, plugin/theme PR76 manifest 03.10 и работающий cron v3 04.10. Эти свидетельства имеют дату/область и не являются свежим live status при чтении. Полный core/вся схема/все business scenarios и clean-host recovery отдельно не проверены.

Последний `/` — 76%; владелец предполагает полную тестовую копию, размер ещё не измерен. HDD провалил long SMART. Ручной encrypted snapshot и isolated restore есть; регулярное расписание — `not_installed`, финальный этап по [плану](../roadmap/USTAR_AUDIT_REMEDIATION_PLAN_20261005_RU.md).

`git_state.yaml` описывает Git; `docker.yaml`/`moodle.yaml`/`server.yaml` — dated evidence, не guessed VM/Caddy. Historical snapshots и `tests/stage/runtime.json` остаются исходными synthetic/preflight источниками; их не переписывают под новую версию. `refresh_context.py` создаёт отдельный audit bundle, не заменяет canonical state автоматически. После этой GitHub-поставки — остановка до команды владельца.
