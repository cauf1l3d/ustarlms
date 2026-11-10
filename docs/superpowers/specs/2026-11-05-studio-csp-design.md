# Студия: запуск YUI при строгой CSP

05Nov2026. Owner сообщил EvalError в YUI._afterConfig и последующий reading one в javascript-static.js; подтвердил, что45e9d6b7 ещё не установлен. Production остаётся719bf47c/toolaa61d595.

Источник: course_studio.php18 задаёт script-src self unsafe-inline, без unsafe-eval. В pinned Moodle5.1.8 YUI._afterConfig использует Function("return this") только если config.global отсутствует. Moodle сначала выводит YUI_config в head, затем additionalhtmlhead, а YUI rollup загружает в начале body.

Выбранное минимальное исправление: только для запроса course_studio.php дописать доверенный inline bootstrap `YUI_config.global = window` через существующий additionalhtmlhead после авторизации и до header. Сохранить уже настроенное head-содержимое. Не писать config в БД, не менять Moodle core, CSP, роли, данные, iframe sandbox или worker.

Альтернативы: unsafe-eval в CSP одной страницы совместим с legacy, но не нужен, если передать штатный global; глобальное ослабление CSP и перепись Moodle/YUI отклонены существующими границами.

Проверка должна запускать настоящую страницу Moodle с настоящими YUI/AMD и API в Chromium, а не только отдельный editor.html. Негативные проверки подтверждают, что динамический eval по-прежнему блокируется, preview остаётся opaque-origin и не читает parent. Existing custom head сохраняется; на home bootstrap не переносится. Реальный save/reload подтверждает пригодность редактора.

Поставка: новый immutable cumulative successor от установленного719bf47c, включает непоставленный Route Studio45e и CSP-совместимость. Старые45e/aa61 и прочие операторы неизменны. Схема/local/theme0200,108таблиц и worker267 сохраняются; check/apply/recover, fullRC и CI/retained byte equality обязательны. Production и main не изменяются агентом.
