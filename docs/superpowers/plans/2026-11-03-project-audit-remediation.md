# USTAR — план исправлений по аудиту 03.11.2026

> Исполнение: последовательно одним агентом, без субагентов. Перед реализацией конкретного пакета прочитать текущие STATE/ADR и применить native executing-plans workflow. Публикация плана не разрешает новый merge, изменение production или provider sends.

**Цель:** устранить подтверждённые ошибки, ограничить стоимость чтения, доказать готовность к расширению внутри компании и подготовить отдельный внешний пилот.

**Архитектура:** Moodle остаётся владельцем auth/users/File API/native learning; local_ustar — бизнес-правил, theme — представления. Все изменения идут через существующие canonical writers, без второй базы сотрудников, балансов, прогресса и сообщений. Нужны небольшие исправления и выделение readers, а не перепись платформы.

**Стек:** существующие PHP/Moodle/PostgreSQL, Mustache/JS/SCSS, Python Connect/console, owner-executed operators.

**Основание:** [аудит и30находок](../../../context/reviews/20261103/AUDIT_RU.md), [машинный реестр](../../../context/reviews/20261103/findings.json). Application baseline `5e61c0d2`, worker `267e5587`; metadata receipt `a1dfe7c2`. Перед исполнением проверить отсутствие нового source drift.

## Ограничения для каждого пакета

- Не менять реальные права ради скрытия UX-ошибки. Actor, capability, current scope, sesskey, revision, locks и idempotency сохраняются.
- Грант не заменяет ACL. Независимый обязательный маршрут не начинает требовать покупку.
- Сохранить уже оплаченный доступ, исходные SCORM/Quiz attempts, grades, evidence, награды и кадровую историю.
- `view-as` не пишет. Состояние клиента и callback не предоставляют полномочие.
- Frozen installers/archives не редактировать. Любая поставка — новый immutable successor с точным baseline, check/apply/recover и журналом.
- Schema delta требует XMLDB install/upgrade/repeat parity; старые миграции не переписывать. Новые storage projections/tenant model/locking architecture — только после ADR.
- Не считать mocks/DOM успешным native SQL или полноценной browser/owner-приёмкой. Не удалять историю failed jobs/backoff.
- Agent SSH, provider sends и автоматические новые merge запрещены. Production шаги делает владелец после конкретного подготовленного пакета.

## Что может потеряться на границах и обязано попасть в тесты

1. Сотрудник переведён в другой отдел между чтением и POST: write снова проверяет текущие права — T01/T03/T06.
2. Два гранта одного оффера, один revoked/future, плюс доступ из маршрута: дедупликация не теряет действующий путь — T01/T03.
3. Поиск/страница за ACL-границей: hidden items не попадают ни в count, ни в next cursor — T01/T03.
4. Два автора и публикация/перемещение одновременно: lock optimisation не меняет revision/sourceToken и не стирает черновик — T04.
5. Полный/повреждённый transport journal и повтор update после restart: нельзя повторить бизнес-команду/рассылку ради восстановления доступности — T03.

## Порядок и gates

| Волна | Пакеты | Результат | Условие перехода |
|---|---|---|---|
| A — исправления действующих сценариев | T01,T02 | Нет пропавших материалов/тупиковых CTA; банк расширяется | Native negative/positive tests и owner smoke |
| B — запас мощности | T03,T04 | Ограниченные reads, безопасный Connect headroom, быстрый Studio lookup | Измеренные query/size/concurrency budgets, parity |
| C — расширение отделов | T05,T06,T09 | Приняты роли и нагрузка; source/evidence согласованы | Реальный пилот и принятые риски, отдельное merge-разрешение |
| D — внешний пилот | T07,T10 | Изолированная установка, data lifecycle, restore, perimeter | ADR и эксплуатационные критерии; затем решение о публикации |
| По доменным изменениям | T08 | Меньше связности и стоимости review | Один extraction с характеризацией за выпуск |

Пакет — единица планирования, а не один гигантский PR. Подпакеты T03a–d/T07a–d должны иметь независимые тестируемые результаты. Размеры S/M/L ниже — относительная сложность, не обещание календарного срока. Точные сроки появляются после baseline T05 и решения о внешней модели.

## T01. Доступность всего назначенного контента и корректные CTA — M

**Закрывает:** U01,U02,U03. **Владелец:** backend + UI; приёмка — сотрудник/руководитель/редактор.

**Файлы:** `moodle/local/ustar/classes/library_view.php`, `library_grant.php`, `library_access.php`, `library_runtime.php`, `moodle/local/ustar/library_manage.php`, `library.php`, `templates/library_catalog.mustache`, `library_checks.mustache`, `library_manage.mustache`; тесты в `moodle/local/ustar/tests/` и `tests/release_20260912/`.

**Контракты:** действующие публичные `library_view::mine/catalog/checks` сохраняют формат карточек; добавить явные поля для допустимого действия/next cursor. Reader может принимать готовый scoped контекст, writer всегда проверяет права заново. Поисковые endpoint используют текущую author/grantor boundary, а не глобальный каталог пользователей.

- [ ] Создать failing native `test_mine_finds_oldest_of_501_active_grants` и `test_catalog_locked_card_has_no_forbidden_marketplace_action`; адаптировать audit fixture из `probes/library_reads.php`, но выполнять реальные Moodle records и capability assignments.
- [ ] Добавить `test_management_selector_keeps_selected_subject_beyond_first_500` и `test_history_can_find_101st_grant`; проверить grants с expiry/revoke и поиск недоступного сотрудника.
- [ ] Для mine перенести фильтрацию и дедупликацию до page boundary; отказаться от скрытого cap500. Сохранить материалы из истории и saved feed как независимые источники с явной навигацией, не читать бесконечный список в память.
- [ ] Для закрытого marketplace формировать допустимый статус/действие назначения. Существующий `can_view_marketplace` и прямой URL guard не ослаблять.
- [ ] Добавить server-paginated scoped subject/person/history search; текущий selected item загружать отдельно с проверкой ACL. Инвариант: невозможно выбрать чужой субъект даже прямым POST.
- [ ] Запустить native Library suite, DOM навигацию и выборочные реальные account cases на staging. Включить500/501/2000 grants, transfer-between-GET-and-POST, two-grants, hidden-count.
- [ ] Commit/PR с before→after и точными tests; поставка после отдельной квалификации общего кандидата.

**Готово:** старый доступ обнаружим, CTA никогда не ведёт в заведомо закрытую витрину, все административные записи доступны через поиск, ACL до totals/pagination сохраняется.

## T02. Авторские формы, навигация и понятная совместимость — M

**Закрывает:** U04,U05,U06,P06. **Зависит:** T01 для read-model полей.

**Файлы:** `library_manage.php`, `library.php`, `templates/library*.mustache`, `classes/library_assessment.php`; новый небольшой AMD module для repeatable question editor по существующим паттернам; `course_studio/model.js`, `editor.html`, `material.js`, `docs/COURSE_STUDIO_RU.md`. Новый JS добавляет presentation behavior, серверный writer остаётся `library_assessment::save`.

- [ ] Failing DOM `test_add_sixth_question_and_preserve_correct_answer`; native `test_question_removal_does_not_shift_correct_index`; покрыть пустой slot внутри options и лимит100.
- [ ] Добавить кнопки вопроса add/remove/move с устойчивым client key и нормализацией submitted indices только в одном месте; отображать limit/error без потери введённого текста. Существующий strict validator не ослаблять.
- [ ] `test_catalog_search_preserves_parent_and_back_context`, `test_previous_page_preserves_query`, `test_no_matches_differs_from_no_published_checks`: добавить parent/return filters, previous/current page и разные empty states.
- [ ] Для revoke/refund показать review конкретного actor/target/material/amount/effect; cancel0writes, confirmed POST прежним writer, stale/replay отказ. Не добавлять новую систему финансовых транзакций.
- [ ] В import preview вывести результат проверки пакета и границы: редактируемый USTAR/static conversion/unsupported original. Объяснить copy→draft→publish→explicit route binding. Не обещать roundtrip исходного JS/SCORM2004 sequencing.
- [ ] Проверить текущие Word/PDF/static-SCORM, sourceToken,revision,shared draft,keyboard и export fixtures; package corpus с неподдержанными media/script элементами должен отказать объяснимо.
- [ ] Отдельный небольшой PR формы и PR import/navigation при необходимости; documented acceptance в инструкции автору.

**Готово:** 6+ вопросов создаются штатно; автор видит назначение черновика и формат; пользователь сохраняет контекст и может отменить опасное действие.

## T03. Пакетные чтения и ресурсный предел Connect — L, четыре независимых подпакета

**Закрывает:** E01,E02,E03,E07. **Зависит:** T01 contracts; измерения T05 полезны для приоритета, но доказанные DB-call slopes не требуют ожидания production нагрузки.

### T03a — marketplace

**Файлы:** `classes/library_access.php`, `library_store.php`; существующие native Library tests.

- [ ] Native `test_storefront_query_budget_and_acl_parity` с24/240/2400 offers, mixed content/catalog/assessment, shared ancestors, inactive actor и grants. Считать `$DB->perf_get_reads()`; отдельно вернуть IDs/count/cursor, без числа из synthetic adapter как эталона PG.
- [ ] Внутри `resolve_many` подготовить один actor snapshot и batched subject/grant/ancestor data; private evaluator принимает эти данные. Single `resolve` делегирует тому же evaluator, чтобы исключить divergence.
- [ ] Для page retrieval применить bounded candidate windows с ACL evaluation; next cursor и count должны иметь точную согласованную семантику. Если exact total слишком дорог, не показывать неточный как точный — принять UI contract до реализации.
- [ ] Assert: при фиксированной странице и одинаковом типе/scope набор reads не растёт линейно с общим каталогом. Старые deny reasons и видимость не меняются. Fresh/upgrade/repeat при любых новых индексах.
- [ ] Commit после native PostgreSQL parity и source checks.

### T03b — mine/catalog/checks

**Файлы:** `library_view.php`, `library_runtime.php`, `library_assessment.php`, при необходимости existing content/catalog readers.

- [ ] Query budget для каждой вкладки; ancestor depth1/8/64, cyclic parent, latest revision attempts, повтор одного subject.
- [ ] Убрать duplicate offer/definition reads; batch best results и ancestry; не менять native enrolment/route entitlement на «бесплатный offer открывает всё».
- [ ] Проверить page/data parity и T01 regression suite, затем отдельный commit.

### T03c — HR/manager list

**Файлы:** `library_manage.php`, `library_access.php`, `access_context.php`, `organization_directory.php`, `organization_model.php`.

- [ ] `test_grant_picker_resolves_actor_scope_once` для non-admin HR и manager с10/100/1000 людьми; отдельно `test_transfer_after_picker_read_denies_submit`.
- [ ] Для reader переиспользовать вычисленный scope/eligible map, batch hydrate identities; не кешировать доступ между запросами без invalidation contract. Actor-aware search возвращает только свою область.
- [ ] Запись `assign/revoke` сохраняет текущие проверки после lock. Native tests acting/expired/ambiguous hierarchy и запросы не растут квадратично.
- [ ] Commit; сравнить профили SQL на representative staging.

### T03d — Connect headroom/recovery

**Файлы:** `connect-worker/ustar_connect/receiver_journal.py`, `receiver_runtime.py`, соответствующие observer/probe/health views и `tests/r16/test_connect_receiver.py`. Frozen installer/unit исходники не менять ради текущего хоста; новый worker/operator только после квалификации.

- [ ] Превратить capacity probe в regression:10k terminal recent events, subsequent begin, restart,duplicate update,uncertain,rate window,disk full. Сначала зафиксировать существующий отказ, затем новый согласованный contract.
- [ ] Определить traffic/retention budget и durable dedup watermark. Сохранять неизвестный исход и запрет replay; нельзя просто удалить все events или бесконечно увеличить cap.
- [ ] Добавить health headroom и явное временное backpressure/recovery состояние; terminal failure и configurable limit документировать. Unit restart behavior согласовать с этим contract; бесконечный restart loop не решение.
- [ ] Нагрузить actual SQLite реальными begin/phase/finish и restart; никаких Telegram sends. Verify crash consistency и одинаковый offset.
- [ ] Новый immutable worker package, tests credentials/UID/permissions/source recovery, owner precheck и отдельная установка.

**Готово T03:** measured query growth ограничен; capacity Connect не создаёт неожиданную безвыходную остановку при нормальной целевой нагрузке; idempotency сохранена.

## T04. Studio lookup и runtime маршрута — L

**Закрывает:** E04,E05. **Зависит:** измерения/characterisation до изменения lock order.

**Файлы:** `classes/course_studio.php`, `course_studio_material.php`, `moodle/local/ustar/course_studio_api.php`, `classes/route_model.php`, `library_runtime.php`, existing course_studio/route tests.

- [ ] Измерить `get/save/listing` на100/1000 blueprints и нескольких размерах sourcejson; зафиксировать read bytes, decode count, lock duration, latency. Native `test_find_project_does_not_read_all_blueprint_bodies` должен поймать текущий полный scan.
- [ ] Передавать проверенный contentid там, где он уже есть, и искать по canonical key; code всё ещё проверяется на соответствие. Для listing — bounded metadata projection. Если нужна индексируемая связь code→blueprint, оформить ADR и миграцию, не второй каталог.
- [ ] Два independent save + same-project stale save + move/delete/publish race. Только после этих тестов сузить hierarchy critical section; канонический порядок locks задокументировать, не удалять общий lock вслепую.
- [ ] Маршрут: native `test_preview_has_zero_writes`, `test_locked_future_point_never_evaluated`, `test_scorm_completion_opens_next_once`, `test_route_read_query_growth` (10/100 points).
- [ ] Выделить чтение current versions/progress пакетами, presenter без writer calls. Reconciliation остаётся через прежние canonical services; пересмотр синхронности — отдельное решение после latency evidence.
- [ ] Native PostgreSQL + SCORM/Quiz native/DOM/export tests; no lost evidence/rewards. Отдельные PR Studio и route, отдельный source manifest.

**Готово:** один проект не требует чтения всех course JSON; независимые авторы не блокируются дольше согласованного бюджета; route preview остаётся read-only, completion корректен.

## T05. Пилот подразделений, UX/accessibility и измерение рабочего дня — M/L

**Закрывает:** P04,U07,E08,I05. **Владелец:** инженер + HRD/руководители; факты production предоставляет владелец.

**Файлы:** новый dated readonly observer successor в `scripts/infra/` от проверенного действующего observer, `tests/r16/`; `tests/mobile/library.cjs`, relevant Studio/material/route browser suites; документы в `context/reviews/20261103/` и новый dated runtime receipt. Старый collector2900 не править.

- [ ] Адаптировать manifest/version guard для app5e61; тесты readonly0mutation/no secrets и bounded output. Сначала проверить локально/CI, затем выдать владельцу concrete command.
- [ ] Снять обычный рабочий день без активной нагрузки на production: latency/traffic/errors/saturation, SQL/page где доступно безопасно, cron/ad-hoc/delivery lag, volumes и роли. Отсутствующий сигнал отметить null/not measured, не0.
- [ ] На staging воспроизвести representative counts и concurrent users; согласовать p95/error/query/queue budgets по фактам, не обещать5000 пользователей по grep.
- [ ] Ролевой пилот: employee,manager,HRD,editor,admin; перевод,acting,termination,контент draft/published/archived; Chrome/Android/desktop,slow network,back/refresh.
- [ ] UX review по Nielsen: next action,system status,undo/error recovery,recognition/help; task success/time/errors собрать у представителей ролей, не придумывать результаты интервью.
- [ ] WCAG2.2 AA applicability matrix: automated scan + keyboard/screen reader,focus trap/return,form errors/labels,contrast,zoom/reflow,status messages. Для каждого claim сохранить evidence и known exceptions.
- [ ] Выдать gate «пилот принят/не принят» с фактическими критериями, владельцем и датой. Старыеfailed/cron snapshots не очищать.

**Готово:** решение о расширении опирается на измерения и реальные сценарии; SMTP/provider delivery проверяется только с отдельным разрешением на конкретные тестовые отправки.

## T06. Privacy, onboarding и внешние trust boundaries — L

**Закрывает:** P03,P07,I02. **Зависит:** решения о retention/допустимых данных от владельца процессов.

**Файлы:** `classes/privacy/provider.php`, `employment.php`, registration/HR/import existing writers, `scripts/ci/privacy_inventory.py`, native privacy tests; security matrix в `context/reviews/20261103/`.

- [ ] Утвердить политику по каждому домену: owner,поля/связи/файлы,retention,legal hold/retained fact,export/anonymise/delete. Inventory не принимает решение автоматически.
- [ ] Native tests `test_export_includes_task_feed_economy_employment`, `test_erasure_preserves_other_author_and_retained_evidence`, `test_discovery_finds_user_with_only_legacy_domain_data` должны сначала показать текущие пробелы.
- [ ] Добавлять один доменный handler за PR; discovery/export/delete согласованы, replay credentials не попадают в export, удаление не воскрешает токены и не нарушает accounting history.
- [ ] Инвентаризировать все account creation writers и lifecycle; новый пользователь explicit pending/approved according to source. Legacy batch только plan/SHA/backup/reference/dry-run; не менять resolve() на deny для всех без миграционного решения.
- [ ] ASVS5.0 applicability/evidence matrix: Academy sessions/capabilities/File API/SCORM/RSS/Connect/ops. Трассировать actor→scope→input→writer→output, добавлять missing negative tests, не объявлять PARAM_RAW уязвимостью.
- [ ] Проверить effective public boundary на изолированном окружении: firewall/IPv6/NAT,TLS,admin separation,rate/upload/SSRF,logging,shared www-data/caddy. Подготовить owner change plan, не менять периметр сейчас.

**Готово:** доказанное покрытие согласованного data lifecycle; явные условия безопасного onboarding; непринятых external-critical рисков нет.

## T07. Выделение продукта из конкретной инсталляции — L, до внешнего пилота

**Закрывает:** P01,P02,P05,U08,E10. **Зависит:** ADR/продуктовое решение, T06 и T10 для допуска внешнего использования.

### T07a — site learning configuration

**Файлы:** `catalog_mastery.php`, `product_quiz.php`, existing settings/config, native tests, новый ADR.

- [ ] Согласовать contract canonical product assessment binding и migration defaults70/41/69/11 только для известной текущей базы.
- [ ] Tests с произвольными ID и absent/wrong module; service lookup по validated binding, не по числовой случайности. Сохранить попытки/награды и compat reader.
- [ ] Экспорт/импорт настроек содержит stable semantic mapping, не переносит чужие database IDs без resolution.

### T07b — модель клиента и fresh install

**Файлы:** новый ADR, отдельный bootstrap profile в `scripts/infra/`, CI fresh-instance fixtures, docs installation/support.

- [ ] Выбрать supported deployment: первый вариант на рассмотрение — один изолированный Moodle instance на клиента. Shared SaaS не реализовывать до отдельного архитектурного проекта.
- [ ] Fresh install на пустой базе и upgrade с supported release не требуют server1 journal/UUID; secrets/env вводятся private способом. Перед apply выдаются план/manifest.
- [ ] Проверить isolation двух fixtures,per-client backup/restore и независимый uninstall/retention. Не менять frozen server1 operators.

### T07c — locale/brand

**Файлы:** `lang/*`, native templates/controllers, Studio boot/runtime export.

- [ ] Переносить пользовательские строки в existing i18n layer по экранам, brand config отдельно от прав/индентификаторов.
- [ ] Russian/second-locale,long text,SCORM export tests; preserve source/project schema and IDs.

### T07d — supply chain/release contents

**Файлы:** active dependency locks/vendor notices, CI release manifest tooling, SBOM artifact definition.

- [ ] Получить точный composition core/image/addons/JS/Python; legacy frontend явно excluded unless shipped.
- [ ] Генерировать SBOM и лицензионный inventory, прогонять advisory scan с version/date; findings triage и exceptions owner/expiry.
- [ ] Rebuild проверяет artifact identity и список компонентов. Инструкция supported versions/config/upgrade/downtime/support responsibilities готова.

**Готово:** независимый клиент разворачивается без заимствования рабочей БД Устар, без смены исходников под его ID/бренд; условия сопровождения и изоляция доказаны.

## T08. Последовательное уменьшение крупных объектов — M на домен

**Закрывает:** E06. **Не блокирует** T01/T02; избегать параллельного массового рефакторинга.

**Файлы:** `route_model.php`, `content_admin.php`, `catalog_mastery.php`, `home.php`; новые reader/presenter/codec внутри тех же доменов, tests публичных фасадов.

- [ ] Выбрать один узкий блок, например route presenter или catalog question generation, и перечислить его вход/выход/побочные эффекты.
- [ ] Зафиксировать characterisation fixtures публичного метода: returned shape,ordering,escaping,exceptions и SQL/writes.
- [ ] Вынести блок с сохранением старого facade API. Не переносить boundary checks в theme и не дублировать writer.
- [ ] Сравнить API/HTML/DB traces до/после, native gate для затронутого домена; отдельно форматирование dense first-party code без логических изменений.
- [ ] Commit и обновить code map/ADR при изменении границы. Следующий extraction после принятого предыдущего.

**Готово:** review и изменение одной ответственности локальны; размер файла — вторичная метрика. Исторические upgrade.php шаги сохраняются.

## T09. Интеграция принятого source и поддерживаемый выпуск — M

**Закрывает:** E09. **Зависит:** точный кандидат и явное разрешение owner на merge; подготовка разрешена, merge пока нет.

**Файлы:** Git integration branch/PR112–114, STATE/ACTIVE/BACKLOG/generated inventory, release manifest/CI receipts.

- [ ] Сверить ancestry и exact trees accepted app5e61/worker267, сохранить source/CI/owner distinctions; интеграционную ветку создать отдельно.
- [ ] Проверить no lost fix, schema parity и full RC на exact integration SHA. Не считать зелёный предыдущий SHA достаточным для изменившегося tree.
- [ ] Подготовить reviewable PR с составом и evidence; запросить merge только как финальный шаг после подготовки, по действующему ограничению владельца.
- [ ] После разрешённого merge — release tag/manifest и корректный current context. Merge не изменяет production автоматически.
- [ ] В entrypoints оставить краткие актуальные указатели, старые snapshots immutable. Дополнить consistency checks для obsolete pending/deployed prose где возможно.

**Готово:** default source и поддерживаемый production release согласованы; новый инженер воспроизводит конкретную версию без восстановления истории чата.

## T10. Эксплуатационная готовность внешнего продукта — L, отдельное окно

**Закрывает:** I01,I03,I04. **Зависит:** owner window, supported source T09 и deployment profile T07.

**Файлы:** новые dated runtime/DR evidence, reproducible image/compose profile согласно ADR18, backup/observer successors; прежние frozen files неизменны.

- [ ] Согласовать RPO/RTO,retention,copies/secrets inventory и clean-host rehearsal plan. Текущий unknown внешний restore не объявлять провалом.
- [ ] В отдельном изолированном окружении восстановить данные/файлы/config/cron/worker; provider sends disabled. Verify login,учебный прогресс,задачи,файлы,ledger и контрольные hashes; измерить duration/loss window.
- [ ] В плановом Moodle/PHP окне проверить pinned reproducible image,logging budgets,healthchecks и recovery; до окна только наблюдение за местом по accepted trigger.
- [ ] Подготовить независимый host-loss observer и UPS shutdown/restart test; конкретные каналы/получатели отдельно разрешаются. Не считать локальный Telegram notifier независимым от хоста.
- [ ] Новый owner package имеет exact source/backup guards,check/apply/recover,CI artifact identity и скачанную сохранённую копию; выдача команд по фактам, без нового SSH агентом.

**Готово:** измеренное восстановление и обнаружение отказа соответствуют согласованному обязательству, а не только успешному backup job.

## Общая приёмка исправлений

Для каждой находки в `findings.json` появится: fixing commit,focused test evidence,native/CI scope,release/tool/archive identity и owner acceptance при поставке. Закрытие технической ошибки не закрывает автоматически UX/нагрузку/DR. Пакеты с изменением поведения получают relevant tests; весь существующий full RC нужен на интеграционном выпуске, а не многократно без изменения исходников.

Все30находок сопоставлены T01–T10. Следующий практический шаг — T01: исправить исчезновение старых материалов и запрещённые CTA, сохранив текущие права. Этот документ пока является планом, код приложения не изменён.
