# Security baseline: открытые вопросы на 05.10.2026

Источник — [датированные серверные свидетельства](evidence_20261005.yaml), [аудит](../audits/USTAR_INFRASTRUCTURE_AUDIT_20261005_RU.md). Полные шаги/проверки/откат — в [плане](../roadmap/USTAR_AUDIT_REMEDIATION_PLAN_20261005_RU.md). Публикация не закрывает эти риски.

| Контроль | Текущее evidence | Закрывающая работа |
|---|---|---|
| DB role | `moodle` SUPERUSER/CREATEROLE/CREATEDB/REPLICATION/BYPASSRLS, 04.10 23:57 UTC | INF-02: separate tested admin, ownership/grants, least privilege, upgrade/backup/restore |
| Code/config | `www-data` WRITABLE config.php и plugin; тема NOT_WRITABLE | INF-03: code owner, parents/ACL, no replacement, data still writable |
| Secrets/dumps | Ранее dump 0644 с проходными parents; полная актуальная проверка не выполнена | INF-03: local access inventory и адресное ограничение, сохранение копий/ключей |
| Transport/cookie | LAN HTTP через Apache; Secure не найден в HTTP response при cookiesecure=1 | INF-07: trusted HTTPS, browser/File API/SCORM/cookie acceptance |
| Core/patch | 5.1.1+ Build 20251219; полный upstream core identity не проверен | INF-01/08 и PLAT-01: exact provenance, available release, compatibility + restore |
| Firewall/SSH/MFA | Исторические UFW/SSH/factor данные неполны, external surface не аттестована | INF-10: effective config/router/service matrix, tested recovery |
| Docker | No healthcheck и unlimited json-file в снимке; DB host port не опубликован | INF-05: readiness/log rotation, checked recreate и cron binding |
| Application security | Есть DML/capabilities/sesskey/locks; полного security verdict нет | INF-12: role/IDOR/files/CSRF/XSS/SQL/concurrency/retry negative tests |
| Dependencies/reporting | Repository features/owners/private security channel не подтверждены | INF-13: real owners, advisory response, selected dependency automation |
| Data/tenants | Privacy provider отсутствует; B2B isolation не доказана | PRIVACY-01/B2B-01/02: policy, export/delete, isolation и recovery |

Не выполнять слепое снижение DB прав без admin path, рекурсивные chmod/chown, отключение необходимых служб, изменение ролей по названиям должностей, clearing history или автоматические security fixes, затрагивающие регистрацию. Нет доказательства текущей компрометации; не объявлять глобально «SQL/XSS чисто». Next.js не используется и его session protections не описывают текущую Moodle HTTP-сессию.

Инциденты/обновления используют ограниченные проверенные источники и runbooks; наблюдаемость — [MONITORING](MONITORING.md), recovery — [BACKUP_RESTORE](BACKUP_RESTORE.md). Любое новое действие на production требует конкретного scope и разрешённого продолжения, сейчас агент ожидает владельца.
