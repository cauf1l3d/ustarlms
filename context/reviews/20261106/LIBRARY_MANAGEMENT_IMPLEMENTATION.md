# Управление Библиотекой —06.11.2026

LIBRARY-MANAGEMENT-01, продолжение audit T01/T02 U03/U04/U09/U10. Владелец принял Preview общим «все ок» и поручил следующий пакет. [Приёмка](../../runtime/studio_preview_owner_acceptance_20261106.json) не содержит terminal manifest; ожидаемый predecessor2cb подтверждается source/journal guards, не догадкой о сервере.

## Причины и изменения

Исходный library_manage.php после moodle_exception заново читал DB/пустые формы. Точная копия настоящего controller под synthetic Moodle bootstrap теряет typed title/six rows и stale revision; это наблюдавшийся RED, не предположение по строкам. Страница имела фиксированные5блоков,500subject и100history caps, status только revokedat. DOM RED не может добавить6й вопрос. SQL/mocked boundary не заменяет native PostgreSQL.

App 53c9f16e615ec4e5701e21207176ffca5bfaecda: library_manage_form — только безопасная проекция rejected inputs, library_manage_reader — авторизованные поиски/страницы и presentation states. Controller сохраняет прежние canonical dispatch/writers/sesskey/real actor. Stable AMD keys и6slots на вопрос сохраняют correct answer при reorder/remove; server limit100 и strict middle blank validator прежние. Rejected form сохраняет исходный expected token и остаётся dirty для предупреждения об уходе. Два дополнительных RED→GREEN защищают отсутствующий correct choice от автоподстановки и keyboard focus от сброса в body. Некорректный enum-массив отображается пустым недопустимым выбором, а не меняет политику на разрешённую.

Subject поиск50/page, people/history20/page с SQL windows100; can_target до visible boundary/hasmore. История batch-hydrates user/offer; selected subject загружается отдельно и immutable binding показан readonly. Текущая gatepoint за старым500cap закрепляется отдельно. Checks за100 доступны поиском/страницами. GET context сохраняет edit/check и остальные фильтры; search сбрасывает только свою страницу. Статусы refunded/revoked/expired/future/active повторяют validfrom/validto границы без изменения domain access/ledger.

Source delta3changed +7added =660fixed files/108XMLDB tables, local/theme0200. Ни canonical library_assessment/offer/access/grant/store writers, ни schema/core/worker267 не менялись. T03 full offer resolution/HR query growth и полный поиск gatepoint остаются отдельными; bounded windows не являются доказательством PG latency или закрытия всех N+1.

## Проверки

Локально:7actual-controller cases под synthetic bootstrap PASS; source и shippedAMD DOM PASS (6й вопрос, reorder/remove/correct,100limit, restored draft guard/focus, immutable501 binding, GET context). Полный npm suite PASS. Реальные36browser views390/768/1440 light/dark/touch/keyboard PASS. 10.11.2026. Moodle 5.1.1 и 5.1.8 прошли по 10 целевых тестов и 53 проверки. В обоих браузерных сценариях создание банка из шести вопросов, отказ без записи, восстановление формы, перемещение и удаление с сохранением правильного ответа и повторное открытие прошли. Полные прогоны PostgreSQL прошли по 440 тестов и 2041 проверок. [Результаты](../../runtime/library_management_qualification_20261110.json).

Первый CI 31d1a942 остановил обе browser/native SQL проверки на тестовой выдаче прав: Moodle не регистрирует business operation `learning.use` как capability. Fixture использует каноническое `local/ustar:use` и по-прежнему проверяет изоляцию history/targets при выданном `librarygrant`. Production PHP/JS/шаблоны совпадают с d63a63d3.

Поставляемый AMD — standard named exports wrapper существующего ES module, проверяется тем же DOM regression; отдельный minifier не установлен и новая dependency не вводилась. Loader дополнительно проверяется настоящим Moodle RequireJS. Native cases используют501subjects/101history/101banks, hidden history before page, scope-empty nonadmin, suspension between picker and writer, view-as, exact temporal boundaries, malformed view projection и неизменный codec.

Новый operator от2cb сохраняет private active Preview+CSP+Library journals и полный source manifest до outage. Локальные19operator cases PASS с1conditional UID33 skip;4actualPHP lint payloads, rollback после addedfile/обрыва/failedstart, preserved controls проверены. На f374d2d9 прошли все 19 проверок установщика, включая реальный UID 33. Проверена побайтная идентичность локального архива, CI и скачанной копии. [Установка](../../../docs/LIBRARY_MANAGEMENT_INSTALL_RU.md).

Inline author review, без независимого агента по запрету владельца. Agent SSH/provider sends/merge/production mutations не выполнялись. Owner приёмка нового пакета отдельно.
