# Активная работа —29.10.2026

## RSS-01 + PERF-01: единый комплект2803

Production2802 подтверждён terminal/status/cron:
[установка](../runtime/perf2802_owner_deploy_20261029.json).
Владелец принял Материалы/настройки Ленты/Соревнования/Студию:
[browser receipt](../runtime/perf2802_owner_browser_acceptance_20261029.json).
Он сообщил о прежнем дефекте повторяющихся RSS фото и поручил исправление вместе
со следующим этапом. Это текущий PERF-01 passive workday capture, не новая интеграция.

[Дизайн](../../docs/superpowers/specs/2026-10-29-feed-media-workday-design.md),
[план](../../docs/superpowers/plans/2026-10-29-feed-media-workday.md),
[ledger](../runtime/feed_media_workday_progress_20261029.md).
Одна обложка RSS, дополнительные фото в details; exact provider/content dedup,
без удаления старых файлов и без изменений employee/ACL/library/history.
Отдельный bounded read-only observer собирает workday evidence; SQL/page,
Web-SAPI OPcache и concurrency/SLO остаются отдельными измерениями/решениями.

Кандидат local2803/theme2700 не установлен и не слит; canonical main app22607c69.
Замороженный predecessor tool3c1ddd09 и worker6c56 сохраняются.
Новый journal и paired/publication-aware recovery без переписывания прежних операторов.

Полный applicable source/native/infra/context/console gate на точном SHA перед
выдачей одного проверенного архива. Никакого автоматического merge нового этапа.
Агент работает сам, не подключается по SSH и не отправляет через провайдеров.
Принятые инфраструктурные проверки не повторять; логи/health/recreate — в Moodle window.
