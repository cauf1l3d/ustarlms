# Security baseline на16Oct2026

Текущее положение сведено по [owner evidence](evidence_20261016.yaml) и [аудиту](../audits/AUDIT_RECONCILIATION_20261016_RU.md), без нового live probe. Moodle5.1.8/краткая browser-приёмка13Oct, targeted AppArmor repair, host packages и native boot14Oct приняты. Эти этапы не повторять. Следующая работа — [пульт/Telegram](control_panel_telegram_20261016.md), переход Moodle5.2/PG17 отложен.

| Контроль | Подтверждено и остаток | Задача |
|---|---|---|
| DB role | Исторический runtime moodle — cluster superuser; уменьшение прав прода не поставлялось. Новый app role в скрипте копии не исправляет прод | INF-02 |
| Code/config | Ранее www-data мог писать config/plugin; безопасные owners/parents/ACL и deploy ещё не приняты. RO mount новой копии не считается production fix | INF-03 |
| Keys/dumps | Полная актуальная local-access inventory открыта. Transfer tar содержит ключ; не выдавать его через Telegram/Git | INF-03/14 |
| HTTPS | Academy LAN HTTP через Apache; trusted TLS/cookie/SCORM acceptance открыта | INF-07 |
| Moodle patch | 5.1.8 upgrade/repeat/brief browser/cron принят13Oct. Full addon compatibility и новый fresh exact manifest не приписываются | INF-08 закрыт в своём scope, OPS-01 открыт |
| Host/AppArmor | Optional include repair,63 versions, kernel147/Docker29.9.0, boot/mount/ID-policy приняты. Full shared-host restore не выполнен | INF-10-OS закрыт в своём scope; OPS-02 открыт |
| SSH/firewall/MFA | Effective policies, external surface и emergency path требуют отдельной проверки | INF-10 |
| Docker/logs | Production-wide healthchecks/rotation/recreation/budgets ещё не приняты | INF-05 |
| Application | ACL/IDOR/files/view-as/CSRF/XSS/SQL/concurrency negative matrix ещё не завершена; route refresh bug не исправлен | INF-12/OPS-01 |
| Delivery/observation | SMTP/H5P exceptions, Telegram delivery/NoData/recovery и независимый observer пока не приняты | INF-06/11 |
| Dependencies | Legacy frontend вне active release/CI и не развёрнут. Это не разрешение публиковать старый клиент и не подтверждение его безопасности | INF-13 |
| Privacy/tenants | Полный Privacy provider и B2B isolation остаются отдельной работой | PRIVACY-01/B2B |

Не снижать DB права без проверенного admin path; не делать рекурсивный chmod/chown, не отключать нужные службы и не менять реальные роли/историю по предположению. Пульт использует ограниченные adapters, без произвольного shell/full Docker socket в web/bot. Bot token, recovery identity и private logs не коммитятся. Прямое сообщение или выдача приватного архива через Telegram в этой сверке не выполнялись.

[BACKLOG](../roadmap/BACKLOG.yaml), [мониторинг](MONITORING.md), [backup contract](BACKUP_RESTORE.md) и [сверка](../audits/AUDIT_RECONCILIATION_20261016_RU.md) задают текущие остатки; исходные05/06Oct документы сохранены как история.
