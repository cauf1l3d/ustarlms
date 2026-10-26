# Активные задачи USTAR

## Текущая работа 26 октября: STUDIO-01

Владелец согласовал дизайн и отправку изменений в GitHub. PR107 переносит визуальный редактор в Материалы с общими черновиками, Moodle File API и подготовкой SCORM. Исправленный candidate требует полного CI и ревью. Прод не изменён. [Инструкция](../../docs/COURSE_STUDIO_RU.md). INF-06 ниже сохраняется как отдельная задача.

## Следующая работа INF-06

Реализовать [пульт управления и Telegram](../runtime/control_panel_telegram_20261016.md), опираясь на свежий main и прототип PR103. Linear-inspired UI на том же сервере, login/password и защищённый доступ; метрики/алерты, стенды/адреса/TTL, SFTP, ручные backup/restore и управляемые операции Академии. Telegram показывает статус/метрики, алертит failure/recovery/NoData и выдаёт последний проверенный зашифрованный бэкап по запросу разрешённого оператора.

Начальный срез: auth + read-only collector/API + реальный UI + Telegram delivery; затем job adapters и выдача backup. Работающий бот/backend/auth ещё не поставлены. Зависимости INF-02/03/05/07 остаются отдельными рисками; не создавать для пульта неограниченный root/Docker socket ради обхода.

## Ожидание внешнего результата OPS-02

Владелец16Oct подтвердил: архив получен, инструкция передана сисадмину. [Receipt](../runtime/sysadmin_handoff_result_20261016.json). Ожидаются clean-host import, вход разными ролями, файлы/SCORM, сохранение прогресса и reboot копии. Не повторять упаковку без новой необходимости, не считать успешную передачу доказанным restore.

## Отложено PLAT-01

Moodle5.2 и PostgreSQL17 по команде16Oct. PR101/загруженные5.3 assets — история исследований, не согласованная текущая версия для запуска. Не создавать новый стенд и не переключать production платформу в рамках пульта.

## Принятые этапы

SSD11Oct; full-addon lab upgrade/repeat/paired rollback12Oct; production5.1.8 upgrade/repeat/brief browser/cron13Oct; AppArmor13Oct; r6 SSD archive/63 packages/native reboot/mount/ID-policy14Oct; archive handoff16Oct. Детали и ограничения — [аудит](../audits/AUDIT_RECONCILIATION_20261016_RU.md).

Общий остаток, включая DB superuser, runtime code write, HTTPS, SMTP/disabled tasks, retention/offsite, ACL/Privacy/performance — [BACKLOG](../roadmap/BACKLOG.yaml). Ошибка route refresh не исправлялась и сохраняется в OPS-01.
