# Активные задачи USTAR

## Сейчас — INF-06: доработка пульта по запросу17:03МСК

Владелец отменил полную приёмку пульта. Выполнить [новые требования](../../server-console/REVISIONS_20261018.md): HTTPS/client trust и диагностика Mini App/VPN, темы/zoom, Скрипты/Документация, request p95/RPS/ошибки и безопасные события служб. Предыдущая поставка графикиb93e16af сохраняется; повторно собирать её с нуля не нужно.

Доработка source1c881495 проверена: все3 CI SUCCESS,355 backend+1skip/114 Chromium, настоящие systemd/Apache fixtures PASS; artifact12139739548 скачан и сверен. [Команды обновления и проверки клиентов](../runtime/console_revisions_delivery_20261018.md). [Owner terminal18:04МСК](../runtime/console_revisions_native_20261018.json): update1c881495 PASS, службы active, server TLS и Apache logging configured. [Owner18:38МСК](../runtime/console_client_https_result_20261018.json): сертификаты обновлены, предупреждения исчезли, офисный доступ работает с Happ/NovaVPS и без него. Настройка исключения Happ не требуется. Mini App не выделен отдельно в общем сообщении «всё работает»; не считать его сломанным и не выдумывать отдельную client matrix. В INF-06 остаётся проверка фактических новых p95/RPS/events и UI в согласованном объёме; повторная установка/CA/сеть не нужны.

## Следующий этап после доработки — INF-07: HTTPS Академии

[Сообщение14:42](../runtime/console_owner_acceptance_20261018.json) — историческое; новый запрос17:03 возвращает этап на доработку.

[Подготовка HTTPS](HTTPS_NEXT_20261018.md) возобновляет выбранную ранее очередь после принятых Moodle patch/ОС и более позднего приоритета пульта:

1. Read-only сверка Apache/vhost, proxy/wwwroot, DNS и сертификатов по свежему контексту; узкий дополнительный сбор только при необходимости.
2. Внутреннее имя и доверенный сертификат/CA для Академии, план redirects/cookies и отката на действующем Apache.
3. Проверка на копии: вход/выход, SCORM, upload/download, Quiz, чат и сессии; затем конкретный план переключения и приёмки клиентов.

Этот запрос обновляет контекст и порядок, без применения настроек к серверу. После HTTPS — INF-02/03 (минимальные права PostgreSQL и защита кода/config).

## Эксплуатационный остаток — INF-06-OPS

Исходники/CI/пакетb93e16af проверены; прежний owner report о рабочем этапе сохранён как история, полная приёмка отменена17:03. Exact native SHA, численное время ответа, отдельные роли/download/jobs/overhead, независимый observer и домашний маршрут этим сообщением не измерены. Они сохранены отдельной planned задачей ; новое поручение17:03 разрешает перечисленные доработки интерфейса. Restore/RTO учитывается только в OPS-02. [Результат поставки](../runtime/console_telegram_graphics_20261018.json) и исторические receipts сохраняются.

## Ожидание внешнего результата OPS-02

Владелец16Oct подтвердил: архив получен, инструкция передана сисадмину. [Receipt](../runtime/sysadmin_handoff_result_20261016.json). Ожидаются clean-host import, вход разными ролями, файлы/SCORM, сохранение прогресса и reboot копии. Не повторять упаковку без новой необходимости, не считать успешную передачу доказанным restore.

## Отложено PLAT-01

Moodle5.2 и PostgreSQL17 по команде16Oct. PR101/загруженные5.3 assets — история исследований, не согласованная текущая версия для запуска. Не создавать новый стенд и не переключать production платформу в рамках пульта.

## Принятые этапы

SSD11Oct; full-addon lab upgrade/repeat/paired rollback12Oct; production5.1.8 upgrade/repeat/brief browser/cron13Oct; AppArmor13Oct; r6 SSD archive/63 packages/native reboot/mount/ID-policy14Oct; archive handoff16Oct. Детали и ограничения — [аудит](../audits/AUDIT_RECONCILIATION_20261016_RU.md).

Общий остаток, включая DB superuser, runtime code write, HTTPS, SMTP/disabled tasks, retention/offsite, ACL/Privacy/performance — [BACKLOG](../roadmap/BACKLOG.yaml). Ошибка route refresh не исправлялась и сохраняется в OPS-01.
