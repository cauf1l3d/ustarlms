# Карта реализации USTAR

Текущая область16Oct: native local_ustar/theme_ustar,526 файлов прежнего application baseline. `frontend/` — legacy вне active CI/release. [Сверка](../audits/AUDIT_RECONCILIATION_20261016_RU.md), [следующий пульт](../runtime/control_panel_telegram_20261016.md), [CI scope](../runtime/CI_SCOPE_20261016.md). Ниже сохранены датированные сведения о реализации.

Проверено по PR76 `e1d57f5bc28f6964afd20aeff7620345b34a80fe`. Карта относится к Git source, не к снимку live DB. Полный schema inventory строится из XMLDB; классы/API — из exact Git tree.

| Область | Входы | Сервисы / контракт |
|---|---|---|
| [organization](../domains/organization.yaml) | `team.php`, `executive.php`, `positions.php` | `organization_identity`, `organization_model`, `organization_directory`, `structure` |
| [registration](../domains/registration.yaml) | `register.php`, `staffing.php` | `registration_service`, `staffing_requests`, `hr_access`, `access_context` |
| [adaptation](../domains/adaptation.yaml) | `adaptation.php`, `adaptation_control.php` | `adaptation_service`, `staffing_requests`, `checklist_service` |
| [routes](../domains/routes.yaml) | `route.php`, `route_studio.php`, `activity_launch.php`, `forced_retraining.php` | `route_model`, `route_family`, `route_scope`, `route_commands` |
| [learning](../domains/learning.yaml) | `materials.php`, `knowledge.php`, `catalog.php`, `assessment_studio.php`, `development_assessment.php` | `content`, `content_admin`, `native_learning`, `moodle_activity_bridge` |
| [evidence](../domains/evidence.yaml) | `route.php`, `achievements.php` | `target_core`, `evidence`, `completion_cycle`, `route_point_evidence_provider` |
| [grades](../domains/grades.yaml) | `grades.php`, `route_career.php` | `grade_ladders`, `grade_rules`, `grade_promotion`, `grade_assignment_directory` |
| [work](../domains/work.yaml) | `tasks.php`, `checklist_studio.php`, `notebook.php`, `board_api.php`, `home.php` | `learning_tasks`, `task_workspace/service`, `task_workspace/policy`, `task_workspace/worker` |
| [economy](../domains/economy.yaml) | `achievements.php`, `competition_studio.php`, `reward_control.php`, `games.php` | `economy`, `reward_control`, `reward_conditions`, `route_rewards` |
| [feed](../domains/feed.yaml) | `feed.php`, `feed_admin.php`, `admin_feed_roles.php`, `knowledge.php` | `feed_service`, `feed_access`, `feed_query`, `feed_files` |
| [messaging](../domains/messaging.yaml) | `messages.php`, `messages_api.php`, `notifications.php` | `communication`, `chat_groups`, `chat_files`, `workflow_notifications` |
| [mobile_web](../domains/mobile_web.yaml) | `app_manifest.php`, `app_icon.php`, `app_worker.php`, `app_offline.php` | `mobile_app`, `hook_callbacks` |

Все имена страниц в таблице относительны `moodle/local/ustar/`; сервисов — `classes/`. Domain YAML содержит полные проверяемые пути и логические XMLDB table names.

## Где проверять изменения

- Schema/upgrade/roles: `db/install.xml`, `db/upgrade.php`, `db/access.php`, `db/install.php`.
- Moodle regressions: `moodle/local/ustar/tests/`; PHP/DOM: `tests/release_20260912/`; browser: `tests/mobile/`; isolated runtime: `tests/stage/`.
- Service APIs: [web_services](generated/web_services.md); native controller endpoints не становятся token API автоматически.
- [PHP files](generated/php_files.md), [classes](generated/classes.md), [tables](generated/database_tables.md), [source hashes](generated/source_manifest.json).

## Воспроизведение

```bash
bash scripts/build_code_map.sh e1d57f5bc28f6964afd20aeff7620345b34a80fe
python3 scripts/build_context_index.py
python3 scripts/check_context.py
```

Для нового application release заменить SHA на проверенный commit и обновить STATE/domain maps. Documentation commit может иметь иной SHA при неизменном application tree. Генераторы не опрашивают production и не копируют config.php.

## Infrastructure planning helper

[`lab_patch_preflight.py`](../../scripts/infra/lab_patch_preflight.py) проверяет retained clone без Moodle bootstrap и считает rollback budget. `trusted` сохраняет strict code/control/PostgreSQL policy; `moodledata_entry` применяется только к exact retained Moodledata tree с root0700/33:33, проверяет per-entry33:33/type/links/no-executable-files и nonwritable directories. Обычные data files с write bits только учитываются через metadata, их содержимое не открывается. Live scan повторно проверяет private root и сообщает count широких file write modes. [Owner refusal и контракт](../runtime/lab_patch_preflight_20261005.md); server planning20:44:34UTC PR86 PASS; [точный report](../runtime/lab_planning_result_20261005.json).

[`lab_cold_checkpoint.py`](../../scripts/infra/lab_cold_checkpoint.py) использует SHA-pinned planning/resume/engine, отдельный module namespace и ownership guards. `cold_checkpoint` копирует согласованный комплект после lab-only web/PG stop и всегда возобновляет same source IDs; `copy_workspace` создаёт independent copy без shared inodes, `start_workspace` проверяет DB/files/bootstrap/HTTP/offline isolation. Frozen manifest не монтируется. Source chmod/chown/production exec/SQL отсутствуют; owner/mode сохраняются только на новых copies. [Контракт и deployment pending](../runtime/lab_cold_checkpoint_20261005.md).


[`backup_restore_test.py`](../../scripts/infra/backup_restore_test.py) selects the latest completed HDD archive or explicit copied pair, binds exact private verifier/restore engine, restores independent offline containers with a chosen duration (30m/2h/1d/0), counted after readiness. [`ustar-restore-test-20261014.service`](../../scripts/infra/ustar-restore-test-20261014.service) provides lifecycle/ExecStopPost cleanup. [Runbook](../runtime/backup_restore_experiment_20261014.md). Application manifest526/files and schema93 remain unchanged.

[`backup_transfer.py`](../../scripts/infra/backup_transfer.py) packages the latest verified HDD backup, private key and exact restore helpers; starts a chosen-duration lab or portable clean-host copy (default0/no timer). New generated unit `ustar-backup-recovery-20261015.service` replaces the legacy fixed watchdog for this workflow. Optional dependency installation is restricted to clean Ubuntu24.04; no reboot autostart. [Current four-stage runbook](../runtime/backup_transfer_20261015.md). Source e68c887a/59 selected tests PASS; native owner acceptance pending.


[`production_restore.py`](../../scripts/infra/production_restore.py) — permanent recovery: source cron-lock/maintenance/fresh backup/web freeze and explicit abort; empty-host PG restore without task-disable/siteidentifier changes, preserved config with local DB/runtime overrides, stock URL replace, persistent owned IDs/boot unit/minute cron. `backup_transfer.py` packages v2 with this pinned worker and dispatches explicit `--start-production`; old `--start` stays isolated. [Production runbook](../runtime/production_transfer_20261015.md), code 99ab241e1771b850bfedb3a5cab03ad4a2122917. No Moodle application/schema change; native recovery/reboot/SMTP pending.


[`prepare_production_copy.sh`](../../scripts/infra/prepare_production_copy.sh) installs pinned restore helpers and submits only read-only `--pack` from the last successful HDD backup. `backup_transfer.py --start-production-copy` passes `--parallel-copy` to the permanent worker: distinct canonical URL, same recovered business data/task flags, mail+sendmail+cron off, boot startup without cron timer. Source freeze is not called. [Current sysadmin handoff](../runtime/production_copy_20261015.md), codea1a0c62b/prepare38485343;101 selected CI checks PASS, native import/reboot pending.

[development_source.json](development_source.json) — отдельный unmerged candidate546files/99tables, local2026101903 (CON01C.2). Generated baseline files526/93 описывают accepted e1d57f5b; STATE.development_candidate и byte/tree validation в check_context связывают текущий код с новым manifest без подмены production evidence.


[`connect_chat_link_update.py`](../../scripts/infra/connect_chat_link_update.py) —
отдельный fixed830/1905→1e8/2000 application+cron controller,99schema/tasks preserved,
HTTPS/renewal lock,paired recovery и strict HTTPS login.
[`backup_https_prepare.py`](../../scripts/infra/backup_https_prepare.py) готовит
согласованную producer/scheduler readiness пару перед fresh HDD;без запуска jobs
или изменения units/calendar. [План/границы](../proposals/USTAR_CONNECT_COORDINATED_UPDATE_PLAN_20261021.md).
Exact6aa source CI verified: [receipt](../runtime/connect_chat_link_update_ci_20261021.json).
Native application/backup preparation not deployed.

## 21Oct owner backup HTTPS preparation accepted

[Exact owner receipt](../runtime/connect_backup_https_owner_20261021.json) confirms
producer a604fbcd / scheduler a97039a8 and ready_for_chat_link_update. The updater
source6aa/PYZ e0d364ab is unchanged;application1905 remains installed until separate upgrade.
No production backup,application2000 or bot/link/notify acceptance is inferred.

## 21Oct effective addon update accepted

[Owner receipt](../runtime/connect_chat_link_update_owner_20261021.json):fixed tool6aa
installed payload1e8a8819/local2000/theme0202/552files/99tables;new scheduled cron22:08UTC
exit0 and general Academy browser-open accepted. Generated development inventory is
source evidence and does not substitute for this deployment receipt. Private transport
continues under [the delivery plan](../proposals/USTAR_CONNECT_PRIVATE_BRIDGE_DELIVERY_PLAN_20261021.md).
