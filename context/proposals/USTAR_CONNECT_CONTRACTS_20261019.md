# USTAR Connect — контракты и сопоставление с исходниками

**Точка продолжения19Oct15:31МСК:** C3 завершён на941a853cbfd03eaefd691fdbdf413607d9a92aba: Context39251446669/Review39251446110 SUCCESS;106native/530assertions,5concurrency/schema parity,661r16/133CI skips,24link/20onboarding/60mobile PASS. Не переоткрывать C1/C2/C3 по датированным записям ниже. Текущий C4 — отдельная provider-read Connect-сессия и собственный реальный профиль в Mini App; [план](USTAR_CONNECT_CON01C4_PLAN_20261019.md), [ledger](USTAR_CONNECT_CON01C4_PROGRESS_20261019.md). Full Moodle handoff/recovery/MFA/step-up/role actions остаются отдельными этапами. Native MFA/forced password/alternate login блокируют provider-read; default activation OFF. Новая реализация и native/browser CI ещё pending. Продакшен e1d57f5b/526files/93tables и main не менялись.0subagents/no live provider/network operations.


19Oct12:55МСК, [CON01C.2](USTAR_CONNECT_CON01C2_PLAN_20261019.md): CON01C.2: управление собственными messenger links в native Moodle и signed uc1_ claim в Mini App. Личный кабинет → Мессенджеры → персональная ссылка → Telegram → второй Academy confirm в той же сессии; owner-only revoke/list. RAM receipt не onboarding/employee grant; timeout uncertain без автоповтора. Candidate2026101903/546files/99tables, production e1d57f5b/526/93 unchanged. 8new WSGI tests,31web/full647r16 OK/30environment skips. Native RED d9114daa/39234234916: exactly6missing-class errors,78previous tests pass. Browser RED2c971f93/39235237752: missing confirmation screen; prior20onboarding/60mobile pass. Final new native/browser CI receipt — PR106; читать exact final receipt перед продолжением, не переоткрывать C2 по старой pre-CI записи. Новый business bot @ustarcorp_bot, секрет из чата не использован/не сохранён, требуется перевыпуск владельцем. CrazeDNS em2025.netcraze.pro предложен; rule/cert/availability ещё не приняты. Full canonical remote login/recovery/MFA/actor step-up, canonical notification leases/delivery/preferences/ACK и role screens — остаток.0subagents/no main merge/no prod/router/network changes.

19Oct10:33МСК: [C1 receiver contract](USTAR_CONNECT_CON01C1_PLAN_20261019.md): only trusted fixed Telegram API update→private command→existing launch.create, Bot API send receipt и local transport cursor. Не public webhook handler или caller actor grant. Main Mini App direct startapp даёт signed start_param; create/exchange имеют один bridge key ID. Canonical notifications, remote HRD actor и full login этим срезом не реализуются. Exact evidence PR106.

19Oct09:55МСК update: private backend B3 verified78native/399; [CON01B.4 browser contract](USTAR_CONNECT_CON01B4_PLAN_20261019.md) реализует узкие /api/bootstrap, launch, options, status, register, logout на private worker, не полный /connect/v1 API ниже. HttpOnly/CSRF-bound volatile onboarding cookie, server-owned command, no public launch.create/bridge secrets/employee capability. Real receiver/edge/deploy/actor step-up отсутствуют; exact CI receipt PR106.

19.10.2026, Europe/Moscow. **Public API PROPOSED; CON01B.3 private canonical signup candidate**, приложение к [RFC](USTAR_CONNECT_RFC_20261018.md). Публичные пути API ниже ещё не реализованы; отдельный private endpoint описан в конце. Исходный review основан на application baseline e1d57f5b; наличие AJAX external function не доказывает готовность безопасного публичного API.

## 1. Канонические владельцы операций

| Сценарий | Действующий источник в moodle/local/ustar | Что должен делать Connect |
|---|---|---|
| Регистрация/approval | classes/registration_service.php::register; staffing_requests.php::review; employment.php::approve_registration | Canonical pending user+request; HRD approveregistration + hrmanage, actual actor; no manager grant |
| Actor/employment/организационный scope | classes/access_context.php, capabilities.php, organization_identity.php, organization_model.php | Current actor, active employment, capabilities и актуальный scope; не role string из messenger |
| Личный маршрут | classes/route_model.php::read_only_snapshot, assessment_lifecycle.php::read_route_point | Read-only опубликованные version/cycle; не force refresh или sync на GET |
| Материалы маршрута | classes/content.php и Moodle File API | Проверить published/access/context; файловый URL не даёт публичного доступа |
| Аттестация Quiz, ручные ответы | classes/route_quiz_grading.php::manual_grade_batch; hr_access.php::can_grade/require_grader | Сохранить actual grader, finished attempt, pending slots, marks/comments, lock, view-as denial и canonical grade/evidence |
| Product Quiz | classes/product_quiz.php::manual_grade | Отдельный вид assessment; не отправлять его IDs в route Quiz writer |
| HR review сотрудника | classes/external/hr_save_review.php | Это performance review local_ustar_reviews, **не ручная оценка Quiz**; не путать endpoints |
| Решение по адаптации | classes/adaptation_service.php::manager_final_decision / hrd_final_decision | Верная стадия, actor, reason и capability; executive не становится HRD |
| Remediation | classes/assessment_lifecycle.php::authorize_remediation/start_remediation | Сохранить авторизацию и канонический lifecycle; не сбросить progress прямым SQL |
| Рабочие task/checklist отчёты | classes/task_workspace/service.php::report, can_review_record и domain commands | Expected revision, собственный assignee/reviewer, locks, audit; scope не обходится callback кнопкой |
| Персональные операционные чек-листы | classes/external/submit_checklist.php → checklist_service::submit | Это отдельный существующий контракт, не тот же task_workspace object; подтверждённый текущий user |
| HR структура/кандидаты | classes/hr_access.php, staffing_requests.php и существующие кадровые services | Recruiter restrictions и раздельные capabilities; нет universal hr.write endpoint |
| Личный inbox и доставка | classes/workflow_notifications.php; target_core.php; tables local_ustar_notifications/notify_delivery | Один notification ID + channel receipt; старые Bitrix pending не активировать автоматически |
| Task эскалации | classes/task_workspace/policy.php, worker.php, local_ustar_task_escalations | Не менять defaults/cron при подключении канала; CEO — отдельное proposed расширение |
| Company/private аналитика | capabilities.php::COMPANY_READ; development_assessment.php | Company-read не раскрывает private development answers без developmentanalytics |
| Чаты между людьми | classes/communication.php + Moodle core_message | Connect business bot не копирует переписку и не создаёт второй корпоративный messenger store |

Особенно важно: manual_grade_batch проверяет, что graderid совпадает с реальным Moodle USER. Background worker не может передать graderid HR в запросе, оставаясь системным администратором. Facade сначала аутентифицирует delegated user/session и создаёт корректный пользовательский request context; изменение writer для обхода этой проверки запрещено.

## 2. Предлагаемый versioned API

Префикс `/connect/v1` логический; точная Moodle routing/transport реализация определяется implementation plan. Client не выбирает произвольные PHP function names. Default actor — текущая server-verified session; target IDs являются объектами проверки, а не идентичностью caller.

| Endpoint | Результат/команда | Проверка |
|---|---|---|
| POST /onboarding/sessions | Ограниченная bootstrap session | Verified provider + browser nonce/replay; не employee grant |
| POST /onboarding/registrations | Canonical user+pending request | CSRF/rate limits, canonical validation, outer transaction/lock/receipt, no duplicate user |
| GET /onboarding/registration | Только собственная заявка | Pending onboarding session, без бизнес-данных |
| POST /registrations/{id}/decision | Canonical approve/reject | Academy actor + step-up + approveregistration + hrmanage + state/lock |
| GET /session | Текущий user, capability-filtered features, links, server time | Valid session, current employment |
| POST /links/challenges | Одноразовая challenge для channel/tenant | Свежая Academy reauth, CSRF, rate limit |
| POST /links/confirm | Подтверждённая связь | Proof обеих сторон, TTL, uniqueness, replay |
| POST /links/revoke | Отзыв связи/сессий/pending actions | Own identity либо отдельная approved admin recovery policy |
| POST /sessions/provider | Connect session из проверенного launch proof | Provider verification + active mapping, nonce/TTL |
| POST /sessions/handoff | One-time ticket для доверенного Moodle origin | Session/browser binding, allowed return path; не bearer login из chat URL |
| GET /me/home, /me/route, /me/results | Личный dashboard и текущая версия/цикл | Self only; read не меняет бизнес-состояние |
| GET /me/work | Личные задачи и оба различимых типа checklist | Current assignee/scope, pagination |
| GET /team/overview | Команда или разрешённый company overview | access_context current scope, no arbitrary department override |
| GET /hr/reviews | Типизированная очередь pending assessments | can_grade + object/type scope, private data filter |
| POST /assessments/{kind}/{attempt}/grade | Canonical grading command | Step-up, exact writer per kind, finished attempt, revision/state, idempotency |
| POST /tasks/{id}/report, /tasks/{id}/review | Действующие domain commands | Assignee/reviewer, expected revision, step-up согласно риску |
| POST /checklists/{id}/submit | Персональный checklist_service submit | Own assignment/date/context, replay guard |
| POST /adaptations/{id}/decision | Manager/HRD решение | Current stage + capability + actor + reason, step-up |
| GET /notifications; POST /notifications/{source}/{id}/ack | Namespaced inbox и ознакомление | Recipient/self, ack не закрывает business object |
| PUT /me/delivery-preferences | Основной канал/fallback/digest/quiet hours | Own identity/valid channels; mandatory domain policies не отключаются |
| GET /commands/{id} | Receipt принятого запроса | Actor/permission check; никакого чужого payload |

Uploads и File API не являются generic URL fetch. File token, если нужен, короткий, scoped к actor/object/version и не обходящий последующую проверку доступа. CRM означает Academy task_workspace и /me/work, /tasks; отдельный Bitrix CRM adapter не нужен.

### Общая форма команды

Поля: api_version, request_id, operation, target{kind,id,cycle/version}, expected_revision либо domain state token, expires_at, idempotency_key, validated payload. Actor и provider tenant добавляются доверенной server boundary, не принимаются из client JSON как grant. CSRF обязателен для cookie-session; provider ingestion отдельно защищён signature/origin/replay правилами.

Ответ: command_id, outcome (succeeded/rejected/accepted_pending), canonical object ref/revision, observed_at, typed error и разрешённый next action. `accepted_pending` не показывать как сохранённую оценку. Command idempotency и результат доменной записи должны фиксироваться согласованно; если операция уже выполнена при потере ответа, повтор возвращает её receipt.

Errors: unauthenticated, unlinked, inactive_actor, forbidden_scope, step_up_required, expired_action, stale_revision, idempotency_conflict, invalid_payload, source_unavailable, rate_limited, uncertain_delivery. 409/stale не превращается в автоматическую перезапись новой версии.

## 3. События и состояния

Event envelope: schema_version, event_id, event_type, domain, object_ref, cycle/version, occurred_at, actor_ref если применим, audience policy reference, privacy_class, payload reference. Это один доменный факт; per-recipient notification ID и per-channel delivery ID производны от него. Полный assessment answer не помещается в transport envelope.

| Семейство | Примеры нормализованных событий | Canonical owner |
|---|---|---|
| Обучение | route.assigned, learning.due, assessment.needs_review, assessment.graded | route/assessment services; типы ниже являются proposed taxonomy, не уже существующими event names |
| Задачи | task.assigned, task.reported, task.review_due, task.escalated, task.resolved | task_workspace |
| Адаптация | adaptation.assigned, adaptation.decision_required, adaptation.decided | adaptation_service |
| Кадры | employee.activated, employee.suspended, employee.assignment_changed | employment/staffing services |
| Terminal исключения | escalation.terminal_candidate, escalation.executive_sent, escalation.resolved | Расширенная политика конкретного domain, disabled до принятия |
| Identity | channel.linked, channel.revoked, session.revoked | Connect identity service |

Existing event names сохраняются при adapter mapping; переименование старой истории или создание фиктивного события на GET запрещены. Бизнес-получатель определяется canonical policy; provider mapping выбирает способ доставки, а не нового получателя.

Delivery flow: disabled / pending → leased → provider_accepted либо retry_wait / uncertain / failed; obsolete/revoked события → cancelled. Provider-specific delivered/read могут поступить позже; acknowledgement относится к пользователю, business_resolved — к домену. Числа попыток/nextattempt/providerref уже есть в notify_delivery; lease/cancel/error classification/schema delta определяются до XMLDB migration.

Предложены bounded настройки: UI commands TTL120с до durable business accept, link TTL10мин, handoff60с; interactive web timeout выдаёт command receipt/status lookup, не повторяет действие новым ключом. Notifications имеют domain-defined expiry, а не общий бесконечный срок. Concrete retry/retention/limits публикуются до activation и проверяются на отказах, не подменяются маркетинговым SLA.

## 4. Минимальная модель и миграция

Планируемые новые сущности: channel identity link/challenge, Connect session/revocation, provider inbox receipt/cursor, command idempotency receipt, личные delivery preferences. Это метаданные связи/исполнения, не вторая модель сотрудников/оценок/чатов. Точные таблицы/индексы утверждаются через XMLDB schema review; все существующие business tables сохраняются.

Уникальности: active provider+tenant+subject; один active link на user/channel в первом релизе; provider+tenant+event_id; actor+operation+idempotency_key с payload hash; existing notification+channel. Foreign object IDs всегда type-qualified. Epoch/revocation проверяется до выдачи user session и перед mutation; роль в долгоживущем JWT не служит актуальным grant.

Backfill не отправляет старые pending/disabled, не переносит root allowlist пульта и не связывает людей по ФИО/телефону. Legacy mappings — только подтверждённый preview, проверка владения и opt-in. DB rollback — парный restore/совместимая migration strategy; downgrade файлов после schema upgrade не называется откатом.

## 5. Приёмочная матрица будущей реализации

- Employee→чужой route/result/file; manager→вне subtree; HR→HRD decision/private analytics; CEO→HRD write: отказ по API и прямому URL.
- Deleted/suspended/terminated/pending, stale session, HRD→HR, новый отдел, unlink: отказ и отмена устаревших actions/deliveries.
- Forged initData/callback/tenant/phone, reused/expired challenge, login CSRF/open redirect: отказ без side effect.
- Double tap/два канала, changed payload same key, stale revision, timeout после commit: одна canonical оценка/запись.
- File upload target substitution, revoked direct file URL, чужой draft, недопустимые тип/размер: отказ.
- Provider429/5xx/outage, connector restart, sender timeout, lease expiration, retry после решения: явные статусы и bounded recovery.
- Telegram gap после24ч, Bitrix cursor replay, legacy worker overlap: нет фиктивной полноты или двойного execution.
- LAN работает при отключении connector/edge; before/after default route/resolver и доступ Академии совпадают; uninstall не требует ремонта сети.
- Full LMS gate: login/logout, cookies/CSRF, Quiz save/resume/grade, SCORM progress, files/video ranges/uploads, mobile/WebView и office fallback.

Эти проверки — критерии CON-01..05. CON-01A проверяет только offline identity/proof/code primitives. DB/registration/replay-consumption/role/provider-delivery/network acceptance ещё не выполнена.

Первое касание и общий одноразовый код уточнены в [review](USTAR_CONNECT_REVIEW_20261019.md). Формат uc1_,256бит; TTL600с и atomic consume реализуются в будущем Moodle storage, не в pure hash helper.

## Реализация CON01B.1

Внутренний classes/connect/identity_service: issue → claim_verified → confirm, revoke, resolve_active_user.3metadata tables и XMLDB candidate2026101900; [план](USTAR_CONNECT_CON01B_PLAN_20261019.md). Это не реализация публичных /links или /onboarding endpoints из таблицы выше. claim_verified требует будущей trusted transport boundary; не вызывать из browser JSON. Current capability/scope конкретных бизнес-команд проверяется их facade/domain writer, link не хранит роли.

## Реализация CON01B.2

[Точный bridge/entry contract](USTAR_CONNECT_CON01B2_PLAN_20261019.md): POST `/local/ustar/connect_bridge.php`, disabled default, private peer+TLS+scoped service HMAC. Только launch.create/exchange, entry.status/revoke, link.claim. Транспортный nonce durable; launch single-use и bound к provider subject; signed initData.start_param обязателен. Browser-bound entry TTL600с со scope onboarding — не Moodle login и не business capability. Caller userid/role запрещены. Candidate2026101901/539files/98tables; privacy/cleanup для новых metadata. Canonical signup receipt/HRD approvals/outbox и public endpoints выше всё ещё не реализованы. Старые CON01B.1 строки — dated implementation checkpoint, не отсутствие нового private bridge.

## Реализация CON01B.3 —19Oct05:30

[План](USTAR_CONNECT_CON01B3_PLAN_20261019.md), [ledger](USTAR_CONNECT_CON01B3_PROGRESS_20261019.md). Private bridge добавляет registration.options/submit/status. Canonical registration writer получает internal completion callback внутри собственной transaction/email lock; account/pending/request/HRD notice и Connect link/receipt/own entry фиксируются вместе. New metadata table local_ustar_conn_signups, candidate2026101902/542files/99tables, no production install. Exact typed form HMAC под private bridge key; ur1_ command и same-form replay, no automatic existing-user linking. Current request status берётся из registration_service, без копирования HR state. HRD review/уведомления переиспользованы, не новый generic remote approval API. Public forms/BFF/actor step-up/provider sends остаются следующим срезом. Эта запись заменяет прежнее отсутствие backend signup/HRD integration, не заявляет готовность бота целиком.
