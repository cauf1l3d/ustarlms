# Активные задачи USTAR

## Текущая работа INF-06

Реализовать [пульт управления и Telegram](../runtime/control_panel_telegram_20261016.md), опираясь на свежий main и прототип PR103. Linear-inspired UI на том же сервере, login/password и защищённый доступ; метрики/алерты, стенды/адреса/TTL, SFTP, ручные backup/restore и управляемые операции Академии. Telegram показывает статус/метрики, алертит failure/recovery/NoData и выдаёт последний проверенный зашифрованный бэкап по запросу разрешённого оператора.

16Oct по новой команде владельца подготовлены [архитектура](../../server-console/ARCHITECTURE.md), [каталог health/метрик/алертов](../../server-console/OBSERVABILITY.md) и [UI/UX спецификация](../../server-console/DESIGN.md). Это проектные решения, не работающий collector или native acceptance.

Auth + read-only collector/API + UI реализованы в исходниках. Далее подключение native источников и Telegram delivery; затем job adapters и выдача backup. Бот ещё не поставлен; backend/auth/UI добавлены в текущем read-only срезе, не развёрнуты на server1. Зависимости INF-02/03/05/07 остаются отдельными рисками; не создавать для пульта неограниченный root/Docker socket ради обхода.

## Ожидание внешнего результата OPS-02

Владелец16Oct подтвердил: архив получен, инструкция передана сисадмину. [Receipt](../runtime/sysadmin_handoff_result_20261016.json). Ожидаются clean-host import, вход разными ролями, файлы/SCORM, сохранение прогресса и reboot копии. Не повторять упаковку без новой необходимости, не считать успешную передачу доказанным restore.

## Отложено PLAT-01

Moodle5.2 и PostgreSQL17 по команде16Oct. PR101/загруженные5.3 assets — история исследований, не согласованная текущая версия для запуска. Не создавать новый стенд и не переключать production платформу в рамках пульта.

## Принятые этапы

SSD11Oct; full-addon lab upgrade/repeat/paired rollback12Oct; production5.1.8 upgrade/repeat/brief browser/cron13Oct; AppArmor13Oct; r6 SSD archive/63 packages/native reboot/mount/ID-policy14Oct; archive handoff16Oct. Детали и ограничения — [аудит](../audits/AUDIT_RECONCILIATION_20261016_RU.md).

Общий остаток, включая DB superuser, runtime code write, HTTPS, SMTP/disabled tasks, retention/offsite, ACL/Privacy/performance — [BACKLOG](../roadmap/BACKLOG.yaml). Ошибка route refresh не исправлялась и сохраняется в OPS-01.

## Продолжение16Oct: первый рабочий срез

В PR103 добавлены auth/сессии/CSRF/rate limit, collector/API, реальный UI, ограниченные systemd и [установка](../../server-console/INSTALL.md). GitHub-публикация и exact-head результаты CI фиксируются в PR, server1 ещё не установлен. Следующий этап после сверки установки: Prometheus/правила/DB-cron adapters, sanitized logs, Telegram/outbox и внешний observer; затем verified download/jobs. Не объявлять весь INF-06 закрытым.

16Oct после замечания владельца о мелком/нечитаемом тексте UI переработан: body16px, метрики32–35px, явные цвета таблиц, смысловые акценты, responsive Grafana-style time-series с CPU/RAM и HTTP. Synthetic ряды — только в fixtures; реальные отсутствующие источники остаются NoData. Публикация в том же PR103, server1 не менялся.
