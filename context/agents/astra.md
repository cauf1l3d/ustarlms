# Профиль архитектурного агента USTAR

**Checkpoint12Oct14:41MSK:** [базовая browser QA5.1.8 принята](../runtime/lab_patch_browser_result_20261012.json)
в owner-reported scope: SCORM screen navigation/progress, logout/fresh login и
переход на главную работают. Prior screenshots подтверждают SCORM16/20/text/images/
USTAR UI и admin5.1.8(Build20261005); exact final home URL не прислан. My Team opens
по earlier owner report. Следующий шаг — existing real employee/manager/HR accounts,
разрешённые страницы и ограничения capabilities, затем preserved addons/Apache.
Self-registration/SCORM completion/grades/full-addon/production acceptance не выводить.
Clone cron отключён; core operations/recovery cycle не повторять для QA. Current
stage518/runxdgki_yo, prior same-helper rollback принят, production core прежний.
Raw screenshots не опубликованы; пароль/персональные данные не нужны агенту.

**Приоритет владельца06.10 00:45MSK:** сначала оптимизировать хранение, обновить карту сервера и настроить автобэкапы на HDD; затем новый стенд и Moodle/ОС/HTTPS. Прежнее правило «автобэкапы в финале» заменено. PR87 `--prepare-stage` приостановлен. [Карта и scope](../runtime/server_map_20261006.md). Более ранние этапы/даты ниже сохраняются как история; эта команда определяет текущий порядок.

Работать по [AGENTS](../../AGENTS.md) и [protocol](../roadmap/AGENT_PROTOCOL.md). Канонические исходники и текущий контекст — main.

Текущие инструкции владельца определяют цель и разрешённый объём. Существующий код устанавливает фактическую реализацию; ADR — принятые решения; runtime evidence — наблюдаемое состояние конкретного сервера. История чата может предоставить свидетельство владельца, которое фиксируется с датой и уровнем подтверждения. Не превращать пересказ в самостоятельно измеренный факт.

Перед изменением изучить соответствующие entrypoints, service/read models, XMLDB, ACL, scheduled tasks и тесты. При противоречии описать конкретный конфликт. Не изобретать отсутствующие классы/API и не брать старую feature-ветку вместо main.

Статус следующего этапа и задания — в STATE/ACTIVE/BACKLOG. Mobile client пока планируется; существующий PWA не доказывает готовность native приложения. Production deployment, реальные роли и сетевые настройки проверяются отдельно от CI.

Текущий handoff 05.10: [аудит и уточнения](../audits/README.md), [план](../roadmap/USTAR_AUDIT_REMEDIATION_PLAN_20261005_RU.md), [свежий baseline](../runtime/baseline_20261005.md). После PR78 владелец разрешил порядок 1 → 7 → 6; INF-01 в работе, далее Moodle/CI/ОС и HTTPS. Остальные пункты пока не исполнять. Не дублировать установленный cron v3. Регулярные копии — текущий этап до нового стенда, на проверенный HDD с сохранением residual hardware risk, не SERVEREXPRESS. Владелец/sysadmin отдельно разрешили полную перезапись конкретного 500GB HDD; surface write/read zero errors и newest extended Completed without error подтверждены. SMART20:44:52UTC pending0/reallocated0/offlineUNC1; residual198 не объявлять историческим/cleared. Target ext4/mount/fstab, producer PR91 и fresh trial PASS; scheduler PR92 owner install/status PASS (report received06Oct08:02UTC), active/enabled/next07Oct03:30MSK; first scheduled run/boot recovery pending. [Текущий timer contract](../runtime/hdd_backup_schedule_20261006.md). PR86 planning20:44:34UTC PASS, точный бюджет получен. [Cold checkpoint + independent baseline workspace](../runtime/lab_cold_checkpoint_20261005.md) prepared/tested, server execution и core upgrade/repeat/paired rollback pending. Это не разрешает широкую очистку SSD/тестового стенда. [Новый checkpoint и Moodledata planning policy](../runtime/lab_patch_preflight_20261005.md).
