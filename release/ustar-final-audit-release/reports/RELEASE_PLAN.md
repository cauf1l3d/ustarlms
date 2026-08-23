# USTAR — release plan (draft, gated)

Дата: 2026-08-23  
Статус: **audit/test-only blocks выполнены; production release остаётся gated**

## 1. Принцип

Релиз строится от утверждённой архитектуры и реальных role journeys, а не от визуальных карточек. Production CURRENT остаётся evidence и migration source, но не TARGET.

## 2. Gate 0 — решение владельца

Владелец подтверждает:

- `AUDIT_FINAL_2026.md`;
- `ARCHITECTURE_DIFF.md`;
- список P0/P1;
- приоритет canonical auth flow;
- подтверждение обязательного native Moodle login shell без замены `core/loginform`;
- право на security containment публичных HR-файлов;
- test environment и тестовые роли;
- порядок обновления Moodle/Next.js;
- допустимое maintenance window.

Аудит, ветка и isolated test work уже согласованы. Этот gate теперь применяется к любому новому production scope: без отдельного подтверждения не публиковать login/icons/game fixes, role changes или dependency upgrades.

## 3. Phase 1 — incident containment

1. Создать pre-change backup set и checksum manifest.
2. Убрать HR mappings из web-root без потери исходных данных.
3. Проверить Caddy/Moodle access logs на обращения к двум URL.
4. Классифицировать содержимое и возможное раскрытие.
5. Снизить permissions `bx_grade_tokens.txt`, проверить owner и необходимость rotation.
6. Провести credential/token inventory без вывода секретов.
7. Зафиксировать production code/config как read-only для web-процесса по проверенному runbook.
8. Сравнить default `user` role с clean Moodle archetype и отдельно утвердить каждое USTAR/webservice capability.
9. Отключить `display_errors` после проверки server-side logging; определить password/MFA policy.
10. Зафиксировать `core_publicpaths` как Caddy 308→404 compatibility noise либо добавить протестированное pre-normalisation 404 правило; для `local_ai_manager` сначала утвердить отдельный AI tenant source/default/disable decision, не изменяя HR fields.

Acceptance: anonymous requests к HR artifacts дают 404/403; функционал использует private storage; incident record закрыт владельцем.

## 4. Phase 2 — architecture closure

1. Открыть обратно конфликтующие Studio decisions.
2. Добавить все недостающие entities из `ARCHITECTURE_DIFF.md`.
3. Связать каждое решение с B001–B109.
4. Заполнить owner, source of truth, lifecycle, access/evidence policy.
5. Закрыть четыре undecided overrides.
6. Вывести role contracts и migration mappings.
7. Прогнать Final Review; подтвердить TARGET владельцем.

Acceptance: zero unresolved/contradictions/orphans/multiple SoT; `review.confirmed=true`; generated outputs содержат roles и B-traceability.

## 5. Phase 3 — branch and isolated environment

Только после Gate 0:

1. Установить требуемый checklist skill по договорённой команде.
2. Создать Git branch `ustar-final-audit-release` от зафиксированного commit.
3. Поднять isolated environment из проверенного backup.
4. Зафиксировать baseline automated, security, visual и role tests.

## 6. Phase 4 — security/platform upgrades

1. Обновить Moodle до актуальной поддерживаемой 5.1.x через documented upgrade path.
2. Обновить Next.js/frontend на поддерживаемую исправленную ветку; не считать простую замену версии достаточной.
3. Заменить client token/session flow на принятый canonical auth.
4. Убрать token из file URL query.
5. Ограничить external service users/functions/tokens.
6. Ввести MIME/signature/size/malware policy uploads.
7. Исправить account type default и HR/HRD capability mapping.
8. Перевести Moodle code/theme/plugin files в immutable deployment ownership; web-процесс пишет только в предназначенные data/cache dirs.
9. После обновления получить нулевой согласованный набор CRITICAL/ERROR в Moodle status/security checks.

Acceptance: dependency scan clean по согласованному threshold; negative auth tests pass; token/PII absent from URLs/logs.

## 7. Phase 5 — domain model and migration

Рекомендуемый порядок зависимостей:

1. Person / Account Lifecycle.
2. Organization / Department / Staff Place / Position / Assignment.
3. Manager relation / Access Profile.
4. Skill / Level / Requirement / KPI / Development.
5. Content ownership/version lifecycle.
6. Learning Assignment / Route / Step / Evidence / Gate.
7. Mentor / Goals / Reviews / Tasks / Notifications.
8. XP / USCOIN / Season / League / Ranking.
9. Boards / Reporting / Integrations.
10. Role interfaces and journeys.

Для Moodle определить technical-only projection: cohort/enrol/course/module/completion и правила синхронизации. Не удалять Moodle entities только потому, что они скрыты в business UI.

## 8. Phase 6 — UX/UI implementation

1. Канонический пользовательский вход строить как shell вокруг нативной формы Moodle `core/loginform`; отдельный Next token-login убрать/редиректить после migration plan.
2. Применить Canva как brand direction, дополнив functional states, не изменяя native auth semantics.
3. Ввести единый design system и icon registry.
4. Пересобрать navigation по role contracts.
5. Добавить empty/error/loading/permission/stale states.
6. Сделать responsive/accessibility pass.
7. Добавить provenance/freshness в reports.

## 9. Phase 7 — tests

- Unit/contract/migration tests.
- Schema upgrade and rollback tests.
- Security tests: auth, CSRF, XSS, file access, capability boundary, token leakage.
- Full scripts from `ROLE_TEST_REPORT.md`.
- Backup restore and release rollback rehearsal.
- Browser matrix and visual regression.
- Performance budgets from `UX_UI_AUDIT.md`.
- Cron/background/integration failure and retry scenarios.

## 10. Phase 8 — release

1. Freeze changes and produce exact manifest/digests.
2. Create verified pre-release backup.
3. Apply schema/code/config in documented order.
4. Run anonymous, employee, manager, HR/HRD, CEO smoke tests.
5. Observe errors, cron, DB, latency, access logs.
6. Roll back immediately on defined triggers.
7. Sign `RELEASE_NOTES.md`, `BACKUP_INFO.md`, `FINAL_STATUS.md`.

## 11. Current owners needed

| Decision | Required owner |
|---|---|
| Constitution/TARGET | USTAR product owner |
| Org/staff place/assignment | HRD |
| Access/capabilities/security incident | System owner + security owner |
| Learning/evidence/gates | Learning owner + functional safety owner |
| Economy/USCOIN | Business/economic owner |
| Technical migration/recovery | Technical owner |
| Production go/no-go | Product + technical + security owners |

## 12. Current go/no-go

Аудит письменно принят 2026-08-23. P0 public HR-file exposure закрыт обратимым containment; production code/config permission hardening выполнен по отдельному узкому разрешению. Fresh backup, isolated runtime, negative/positive role-boundary tests и независимый full isolated DR прошли. `core_publicpaths` disclosure не воспроизведён; AI health ERROR локализован до конфликта HR field ↔ tenant identifier. **Production release остаётся NO-GO**, потому что отдельное production approval не дано и остаются production default-role remediation, AI tenancy/TARGET/HRD decisions, dependency security, asset licence gate и production rollback/RPO/RTO gates.

Дополнительный repository gate: ветка существует локально, но у control-repo не настроен Git remote. До push владелец должен указать canonical GitHub repository; исторические ссылки не принимаются за разрешение на публикацию.
