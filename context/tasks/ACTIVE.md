# Активные задачи USTAR

## Сейчас — актуализация контекста17Oct

Оба owner archives получены04:16MSK, проверены и импортированы без редактирования:17/13 и28/2 ok/error, SMART excluded. [Свежая сверка](../reviews/20261017/runtime_reconciliation.md), [provenance](../runtime/server_facts_received_20261017.json), [покрытие](../coverage.yaml).526 файлов совпали; роль DB superuser и writable plugin/config подтверждены. Query statistics отсутствуют, scheduled task rows и clock values неизвестны; getters исправлены, повторный live probe не выполнялся. Контекст готов к повторному аудиту владельца. INF-01/P0/restore целиком не закрываются; agent SSH не использовался.

## После повторного review — INF-06

Реализовать [пульт управления и Telegram](../runtime/control_panel_telegram_20261016.md), опираясь на свежий main и прототип PR103. Linear-inspired UI на том же сервере, login/password и защищённый доступ; метрики/алерты, стенды/адреса/TTL, SFTP, ручные backup/restore и управляемые операции Академии. Telegram показывает статус/метрики, алертит failure/recovery/NoData и выдаёт последний проверенный зашифрованный бэкап по запросу разрешённого оператора.

В [PR103](https://github.com/cauf1l3d/ustarlms/pull/103) на98559017 уже подготовлены backend/auth/read-only collector/API/UI, systemd/CI/runbook и новый standalone прототип с читаемыми подписями/графиками. Последние три CI этого SHA успешны. Это открытый PR, не установка на server1. Telegram delivery, job adapters, backup access и runtime acceptance ещё не выполнены. Зависимости INF-02/03/05/07 остаются отдельными рисками; не создавать для пульта неограниченный root/Docker socket ради обхода.

## Ожидание внешнего результата OPS-02

Владелец16Oct подтвердил: архив получен, инструкция передана сисадмину. [Receipt](../runtime/sysadmin_handoff_result_20261016.json). Ожидаются clean-host import, вход разными ролями, файлы/SCORM, сохранение прогресса и reboot копии. Не повторять упаковку без новой необходимости, не считать успешную передачу доказанным restore.

## Отложено PLAT-01

Moodle5.2 и PostgreSQL17 по команде16Oct. PR101/загруженные5.3 assets — история исследований, не согласованная текущая версия для запуска. Не создавать новый стенд и не переключать production платформу в рамках пульта.

## Принятые этапы

SSD11Oct; full-addon lab upgrade/repeat/paired rollback12Oct; production5.1.8 upgrade/repeat/brief browser/cron13Oct; AppArmor13Oct; r6 SSD archive/63 packages/native reboot/mount/ID-policy14Oct; archive handoff16Oct. Детали и ограничения — [аудит](../audits/AUDIT_RECONCILIATION_20261016_RU.md).

Общий остаток, включая DB superuser, runtime code write, HTTPS, SMTP/disabled tasks, retention/offsite, ACL/Privacy/performance — [BACKLOG](../roadmap/BACKLOG.yaml). Ошибка route refresh не исправлялась и сохраняется в OPS-01.
