# USTAR — решения владельца, необходимые для продолжения

Дата: 2026-08-23

Текущий статус: **production NO-GO**. Этот файл не является разрешением на релиз, пока владелец не заполнит и явно не отправит выбранные пункты.

## 1. Канонический GitHub repository — закрыто

Подтверждено владельцем:

```text
CANONICAL_GITHUB_URL = https://github.com/cauf1l3d/ustarlms
ALLOW_ADD_OR_UPDATE_REMOTE = YES
ALLOW_PUSH_BRANCH_USTAR_FINAL_AUDIT_RELEASE = YES
```

Remote review branch опубликована; production release этим не разрешён.

## 1.1 Обязательное owner supplement — принято в release scope

`CODEX_RELEASE_SUPPLEMENT_HR_MATERIALS_UX_FINAL.md` принят владельцем как обязательный gated release block.

Зафиксированы TARGET access roles: `employee`, `manager`, `retail_manager`, `hr`, `hrd`, `ceo`, `system_admin`.

Canonical Studio read-only reconciliation восстановил 37 person overrides и точные object IDs. Их source SHA-256 и конфликтный dry-run описаны в `reports/HR_TARGET_MIGRATION_DRY_RUN.md`; private ID-level package намеренно хранится вне Git. Это не снимает обязательные gates ниже: Final Review не подтверждён, action list неполон и противоречив.

До изменения production всё ещё нужны:

```text
ACCOUNT_ACTION_LIST_VERSION = studio-2026-08-22T13:38:37Z / NEEDS FINAL REVIEW
KEEP_USER_IDS = REQUIRED FOR 54 UNMAPPED CURRENT ACCOUNTS
MERGE_PAIRS_SURVIVOR_ID <- SOURCE_ID = OWNER MUST CONFIRM DIRECTION
DISABLE_USER_IDS = REQUIRED WHERE REMOVE MUST PRESERVE HISTORY
DELETE_USER_IDS = RECOVERED OWNER OVERRIDES / NOT YET APPLY-AUTHORIZED
CURRENT_TO_TARGET_MAPPING_DIGEST = PRIVATE PACKAGE SHA256 17794cc109072db69ba0818180796bdf43fd187afbcbfe6b29e30c04964b84e3
HR_MIGRATION_ROLLBACK_VERIFIED = YES / NO
MATERIALS_LIBRARY_ACCEPTANCE_EVIDENCE = commit 112fa3f / 8 PNG / employee 0→1 / role isolation 1-0-0 / cleanup PASS
```

ФИО, email, CURRENT role/cohort/position или прежнее текстовое упоминание не заменяют точный ID-level action list. До него реальные аккаунты не изменяются.

## 2. Точный production scope

Для каждого блока выбрать `APPROVE`, `DEFER` или `REJECT`.

| Блок | Выбор владельца | Что изменится | Основной gate |
|---|---|---|---|
| Isolated login polish | `UNDECIDED` | Responsive shell вокруг неизменённой native Moodle form | Canonical auth/session decision; rollback theme files |
| Academy feature icons | `UNDECIDED` | 12 decorative card/feature PNG; actions/navigation остаются SVG | Licence/provenance и file-size optimisation |
| Game same-origin media fix | `UNDECIDED` | Question images генерируются через current-host Moodle File API | Fresh snapshot; game E2E after deploy |
| Hide active games with 0 active questions | `UNDECIDED` | Draft/empty game не показывается learner catalog | Editor/catalog regression |
| Default user-role remediation | `UNDECIDED` | Удаление четырёх risky extras и возврат одной capability к archetype | Явное capability-by-capability approval; session/API regression |
| Moodle/Next.js upgrades | `UNDECIDED` | Supported security versions | Separate compatibility, migration and rollback plan |
| AI tenancy remediation | `UNDECIDED` | Новый tenant source/default либо disable AI manager | TARGET owner/privacy decision; не использовать HR `institution` как tenant |
| HR/HRD separation | `UNDECIDED` | Раздельные access profiles и approval lifecycle | TARGET role contract and data-scope tests |
| Materials / Personal Library | `UNDECIDED` | Explorer workspace, route-only learning event и персональная Library read model | Browser/context/mobile evidence PASS; native drag manual check, fresh backup, DB migration and post-deploy role/rollback gate |
| USCOIN TARGET economy/store | `UNDECIDED` | Store, atomic debit, reversal, operator audit, caps/alerts or explicit non-spendable model | B049–B053 owner policy; CURRENT accepts overspend and manual actor is null |
| Leaderboard / competitions | `UNDECIDED` | Separate competition score, versioned season/rules, comparable audience, privacy, ties, close/archive/reward lifecycle | B054–B057/B074–B085; CURRENT exposes global people/balances, assigns distinct ranks to ties and has no season entity |
| Boards / collaboration | `UNDECIDED` | Personal vs official type, audience/editor rights, immutable versions/audit, retention/transfer/archive | Production CURRENT still loses 23/24 concurrent writes. Atomic row-lock containment is proven only in isolated (`1/23`, rollback/reapply PASS); all 7 current boards private and share UI absent |
| Tasks / notifications / messaging | `UNDECIDED` | Separate official and personal tasks; USTAR notification severity/outbox; channel, Bitrix, retry/receipt/escalation; Moodle chat boundary | CURRENT has 70 Moodle alerts but 0 `local_ustar` notifications/providers, no task/rule/delivery/escalation tables and only enrolment sync. Goal API guard is isolated-PASS; business workflow is absent |
| Evidence / Checklists / Gate | `UNDECIDED` | Risk-based Evidence policy, mirrored adaptation checklists, reviewer authority and scoped Gate decision/expiry/revocation/history | CURRENT has 12 activity mappings, 2 checklist definitions and one automatic `previous_adaptation` gate. Fail-closed Evidence/catalog guards are isolated-PASS; business admission lifecycle is absent |
| Organization / Reporting / Team | `UNDECIDED` | Canonical staff-place/assignment/reporting model; effective dates, vacancy/temp assignment, approval/history; direct-report vs department/named scope; CEO drill-down | CURRENT declares 16 departments/52 positions but has 0 reporting lines. Isolated truth/access/integrity guard and rollback PASS; it does not approve CURRENT org catalogs as TARGET |

Уже выполненные отдельно разрешённые production-блоки повторять не нужно:

- public HR mappings containment: anonymous 200 → 404;
- `config.php` и USTAR code permission hardening с rollback manifest.

## 3. TARGET-решения, которые нельзя вывести из CURRENT

Для каждого пункта нужен короткий ответ владельца либо решение через Architecture Studio.

1. **Identity/auth:** Moodle login является canonical, Next redirect/decommission или утверждённый SSO?
2. **Organization source:** USTAR, Bitrix/HR master или отдельная система?
3. **Manager relation:** где хранится versioned reporting chain и кто её утверждает?
4. **HR vs HRD:** какие действия относятся только к HRD?
5. **Learning assignment:** какая business entity является назначением, а Moodle enrol/course/completion остаются technical-only projection?
6. **Evidence/gate:** какие типы допуска требуют практического evidence, reviewer, expiry и revocation?
7. **XP/USCOIN:** разрешён ли conversion; является ли USCOIN spendable; запрещён ли отрицательный баланс для purchase; какие store, price/stock/fulfilment, reversal, operator approval, caps и anomaly rules? CURRENT race-idempotency PASS, но manual actor null и overspend accepted.
8. **Leaderboard:** persistent XP отдельно от competition score; season/rule version; comparable league/team scope and team-size/newcomer/transfer formula; tie timestamp/shared place; audience and visible fields (including USCOIN); creator/HRD approval; close/archive/reward/correction lifecycle? CURRENT isolated audit: 90 participants, 89 others disclosed to employee, 87 people in ties receive distinct ranks, `reporting=0`, season tables=0.
9. **Boards:** personal scratchpad, team artifact or official record; direct-team/department/named audience; viewer/editor/share/transfer/archive rights; immutable history/audit/retention/quota/import policy; simultaneous editing or explicit conflict workflow? The technical lost-update containment is isolated-PASS but not deployed; sharing still has no UI.
10. **Content governance:** owner, source of truth, review period и obsolete/version lifecycle?
11. **Tasks/notifications:** official Task and separate Personal Task fields/lifecycle; allowed creators and organisational scope; notification normal/action-required/critical rules; Moodle chat boundary; channels, Bitrix, acknowledgement, receipt, retry, dead-letter and escalation? CURRENT isolated audit: 70 Moodle alerts, 0 USTAR alerts/providers, no task/rule/delivery/escalation tables.
12. **AI tenancy:** dedicated tenant identifier, single default tenant или отключение до готовности?
13. **Organization/Team scope:** manager Team means direct reports, department, named responsibility scope or an approved composition; what may CEO drill into by default?
14. **Final TARGET:** кто подтверждает B001–B109 traceability и Final Review?

## 4. Production release confirmation

Заполняется только после выбора точного scope выше.

```text
I APPROVE PRODUCTION RELEASE = YES / NO

APPROVED BLOCKS =
EXCLUDED BLOCKS =
MAINTENANCE WINDOW (Europe/Moscow) =
AUTHORIZED APPROVER NAME/ROLE =
ROLLBACK TRIGGER ACCEPTED = YES / NO
POST-RELEASE OBSERVATION WINDOW =
```

Фраза «продолжай», «делай всё» или согласование аудита не трактуется как production approval. Разрешение должно перечислять конкретные блоки.

## 5. Git-публикация без production — закрыто

```text
CANONICAL_GITHUB_URL = <точный URL>
ALLOW_ADD_OR_UPDATE_REMOTE = YES
ALLOW_PUSH_BRANCH_USTAR_FINAL_AUDIT_RELEASE = YES
PRODUCTION RELEASE = NO
```

Этот ответ уже получен и использован только для публикации review-ветки. Deployment на сервер по-прежнему не разрешён.
