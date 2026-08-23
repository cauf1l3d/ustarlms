# USTAR — role test report

Дата: 2026-08-23  
Статус: **isolated role smoke, capability matrix, entry-point boundaries and revocation verified; business-model gaps remain**

## 1. Исходный Phase 0 результат

На исходном read-only этапе реальные входы employee / manager / HR / HRD / CEO не выполнялись. Этот исходный статус заменён фактическими isolated-тестами в разделах 7–8. Аккаунты, роли и токены на production не создавались и не изменялись.

Документ по-прежнему не объявляет бизнес-модель ролей согласованной: отдельной HRD-роли в CURRENT нет, reporting hierarchy пуста, а TARGET owner approval отсутствует.

## 2. Фактическая role/capability matrix

| Moodle role | Основные USTAR capabilities | Current assignment evidence | Risk |
|---|---|---:|---|
| authenticated user | `local/ustar:use` | Базовый доступ | 82 аккаунта получают employee behavior через default account type |
| manager | `use`, `viewteam` | В custom roles/assignments manager users присутствуют | reporting hierarchy пуста |
| `ustar_manager` | `use`, `viewteam` | 8 assignments | Team scope нельзя полноценно доказать |
| `ustar_hr` | `use`, `hr`, `hrmanage` | 4 assignments | HR и HRD не разделены |
| `ustar_exec` | `use`, `executive` | 2 assignments | Executive data/privacy contract не зафиксирован |
| `ustar_superadmin` | admin/management capabilities | 4 assignments | Требуется separation technical/business decisions |

11 пользователей имеют более одной distinct Moodle role. Role inheritance/precedence должен быть частью теста.

Дополнение security check: Moodle помечает default role `user` как `CRITICAL`. Помимо `local/ustar:use`, в system context разрешён широкий набор, включая `moodle/webservice:createtoken`, `moodle/webservice:createmobiletoken`, `webservice/rest:use`, AI/chat/report/rating capabilities. До role E2E требуется diff с чистым archetype и явное решение KEEP/REMOVE по каждому отклонению.

## 3. Static scenario status

| Scenario | Static evidence | E2E | Status |
|---|---|---|---|
| Employee login and home | Routes/API/code inspected | Не выполнен | BLOCKED |
| Employee sees only own data | Capability paths inspected | Не доказано | BLOCKED |
| New employee pending HRD | Studio intent exists | Нет готового role state test | BLOCKED |
| Manager sees direct team | `viewteam` exists | reporting=0 | FAIL by data prerequisite |
| Manager receives escalation | Studio text/Bitrix intent | End-to-end event absent | BLOCKED |
| HR manages people/routes | `hr`/`hrmanage` exist | HR/HRD scope not separated | FAIL architecture |
| HRD approves employee | Studio intent | Capability/state transition not proven | BLOCKED |
| CEO sees aggregate only | `executive` exists | Privacy/aggregation not tested | BLOCKED |
| Superadmin operates health/config | Admin code paths exist | No authenticated run | BLOCKED |
| Employee leaderboard privacy | Current code reviewed | Global names/positions | FAIL privacy scope |
| Board audience restrictions | Current same-department logic | Fine-grained target absent | FAIL target parity |
| Route completion → gate | Code/data inspected | Full scenario not run | BLOCKED |
| Third failure escalation | Studio decision | Notification/task chain not modelled | FAIL architecture |

## 4. Required test identities

Использовать только специально созданные/санкционированные тестовые записи без реальных персональных данных:

- `test_new_employee_pending`;
- `test_employee_retail`;
- `test_manager_retail` with 2–3 synthetic reports;
- `test_hr_operator`;
- `test_hrd_approver`;
- `test_ceo_viewer`;
- `test_superadmin_operator`.

Для проверки auth требуется отдельное подтверждение владельца непосредственно перед вводом credentials.

## 5. Mandatory E2E scripts

### New employee

1. Account created from approved source.
2. Pending screen explains status and responsible actor.
3. No unauthorized employee/company data visible.
4. HRD approval activates correct assignment/access.
5. Route appears from staff place/position rule.
6. Mentor/tasks/notifications created if TARGET requires.

### Employee

1. Login → Today/Home.
2. Assigned route and due step visible.
3. Material/assessment/evidence lifecycle completes.
4. Gate grants only correct work admission.
5. XP and USCOIN events are separate and traceable.
6. Other employees' private data is not exposed.

### Manager

1. Only effective direct/allowed team visible.
2. Vacancies/temporary assignments interpreted correctly.
3. Overdue and failed attempts create expected task/escalation.
4. Review/evidence action has audit history.
5. Removing manager assignment removes access promptly.

### HR / HRD

1. HR cannot perform HRD-only access governance.
2. HRD can approve/reject with rationale and history.
3. Org change is versioned and does not rewrite history.
4. Content/route changes follow ownership and review policy.
5. PII exports require explicit capability and audit event.

### CEO

1. Executive metrics are aggregate and explain source/freshness.
2. Drill-down obeys necessity and role access.
3. No technical Moodle controls in executive journey.
4. Empty/stale/incomplete data is visibly labelled.

## 6. Exit criteria

- All mandatory role scenarios pass in isolated test environment.
- Moodle `core_defaultuserrole` больше не CRITICAL; утверждённый default-role diff приложен.
- Negative permission tests pass for every capability boundary.
- Access removal and session revocation are verified.
- Audit trail includes actor, action, object, before/after, time and rationale.
- No token/PII appears in browser URL, logs, screenshots or exports.
- OWNER signs the role matrix and expected views.

## 7. Executed isolated E2E — 2026-08-23

Этот раздел заменяет Phase 0 placeholders «не выполнен» для перечисленных smoke-сценариев. Production identities не использовались. В isolated DB созданы только synthetic accounts:

| Scenario | Position custom field | Technical role | Landing | Result |
|---|---|---|---|---|
| Employee | `retail_seller` | default user only | `/local/ustar/home.php` | PASS |
| Retail head | `retail_head` | `ustar_manager` | `/local/ustar/team.php` | PASS |
| HR | `hr_head` | `ustar_hr` | `/local/ustar/hr.php` | PASS |
| CEO | `ceo` | `ustar_executive` | `/local/ustar/executive.php` | PASS |

Проверено фактически:

- first login и mandatory policy flow;
- employee: home, learning empty state, games, knowledge, catalog, team, achievements/USCOIN, boards, profile;
- manager: team, learning, games, catalog, achievements, knowledge, boards;
- HR: people, positions, materials, operations, catalog, employee creation screen;
- CEO: executive, team, catalog, achievements, boards, profile;
- no access-denied alerts on intended surfaces;
- no browser console errors in captured role journeys;
- synthetic logout/login isolation between roles.
- synthetic employee game runtime: same-origin question image, wrong/correct feedback, 2 persisted attempts, 1 mastery row, 25 Game XP and one +5 idempotent USCOIN ledger event after test-only media fix;

Default user role diff до test remediation: 172 current system capabilities против 168 archetype; extras `block/ai_chat:addinstance`, `block/ai_chat:view`, `moodle/webservice:createtoken`, `webservice/rest:use`; `block/ai_chat:myaddinstance` был ALLOW вместо archetype PROHIBIT. После `reset_role_capabilities()` только в isolated DB: 168/168, extra=0, changed=0, missing=0.

Не закрыто smoke-проходом: HR/HRD separation, direct-report data correctness, route→evidence→gate lifecycle, privacy of real leaderboards/boards и integration failure paths. Production role reset не выполнялся.

Game-specific evidence and the production CURRENT defect are documented in `GAME_RUNTIME_VALIDATION.md`. Production game code remains unchanged.

## 8. Isolated boundary and revocation tests — 2026-08-23

Проверена фактическая capability matrix для synthetic employee, manager, HR, CEO и USTAR superadmin:

- matrix status: PASS;
- employee не имеет `viewteam`, HR, executive, admin, webservice-token или REST capability;
- manager имеет только `use + viewteam` из проверенного USTAR набора;
- HR имеет `use + hr + hrmanage`, но не executive/admin;
- CEO имеет `executive + viewteam`; `viewteam` приходит через position-derived `ustar_manager`, потому что CEO position помечена как head;
- USTAR superadmin имеет административные USTAR capabilities, но не HR/executive;
- default-user risky extras `moodle/webservice:createtoken` и `webservice/rest:use` отсутствуют после test-only archetype reset.

Entry-point verification выполнялась включением реальных защищённых PHP-страниц с synthetic user context:

- 34/34 запрещённых role/page комбинаций: PASS, каждая завершилась `core\\exception\\required_capability_exception`;
- 18/18 разрешённых role/page комбинаций: PASS, включая HR workspace/positions/materials/routes и superadmin brand/game/checklist/bulk screens;
- те же capability, 34 denial и 18 allow проверки повторно прошли на полностью восстановленном DR-стеке.
- после последних login/icon/game изменений capability matrix, 34/34 denial и 18/18 allow третий раз прошли на final RC restore `18082`; exact implementation hashes and 12 Academy assets also matched the snapshot.

Revocation lifecycle:

1. `audit_employee` baseline `viewteam=false`.
2. Временная isolated assignment `ustar_manager` → `viewteam=true`; активная браузерная сессия сразу показала manager shell.
3. Role unassign + access cache clear → `viewteam=false`, residual assignment=false; та же сессия сразу вернулась к employee shell.
4. Штатный `core\\session\\manager::kill_user_sessions()` удалил 1/1 session; та же вкладка получила «Время вашего сеанса истекло» и вернулась на login.
5. После теста manager assignment отсутствует, все synthetic passwords рандомизированы, все synthetic sessions удалены.

Подтверждённый CURRENT conflict: роли `ustar_hrd` не существует, а `ustar_hr` одновременно содержит read-capability `hr` и write-capability `hrmanage`. Это нельзя исправлять автоматически до TARGET-решения о HR/HRD separation.

## 9. Materials / Personal Library role boundary — authenticated browser

- `audit_hr` opened the full Materials workspace and completed a synthetic context move.
- `audit_employee` direct access to `/local/ustar/materials.php` returned the expected no-permission page for the HR capability boundary.
- After a guarded route-open event, the per-user Library read model contained `audit_employee=1`, `audit_hr=0`, `audit_superadmin=0`; no cross-user row was created.
- Mobile 390×844 checks covered both HR context actions and the employee Library.
- Cleanup randomised temporary credentials, killed all guarded sessions (`1 → 0`) and returned the claimed browser tab to login.
- Only synthetic isolated identities were used; production accounts and production permissions were unchanged.

## 10. Leaderboard employee disclosure and team-rank boundary

Read-only isolated runtime evidence is recorded in `LEADERBOARD_CURRENT_RUNTIME_AUDIT.md`:

- a synthetic employee with common `local/ustar:use` can receive 89 other full-name/position/XP/coin rows;
- 87 of 90 participants belong to an equal-XP group but receive distinct ranks;
- no reporting row or competition/season/league table exists;
- `Моя команда` falls back to same-position peers;
- the synthetic employee's team-local rank was `#2`, but the page current-rank value remained global `#10`;
- probe mutations were zero and production identities were not used.

Result: technical page access is proven, while TARGET privacy, comparable group and fair-rank semantics fail and remain owner decisions.

## 11. Boards owner/team/write and concurrency boundaries

Isolated evidence in `BOARDS_CURRENT_RUNTIME_AUDIT.md` proves:

- private owner read PASS; same-department peer private read denied;
- synthetic `sharedteam=1` same-department read PASS; cross-department HR and technical USTAR superadmin denied;
- shared viewer write denied; only owner can save;
- sequential stale save, invalid JSON and >10 MiB rejected;
- critical concurrency FAIL: 24 workers using expected version 1 all received success, while final version stayed 2 and only one JSON document survived;
- two synthetic rows deleted and exact seven-row baseline restored.

Result: CURRENT read/write ACL is technically bounded. The isolated candidate now passes exact 1/23 atomic-save acceptance and rollback→reapply, but production still has the old unsafe path; team semantics and conflict UX are not approved.

## 12. Workflow/Communication ownership boundaries

Self-cleaning isolated evidence in `WORKFLOW_COMMUNICATION_CURRENT_RUNTIME_AUDIT.md` proves:

- an employee notification list excludes a peer's notification;
- an employee cannot mark a peer notification read but can mark the employee's own row;
- a foreign Moodle conversation is denied by core membership;
- a personal goal can be created/completed/deleted only by its owner; peer write is denied;
- an employee cannot create an HR review, invalid score is denied, HR can create one, and one matching HR action is written;
- all synthetic rows were deleted and counters returned exactly to notifications/USTAR notifications/goals/reviews/HR actions `70/0/2/1/176`.

The old goal service falsely accepted an unknown action; the isolated candidate now rejects it, and rollback→reapply reproduced both behaviours. These checks prove technical ACL only. No official/personal Task entity, manager authority scope, USTAR notification provider, Bitrix delivery, acknowledgement, retry or escalation exists.
