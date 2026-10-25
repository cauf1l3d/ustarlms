# Инфраструктурная нормализация: возврат23Oct2026

**25Oct — INF03 code hardening applied by owner; keys/dumps slice next.**
Operator SHA6447d5d6: prepared/backup_complete/writers_stopped/archive_ready,
22removed/web_verified -> code_hardened, archived_files22, app_roleustar_app,
business_rows_restored=false. Coordinator verified protected retained tree,
private archive, effective app role/HTTPS and resumed original services/policies.
Owner precheck was44210entries/22archive/4785planned metadata changes; not a new
independent post-apply census. Browser acceptance after hardening still pending.
Review40141745333/Context40141745326 SUCCESS on e573cd8e; both1130/174skips/browser,
20/20root runtime and existing DB fixture121015572073 PASS. Do not repeat apply.
Legacy extras unused per owner, preserved for future USTAR Connect. Before old
app/DB/physical restore undo code hardening first; source drift requires review.
Next INF03 config/keys/dumps/backup access outside code, metadata-only inventory.
New /var/lib journals/archive remain outside standard backup scope: preserve exact
fresh pre-hardening HDD backup. Full INF03/DR still open.0subagents/main unmerged.


## Решение владельца

23Oct владелец поставил дальнейшую разработку Telegram/Connect на паузу и попросил
вернуться к инфраструктурной нормализации server1. Рабочие receiver/notify не
останавливались. Telegram2300 принят: новая доставка, постоянный ACK/время и личная
история подтверждены; [receipt](connect_telegram_finish_owner_20261023.json).

Одноразовое напоминание создать удалось:24Oct2026,15:00,Europe/Moscow. Содержание —
показать правила KeenDNS ниже страницы и проверить https://em2025.netcraze.pro с
мобильного интернета без Wi-Fi. Это напоминание владельцу, не автоматическое
возобновление разработки, публикация Mini App или изменение роутера.
[Точка возврата Connect](connect_telegram_next_preflight_20261023.md).

## Что уже принято и не требует повторной установки

- SSD: GPT/partition/ext4 growth11Oct.
- Moodle5.1.8: upgrade/repeat, browser/SCORM и post-switch cron13Oct.
- Ubuntu24.04 patch/AppArmor/Docker и reboot14Oct с проверкой shared services/mounts/cron.
- Canonical LAN HTTPS https://ustar.local и private bridge20–21Oct; AutoReport9443
  зарегистрирован в owner topology23Oct.
- Scheduled HDD backup03:30Europe/Moscow и сохранность mount/timer после reboot;
  подтверждённые scheduled runs12–14Oct. Поздние controller fresh_backup_complete
  не заменяют свежую квитанцию очередного автоматического запуска.
- Connect2300/theme0202,557files/100tables по fixed payload, приложение и workers6c56;
  это последняя owner application deployment, не baseline2000/2200 из старых заметок.

## Остаток по актуальному BACKLOG

Статусы ниже означают незакрытую приёмку, а не свежую независимую диагностику прода.
Общий исходный план: [remediation05Oct](../roadmap/USTAR_AUDIT_REMEDIATION_PLAN_20261005_RU.md),
сверка: [reconciliation16Oct](../audits/AUDIT_RECONCILIATION_20261016_RU.md),
поздние области HTTPS/cron/Connect уточнены owner receipts20–23Oct.

| Приоритет/задачи | Незакрытая работа | Граница сохранности |
|---|---|---|
| INF-01 / OPS-01 | Свежий read-only baseline: версии/source, DB роли, mounts/права, health/logging, место, current cron/backup; полная общая инвентаризация ещё не принята | Не повторять уже принятые patch/reboot/resize/cron installation |
| P0 INF-02 | Отдельный проверенный admin PostgreSQL и достаточные runtime privileges; DDL/ownership/upgrade/backup acceptance | Не снимать SUPERUSER до проверки независимого admin и восстановления |
| P0 INF-03 | Весь code/config/parents/ACL, dumps и keys; runtime не должен заменять приложение, deploy должен работать | Выполненная узкая подготовка include parents не является полным file-hardening |
| INF-04/05 | SMART/аппаратный и power/offsite остаток; бюджеты места/логов, фактическая ротация существующих Docker logs, readiness и безопасное recreation | IDs/images/mounts/shared services и единственный cron сохраняются |
| INF-10 | Эффективные SSH/firewall/MFA policies, emergency admin и сквозная проверка необходимых mail/DNS/ISPConfig | OS patch/reboot закрыт отдельно; службы не удалять по списку пакетов |
| INF-11/07 | SMTP delivery, H5P/Moodle.org exceptions и полный hourly/nightly cycle; future TLS renewal/alert и полный client checklist | Registration Moodle.org не равна регистрации сотрудника; LAN HTTPS уже работает |
| INF-14/OPS-02 | Retention/RPO/RTO, отдельный доступ к ключу, независимая/offsite copy и alerts; реальное clean-host restore/roles/files/SCORM/reboot от сисадмина | Архив16Oct получен и инструкция передана; повторная упаковка без причины не нужна, actual restore ещё waiting_external |
| INF-06-OPS | Независимый host-loss observer/alerts и эксплуатационные остатки пульта, включая журнал/heartbeat | Отдельный пульт PR103 не заменяется бизнес-ботом; прежняя отсрочка journal/heartbeat не снимается молча |
| PERF-01/INF-12/AUDIT-01/INF-13 | Измерения latency/SQL/cron lag, сценарные ACL/API/files/view-as/races, legacy consumers и итоговая приёмка/сопровождение | Наличие CI и active services не является полной production acceptance |

## Рекомендуемый первый рабочий срез

Сначала однократная read-only сверка текущих DB ролей/admin path и реальных прав
code/config/data, с current logging/health/storage/cron receipts. Затем подготовить
конкретное изменение INF-02/03 и проверить на копии login/domain writes/cron/upgrade/
backup/recovery. Это рекомендация порядка по P0 исходного плана; новая изменяющая
операция на server1 не выполнялась. Два исторических пропуска UNIQUE индексов не
ремонтировать под видом hardening; они приняты отдельным exact compatibility profile.

PLAT-01 Moodle5.2/PG17, новый mobile/B2B, новый внешний Telegram вход и reserve routing
не включены в текущее возобновление. Source/raw host archives и работающие сервисы
не менялись; no agent SSH/provider sends/main merge,0subagents.

## Начало выполнения одобрено владельцем23Oct

Owner: «хорошо начнем тогда по этому плану». Подготовлен первый
[read-only operator batch](infra_readonly_baseline_20261023.md) для P0 DB/file triage,
с logging/health/mount/cron/backup metadata. Owner output получен23Oct21:08МСК;
INF-01 целиком не закрыт. [Квитанция](infra_readonly_owner_20261023.json): только один
superuser `moodle`, code local_ustar writable под www-data33; scheduled cron18:08UTC
exit0 и HDD03:30МСК success,48G свободно. Docker metadata не получены из-за ошибки
шаблона Health; primary config уровнем выше ещё не прочитан. В runbook — исправленный
узкий follow-up, без повторного SQL/cron/backup. Не демотировать единственный admin.
Runtime не менялся. Поздний owner follow-up выполнил исправленный шаблон: оба containers
running/restarts0/OOMfalse, exact accepted images; healthchecks и json-file limits нет.
Primary config33:33/0755 readable+writable под www-data, static dbuser=moodle/DBmoodle/
prefixmdl_. [Тот же runbook](infra_readonly_baseline_20261023.md) теперь содержит первый
адресный write: создание ustar_pg_admin и два отдельных обязательных SCRAM-входа;
Owner corrected retry уже создал ustar_pg_admin: два required-SCRAM входа9976/9995,
session/current role совпали, login/superuser=t. Создание не повторять. Далее copy rehearsal и HTTPS config metadata
handoff перед demotion/file hardening. Full INF01/02/03 ещё не закрыты.

Owner напомнил о пройденных стендах: прежняя формулировка полного повтора была
слишком широкой. Засчитать принятую историю patch/upgrade/recovery и Connect CI;
следующий scope — DB consumers и только недостающие reduced-role проверки в
существующем CI. Новый owner-стенд/full restore/repackage/UI круг по умолчанию не
нужны. Latest fixture admin ustar_fixture и restore code ordinary ustar_app покрывают
разные условия; [разбор](infra_readonly_baseline_20261023.md) фиксирует точный пробел.


**25Oct — owner accepted DB app handoff; INF-03 metadata inventory next.**
Server1 ttyfix SHA256 976afa0d2dffd58bf260dde4cb90cb5297f73d3f78fb9df10c2f9dc4b60ecf5e:
prepared/backup_complete/writers_stopped/ownership_committed/config_written/https_written/
web_verified/app_role_active. Ordinary ustar_app owns3479 public objects (657tables,
656sequences,2166indexes); bootstrap_admin_retained=true, business_rows_restored=false.
Owner confirmed login and course opening. Prior SCRAM refusals no longer reproduce;
root cause unproven, password not changed. Do not repeat apply/role creation/old stands.
Review40130463638 on6590bde6 SUCCESS; Context40130463630 failed solely on two stale
context-index hashes (confirmed logs); index regenerated in this checkpoint.
Next INF-03: read-only code/parent/ACL inventory before guarded permission changes.
Existing deploy preserves metadata and old recovery checks snapshots: no blanket chmod.
Full INF-03 and broader infrastructure acceptance remain open.0subagents/no SSH/main merge.
