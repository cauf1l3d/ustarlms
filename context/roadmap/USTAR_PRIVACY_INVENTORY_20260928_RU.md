# USTAR: реестр прямых ссылок на людей (черновик privacy)

Источник — `moodle/local/ustar/db/install.xml`. Это механически проверяемый
перечень явных колонок с пользователями; он **не является** полной картой
персональных данных и не запускает удаление. Переименования и новые таблицы
должны обновлять этот перечень через `scripts/ci/privacy_inventory.py`.

| Таблица | Прямые поля Moodle user ID |
|---|---|
| `local_ustar_structure` | `usermodified` |
| `local_ustar_goals` | `userid` |
| `local_ustar_game_attempts` | `userid` |
| `local_ustar_hr_actions` | `actorid`, `targetuserid` |
| `local_ustar_game_mastery` | `userid` |
| `local_ustar_check_runs` | `userid` |
| `local_ustar_check_def_ver` | `createdby` |
| `local_ustar_reviews` | `userid`, `reviewerid` |
| `local_ustar_skill_evidence` | `usermodified` |
| `local_ustar_content` | `owneruserid`, `usermodified` |
| `local_ustar_content_versions` | `createdby` |
| `local_ustar_content_access` | `createdby` |
| `local_ustar_content_ack` | `userid` |
| `local_ustar_content_events` | `actorid`, `userid` |
| `local_ustar_library` | `userid` |
| `local_ustar_evidence_rec` | `userid`, `recordedby` |
| `local_ustar_evidence_evt` | `actorid` |
| `local_ustar_gate_defs` | `ownerid` |
| `local_ustar_gate_decisions` | `userid`, `decidedby` |
| `local_ustar_check_submits` | `userid`, `submittedby` |
| `local_ustar_official_tasks` | `userid`, `ownerid`, `createdby` |
| `local_ustar_personal_tasks` | `userid` |
| `local_ustar_workflow_events` | `actorid` |
| `local_ustar_notifications` | `userid` |
| `local_ustar_coin_ledger` | `userid`, `actorid` |
| `local_ustar_reporting` | `userid`, `managerid`, `usermodified` |
| `local_ustar_catalog` | `usermodified` |
| `local_ustar_route_families` | `usermodified` |
| `local_ustar_routes` | `usermodified` |
| `local_ustar_route_points` | `usermodified` |
| `local_ustar_route_scope` | `usermodified` |
| `local_ustar_route_versions` | `usermodified` |
| `local_ustar_route_progress` | `userid`, `recordedby` |
| `local_ustar_assess_policy` | `usermodified` |
| `local_ustar_assess_runtime` | `userid`, `managerid` |
| `local_ustar_dev_assess` | `usermodified` |
| `local_ustar_dev_assess_ver` | `usermodified` |
| `local_ustar_dev_assess_try` | `userid` |
| `local_ustar_test_defs` | `usermodified` |
| `local_ustar_test_attempts` | `userid` |
| `local_ustar_test_results` | `userid` |
| `local_ustar_coin_accounts` | `userid` |
| `local_ustar_employment` | `userid`, `approvedby`, `usermodified` |
| `local_ustar_staff_places` | `usermodified` |
| `local_ustar_assignments` | `userid`, `usermodified` |
| `local_ustar_comp_scores` | `userid`, `actorid` |
| `local_ustar_coin_balance` | `userid` |
| `local_ustar_competitions` | `ownerid` |
| `local_ustar_comp_rules` | `createdby` |
| `local_ustar_comp_participants` | `userid` |
| `local_ustar_staff_requests` | `employeeid`, `requestedby`, `reviewedby`, `createduserid` |
| `local_ustar_route_testers` | `actorid`, `sandboxuserid` |
| `local_ustar_route_test_tokens` | `actorid`, `sandboxuserid` |
| `local_ustar_adaptations` | `userid`, `managerid`, `createdby` |
| `local_ustar_completion_cycle` | `userid` |
| `local_ustar_standards` | `ownerid` |
| `local_ustar_standard_ver` | `createdby` |
| `local_ustar_content_blueprints` | `authorid` |
| `local_ustar_grade_rules` | `createdby` |
| `local_ustar_grade_ladders` | `createdby` |
| `local_ustar_grade_ladder_ver` | `createdby` |
| `local_ustar_grade_bindings` | `usermodified` |
| `local_ustar_employee_grades` | `userid`, `usermodified` |
| `local_ustar_grade_requests` | `userid`, `managerid`, `decisionby` |
| `local_ustar_learning_tasks` | `ownerid`, `assigneeid`, `assignerid` |
| `local_ustar_learning_task_events` | `actorid` |
| `local_ustar_catalog_versions` | `actorid` |
| `local_ustar_board_archive` | `ownerid`, `archivedby` |
| `local_ustar_feed_posts` | `actoruserid` |
| `local_ustar_feed_comments` | `actoruserid` |
| `local_ustar_feed_reactions` | `userid` |
| `local_ustar_feed_events` | `actoruserid` |
| `local_ustar_feed_reports` | `reporterid`, `resolvedby` |

## Связанные записи и файлы, которые нельзя потерять при реализации

- Косвенные связи: `check_answers → check_runs`, `test_answers → test_attempts`,
  `learning_task_events → learning_tasks`, `notify_delivery → notifications`,
  `feed_audience/comments/reactions/events/reports → feed_posts`,
  `comp_score_events/results → comp_participants`. Для экспорта нужны обе стороны связи.
- Moodle File API: `feed_attachment`, `task_attachment`, `task_result`,
  материалы, изображения/версии контента, SCORM и доказательства;
  ownership файла и право на удаление выводятся из исходного объекта.
- JSON и свободный текст в структуре, заданиях, уведомлениях, оценках и
  публикациях могут содержать имена и другие персональные сведения без явного user ID.
- Неизменяемые учебные/кадровые факты, события аудита и сведения о других
  авторах требуют отдельной политики хранения и редактирования перед удалением.

## Незакрытые условия

1. Сроки Ленты приняты владельцем 28.09.2026; автоматическое удаление выключено.
   Основания/исключения и сроки остальных доменов остаются открытыми;
2. Реализовать `classes/privacy/provider.php`: metadata, полный export,
   context/userlist discovery и deletion для всех доменов/файлов;
3. Покрыть точным Moodle DB тестом экспорт/удаление пользователя и чужих данных;
4. Отдельно проверить, что системные роли и закрытый блокнот не открывают данные HR.
