# USTAR LMS — продуктовый, UX/UI и инженерный аудит

Срез: 03.11.2026; завершено 04.11.2026. Самостоятельная проверка без субагентов. Это аудит исходников, доступных CI и датированных свидетельств владельца, с изолированными воспроизводящими экспериментами. Не pentest живого сервера, не WCAG-сертификация, не гарантия нахождения абсолютно всех дефектов.

## Решение для руководства

USTAR уже имеет работающие сквозные бизнес-процессы и серьёзные защитные механизмы. Полная перепись или переход на микросервисы не обоснованы. **Расширение внутри компании разумно проводить поэтапно**, закрыв обнаруженные функциональные ограничения, измерив нагрузку и приняв процессы по ролям. **Внешний выпуск пока требует отдельной подготовки продукта и эксплуатации**. Общая multi-tenant установка не следует из наличия отделов; безопасный первый вариант для оценки — изолированный instance на клиента, с ADR и собственной квалификацией.

Ни одна находка не объявлена P0: новый подтверждённый эксплуатируемый критический обход доступа не получен. Это не доказательство отсутствия уязвимостей. P1 ниже означает блокер указанного этапа или существенную проблему; P2 — плановый долг/UX-риск. «Не измерено» не обозначается «сломано».

## Версии и свежая установка

- Установленное приложение: `5e61c0d2566e8103d01121968d56852e26da99fa`; local/theme `2026110200`;649 fixed addon files/108 таблиц по inventory.
- Исправленный installer: `4aee12968ceefe6189143f3c40f59976831f6513`. Owner: SHA OK, initial busy → ready → active, service02 success/exit0, readonly postcheck active. Ранний busy не является повреждением; holder не установлен.
- [Квитанция](../../runtime/studio_scorm_edit_owner_deploy_20261103.json) сохраняет время журнала без выдуманной timezone. Общий отзыв «все вроде ок» не заменяет перечисленные edit/export/role cases.
- Worker: `267e55874eb8f6c36259d748f28a2c636adc9810`; сравнение дерева с проверяемой веткой пустое. Его runtime-наблюдение остаётся от1Nov.
- main остаётся отдельно: app `5cc02944`/local2900; PR112/113/114 не слиты. Метаданные установки опубликованы commit `a1dfe7c2`.
- Этот аудит не менял application, worker, schema, production или frozen installers; добавлены доказательства, отчёт и план.

## Метод и отраслевые ориентиры

Использованы трассировка controller→ACL→reader/writer→DB/file, проверка отрицательных сценариев, сравнение с ADR и прежним аудитом, probes на настоящих методах с явно указанными synthetic dependencies, review результата существующих CI. Расследование причин и проверка результата выполнены одним агентом.

Ориентиры: [OWASP ASVS5.0](https://asvs.owasp.org/) для границ и контроля безопасности; [WCAG2.2](https://www.w3.org/TR/WCAG22/) и [эвристики Nielsen](https://www.nngroup.com/articles/ten-usability-heuristics/) для UX; [Google SRE: monitoring](https://sre.google/sre-book/monitoring-distributed-systems/) для latency/traffic/errors/saturation; [NIST SSDF1.1](https://csrc.nist.gov/pubs/sp/800/218/final) для release/supply chain; [Moodle Privacy API5.1](https://moodledev.io/docs/5.1/apis/subsystems/privacy) для export/discovery/deletion. Источники сверены03Nov. Использование ориентиров не означает полную сертификацию или построчное выполнение всех требований ASVS.

Продуктовые критерии: конкретный пользователь и задача, доступный следующий шаг, обратимость ошибки, жизненный цикл контента/сотрудника, результат и стоимость сопровождения. Для внешнего продукта добавлены installability, изоляция клиента, data lifecycle и эксплуатационные обязательства.

## Охват и ограничения

| Область | Проверенный материал | Глубина / остаток |
|---|---|---|
| Library/marketplace/catalog/checks | controllers,templates,readers,ACL,grants/store,tests | Самая глубокая проверка; DB-call/501/dead-CTA и DOM probes |
| Studio/materials/SCORM | source adapter,storage,codec,locks,import/export,DOM/native CI | Код/регрессии; не новая live UX-приёмка всех пакетов |
| Routes/evidence/assessment | route_model,library_runtime,product_quiz,catalog_mastery; canonical contracts | Чтение и побочные эффекты/версии; не новый native workload наprod |
| HR/organization/registration | access_context,identity,directory,model,employment,hr_people | Scope,compatibility,рост чтений; новые отделы/SSO не приняты |
| Adaptation/grades/rewards | actor/lock/revision guards,ADR,domain history,CI | Выборочные critical writers; каждый бизнес-сценарий вручную не проигран |
| Tasks/notebook/feed/messages | канонические service/policy/file guards,DOM suites,feed RSS transport | Выборочные границы; не полный контентный/privacy аудит каждого payload |
| Connect | точный worker tree,receiver/journal/runtime/systemd,41 receiver tests | Actual SQLite capacity probe; без provider sends |
| Console | auth/ops source,unit,CI/test contracts | Отдельная ops boundary; локальный HTTP test blocked missing fastapi |
| Infra/delivery/DR | source installers,ADR17/18,owner receipts,CI | Нет SSH/свежего host survey,нагрузки,пентеста и clean-host restore |
| Legacy frontend/release snapshots | классификация источников | Не включены в активное приложение; отсутствие актуальности не объявлено prod-дефектом |

Не проводились интервью пользователей, измерения task success, SQL EXPLAIN на production, нагрузочные отправки, скан внешнего периметра или удаление данных. Поэтому вывод о готовности — условный gate, не оценка «всё проверено на100%».

## Краткий реестр

| ID | Находка | Приоритет | Доказательство | План |
|---|---|---|---|---|
| P01 | Зависимость учебного ядра от конкретных объектов Устар | P1 до тиражирования | код: подтверждено | T07 |
| P02 | Организационная область не является изоляцией клиентов SaaS | P1 до общего SaaS | архитектурное ограничение | T07 |
| P03 | Privacy API покрывает только часть персональных доменов | P1 до внешнего выпуска | код: подтверждено | T06 |
| P04 | Нет завершённого пакета доказательств готовности каждого подразделения | P1 для массового запуска | пробел доказательств | T05 |
| P05 | Поставки воспроизводимы для server1, но не являются универсальной установкой продукта | P1 до внешнего выпуска | код и ADR: подтверждено | T07 |
| P06 | Редактируемость SCORM имеет чёткие границы формата | P2; P1 для обещания универсальной совместимости | код: подтверждено | T02 |
| P07 | Legacy-сотрудник без employment-записи считается active | P1 перед массовым импортом/внешним входом | подтверждённый compatibility risk | T06 |
| U01 | Карточка ведёт в Маркетплейс, который этому сотруднику запрещён | P1 | воспроизведено на реальных readers | T01 |
| U02 | После 500 активных грантов старый материал исчезает из поиска | P1 | воспроизведено на реальных readers | T01 |
| U03 | Административные селекторы и история молча обрезаются | P1 для масштабирования контента | код: подтверждено | T01 |
| U04 | В редакторе новой проверки нельзя добавить шестой вопрос | P1 для авторского процесса | код + DOM воспроизведение | T02 |
| U05 | Поиск и страницы теряют контекст и дают неточное пустое состояние | P2 | код + DOM воспроизведение | T02 |
| U06 | Отзыв доступа и возврат выполняются одним кликом без обзора последствий | P2 | подтверждённый UX-риск | T02 |
| U07 | Браузерные PASS не доказывают WCAG AA и полный ролевой UX | P1 для заявления о соответствии; P2 внутренний | пробел доказательств | T05 |
| U08 | Часть пользовательских строк и брендинга зашита в код | P2; P1 для многоязычного white-label | код: подтверждено | T07 |
| E01 | N+1 остаётся внутри пакетного resolver Маркетплейса | P1 | воспроизведено на реальном коде | T03 |
| E02 | Другие вкладки Библиотеки также проверяют весь набор до пагинации | P1 при большом каталоге | код: подтверждено | T03 |
| E03 | Повторный расчёт области управления внутри списка сотрудников | P1 для крупных подразделений | код: подтверждённый путь O(N²) при растущем scope | T03 |
| E04 | Studio ищет проект сканированием всех JSON и держит общий lock | P1 при росте авторов и курсов | код: подтверждено; contention не измерен | T04 |
| E05 | Маршрут сочетает чтение с синхронизацией и запросами на каждую точку | P2; измерить до массового запуска | код: подтверждено | T04 |
| E06 | Сосредоточение нескольких ответственностей в крупных объектах и страницах | P2 технический долг | код и воспроизводимая метрика | T08 |
| E07 | Connect останавливается при 10 000 событиях младше семи дней | P1 до массового подключения | воспроизведено actual SQLite Journal | T03 |
| E08 | Не измерена ёмкость системы на рабочем дне | P1 как gate расширения | пробел доказательств | T05 |
| E09 | main и установленный source снова расходятся | P1 перед следующим интеграционным выпуском | Git и owner receipt: подтверждено | T09 |
| E10 | Нет единого доказательства состава и уязвимостей всей поставки | P1 до внешнего выпуска | пробел покрытия, не найденная CVE | T07 |
| I01 | Полное восстановление на независимом чистом хосте не подтверждено | P1 для SLA внешнего продукта | принятый риск и пробел доказательств | T10 |
| I02 | Внешний периметр имеет незакрытые условия допуска | P1 до открытия в интернет | принятый риск; не свежий pentest | T06 |
| I03 | Ротация логов и воспроизводимый production image отложены | P2 сейчас; P1 перед тиражированием | принятый риск | T10 |
| I04 | Независимое обнаружение потери хоста и shutdown ИБП не доказаны | P2 внутри; P1 при SLA | пробел доказательств | T10 |
| I05 | Свежая успешность cron/доставки/почты не следует из установки overlay | P1 при gate массового запуска | пробел доказательств | T05 |

## Детали и доказательства

### Продукт и готовность тиражирования

#### P01. Зависимость учебного ядра от конкретных объектов Устар

**P1 до тиражирования · код: подтверждено · подразделения и внешний продукт.**

catalog_mastery фиксирует POINT_ID=70 и SCORM_CMID=41; product_quiz фиксирует CMID=69, QUIZID=11, POINTID=70. Это действующие сервисы, а не только исторические миграции.

**Последствие:** На другой базе эти ID могут отсутствовать или обозначать другие объекты. Для новых подразделений нельзя предполагать, что одна товарная аттестация подходит всем. Это не доказательство неправильной работы текущей настроенной базы.

**Исправление:** Ввести явную проверяемую привязку учебного сценария к существующим course/module/point и области должностей; сохранить текущие привязки миграцией. Сначала ADR для политики конфигурации, без второго хранилища прогресса.

**Критерий закрытия:** Два отдела и две независимые БД с разными ID; отсутствие/удаление/подмена module отказывает; старые попытки и награды неизменны.

**Код/свидетельства:** [moodle/local/ustar/classes/catalog_mastery.php:16](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/catalog_mastery.php#L16); [moodle/local/ustar/classes/product_quiz.php:18](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/product_quiz.php#L18). Задача плана: T07.

#### P02. Организационная область не является изоляцией клиентов SaaS

**P1 до общего SaaS · архитектурное ограничение · внешний продукт.**

company_scope читает пользователей всего Moodle; каталог организации общий, права часто в context_system. Поля tenant в схеме относятся к Connect identities, не являются tenant boundary для задач/учёбы/кошелька.

**Последствие:** Нельзя разместить независимых клиентов в одной базе только разными отделами и обещать изоляцию. Для текущей одной компании это допустимая архитектура.

**Исправление:** Первый внешний пилот планировать как отдельный Moodle instance/БД/файлы/секреты на клиента, после отдельного ADR и проверки восстановления. Общий multi-tenant SaaS — отдельный проект, не добавление поля tenant в несколько таблиц.

**Критерий закрытия:** Два изолированных клиента: пользователи, файлы, поиск, отчёты, cron, backup и Connect не пересекаются; восстановление одного не затрагивает другого.

**Код/свидетельства:** [moodle/local/ustar/classes/access_context.php:36](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/access_context.php#L36); [moodle/local/ustar/classes/organization_directory.php:8](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/organization_directory.php#L8); [moodle/local/ustar/db/install.xml:1](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/db/install.xml#L1). Задача плана: T07.

#### P03. Privacy API покрывает только часть персональных доменов

**P1 до внешнего выпуска · код: подтверждено · внутренние данные и внешний продукт.**

Discovery/export/erase реализованы для Connect, route_access и Library. В полном provider нет обработки local_ustar_coin_ledger, local_ustar_learning_tasks, local_ustar_feed_posts, local_ustar_employment; записи этих таблиц подтверждены install.xml. Наличие inventory не делает export/deletion полными.

**Последствие:** Экспорт субъекта неполон; утверждённое удаление не охватывает все данные и файлы. Это технический разрыв, не заключение о нарушении закона и не разрешение удалять кадровую/учебную историю.

**Исправление:** Составить матрицу data owner → purpose → retention → export → anonymise/delete; утвердить политику сохранения фактов и сторонних авторов; добавить доменные handlers в существующий provider.

**Критерий закрытия:** Пользователь с данными во всех доменах, чужие соавторы/файлы, retained evidence, повтор удаления; точные Moodle DB/Privacy API тесты.

**Код/свидетельства:** [moodle/local/ustar/classes/privacy/provider.php:113](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/privacy/provider.php#L113); [moodle/local/ustar/classes/privacy/provider.php:174](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/privacy/provider.php#L174); [moodle/local/ustar/classes/privacy/provider.php:239](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/privacy/provider.php#L239); [scripts/ci/privacy_inventory.py:1](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/scripts/ci/privacy_inventory.py#L1). Задача плана: T06.

#### P04. Нет завершённого пакета доказательств готовности каждого подразделения

**P1 для массового запуска · пробел доказательств · подразделения.**

Есть реальные owner-приёмки и подробные CI, но текущие receipts отдельно оставляют остальные ролевые процессы руководству и не перечисляют весь цикл по каждому отделу. Нет полученного workday/SLO отчёта. Сведения о бизнес-эффекте обучения в этом аудите не получены.

**Последствие:** Раннее распространение может увеличить нагрузку на единственного оператора и поддержку. По числу фич нельзя установить эффективность адаптации или успешность самостоятельной работы сотрудников.

**Исправление:** Пилотная матрица employee/manager/HRD/editor/admin и перевод/совмещение/увольнение. Считать task success, время до первого полезного действия, отказы по доступу, задержку проверки руководителем и обращения; использовать существующие события, не записывать лишние персональные payload.

**Критерий закрытия:** В каждом пилотном отделе пройти онбординг → назначение → материал → оценка → отчёт/возврат → перевод/отзыв. Зафиксировать ожидаемый результат и подпись владельца процесса.

**Код/свидетельства:** [context/tasks/ACTIVE.md:1](https://github.com/cauf1l3d/ustarlms/blob/a1dfe7c2b86e984bebbd03de2ffbef9d9900a7fb/context/tasks/ACTIVE.md#L1); [context/runtime/connect_visual_owner_deploy_20261101.json:1](https://github.com/cauf1l3d/ustarlms/blob/a1dfe7c2b86e984bebbd03de2ffbef9d9900a7fb/context/runtime/connect_visual_owner_deploy_20261101.json#L1); [context/runtime/studio_scorm_edit_owner_deploy_20261103.json:1](https://github.com/cauf1l3d/ustarlms/blob/a1dfe7c2b86e984bebbd03de2ffbef9d9900a7fb/context/runtime/studio_scorm_edit_owner_deploy_20261103.json#L1). Задача плана: T05.

#### P05. Поставки воспроизводимы для server1, но не являются универсальной установкой продукта

**P1 до внешнего выпуска · код и ADR: подтверждено · внешнее развёртывание.**

Successor требует точного baseline, прежних журналов и путей /opt/ustar; ops broker привязан к датированным backup/lab producers и их SHA. Это сознательные transaction guards текущего сервера.

**Последствие:** Новый клиент не может использовать последний overlay как установщик с нуля. Цепочка операторов увеличивает стоимость сопровождения, а одинаковая версия0200 не определяет точный набор исходников.

**Исправление:** Сохранить frozen installers; подготовить отдельный fresh-install profile и upgrade matrix с release manifest, конфигурацией окружения, зависимостями и smoke/restore. Не удалять guards и не превращать старые patch scripts в универсальный shell.

**Критерий закрытия:** Чистая БД/хост и обновление с поддерживаемого релиза; источник, схема, права, rollback и отсутствие прежних journal paths проверяются на стенде.

**Код/свидетельства:** [scripts/infra/studio_scorm_edit_lint_patch.py:13](https://github.com/cauf1l3d/ustarlms/blob/a1dfe7c2b86e984bebbd03de2ffbef9d9900a7fb/scripts/infra/studio_scorm_edit_lint_patch.py#L13); [scripts/infra/studio_scorm_edit_lint_patch.py:67](https://github.com/cauf1l3d/ustarlms/blob/a1dfe7c2b86e984bebbd03de2ffbef9d9900a7fb/scripts/infra/studio_scorm_edit_lint_patch.py#L67); [server-console/backend/ustar_console/operations.py:29](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/server-console/backend/ustar_console/operations.py#L29); [context/decisions/0017-operator-tooling.md:1](https://github.com/cauf1l3d/ustarlms/blob/a1dfe7c2b86e984bebbd03de2ffbef9d9900a7fb/context/decisions/0017-operator-tooling.md#L1). Задача плана: T07.

#### P06. Редактируемость SCORM имеет чёткие границы формата

**P2; P1 для обещания универсальной совместимости · код: подтверждено · авторы и позиционирование продукта.**

Статический HTML конвертируется в страницы; video/audio/canvas/iframe/forms требуют исходного проекта и отклоняются. Экспорт создаёт SCORM1.2 с собственным runtime. Старый источник открывается отдельным черновиком, а не заменяет существующий Moodle activity.

**Последствие:** Можно редактировать подтверждённый присланный пакет, но нельзя обещать импорт любого SCORM без потерь интерактивности, sequencing или исходного поведения. Сохранённая копия не означает замену материала во всех маршрутах.

**Исправление:** Публиковать матрицу форматов и экран предварительного разбора: что переносится, что отклонено, какой источник/черновик/маршрут затронут. Для неподдержанного пакета сохранить исходник и запросить authoring project, не исполнять чужие scripts в редакторе.

**Критерий закрытия:** Корпус USTAR/static/multi-SCO/interactive/SCORM2004; сравнение текста/медиа/порядка, player tracking и явно отказанные функции; публикация не стирает старые attempts.

**Код/свидетельства:** [moodle/local/ustar/course_studio/model.js:31](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/course_studio/model.js#L31); [moodle/local/ustar/course_studio/model.js:103](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/course_studio/model.js#L103); [moodle/local/ustar/course_studio/model.js:27](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/course_studio/model.js#L27); [moodle/local/ustar/classes/course_studio_material.php:80](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/course_studio_material.php#L80). Задача плана: T02.

#### P07. Legacy-сотрудник без employment-записи считается active

**P1 перед массовым импортом/внешним входом · подтверждённый compatibility risk · онбординг и доступ.**

resolve() возвращает ACTIVE/source=legacy, если строки нет. Это документированная совместимость. Саморегистрация имеет register_pending, а capability/user-suspended проверки продолжают действовать.

**Последствие:** Процесс создания аккаунтов вне проверенного registration writer может остаться в legacy-режиме. Это не подтверждённый обход авторизации: результат зависит также от выдачи Moodle ролей и существующих guards.

**Исправление:** Инвентаризировать writers регистрации/HR/import/SSO; согласовать завершение legacy migration window. Новый onboarding должен создавать explicit lifecycle, существующих людей переводить только по проверенному плану, без массового отключения.

**Критерий закрытия:** Новый пользователь каждым входным путём; pending не получает учебный/управленческий доступ; старые допустимые аккаунты и suspended/terminated проверяются раздельно.

**Код/свидетельства:** [moodle/local/ustar/classes/employment.php:17](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/employment.php#L17); [moodle/local/ustar/classes/employment.php:42](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/employment.php#L42); [moodle/local/ustar/classes/library_access.php:24](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/library_access.php#L24). Задача плана: T06.

### Пользовательский опыт и интерфейс

#### U01. Карточка ведёт в Маркетплейс, который этому сотруднику запрещён

**P1 · воспроизведено на реальных readers · каталог и проверки.**

Для published paid catalog offer без гранта catalog_allowed(teaser=true) даёт карточку; URL создаётся tab=shop&open=1. При выключенном отделе can_view_marketplace=false, can_view_offer_page=false. library_probe_result.json воспроизводит этот переход. Аналогичный CTA checks требует проверки тем же кейсом.

**Последствие:** Сотрудник видит доступное действие и после нажатия получает отказ. Защита сервера работает, но путь к получению доступа оборван.

**Исправление:** Выводить состояние «Доступ по назначению» без ссылки в закрытый раздел; предоставить допустимый способ обращения/назначения в существующем процессе. Не открывать marketplace ради исправления кнопки.

**Критерий закрытия:** Пустой allowlist, другой отдел, платный/assigned/gated оффер, действующий грант и обязательный маршрут; карточка не содержит заведомо запрещённого CTA.

**Код/свидетельства:** [moodle/local/ustar/classes/library_view.php:45](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/library_view.php#L45); [moodle/local/ustar/classes/library_view.php:70](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/library_view.php#L70); [moodle/local/ustar/library.php:15](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/library.php#L15); [moodle/local/ustar/classes/library_access.php:75](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/library_access.php#L75). Задача плана: T01.

#### U02. После 500 активных грантов старый материал исчезает из поиска

**P1 · воспроизведено на реальных readers · сотрудники с большой библиотекой.**

mine() запрашивает лишь500 последних грантов, затем применяет поиск и pagination. Probe создаёт501 уникальный грант, ищет ONLY-OLDEST и получает0, хотя запись активна. Учебная история в probe пуста, поэтому независимый fallback не маскирует дефект.

**Последствие:** Часть оплаченного/назначенного контента становится необнаружимой через «Мои материалы». Само право в БД не теряется и прямой разрешённый путь может работать.

**Исправление:** Запрашивать полную логическую выдачу по пользователю и фильтру с корректной дедупликацией до страницы; явный hasnext/cursor. Не устранять лимит простым чтением всех body/проверкой всех ACL в память.

**Критерий закрытия:** 501 и2000 грантов, поиск старейшего, несколько грантов одного offer, revoke/future/expiry, независимые route материалы; страницы без пропусков и утечек.

**Код/свидетельства:** [moodle/local/ustar/classes/library_view.php:11](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/library_view.php#L11); [moodle/local/ustar/classes/library_view.php:24](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/library_view.php#L24); [moodle/local/ustar/classes/library_grant.php:116](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/library_grant.php#L116). Задача плана: T01.

#### U03. Административные селекторы и история молча обрезаются

**P1 для масштабирования контента · код: подтверждено · авторы и руководители.**

Селектор предметов берёт первые500 строк каждого типа; точки —500; история грантов —100; список ручных проверок —100. Pagination предложений существует, но эти независимые списки собственной навигации/поиска не имеют.

**Последствие:** Старое назначение нельзя найти для отзыва из этой истории, поздний материал — выбрать из формы. Выбранный существующий объект за лимитом может отсутствовать в option и создавать ошибку сохранения.

**Исправление:** Scoped autocomplete/pagination по каноническим объектам; включать текущий выбранный объект, даже если он вне страницы. Историю фильтровать по сотруднику/материалу/status и дать переходы.

**Критерий закрытия:** 501 предмет,101 grant/check; старую запись можно найти и изменить; запрещённые сотрудники не появляются даже в поиске и total.

**Код/свидетельства:** [moodle/local/ustar/library_manage.php:58](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/library_manage.php#L58); [moodle/local/ustar/library_manage.php:71](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/library_manage.php#L71); [moodle/local/ustar/library_manage.php:96](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/library_manage.php#L96); [moodle/local/ustar/library_manage.php:113](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/library_manage.php#L113). Задача плана: T01.

#### U04. В редакторе новой проверки нельзя добавить шестой вопрос

**P1 для авторского процесса · код + DOM воспроизведение · проверки знаний.**

Форма создаёт max(5,count(existing)) questionfields. Для новой проверки это5; кнопок добавления/удаления блоков нет. Validator допускает до100, но UI не предоставляет этот путь. Реальный Mustache в JSDOM содержит5textarea и единственную кнопку сохранения.

**Последствие:** Создать проверку из6+ вопросов штатным интерфейсом нельзя; существующий банк из5 вопросов также нельзя расширить.

**Исправление:** Добавление/удаление/перестановка вопросов с устойчивыми ключами и сохранением correct mapping; limits1–100 в UI и сервере. Не добавлять альтернативную модель банка.

**Критерий закрытия:** Создать6, добавить101 с отказом, удалить/переставить ответ и вопрос; correct не меняет смысл; ошибочная форма сохраняет введённые данные.

**Код/свидетельства:** [moodle/local/ustar/library_manage.php:107](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/library_manage.php#L107); [moodle/local/ustar/templates/library_manage.mustache:36](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/templates/library_manage.mustache#L36); [moodle/local/ustar/classes/library_assessment.php:122](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/library_assessment.php#L122). Задача плана: T02.

#### U05. Поиск и страницы теряют контекст и дают неточное пустое состояние

**P2 · код + DOM воспроизведение · ежедневная навигация.**

Search form передаёт толькоtab/q, поэтому поиск внутри catalog parent сбрасывает раздел. Навигация содержит только «Следующая страница», без предыдущей/номера. Проверки при непустом запросе без совпадений говорят «Проверок пока нет…появятся после публикации».

**Последствие:** Пользователь не понимает область поиска и может решить, что учебный контент вообще не опубликован. Browser Back частично помогает, но не заменяет явное состояние.

**Исправление:** Задать явную семантику поиска в разделе/во всей библиотеке; сохранять фильтры и return context; previous/next/current page; различать пустой каталог и отсутствие совпадений.

**Критерий закрытия:** Поиск в nested folder → карточка → назад; page2 → previous; пустые данные и пустой результат поиска имеют разный текст.

**Код/свидетельства:** [moodle/local/ustar/templates/library.mustache:12](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/templates/library.mustache#L12); [moodle/local/ustar/templates/library.mustache:18](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/templates/library.mustache#L18); [moodle/local/ustar/library.php:80](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/library.php#L80); [moodle/local/ustar/templates/library_checks.mustache:3](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/templates/library_checks.mustache#L3). Задача плана: T02.

#### U06. Отзыв доступа и возврат выполняются одним кликом без обзора последствий

**P2 · подтверждённый UX-риск · управление доступом.**

Формы revoke/refund сразу POSTят действие, без отдельного confirmation шага; серверные actor/sesskey/правила операции при этом есть. Текст кнопки возврата объясняет общий смысл, но нет обзора конкретного получателя/материала/суммы.

**Последствие:** Легче ошибиться строкой в длинном списке. Это не CSRF-уязвимость и не доказанная потеря средств.

**Исправление:** Доступное подтверждение с конкретными данными операции или безопасный review step; не заменять идемпотентность и серверные проверки window.confirm.

**Критерий закрытия:** Клавиатурная отмена не меняет данные; подтверждение показывает точный объект; stale/replayed POST не выполняет другую операцию.

**Код/свидетельства:** [moodle/local/ustar/templates/library_manage.mustache:30](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/templates/library_manage.mustache#L30); [moodle/local/ustar/library_manage.php:1](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/library_manage.php#L1); [moodle/local/ustar/classes/library_grant.php:70](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/library_grant.php#L70). Задача плана: T02.

#### U07. Браузерные PASS не доказывают WCAG AA и полный ролевой UX

**P1 для заявления о соответствии; P2 внутренний · пробел доказательств · доступность интерфейса.**

Есть36 synthetic views с responsive/light-dark/touch/одним Tab и отдельные DOM/Studio suites. Нет в проверенной цепочке полного screen-reader traversal, WCAG matrix или task-based измерения реальных ролей. В Studio уже существуют move-up/down — утверждение «только drag» опровергнуто.

**Последствие:** Нельзя утверждать отсутствие всех проблем доступности или оценить cognitive load по скриншотам. Это не подтверждение нарушения всех критериев WCAG.

**Исправление:** Добавить автоматизированные a11y проверки и ручной маршрут keyboard/screen reader; проверить 200–400% zoom,320CSSpx reflow,focus restore/error association/контраст всех состояний.

**Критерий закрытия:** Матрица применимых WCAG2.2 AA: результат и evidence на реальные экраны; автоматический скан дополняется ручными сценариями.

**Код/свидетельства:** [tests/mobile/library.cjs:1](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/tests/mobile/library.cjs#L1); [tests/release_20260912/course_studio_dom.cjs:1](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/tests/release_20260912/course_studio_dom.cjs#L1); [moodle/local/ustar/course_studio/editor.html:5](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/course_studio/editor.html#L5). Задача плана: T05.

#### U08. Часть пользовательских строк и брендинга зашита в код

**P2; P1 для многоязычного white-label · код: подтверждено · внешний продукт.**

Новые Library/Studio тексты и экспортированный runtime содержат русский текст и USTAR напрямую. lang API существует в проекте, но применяется непоследовательно.

**Последствие:** Локализация/брендинг требуют патча исходников; рост вариантов языка ухудшит сопровождение. Для текущей русскоязычной компании это не blocker.

**Исправление:** Постепенно переносить видимые строки в Moodle language strings/JS boot dictionary; бренд и export labels брать из согласованной site config.

**Критерий закрытия:** Русский и второй тестовый язык, длинные строки, export metadata, экранирование и fallback; бренд не меняет права/идентичность проектов.

**Код/свидетельства:** [moodle/local/ustar/library.php:50](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/library.php#L50); [moodle/local/ustar/templates/library.mustache:3](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/templates/library.mustache#L3); [moodle/local/ustar/course_studio/model.js:26](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/course_studio/model.js#L26); [moodle/local/ustar/course_studio/editor.html:1](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/course_studio/editor.html#L1). Задача плана: T07.

### Бэкенд, архитектура и код

#### E01. N+1 остаётся внутри пакетного resolver Маркетплейса

**P1 · воспроизведено на реальном коде · рост каталога.**

resolve_many пакетирует grants, но evaluate повторяет eligible/is_authoring и читает предмет для каждого offer. Все published offers загружаются до page slice. Probe:24/240/2400 offers →101/965/9605 DB API calls, при отображении тех же24 карточек. Это calls синтетического adapter, не измеренное число wire SQL Moodle/PostgreSQL и не latency.

**Последствие:** Рост работы пропорционален всему каталогу, включая невидимую часть; page size24 не ограничивает стоимость запроса.

**Исправление:** Request-local actor facts + batch subjects/ancestors + пакетное решение ACL; bounded candidate scan/cursor, сохранив ACL до totals/pagination. Не вводить межпользовательский cache прав.

**Критерий закрытия:** Native DB perf_get_reads для24/240/2400, одинаковые карточки/сортировка/visibility; отозванные права и пустые страницы; SQL EXPLAIN только на репрезентативном стенде.

**Код/свидетельства:** [moodle/local/ustar/classes/library_store.php:61](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/library_store.php#L61); [moodle/local/ustar/classes/library_access.php:136](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/library_access.php#L136); [moodle/local/ustar/classes/library_access.php:160](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/library_access.php#L160); [moodle/local/ustar/classes/library_access.php:192](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/library_access.php#L192). Задача плана: T03.

#### E02. Другие вкладки Библиотеки также проверяют весь набор до пагинации

**P1 при большом каталоге · код: подтверждено · каталог, проверки, личные материалы.**

mine по гранту читает offer, затем resolve снова offer. catalog делает catalog_allowed дважды и for_subject; ancestry идёт запросом на уровень. checks читает все published, для каждой can_open/best; best повторно читает definition. slice выполняется после работы.

**Последствие:** Это отдельные горячие пути, которые не исправятся только оптимизацией storefront. Иерархия увеличивает множитель.

**Исправление:** Общие batch readers в текущем Library domain; передавать уже прочитанные definitions/offers; загрузить ancestor map и best attempts пачкой.

**Критерий закрытия:** Query budget по каждой вкладке и глубине каталога, одинаковый ACL/expiry/route entitlement. Сохранить независимые права маршрута.

**Код/свидетельства:** [moodle/local/ustar/classes/library_view.php:11](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/library_view.php#L11); [moodle/local/ustar/classes/library_view.php:44](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/library_view.php#L44); [moodle/local/ustar/classes/library_view.php:60](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/library_view.php#L60); [moodle/local/ustar/classes/library_assessment.php:114](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/library_assessment.php#L114); [moodle/local/ustar/classes/library_runtime.php:85](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/library_runtime.php#L85). Задача плана: T03.

#### E03. Повторный расчёт области управления внутри списка сотрудников

**P1 для крупных подразделений · код: подтверждённый путь O(N²) при растущем scope · HR/руководители.**

library_manage уже получает scope, но в цикле каждого кандидата вызывает can_target; для не-admin can_target повторяет access_context::scope. Company scope опять перебирает всех users; manager scope обходится по descendants с запросами. organization_directory также resolve-ит каждого отдельно.

**Последствие:** HR с COMPANY_READ, но без admin bypass, может многократно обходить один и тот же набор. Отдельные account caches не устраняют повтор полного списка/identity. Фактическое время не измерено.

**Исправление:** Одно scoped read context на запрос, batched identities/employment/occupants, paginated employee picker. На каждой записи заново проверить актуальный scope под прежними guards.

**Критерий закрытия:** 10/100/1000 сотрудников, HR non-admin/manager/acting/перевод; bounded read growth и запрет stale scope при submit.

**Код/свидетельства:** [moodle/local/ustar/library_manage.php:85](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/library_manage.php#L85); [moodle/local/ustar/classes/library_access.php:118](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/library_access.php#L118); [moodle/local/ustar/classes/access_context.php:32](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/access_context.php#L32); [moodle/local/ustar/classes/organization_model.php:177](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/organization_model.php#L177); [moodle/local/ustar/classes/organization_directory.php:12](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/organization_directory.php#L12). Задача плана: T03.

#### E04. Studio ищет проект сканированием всех JSON и держит общий lock

**P1 при росте авторов и курсов · код: подтверждено; contention не измерен · редакторы.**

find(code) читает все SCORM blueprints c sourcejson, декодирует до совпадения. listing тоже читает все body. save/open_material держат hierarchy lock10s; внутри — assets checks, find и transaction.

**Последствие:** Чем больше/тяжелее курсы, тем дороже даже get/save одного. Независимые авторы конкурируют за один lock; это риск timeout, не доказанный deadlock.

**Исправление:** Сначала ограничить lookup по существующему contentid и проверять соответствие code; metadata listing без всех body. Затем по concurrency evidence сузить hierarchy lock до структурных изменений, сохранив порядок per-project locks; новая проекция только с ADR.

**Критерий закрытия:** 100/1000 проектов со значительным sourcejson; два автора разных/одного проекта; revision conflicts, delete/move/publish и interrupted save без потери источника.

**Код/свидетельства:** [moodle/local/ustar/classes/course_studio.php:15](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/course_studio.php#L15); [moodle/local/ustar/classes/course_studio.php:38](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/course_studio.php#L38); [moodle/local/ustar/classes/course_studio.php:80](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/course_studio.php#L80); [moodle/local/ustar/classes/course_studio.php:100](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/course_studio.php#L100). Задача плана: T04.

#### E05. Маршрут сочетает чтение с синхронизацией и запросами на каждую точку

**P2; измерить до массового запуска · код: подтверждено · обучение.**

for_user(default) читает current version по точке, обеспечивает enrolment и синхронизирует assessment/completion для доступной последовательности. evaluate_point читает progress. read_only_snapshot уже частично batch-ит и не должен считаться тем же writer. library_runtime повторно читает версии по snapshot points.

**Последствие:** Рендер/request может включать дорогую и побочную работу; сложнее диагностировать p95 и безопасно переиспользовать reader. Эти записи легитимны по текущему runtime, не объявлены несанкционированной мутацией.

**Исправление:** Характеризовать пути default/readonly/preview; пакетировать текущие версии/progress. Разделять presenter и orchestration постепенно, сохранив немедленное открытие следующей точки и канонический completion writer.

**Критерий закрытия:** 10/100 точек, locked future не оценивается, preview0writes, SCORM/Quiz completion/renewal/reset/rewards не дублируются.

**Код/свидетельства:** [moodle/local/ustar/classes/route_model.php:2627](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/route_model.php#L2627); [moodle/local/ustar/classes/route_model.php:2685](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/route_model.php#L2685); [moodle/local/ustar/classes/route_model.php:2712](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/route_model.php#L2712); [moodle/local/ustar/classes/route_model.php:1949](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/route_model.php#L1949); [moodle/local/ustar/classes/library_runtime.php:47](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/library_runtime.php#L47). Задача плана: T04.

#### E06. Сосредоточение нескольких ответственностей в крупных объектах и страницах

**P2 технический долг · код и воспроизводимая метрика · стоимость разработки.**

route_model:3229 строк/48 методов (CRUD,версии,scope,evaluation,enrolment,presentation); content_admin:2587/16 (файлы,версии,публикация,архив); catalog_mastery:2016/16 (попытки,вопросы,SCORM cards); home:2194 строк объединяет controller/render. Метрика не доказывает дефект сама по себе; основания — реально смешанные обязанности. Большой upgrade.php — история миграций, не повод переписать её.

**Последствие:** Риск незаметного влияния изменения на соседние домены и высокая стоимость review. Минифицированные/плотные first-party JS/PHP дополнительно затрудняют точечный diff.

**Исправление:** Сохранять публичные фасады и вытаскивать reader/presenter/codec по одной ответственности при соответствующей задаче. Отдельное форматирование first-party файлов, без vendor и без логических правок. Не вводить микросервисы/DI везде.

**Критерий закрытия:** Characterisation tests до extraction, одинаковый HTML/API/SQL результат, guards и lock order; inventory/code-map обновляется.

**Код/свидетельства:** [moodle/local/ustar/classes/route_model.php:1](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/route_model.php#L1); [moodle/local/ustar/classes/content_admin.php:148](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/content_admin.php#L148); [moodle/local/ustar/classes/catalog_mastery.php:928](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/catalog_mastery.php#L928); [moodle/local/ustar/home.php:1](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/home.php#L1). Задача плана: T08.

#### E07. Connect останавливается при 10 000 событиях младше семи дней

**P1 до массового подключения · воспроизведено actual SQLite Journal · доставка и доступность бота.**

prepare_poll удаляет до500 terminal events только старше604800s; begin при COUNT>=10000 выдаёт journal_full. Реальный Journal на10k synthetic sent events отказал; после7дней уборка дала9500 и приём возобновился. Runtime возвращает4, штатный unit содержит RestartPreventExitStatus=2 3 4. Worker tree совпадает с установленным267.

**Последствие:** Около1429 событий/сутки за7дней уже достаточно для достижения порога; это арифметика лимита, не измеренная нагрузка. После stop уборка в этом процессе сама не продолжится. Проверить effective unit overrides на сервере отдельно.

**Исправление:** Порог/retention и alert headroom по traffic budget; сохранить replay guards и uncertain semantics. Разделить operational history/rate window/dedup watermark, предусмотреть bounded recovery без повторной отправки и без удаления неизвестных исходов.

**Критерий закрытия:** 10k+ поток реальных begin/finish, restart/full/disk-full/old duplicate/uncertain; доказать отсутствие двойной команды и доставки.

**Код/свидетельства:** [connect-worker/ustar_connect/receiver_journal.py:94](https://github.com/cauf1l3d/ustarlms/blob/267e55874eb8f6c36259d748f28a2c636adc9810/connect-worker/ustar_connect/receiver_journal.py#L94); [connect-worker/ustar_connect/receiver_journal.py:110](https://github.com/cauf1l3d/ustarlms/blob/267e55874eb8f6c36259d748f28a2c636adc9810/connect-worker/ustar_connect/receiver_journal.py#L110); [connect-worker/ustar_connect/receiver_runtime.py:166](https://github.com/cauf1l3d/ustarlms/blob/267e55874eb8f6c36259d748f28a2c636adc9810/connect-worker/ustar_connect/receiver_runtime.py#L166); [connect-worker/deploy/ustar-connect-receiver.service:44](https://github.com/cauf1l3d/ustarlms/blob/267e55874eb8f6c36259d748f28a2c636adc9810/connect-worker/deploy/ustar-connect-receiver.service#L44). Задача плана: T03.

#### E08. Не измерена ёмкость системы на рабочем дне

**P1 как gate расширения · пробел доказательств · все подразделения.**

PERF-01 остаётся открытым; observer из2900 требует адаптации к0200. Нельзя получить SQL/page,Web-SAPI OPcache,concurrent users или p95 из статуса systemd/Apache aggregate.

**Последствие:** Невозможно обоснованно обещать скорость для500/5000 людей или выбрать размер сервера. Процент покрытия кода также не следует из числа тестов.

**Исправление:** Read-only observer successor под точный установленный manifest; пассивный день и synthetic representative workload на staging. Согласовать SLI/SLO и бюджеты запросов/очередей/ошибок.

**Критерий закрытия:** Отчёт с объёмом выборки/ролями/маршрутами, p50/p95/p99,DB reads/time,cron/delivery lag,CPU/RAM/IO; без restart/pg_stat_statements enable на prod без отдельного плана.

**Код/свидетельства:** [context/tasks/ACTIVE.md:1](https://github.com/cauf1l3d/ustarlms/blob/a1dfe7c2b86e984bebbd03de2ffbef9d9900a7fb/context/tasks/ACTIVE.md#L1); [context/runtime/perf2802_owner_browser_acceptance_20261029.json:1](https://github.com/cauf1l3d/ustarlms/blob/a1dfe7c2b86e984bebbd03de2ffbef9d9900a7fb/context/runtime/perf2802_owner_browser_acceptance_20261029.json#L1); [context/runtime/infra_closure_matrix_20261026.md:1](https://github.com/cauf1l3d/ustarlms/blob/a1dfe7c2b86e984bebbd03de2ffbef9d9900a7fb/context/runtime/infra_closure_matrix_20261026.md#L1). Задача плана: T05.

#### E09. main и установленный source снова расходятся

**P1 перед следующим интеграционным выпуском · Git и owner receipt: подтверждено · сопровождение и релизы.**

main baseline5cc02944/local2900, фактически установлен app5e61/local0200 из unmerged PR112/113/114. Разделение теперь честно записано, но default clone main не воспроизводит текущую Академию.

**Последствие:** Новый инженер или release pipeline может собрать старое приложение; растёт сложность ancestry/rollback и повторения аудита.

**Исправление:** Подготовить единый integration candidate с точными app/worker trees и migration parity; owner отдельно разрешает merge. Затем supported release/tag, manifest и concise current pointers, исторические receipts сохраняются.

**Критерий закрытия:** Compare merge tree с принятым приложением, combined FullRC на exact SHA, bootstrap от supported release. Никаких автоматических merge из этого аудита.

**Код/свидетельства:** [context/roadmap/STATE.yaml:1](https://github.com/cauf1l3d/ustarlms/blob/a1dfe7c2b86e984bebbd03de2ffbef9d9900a7fb/context/roadmap/STATE.yaml#L1); [context/runtime/studio_scorm_edit_owner_deploy_20261103.json:1](https://github.com/cauf1l3d/ustarlms/blob/a1dfe7c2b86e984bebbd03de2ffbef9d9900a7fb/context/runtime/studio_scorm_edit_owner_deploy_20261103.json#L1); [context/code_map/development_source.json:1](https://github.com/cauf1l3d/ustarlms/blob/a1dfe7c2b86e984bebbd03de2ffbef9d9900a7fb/context/code_map/development_source.json#L1). Задача плана: T09.

#### E10. Нет единого доказательства состава и уязвимостей всей поставки

**P1 до внешнего выпуска · пробел покрытия, не найденная CVE · supply chain.**

Есть pinned Actions,requirements locks,source manifests,vendor licenses и npm audit для console. Полного сопоставленного с фактическим Moodle/image/addons/JS/Python SBOM и результата advisory scan всей поставки в рассмотренной release evidence нет. Legacy frontend исключён из активного выпуска.

**Последствие:** Нельзя говорить «CVE нет» или готовить внешний distributable без версии/происхождения/условий сторонних компонентов.

**Исправление:** SBOM+dependency provenance по реально поставляемым компонентам, versioned advisory scan и triage; inventory лицензий. Не включать legacy frontend в active risk без факта его развёртывания.

**Критерий закрытия:** Manifest→artifact→SBOM совпадают; fixture с известной зависимостью вызывает finding; решения/исключения с владельцем и сроком, без автоматического major upgrade.

**Код/свидетельства:** [.github/workflows/ustar-review-gate.yml:1](https://github.com/cauf1l3d/ustarlms/blob/a1dfe7c2b86e984bebbd03de2ffbef9d9900a7fb/.github/workflows/ustar-review-gate.yml#L1); [.github/workflows/ustar-console.yml:41](https://github.com/cauf1l3d/ustarlms/blob/a1dfe7c2b86e984bebbd03de2ffbef9d9900a7fb/.github/workflows/ustar-console.yml#L41); [moodle/local/ustar/course_studio/vendor/JSZIP-LICENSE.md:1](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/course_studio/vendor/JSZIP-LICENSE.md#L1); [server-console/requirements.lock:1](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/server-console/requirements.lock#L1). Задача плана: T07.

### Инфраструктура и эксплуатация

#### I01. Полное восстановление на независимом чистом хосте не подтверждено

**P1 для SLA внешнего продукта · принятый риск и пробел доказательств · DR.**

Backup/HDD/encrypted companion и изолированные drills существуют; копии переданы. Внешний clean-host restore и whole-host RPO/RTO остаются unknown по решению владельца.

**Последствие:** Backup success не гарантирует восстановление пользователей,файлов,секретов,cron,Connect и DNS после потери единственного сервера.

**Исправление:** Согласовать RPO/RTO и один clean-host rehearsal с актуальным release/restore inventory. Не повторять принятые storage/OS циклы и не удалять рабочие копии.

**Критерий закрытия:** Восстановленная изолированная Academy: login,SCORM progress,task files,balances,disabled provider sends; измеренное время и точки потери данных.

**Код/свидетельства:** [context/runtime/infra_closure_matrix_20261026.md:1](https://github.com/cauf1l3d/ustarlms/blob/a1dfe7c2b86e984bebbd03de2ffbef9d9900a7fb/context/runtime/infra_closure_matrix_20261026.md#L1); [context/roadmap/ACCEPTED_RISKS.yaml:1](https://github.com/cauf1l3d/ustarlms/blob/a1dfe7c2b86e984bebbd03de2ffbef9d9900a7fb/context/roadmap/ACCEPTED_RISKS.yaml#L1); [context/runtime/BACKUP_RESTORE.md:1](https://github.com/cauf1l3d/ustarlms/blob/a1dfe7c2b86e984bebbd03de2ffbef9d9900a7fb/context/runtime/BACKUP_RESTORE.md#L1). Задача плана: T10.

#### I02. Внешний периметр имеет незакрытые условия допуска

**P1 до открытия в интернет · принятый риск; не свежий pentest · внешний продукт.**

LAN парольный доступ принят; SSH/firewall ждут внешнего периметра. Проверка общей www-data/caddy boundary ограничена. У console есть отдельная ops auth и Unix peer allowlist; это не публичная Academy login. HTTPS уже принят в своей области — утверждать «TLS нет» неверно.

**Последствие:** Из текущей LAN-приёмки нельзя вывести защищённость публичной установки, MFA/abuse/rate/restore-secret boundary.

**Исправление:** Threat model и ASVS L2-oriented matrix по Academy/API/File/SCORM/RSS/Connect/console. Отдельно внешние ACL/IPv6/NAT,admin/ops boundary,auth/CSRF/upload/SSRF/rate и негативные сценарии.

**Критерий закрытия:** Независимый staging security review и effective-config evidence; отсутствуют неприемлемые риски до publish, каждый accepted risk имеет trigger/owner.

**Код/свидетельства:** [context/roadmap/ACCEPTED_RISKS.yaml:1](https://github.com/cauf1l3d/ustarlms/blob/a1dfe7c2b86e984bebbd03de2ffbef9d9900a7fb/context/roadmap/ACCEPTED_RISKS.yaml#L1); [context/runtime/infra_closure_matrix_20261026.md:1](https://github.com/cauf1l3d/ustarlms/blob/a1dfe7c2b86e984bebbd03de2ffbef9d9900a7fb/context/runtime/infra_closure_matrix_20261026.md#L1); [server-console/backend/ustar_console/operations.py:318](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/server-console/backend/ustar_console/operations.py#L318). Задача плана: T06.

#### I03. Ротация логов и воспроизводимый production image отложены

**P2 сейчас; P1 перед тиражированием · принятый риск · операции.**

Docker rotation/health/recreate и переход к reproducible Dockerfile deferred до планового Moodle/PHP окна. Анализ writable layer существует, но не равен build spec.

**Последствие:** Рост логов может занять диск; на другом сервере нельзя полагаться на ручной mutable layer. Текущая заполненность не измерена заново.

**Исправление:** В согласованном окне pinned image,задокументированные пакеты,log/health budgets и backup/cron/HTTPS проверка. При abnormal disk growth пересмотреть срок раньше по accepted trigger.

**Критерий закрытия:** Rebuild identity, clean start, controlled rollback, bounded logs и recovery; не запускать старый frozen successor.

**Код/свидетельства:** [context/decisions/0018-container-runtime.md:1](https://github.com/cauf1l3d/ustarlms/blob/a1dfe7c2b86e984bebbd03de2ffbef9d9900a7fb/context/decisions/0018-container-runtime.md#L1); [context/runtime/docker_runtime_successor_20261026.md:1](https://github.com/cauf1l3d/ustarlms/blob/a1dfe7c2b86e984bebbd03de2ffbef9d9900a7fb/context/runtime/docker_runtime_successor_20261026.md#L1). Задача плана: T10.

#### I04. Независимое обнаружение потери хоста и shutdown ИБП не доказаны

**P2 внутри; P1 при SLA · пробел доказательств · доступность.**

Пульт/уведомления есть, но независимый от того же сервера host-loss observer не подтверждён. ИБП присутствует; автоматическое выключение и возврат не приняты отдельным тестом.

**Последствие:** Если хост полностью пропал, локальный монитор может не сообщить. Наличие ИБП не гарантирует консистентность при длительном отключении.

**Исправление:** Отдельная точка health observation и согласованный канал, shutdown/recovery drill в окне; уведомления только по отдельному разрешению адресатам.

**Критерий закрытия:** Контролируемое отсутствие heartbeat обнаружено снаружи; blackout simulation на безопасном стенде; корректное восстановление БД/cron и предотвращение ложных тревог.

**Код/свидетельства:** [context/runtime/infra_closure_matrix_20261026.md:1](https://github.com/cauf1l3d/ustarlms/blob/a1dfe7c2b86e984bebbd03de2ffbef9d9900a7fb/context/runtime/infra_closure_matrix_20261026.md#L1); [context/runtime/MONITORING.md:1](https://github.com/cauf1l3d/ustarlms/blob/a1dfe7c2b86e984bebbd03de2ffbef9d9900a7fb/context/runtime/MONITORING.md#L1). Задача плана: T10.

#### I05. Свежая успешность cron/доставки/почты не следует из установки overlay

**P1 при gate массового запуска · пробел доказательств · фоновые бизнес-процессы.**

Последний SCORM receipt проверяет source-only overlay; он не запускает новый cron/drain/SMTP delivery аудит. Исторические failed/overdue значения сохраняются с датами; актуальное состояние неизвестно.

**Последствие:** Открывающиеся страницы не гарантируют своевременные назначения,эскалации,quick checks и внешние уведомления. Нельзя называть старые ошибки действующим инцидентом без свежего наблюдения.

**Исправление:** Включить readonly scheduled/ad-hoc/task lag и outbox/delivery counters в workday evidence; проверить маршруты доставки с утверждёнными тестовыми получателями.

**Критерий закрытия:** Обычный рабочий цикл,нет нового ростаbacklog,known failures классифицированы; историю не сбрасывать ради зелёного статуса.

**Код/свидетельства:** [context/roadmap/STATE.yaml:33](https://github.com/cauf1l3d/ustarlms/blob/a1dfe7c2b86e984bebbd03de2ffbef9d9900a7fb/context/roadmap/STATE.yaml#L33); [context/runtime/feed2803_owner_deploy_20261029.json:1](https://github.com/cauf1l3d/ustarlms/blob/a1dfe7c2b86e984bebbd03de2ffbef9d9900a7fb/context/runtime/feed2803_owner_deploy_20261029.json#L1); [context/runtime/studio_scorm_edit_owner_deploy_20261103.json:1](https://github.com/cauf1l3d/ustarlms/blob/a1dfe7c2b86e984bebbd03de2ffbef9d9900a7fb/context/runtime/studio_scorm_edit_owner_deploy_20261103.json#L1). Задача плана: T05.

## Что уже исправлено или не подтвердилось

- Старые N+1 статистики RSS и имён competition не переносить: `feed_admin.php:178` использует source_stats_batch; `competition.php:315` batch scoreboard. Прежний PR109/receipt и тест query_batching — отдельные доказательства; нынешний локальный повтор этого теста ограничен отсутствием PDO SQLite.
- Нельзя утверждать, что доступ закрыт только CSS: Library проверяет entitlement на file/native boundaries, marketplace allowlist и view-as. Marketplace/source contract сейчас прошёл27 проверок.
- Нельзя называть покупку неидемпотентной: offer lock → canonical wallet → grant, quote/revision/nonce и ledger replay checks присутствуют. Локальные refusal contracts пройдены.
- Нет оснований утверждать, что у проекта отсутствуют тесты, история действий или ролевое меню. Они существуют; вопрос в точном покрытии, нагрузке и usability конкретных сценариев.
- У Studio есть клавиатурные move-up/down, local journal, revision conflict/keep-both и предупреждение при pending changes. «Только drag» и «нет защиты от конфликтов» неверны.
- Старый PHP-lint инцидент installer a8 закрыт successor4aee и подтверждён владельцем. Не требует повторной установки/recovery.
- Не возвращать снятые claims «app DB superuser», «code/config writable», «HTTPS не установлен»: принятый hardening имеет датированную область. Внешняя boundary-проверка остаётся отдельной.
- Формальная длина файла, `PARAM_RAW`, JSON или наличие global DB не являются самостоятельным доказательством XSS/SQLi/god object. Проверять фактическую цепочку и ответственность.

## Проверки этого аудита

[Результаты](verification.json), [DB-call/501/CTA probe](library_probe_result.json), [Connect capacity](connect_capacity_result.json), [DOM probe](ui_probe_result.json), [метрики](code_metrics.json), [машинный реестр](findings.json). Исходники probes в `probes/`; без внешней сети и Moodle bootstrap. Это диагностические демонстрации текущего поведения, не regression suite исправлений.

На metadata commit `a1dfe7c2` подтверждены все4 workflows и8FullRC jobs. На Moodle5.1.1 и5.1.8 —423теста/1957assertions в каждом; это регрессии существующего покрытия, а не доказательство отсутствия найденных ошибок. Локально повторены41 receiver test,7PHP contract scripts и3диагностических probes; полный JS/DOM набор прошёл после настройки PHP PATH. Локальный query_batching заблокирован отсутствием PDO SQLite, console HTTP suite — отсутствием FastAPI; соответствующие CI отмечены отдельно.

В `library_reads.php` контракты Moodle/ACL заменены минимальными synthetic fixtures, production readers подключаются напрямую. Число calls — нижняя иллюстрация затрат выбранного пути, не измеренный PHP-FPM/PG latency. В `connect_capacity.py` выполняется реальный Journal/SQLite на private tempdir; процесс systemd не запускался. DOM probe не измеряет layout/контраст/assistive technology.

## Очерёдность

Сначала T01–T03: обнаружимость материалов, рабочие CTA, редактор проверок, пакетное чтение и Connect headroom. Затем T04–T06: Studio/route горячие пути, эксплуатационные и ролевые измерения, privacy/границы. Интеграцию source T09 готовить параллельно по смыслу, выполнять последовательно без субагентов и только после отдельного разрешения на merge. T07/T10 обязательны до внешнего пилота, T08 — малыми изменениями по доменам.

[Пошаговый план исправлений](../../../docs/superpowers/plans/2026-11-03-project-audit-remediation.md) содержит все30ID, ожидаемые проверки и ограничения rollout. Наличие плана не означает, что перечисленные дефекты уже исправлены.
