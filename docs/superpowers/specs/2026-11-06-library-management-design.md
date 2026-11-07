# Следующий пакет: управление Библиотекой —06.11.2026

## Основание и решение

Владелец принял предыдущий Preview пакет словами «все ок переходи к формированию следующего пакета». Это функциональная приёмка предпросмотра, без новой terminal receipt или измерений нагрузки. Ожидаемый predecessor app2cb233d9/tool ed292313 проверяется новым установщиком по653source files и active private Preview journal до outage. Новые merge/SSH/provider sends не разрешены; реализация inline без субагентов.

Продолжается уже порученный audit remediation T01/T02: U03/U04/U09/U10. Текущий library_manage.php обрезает sources500/history100, теряет POST после исключения, рисует только5вопросов и считает active только по revokedat. ADR0019/0017 и canonical writers сохраняются. Это исправление существующей страницы, без новой схемы или архитектуры.

Выбран существующий server-rendered GET поиск/страницы + небольшой AMD question editor. Альтернативы: поднять лимиты (повторяет исчезновение старых записей); отдельный SPA/новый JSON API (излишняя вторая поверхность прав/форм для этого пакета). Выбор реализации сделан агентом в пределах порученного последовательного исправления; новые продуктовые решения не требуются.

## Поведение

- Материал нового предложения ищется по типу/названию с server pagination. У существующего предложения immutable subject виден отдельно от страницы поиска и отправляется прежним значением; выбранная точка вне первого списка также сохраняется. Скрытых замен binding нет.
- Выбор сотрудников и история получают поиск/страницы. Проверка can_target выполняется до границы видимой страницы; недоступные записи не влияют на hasnext. Writer перепроверяет права после GET по-прежнему. Никаких глобальных entitlement caches.
- История показывает возвращён/отозван/истёк/будущий/действующий на точных validfrom/validto границах. Отображение не меняет право доступа, ledger или revoke/refund.
- При validation/stale conflict сохраняются введённые scalar поля, все допустимые вопросы и выбранные ответы. При конфликте expected revision остаётся исходным; запрет автоматического overwrite. Mustache экранирует ввод. Reload current version — явная ссылка, не скрытая подмена данных.
- Банк вопросов: добавить до100, удалить, переместить вверх/вниз, клавиатурные кнопки и объявление результата. Stable numeric keys не переиндексируют варианты; единственная нормализация writer editor_questions. Пустой слот посередине остаётся ошибкой, trailing blanks пропускаются прежним validator. Ошибка не закрывает редактор. При уходе с изменённой формы предупреждение браузера сохраняет шанс отменить переход.
- Схема/версии0200/worker267 прежние; покупки/обязательный маршрут/ACL/attempt snapshots не меняются. Не включаются T03 bulk readers/Connect retention, privacy, внешнее развёртывание или импорт SCORM; они остаются отдельными пакетами. История может читать несколько bounded windows до разрешённой страницы; это не заявление о решении всех N+1 или PostgreSQL latency. Полный gatepoint search и remaining global offers reader остаются T03/T04.

## Разложение

library_manage_form: только безопасная проекция form state/choice/question fields, без записей. library_manage_reader: авторизованные subject/target/history readers и presentation statuses, без writer calls. Controller сохраняет прежние dispatch/sesskey/real actor/writers и собирает данные из этих helpers. library_manage AMD + question partial управляют DOM; CSS использует существующие theme tokens.

## Доказательства и выпуск

Сначала local RED реального PHP controller (точная копия под synthetic bootstrap) теряет title/six questions и selected501 subject; DOM RED не может добавить6й вопрос. Затем actual Moodle browser на5.1.1/5.1.8 создаёт6question bank, изменяет order/correct, ошибочный POST сохраняет ввод и revision, fresh reload подтверждает save. Native PostgreSQL tests проверяют501subjects/101grants/current scope/status boundaries и malformed inputs. Обычный/dark/mobile fixture проверяет настоящий Mustache/AMD.

После native/source проверок — immutable owner successor от2cb, with exact complete manifest, preserved old journals/metadata/shared locks, offline plan/check/apply/recover and actualPHP/UID33 interruption/rollback tests. Архив обязан совпасть с CI и скачанной сохранённой копией. Owner функциональная приёмка после установки отдельно; не объявлять весь аудит закрытым.
