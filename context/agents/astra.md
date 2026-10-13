**Checkpoint13Oct04:49MSK:** [OS inventory/APT simulation](../runtime/host_os_preflight_result_20261013.json) received04:37MSK:32 updates/18 new firmware/no removals,kernel139/reboot pending,AppArmor only failed unit,accepted Docker images/profile labels preserved. Current failure cause unconfirmed;[next bounded Snap/profile observation](../runtime/host_os_apparmor_handoff_20261013.md) compiles one profile with Q/K and reads consumers/kernel profiles/SSH socket. No OS repair/install/reboot executed. Owner confirmed latest stable Moodle5.3 LTS target after OS,with PG17+ migration/addons/coherent isolated recovery before production. Accepted5.1.8 patch stays closed;HTTPS remains selected.

# Профиль архитектурного агента USTAR

**Checkpoint12Oct15:25MSK:** [employee/manager smoke принят](../runtime/lab_patch_browser_result_20261012.json)
по owner ответу «все работает» на exact preceding two-account checks: свои learning/
tasks сотрудника, отказ HR page без HR capability, команда/обучение руководителя.
Admin SCORM/progress/logout-login/home приняты14:41,5.1.8 screenshot14:10 сохранён.
Следующий проход — existing real HR: people/card/materials/document/assessment view,
затем bounded Apache/PHP isolation/relevant addons/CI gate. Не выводить full-role
write/cross-scope, self-registration/completion/grades/all-addon/production PASS.
Current stage518/runxdgki_yo, prior same-helper paired rollback принят; cron disabled,
production core прежний. Пройденные checks/core operations не повторять без ошибки.
Raw images/account IDs/passwords/personnel не публикуются.

**Приоритет владельца06.10 00:45MSK:** сначала оптимизировать хранение, обновить карту сервера и настроить автобэкапы на HDD; затем новый стенд и Moodle/ОС/HTTPS. Прежнее правило «автобэкапы в финале» заменено. PR87 `--prepare-stage` приостановлен. [Карта и scope](../runtime/server_map_20261006.md). Более ранние этапы/даты ниже сохраняются как история; эта команда определяет текущий порядок.

Работать по [AGENTS](../../AGENTS.md) и [protocol](../roadmap/AGENT_PROTOCOL.md). Канонические исходники и текущий контекст — main.

Текущие инструкции владельца определяют цель и разрешённый объём. Существующий код устанавливает фактическую реализацию; ADR — принятые решения; runtime evidence — наблюдаемое состояние конкретного сервера. История чата может предоставить свидетельство владельца, которое фиксируется с датой и уровнем подтверждения. Не превращать пересказ в самостоятельно измеренный факт.

Перед изменением изучить соответствующие entrypoints, service/read models, XMLDB, ACL, scheduled tasks и тесты. При противоречии описать конкретный конфликт. Не изобретать отсутствующие классы/API и не брать старую feature-ветку вместо main.

Статус следующего этапа и задания — в STATE/ACTIVE/BACKLOG. Mobile client пока планируется; существующий PWA не доказывает готовность native приложения. Production deployment, реальные роли и сетевые настройки проверяются отдельно от CI.

Текущий handoff 05.10: [аудит и уточнения](../audits/README.md), [план](../roadmap/USTAR_AUDIT_REMEDIATION_PLAN_20261005_RU.md), [свежий baseline](../runtime/baseline_20261005.md). После PR78 владелец разрешил порядок 1 → 7 → 6; INF-01 в работе, далее Moodle/CI/ОС и HTTPS. Остальные пункты пока не исполнять. Не дублировать установленный cron v3. Регулярные копии — текущий этап до нового стенда, на проверенный HDD с сохранением residual hardware risk, не SERVEREXPRESS. Владелец/sysadmin отдельно разрешили полную перезапись конкретного 500GB HDD; surface write/read zero errors и newest extended Completed without error подтверждены. SMART20:44:52UTC pending0/reallocated0/offlineUNC1; residual198 не объявлять историческим/cleared. Target ext4/mount/fstab, producer PR91 и fresh trial PASS; scheduler PR92 owner install/status PASS (report received06Oct08:02UTC), active/enabled/next07Oct03:30MSK; first scheduled run/boot recovery pending. [Текущий timer contract](../runtime/hdd_backup_schedule_20261006.md). PR86 planning20:44:34UTC PASS, точный бюджет получен. [Cold checkpoint + independent baseline workspace](../runtime/lab_cold_checkpoint_20261005.md) prepared/tested, server execution и core upgrade/repeat/paired rollback pending. Это не разрешает широкую очистку SSD/тестового стенда. [Новый checkpoint и Moodledata planning policy](../runtime/lab_patch_preflight_20261005.md).
