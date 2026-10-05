# Карта реализации USTAR

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

[`lab_patch_preflight.py`](../../scripts/infra/lab_patch_preflight.py) проверяет retained clone без Moodle bootstrap и считает rollback budget. `trusted` сохраняет strict code/control/PostgreSQL policy; `moodledata_entry` применяется только к exact retained Moodledata tree с root0700/33:33, проверяет per-entry33:33/type/links/no-executable-files и nonwritable directories. Обычные data files с write bits только учитываются через metadata, их содержимое не открывается. Live scan повторно проверяет private root и сообщает count широких file write modes. [Owner refusal и контракт](../runtime/lab_patch_preflight_20261005.md); server acceptance отдельно pending.
