# Активные задачи USTAR

## Следующий этап — INF-07: HTTPS Академии

[Пульт принят владельцем18Oct14:42МСК](../runtime/console_owner_acceptance_20261018.json). INF-06 завершён в принятом объёме; настройка/установка и графические карточки больше не являются текущей работой.

[Подготовка HTTPS](HTTPS_NEXT_20261018.md) возобновляет выбранную ранее очередь после принятых Moodle patch/ОС и более позднего приоритета пульта:

1. Read-only сверка Apache/vhost, proxy/wwwroot, DNS и сертификатов по свежему контексту; узкий дополнительный сбор только при необходимости.
2. Внутреннее имя и доверенный сертификат/CA для Академии, план redirects/cookies и отката на действующем Apache.
3. Проверка на копии: вход/выход, SCORM, upload/download, Quiz, чат и сессии; затем конкретный план переключения и приёмки клиентов.

Этот запрос обновляет контекст и порядок, без применения настроек к серверу. После HTTPS — INF-02/03 (минимальные права PostgreSQL и защита кода/config).

## Пульт завершён; эксплуатационный остаток — INF-06-OPS

Исходники/CI/пакетb93e16af проверены; owner report принимает рабочий этап. Exact native SHA, численное время ответа, отдельные роли/download/jobs/overhead, независимый observer и домашний маршрут этим сообщением не измерены. Они сохранены отдельной planned задачей и не являются основанием продолжать интерфейс без нового поручения. Restore/RTO учитывается только в OPS-02. [Результат поставки](../runtime/console_telegram_graphics_20261018.json) и исторические receipts сохраняются.

## Ожидание внешнего результата OPS-02

Владелец16Oct подтвердил: архив получен, инструкция передана сисадмину. [Receipt](../runtime/sysadmin_handoff_result_20261016.json). Ожидаются clean-host import, вход разными ролями, файлы/SCORM, сохранение прогресса и reboot копии. Не повторять упаковку без новой необходимости, не считать успешную передачу доказанным restore.

## Отложено PLAT-01

Moodle5.2 и PostgreSQL17 по команде16Oct. PR101/загруженные5.3 assets — история исследований, не согласованная текущая версия для запуска. Не создавать новый стенд и не переключать production платформу в рамках пульта.

## Принятые этапы

SSD11Oct; full-addon lab upgrade/repeat/paired rollback12Oct; production5.1.8 upgrade/repeat/brief browser/cron13Oct; AppArmor13Oct; r6 SSD archive/63 packages/native reboot/mount/ID-policy14Oct; archive handoff16Oct. Детали и ограничения — [аудит](../audits/AUDIT_RECONCILIATION_20261016_RU.md).

Общий остаток, включая DB superuser, runtime code write, HTTPS, SMTP/disabled tasks, retention/offsite, ACL/Privacy/performance — [BACKLOG](../roadmap/BACKLOG.yaml). Ошибка route refresh не исправлялась и сохраняется в OPS-01.
