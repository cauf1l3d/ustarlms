# Верификация ревью USTAR —26.10.2026

Проверены три приложенных документа, UX-предложения из сообщения владельца, Git
PR105/103/106 и код принятого payload6c56a5b6ab830b97b706a5d7028b49cfae31e7a4.
Исходники local_ustar/theme_ustar в интеграции побайтно совпадают с этим payload.
Доступа к серверу у агента нет. Runtime-выводы опираются на квитанции владельца;
новая нагрузка, браузерная приёмка prod и проверка всего хоста не выполнялись.
В submitted сохранены копии без исправления противоречий; один пример заголовка
ключа заменён безопасной подписью для secret guard. Original/repository SHA — в
submitted/PROVENANCE.json; исходные вложения владельца не изменены.

## Вывод и приоритет

Сначала устранить расхождение main и принятого prod и упростить передачу контекста.
Затем измерить реальные задержки, число SQL и пути сотрудника. Самые конкретные
кандидаты оптимизации — статистика источников feed и имена участников competition.
UX-выигрыш вероятнее от ясного следующего действия и согласованной навигации, чем
от механического сокращения числа PHP-файлов. Архитектурный рефакторинг делать при
изменении соответствующего домена, сохраняя фасады, ACL и характеризационные тесты.

## Состояние production и Git

| Утверждение | Вердикт26Oct | Основание / действие |
|---|---|---|
| main отстаёт от prod | Подтверждено: main d9655074 от16Oct, PR105/103/106 открыты | Интеграция с ancestry; exact SHA merge/CI в runtime receipt |
| PR105 уже входит в main | Неверно на момент сверки | compare105…106 diverged;105 ещё open; не путать факт чтения с merge |
| Приложение ходит суперпользователем | Устарело | infra_app_handoff_owner_20261025.json: ustar_app,3479relations;bootstrap admin сохранён для ops |
| Code/config доступны PHP на запись | Устарело в проверенной области | infra_code_hardening_owner_20261025.json;22legacy сохранены;наружные trust boundaries не объявлены закрытыми |
| HTTPS/cron/Connect ещё не поставлены | Устарело | connect_backup_https_owner_20261021.json;connect_telegram_finish_owner_20261023.json |
| Нет ADR для Connect/console/operators | Подтверждено | Добавлены ADR0015–18; решения не выдаются за новый deployment |
| Входные документы слишком велики и устарели | Подтверждено | Новые компактные входы; прежние версии архивированы целиком; byte-limits в check_context |
| Docker successor нужен для текущего prod | Не принят как соразмерный текущий шаг | По прямому решению владельца заморожен; ротация/recreate — плановое обновление Moodle |
| Простая замена ID на labels решает lifecycle | Неполно | Labels требуют единственного совпадения, image/mount/process guards; ID сохранять внутри транзакции;ADR0018 |
| Экспорт на HDD доказывает полный DR | Нет | Копии переданы; внешний restore unknown по решению владельца;whole-host recovery не подтверждён |
| ИБП есть, значит shutdown проверен | Нет | Наличие подтверждено, автоматическое выключение unverified |
| Все8категорий можно объявить done | Нет | Finite accepted_risk с владельцем/датой/триггером; неизвестное не превращать в passed |
| PR107 нужен для актуального prod | Нет подтверждения поставки | Отдельная Studio-разработка от старого main; оставить отдельным PR, затем rebase/qualification |

Исторический аудит24Oct честно ограничен старым main, поэтому его открытые runtime
статусы нельзя переносить на25–26Oct. INF-02 закрыт в scoped области; INF-03 имеет
принятое hardening и оставшуюся общую границу www-data/caddy. SSH по решению владельца
отложен до внешнего доступа. OPS-01 отмечен закрытым в переданном26Oct решении;
это owner-decision, не новая независимая browser-проверка.

## Безопасность и качество: что выдержало сверку

| Находка / рекомендация | Вердикт | Проверенный код и следующий шаг |
|---|---|---|
| Stored XSS в plain description | Предложенный exploit не подтверждён; документ сам себе противоречит | learning_tasks использует FORMAT_PLAIN; task_workspace/page.php экранирует s(); не менять plain contract на HTML |
| SQL injection через parentid | Не подтверждена даже как описанный внутренний путь | materials.php использует PARAM_INT, placeholder :parentid и params; инъекция в связанное значение не становится SQL |
| q_image сохраняет javascript/data | Опровергнуто описанным путём | game_studio.php:127–193: http(s)+URL check, saveerrors, запись только if(!$saveerrors) |
| PARAM_RAW означает отсутствие schema/input checks | Неверное обобщение | Проверять parser→validator→writer→output по каждому endpoint; JSON и богатый текст требуют RAW на транспорте |
| json_encode само исключает XSS | Неверно как универсальное правило | Важен потребитель JSON, DOM sink и escaping; отдельно тестировать notebook output |
| moodle_url::out(false) экранирует HTML | Формулировка неверна | false отключает HTML escaping; конкретный URL создаётся сервером, эксплуатируемый input не показан; проверять sink, не объявлять всё безопасным |
| RSS SSRF полностью доказан одной проверкой IP | Недостаточно | Проверить DNS/redirect/rebinding/IPv6 и фактический HTTP transport; static flag не равен всей negative acceptance |
| Нет истории действий: всего1event class | Опровергнуто в абсолютной форме | people::log_action, adaptation_service events/actorid, learning_events и доменные истории уже есть; Moodle-logstore coverage неполная |
| Добавить10event classes «дёшево» | Полезно при конкретном gap | Сначала карта событий/actor/object/revision/idempotency и consumers; не дублировать history и не логировать персональные payload |
| Нет защиты демо CLI | Частично неверно | ensure_demo_executive и normalize_demo_positions RETIRED/exit78 до bootstrap; migrate_stage2_access требует plan/SHA/backup/confirm;bootstrap_trading_floor_route остаётся кандидатом проверки |
| Upgrade без savepoints | Опровергнуто |89 upgrade_plugin_savepoint; для local plugin нужен plugin, не upgrade_mod_savepoint; старые шаги не переписывать |
| JSON структуры не защищён от concurrent edits | Уже частично решено | organization_structure_editor::change — lock, transaction, expected revision; admin_save_structure тоже expectedversion/lock;инвентаризировать прямые structure::save callers |
| Нужна немедленная реляционная копия структуры | Не обосновано | Это новая проекция с синхронизацией; сначала профилировать readers и проверить текущие writers, не создавать второй truth store |
| «3% покрытия» / global DB делает тесты невозможными | Опровергнуто | Количество файлов не line/branch coverage; native Moodle DB fixture и r16 реально исполняются; DI только на нужных границах |
| time() требует ждать7дней в тесте | Неверно | Исторические dueat/fixtures и граница now позволяют тестировать; clock вводить там, где реально нужна детерминированность |
| Имена save/available одинаковы → нужны общие traits | Не доказательство дублирования | Разные домены/ACL; общая сигнатура не означает общий контракт |
| Длинный route_model гарантирует регрессию | Долг подтверждён, гарантия/блокер преувеличены | Извлекать небольшой reader/presenter при целевой задаче, тестировать публичный API до/после |
| SCSS без токенов | Неверно | theme_ustar/scss/_tokens.scss уже существует, build_agent_inventory его разбирает; рост overrides — отдельный вопрос каскада |
| Upgrade/runtime100%совместимы | Не выводится из списка API | Нужны точные source/native/upgrade/rollback результаты; CI и owner scopes раздельны |
| Секретов/CVE нет | В этом ревью не подтверждено как полная гарантия | Secret guard сохранён; старые grep/CVE заявления не скан всей истории/поставки;frontend legacy вне native релиза |
| Playwright1.62→1.47 как обновление | Ошибочное направление версии | Не применять downgrade из документа; зависимости менять по актуальному advisory и тестам отдельно |

В аудите17Oct заголовок снимает XSS, но разделы9/10/16 по-прежнему называют его P0.
Приведённые «полные» рефакторинги содержат пропуски и смену API/таблиц. Они не являются
готовыми патчами. Например, batch source_stats с get_records_sql по sourceid первым
полем теряет строки разных enrichstatus при одинаковом ключе; нужен уникальный ключ
или recordset. Пример competition читает deleted, не выбрав поле. Такие вставки не
переносятся в production механически.

## Производительность

Подтверждено: feed_admin.php:179–181 вызывает source_stats для каждого RSS source.
В classes/competition.php:267–271 на участника выполняются запрос participant userid
и user record (на открытом scoreboard), либо user lookup (закрытый). Список ограничен100,
но запросы растут с размером выдачи. Это кандидаты на batching, а не доказательство
медленного рабочего дня. Проверка должна сохранить fullname/privacy, пустые списки,
все статусы статистики, сортировку/limit и одинаковый результат при разных размерах.

«387 N+1» — статический grep, не число найденных дефектов. GROUP BY без LIMIT не
является само по себе проблемой: строк результата мало; оценивать EXPLAIN/row counts.
Общий5минутный cache добавлять только после анализа visibility и invalidation.
pg_stat_statements требует отдельного плана/окна, а не внезапного restart PostgreSQL.

Следующий шаг PERF-01: пассивный рабочий день, route/material/home/workspace/feed
частота и p50/p95/p99 при известных actor/scopes; SQL count/latency на изолированных
репрезентативных данных; затем batch и сравнение до/после. Утверждённый SLO пока отсутствует.
Счётчики observer/source_empty и текущий collector не заменяют browser/user timing.

## UX: проверка десяти предложений

101root PHP-файл/55templates — воспроизводимые числа для этого payload, но version,
settings, include и POST action не являются самостоятельными экранами. Число URLs
не равно числу переходов сотрудника. Ревью не содержит analytics или замера кликов.

| Предложение | Что уже есть / что неверно | Что действительно стоит проверить |
|---|---|---|
| Один «Мой день» | home и персональные виджеты уже есть | Ясный next action, просроченное и прогресс по роли; не насильно один экран HR/employee |
| Ролевое меню | theme/layout/ustar.php уже ветвится по admin/hr/executive/manager capabilities | Консистентность ссылок с service guards и пустые разрешённые состояния |
| Материалы8→1 | Среди8URL есть POST/version/player endpoints | Сохранение контекста список→карточка→версия; измерить реальные действия, сохранить старые ссылки |
| authorize+launch ремедиации в1действие | authorize — HR/manager approval, launch — ученик; это разные actor/trust gates | Показать статус и следующий доступный шаг на результате; не выдавать self-approval |
| Маршрут12→1 | Часть URLs — серверные переходы/проверки | Продолжить с текущей точки, положение в маршруте, back/resume на телефоне |
| Убрать7управленческих вкладок у сотрудника | page.php уже скрывает control/rules если !manage;analytics становится «Мои результаты»;перечень ревью даже содержит6ключей | Уточнить названия/порядок и проверить допустимые вкладки по ролям |
| Поиск в шапке | shell.mustache и AMD shell.js уже предоставляют поиск/диалог | Релевантность, capabilities, клавиатура и пустой результат; расширить фильтры по потребности |
| Черновик и публикация | content_admin уже имеет draft/publish version lifecycle | Сделать состояние/аудиторию видимым и проверить default конкретных create-путей; не объединять слепо разные типы контента |
| Нет window.confirm → нет подтверждений | Вывод неверен: Moodle form/reset confirmation существует | Критичные действия: actor/object/последствия/CSRF; доступный confirm UI и защита повторного POST |
| Мобильный контур отсутствует | PWA/shell/responsive и browser suites уже есть | Полноценные три сценария на реальном Moodle: next action→материал→аттестация→resume; не второй отдельный UI |

Ноль Behat не означает ноль интеграционных тестов: tests/stage содержит native DB,
HTTP, upgrade/repeat/recovery и concurrency сценарии, tests/mobile — браузерные проверки.
При этом полнота end-to-end всего HR/learning пути и production удобство ими не доказаны.

## Очередь с ожидаемой отдачей

1. **Сейчас, Git:** main/source/evidence, компактные входы, ADR, независимые infra CI и
   owner/date/trigger риски. Улучшает передачу проекта и снижает повторную работу.
2. **Следующий performance блок:** baseline, затем два подтверждённых batching-кандидата.
   Выигрыш — меньше SQL; фактическое ускорение оценивается после измерения.
3. **Следующий quality/security блок:** карта public endpoints/CLI и существующих guards,
   недостающие negative tests, сверка доменного аудита. Улучшает обнаружение регрессий.
4. **Следующий UX блок:** role/actor сценарии на одном устройстве; видимые next action,
   статус approval и возврат в маршрут. Объединение URL само по себе не цель.
5. **По задачам домена:** небольшие presenter/reader extraction; SCSS cascade/localized
   copy и версия контента. Массовые классы/traits/DI/схемные проекции пока не обоснованы.
6. **Плановое Moodle/PHP окно:** воспроизводимый образ, log budget/health, выборочная
   смена routine discovery и проверка backup/cron/HTTPS/recovery. До окна INF-05 deferred.

Retention7/4/3, SMTP-письмо, другой LANobserver и принудительный renew — предложения,
не выполненные/не принятые факты. Retention требует измеренного места/сохранности;
SMTP — утверждённого адресата; observer должен быть независим от отказа хоста.
Новая risk-модель не делает эти проверки выполненными и не обещает закрытие всего DR.
