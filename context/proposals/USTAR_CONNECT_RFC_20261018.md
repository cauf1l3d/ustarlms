# RFC — USTAR Connect: единый внешний коммуникационный и Mini App контур

- **Статус:** PROPOSED / design-only; не ADR Accepted, не production release.
- **Дата:** 2026-10-18.
- **База репозитория:** main @ d9655074db9a06281fe16f378dbdbe1d279104a0.
- **Датированная база приложения:** PR76 / e1d57f5bc28f6964afd20aeff7620345b34a80fe (526 файлов local_ustar/theme_ustar); не путать с текущим Git HEAD.
- **Инициатива:** единые Telegram / Bitrix24 / WhatsApp каналы, SSO-привязка, персональные Mini Apps, уведомления, HR-процессы, подразделения и конечные эскалации, без открытия входящих портов на Academy server1.
- **Реализация:** ещё НЕ начата. Настоящие токены, external gateway, trusted TLS, провайдерские аккаунты, тесты в production и операторские подтверждения отсутствуют.
- **Связанные инициативы:** INF-06 (операторский пульт + отдельный привилегированный Telegram), INF-07 (HTTPS), INF-12 (ACL/security), MOB-01 (mobile ADR), PRIVACY-01 (privacy), OPS-01 (route refresh), PERF-01.

## 1. Привязка к существующим решениям

1. Moodle core: canonical users/auth/capabilities, course/SCORM/Quiz completion, core_message и File API; без core patch.
2. local_ustar: кадровый lifecycle, организация, route/scope/progress, assessment lifecycle, adaptation, task/checklist flow, workflow notifications, USTAR rewards.
3. theme_ustar: native UI; legacy frontend/Next.js не активировать.
4. Внешний чат не заменяет Moodle core_message и не создаёт вторую историю корпоративных сообщений.
5. Действующие источники: context/architecture/boundaries.md, context/architecture/organization_resolution.md, context/architecture/notification_delivery_20260928.md, context/architecture/route_read_contract_20260928.md; ADR-0002, ADR-0006, ADR-0012, ADR-0010.
6. Действующие пути/классы: moodle/local/ustar/classes/access_context.php, capabilities.php, organization_identity.php, hr_access.php, assessment_lifecycle.php, adaptation_service.php, task_workspace/worker.php, task_workspace/policy.php, workflow_notifications.php, communication.php, route_model.php. XMLDB: db/install.xml + db/upgrade.php.
7. INF-06 — операционное управление сервером, его Telegram admin/operator bot, backup и SFTP. USTAR Connect — пользовательские учебные/HR действия. **Не объединять токены, сервисные учётки, ACL или сообщения с INF-06**, даже при совместном использовании общего инфраструктурного транспортного кода.

## 2. Функциональный принцип

Один доменный command/event contract, несколько transport adapters:
- Telegram bot + Telegram WebApp / Mini App.
- Bitrix24 bot + Bitrix24 portal UI/link (точные возможности в конкретной редакции подтвердить).
- WhatsApp Business Platform adapter: limited messages + signed actions/secure HTTPS links, не заявлять Telegram-style native Mini App там, где её нет.
- В будущем дополнительный channel adapter без новых бизнес-таблиц и не переписывая domain handlers.

**«Один бот» — одна логика, а не один provider-specific bot ID.** Физически отдельные credentials, endpoints и ограничения платформ.

Общая цепочка: provider update → transport validation → provider identity mapping → canonical Moodle actor resolution → capability + active employment + current org scope → approved business query/command → domain event/notification → delivery outbox → connected channel(s) → audit.

Provider metadata никогда не даёт привилегий: chat_id/phone/Bitrix ID, должность и view-as недостаточны для authorisation.

## 3. Назначение компонентов (proposed)

**Academy backend (изменения строго в local_ustar):**
- connect identity/link service (mapping на Moodle user ID).
- API command/read facade, вызывающий существующие домены, не прямой SQL для business mutation.
- notification fan-out service поверх существующего local_ustar_notifications + local_ustar_notify_delivery, не новый независимый центр уведомлений.
- escalation policy adapter: materialize only domain-authorized escalation events; не пересчитывать достижения/оценки при GET.
- privacy/visibility classifier + audit.
- Moodle scheduled tasks или проверенный bounded worker для outbox/retry; не запускать второй Moodle cron.

**Transport worker (минимальные привилегии):**
- Telegram long poll, Bitrix fetch poll; исходящий HTTPS из server1.
- Строгий provider-specific adapter интерфейс для parse/ack/send/edit messages.
- Никакой прямой SQL в Moodle, root, SSH shell, Docker socket, backup storage.
- Durable offsets/idempotency receipts; подтверждение provider offset только после durable accept. Учесть доступность и provider retention.
- При отсутствии связи worker устойчиво восстанавливается, не дублирует исполнение действий.

**Public HTTPS edge (опционально в первом релизе, обязательно для Mini Apps / callback provider):**
- Публикуется только минимальный connect API + Mini App UI, не panel/Apache admin/DB/область Moodle целиком по умолчанию.
- Вариант: HTTPS edge на отдельном узле + исходящее соединение от server1; или outbound-only managed tunnel с access policies. Не выбирать провайдера до теста достижимости из России, DNS/TLS, организации и обработки данных.
- Входящие порты на server1 и корпоративном маршрутизаторе не открываются. Публичный HTTPS адрес у edge/провайдера всё равно существует.
- Если требуется полное Moodle Quiz/SCORM вне LAN: отдельный gate по trusted HTTPS, canonical wwwroot, session/cookies, File API, link integrity, CSP, WebView compatibility, proxy hardening, ACL и нагрузке; узкий Mini App API сам по себе не обещает полный native LMS browser parity.

## 4. Аутентификация и привязка

1. Первый bind: сотрудник проходит настоящую аутентификацию Moodle (в доверенной LAN-сессии или через принятый HTTPS auth gateway), затем подтверждает одноразовую короткоживущую challenge на конкретный provider account/device. Альтернатива — HR-инициированное приглашение со step-up-подтверждением, без выдачи секретов HR в чат.
2. Telegram: сервер проверяет signed initData (HMAC / актуальный алгоритм Telegram), auth_date, replay nonce, ограничения TTL; не доверяет initDataUnsafe и никнейму.
3. Bitrix24: tenant + provider user identity и проверенное событие из доверенного bot API; принадлежность порталу сама по себе не связывает Moodle actor. Для write step-up/secure web session.
4. WhatsApp: подтверждённая привязка account/phone по независимому фактору; совпадение телефонного номера с Moodle-профилем недостаточно.
5. Токены провайдеров — service secrets, не user auth и не Moodle sessions. Пользовательские short-lived sessions хранятся/ревокируются в доверенной auth boundary; не передавать Moodle session cookie через чат.
6. Для каждого запроса актуально проверять moodle deleted/suspended, employment active, capabilities, scope, object ownership и view-as write refusal. При увольнении/блокировке — немедленная потеря полномочий на новых запросах; revoke linked sessions и pending actions.
7. Для HRD / HR / критических кадровых write — step-up (MFA/reauth/explicit confirmation в доверенном UI), server CSRF и request binding.
8. Никогда не показывать/сбрасывать/передавать действующий пароль от Академии через бот. В «Учётных данных» можно показать только безопасную информацию об учётной записи и процесс восстановления.

## 5. Role-to-feature matrix (функциональный scope, не grant)

| Актор | Чтение | Запись |
|---|---|---|
| Сотрудник | собственный маршрут, результаты, курсы, задачи, чек-листы, XP/KPI | только личные подтверждения/чек-листы/заявки по существующим правилам |
| HR | разрешённые сотрудники/материалы, HR-аналитика, проверки | оценивание при gradeassessments/hrmanage; существующие HR-команды при отдельных capabilities |
| HRD | HR scope + HRD-only adaptation + private development analytics при developmentanalytics | HRD decisions только при manageadaptation; approvals только при approveregistration и остальных проверках |
| Руководитель | manager_scope через access_context / organization_model, не по строке «руководитель» | только разрешённые task/assessment/remediation операции в current subtree |
| Генеральный директор | company aggregate при executive/company access, без автоматического открытия приватных результатов | по умолчанию read-only; только действие с отдельной явной capability |
| Оператор INF-06 | server status/backup в отдельном операторском контуре | только INF-06 allowlisted operations — никогда не через HR/employee bot |

**NB:** local/ustar:executive обеспечивает чтение компании, но не подразумевает HRD-решения или доступ к приватным рефлексиям. Генеральному директору направляются только terminal severity events по явно принятой политике, а не все промежуточные уведомления. Нельзя объявить, что такая terminal executive escalation уже реализована.

## 6. Эскалации

Уже существует policy task_workspace/policy.php, уровни **direct → parent → hrd** (по умолчанию минуты 120/540/1080 в рабочем календаре), durable local_ustar_task_escalations и workflow_notifications. Сохранить этот state machine и не создавать второй scheduler по Telegram.

Поток:
- Бизнес-событие и сроки определяет USTAR;
- USTAR фиксирует recipient/cycle/idempotency key;
- connect fan-out доставляет в выбранные каналы и показывает receipt;
- уведомления могут быть acknowledged, но provider «delivered» не означает прочтение человеком или завершение задачи;
- при решении USTAR закрывает actionable item; при повторной доставке не возникает второго кадрового/учебного write.

**Конечные эскалации гендиректору** требуют новой отдельной политической спецификации: какие домены, severity, выдержка времени, HRD review, отсутствие персональных/закрытых данных и suppress/recovery. Запрещён глобальный catch-all всех просрочек или автоматическое продвижение HRD-состояний до CEO без explicit policy.

Delivery policy: user preferences and channel opt-in, quiet hours only for noncritical classes, configurable frequency/digests, no sensitive result body in a third-party notification by default, provider retry/backoff/dead-letter/manual replay, rate limits and per-user dedup.

## 7. Domain operations, UX и MVP

**Employee Mini App:** dashboard, маршрут с current point, материал/ресурс, личный результат и подтверждённое evidence, задачи/checklist quick forms, личные уведомления. «Пройти SCORM/Quiz» на первом этапе — переход на trusted full web origin; не копировать LMS evaluation logic в мессенджер.

**HR/HRD:** inbox «требует проверки» и case-level secure Mini App pages; команда оценивания и решения — только существующий сервис и canonical grade/evidence/assessment flow. Сводки read-only, ограниченный selector employees, права по capability. Материалы активных маршрутов читаются по опубликованной версии и текущему scope.

**Manager:** live current subtree, overdue and route coverage, task/review/checklist queue, эскалации уровня руководителя.

**CEO:** company-level aggregates и только специально выбранные terminal exceptions; без произвольного drill-down в private assessment.

**CRM:** в source отсутствует подтверждённый единый «CRM Академии» как отдельный домен. Определить, идёт ли речь о Bitrix24 CRM. Если да, контакт/deal/activity ownership остаётся в Bitrix24, а Connect запускает scoped actions через отдельный CRM adapter. Не создавать parallel deals/customers в Moodle без отдельного ADR и permission/data contract.

Никакой автоматической отправки файлов сотрудников или оценки в обычный мессенджер. Более детальные формы — secure Mini App после аутентификации.

## 8. Предлагаемые API-контракты

Read API (не делать GET мутирующим):
- connect/session/status — каналы, привязки, активная учётка;
- connect/me/home — агрегат авторизованного пользователя;
- connect/me/route — read-only route snapshot, не force refresh;
- connect/me/tasks — scoped queue;
- connect/team/overview — team/company scope;
- connect/hr/reviews — role-filtered queue;
- connect/notifications — статусы USTAR + Moodle, отдельно source IDs.

Write API (POST, user-bound, scoped, CSRF/replay protection):
- connect/link/issue и connect/link/confirm;
- connect/notifications/ack;
- connect/tasks/{id}/report;
- connect/hr/reviews/{id}/grade;
- connect/adaptation/{id}/decision.

Строки выше — **планируемые контракты**, не существующие endpoints. Каждый должен иметь versioned request/response schema, actor, target, operation capability, status, expected revision, idempotency key, bounded output, privacy classification, audit reason и typed errors. Никакого wildcard «выполнить метод Moodle» или произвольного PHP function invocation.

Outbox model references canonical notification ID + channel + provider recipient + status + attempt/backoff + provider ref. Использовать существующий local_ustar_notify_delivery как основу; миграция нужна только для доказанных недостающих полей. Inbox transport receipt references provider update ID and canonical business request ID; это дедуп transport, не второй store HR events.

## 9. Connectivity matrix

| Канал/функция | Без открытых портов server1 | Дополнительные условия |
|---|---|---|
| Telegram bot updates | Да: Bot API getUpdates long polling, один consumer | egress до Telegram, durable offset |
| Bitrix24 bot events | Да: imbot.v2 fetch / Event.get | конкретный Bitrix24 портал/редакция, scope imbot |
| Telegram Mini App | Да для origin, но требуется публично доступный HTTPS endpoint edge | initData validation, session binding, domain/TLS |
| Bitrix24 embedded view | Возможно через внешний HTTPS edge | проверить поддержку приложения/iframe/SSO данной редакцией |
| WhatsApp Business incoming | Нужен provider-reachable публичный HTTPS webhook или внешний relay | WABA/Meta account, политика и региональная доступность |
| Full Moodle Quiz/SCORM outside LAN | Отдельная фаза; reverse proxy/tunnel при accepted HTTPS | canonical wwwroot, cookie, files, CSP, security tests |

Для server1 с Apache HTTP→127.0.0.1:8082 HTTPS Академии пока **не принят**; Caddy сейчас нельзя считать доказанным TLS gateway LMS. Cloudflare Tunnel, Tailscale Funnel, собственный edge с исходящим WireGuard/reverse tunnel — кандидаты, а не сделанные настройки. Для РФ обязательно проверить route quality, доступность провайдера, устойчивость к блокировкам, DNS, data residency и 152-ФЗ с юристом/оператором ПД.

WhatsApp в РФ сильно ограничен по состоянию 2026 года; это риск product viability и тестов, не просто вопрос webhook.

## 10. Threat model и operational gate

- **No inbound LAN firewall** ≠ «не опубликован API»: edge публичен. Защита edge: HTTPS, narrow routing, WAF/rate limits, signed callbacks, identity/session, no host-admin URLs.
- Revocation: увольнение, suspension, смена позиции/ACTING, роль HRD→HR, provider unlink, stolen device, token rotation, disabled provider, incident response.
- Negative ACL matrix: employee→other person; HR→private dev results; manager→outside subtree; CEO→HRD write; suspended/pending actor→any read/write; read-only view-as→write; forged Telegram initData/Bitrix user ID/WhatsApp phone; replay/double tap; changed payload same idempotency key; stale revisions; expired challenges.
- Delivery: provider 429/5xx/network partition; bounded retries; skip/compensate duplicates; confirmed provider offsets only after durable ingest; no fake success; unknown/NoData is not green.
- Disclosure: avoid passwords, access tokens, full health/private development summaries, personal numbers, file URLs in notifications; narrow audit PII and retention.
- No internet loss of employee HR records: Academy remains canonical, integrations can degrade independently; delivery queues recover from reconnection.
- Benchmark performance and privacy; no unbounded full workforce scan on every Telegram message.
- No simultaneous new core/PG version, no activation of legacy frontend, no interruption of existing host mail/DNS/ISPConfig.

## 11. Delivery slices and acceptance gates

**CON-00 — reviewed ADR + product contract** (this RFC, status proposed)
- confirm Bitrix edition/portal, WABA feasibility, public edge/provider, employee devices, requirements of CRM, data/legal policy and approved escalation thresholds.
- freeze event taxonomy, role matrix, API contracts and data classification; security review.

**CON-01 — core identity + Telegram MVP (internal first)**
- bind via Moodle-confirmed one-time challenge; Telegram polling, /start, /me, /tasks, /route; minimal notifications from durable USTAR outbox.
- denial/revocation tests, no credentials; production-like isolated test suite; no external public UI needed.

**CON-02 — complete notification lifecycle + Bitrix24 fetch**
- shared dedup/outbox, retries/recovery, channel preferences; Bitrix24 bot events via imbot.v2.
- E2E same notification/ack state across channel and Academy; no duplicate task writes.

**CON-03 — external secure Mini App & employee quick actions**
- trusted HTTPS edge, safe auth, read-only dashboards then user-bound checklists/reports; mobile browser tests with Russian networks.
- SCORM/Quiz only after native Moodle access/gateway and canonical URL/cookie/file audits pass.

**CON-04 — HR/HRD/manager dashboards and restricted actions**
- HR grading, adaptation reviews, team control; direct object negative tests and protected result policy. Distinct business capabilities enforced for each operation.

**CON-05 — executive terminal escalations, reporting & WhatsApp gated adapter**
- selective critical event policy, human escalation owner, acknowledgements and dashboards; WhatsApp added only if provider, regional delivery, privacy and acceptance are confirmed.

**Release evidence for every slice:** exact SHA, unit/DB install+upgrade+repeat tests, ABI/contract tests, Playwright/webview where applicable, incident/retry replay, role matrix, log privacy, perf budget, runbook, migration/rollback, OPS-01/Fresh production preflight and signed owner acceptance. Context STATE/ACTIVE/BACKLOG/SESSION_LOG and code map update when an implementation is actually accepted, not upon this design PR.

## 12. Open decisions requiring explicit acceptance

1. Public gateway: managed tunnel vs company-controlled reachable edge in Russia; domain and TLS trust; whether origin is allowed to serve full Moodle via secure proxy.
2. User activation: initial bind from LAN vs secure public login; MFA/step-up policy.
3. Which existing LMS/Bitrix/WhatsApp accounts and provider terms are available; Bitrix edition and CRM ownership.
4. Personal data policy and approved cross-border disclosures/retention; template message content.
5. SLA, quiet hours, thresholds, precise terminal CEO escalation graph.
6. Development effort for full SCORM/Quiz mobile parity vs phased secure deep links.
7. Whether minimal transport worker is managed by INF-06 operations panel for observability without inheriting its privileged operator permissions.

## Official technology references

- Telegram Bot API: https://core.telegram.org/bots/api
- Telegram Mini App validation: https://core.telegram.org/bots/webapps
- Bitrix24 Bot v2 fetch: https://apidocs.bitrix24.ru/api-reference/chat-bots/chat-bots-v2/quick-start.html
- Bitrix24 event delivery modes: https://apidocs.bitrix24.com/api-reference/chat-bots/chat-bots-v2/imbot.v2/events/index.html
- Cloudflare Tunnel egress-only connectivity: https://developers.cloudflare.com/cloudflare-one/networks/connectivity-options/
- Tailscale Funnel public reachability: https://tailscale.com/docs/features/tailscale-funnel
- WhatsApp webhook payload/requirement via Meta Postman: https://www.postman.com/meta/whatsapp-business-platform/folder/tduohwq/webhook-payload-reference

_Design-only PR: source inventory + external documentation informed RFC, no platform provisioning, secrets, runtime or schema changes._