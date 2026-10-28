# Активная работа —29.10.2026

## RSS-01 + PERF-01: комплект2803 готов, owner apply и рабочий день остаются

Production2802 подтверждён [terminal/status/cron](../runtime/perf2802_owner_deploy_20261029.json)
и [основными browser-сценариями](../runtime/perf2802_owner_browser_acceptance_20261029.json).
Владелец поручил прежние дубли RSS фото исправить вместе со следующим PERF-01 этапом.

PR110: app ef903b7d/operator36be3f10, не слит и не установлен. Четыре CI SUCCESS:
PHP8.2/8.3 по1403/234, оба native357/1706, actualPG100tables/fresh+legacy7denials,
actualUID33 и root-only observer output PASS. Local full1400/50 в321.153s PASS.
[Квалификация](../runtime/feed2803_qualification_20261029.json),
[ledger](../runtime/feed_media_workday_progress_20261029.md).

Архив f09b21a9...20688439bytes содержит update.py и observe.py. Все5 CI-файлов
совпадают побайтно, сохранённая копия скачана и проверена. Схема/theme/worker6c56
и все старые операторы неизменны. Новый journal /var/lib/ustar-feed-media-update.
Одна обложка RSS; дополнительные изображения раскрываются без JS. Точные копии
скрыты, исходники/личные сохранения/employee ACL сохранены; неизвестные старые
resize-варианты могут оставаться среди дополнительных фото.

Следом владелец выполняет [установку/status2803](../../docs/FEED_2803_INSTALL_RU.md),
проверяет RSS и запускает из того же комплекта8h passive capture в обычный рабочий день.
Отчёт/реальная нагрузка/SLO ещё не получены. Не выдавать Apache aggregate за отдельные
страницы, SQL/page или Web-SAPI OPcache; observer READ ONLY транзакции входят в DB counters.

Агент работает сам, без SSH/provider sends/автоматического merge. Логи/health/recreate
отложены до Moodle window; принятые инфраструктурные циклы не повторять.
