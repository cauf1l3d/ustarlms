# Connect: рабочий Telegram-бот по ролям

29.10.2026, Europe/Moscow. Owner поручил реализацию после приёмки2803 и напоминания.
Оба предварительных действия выполнены; основание — connect_bot_owner_order_20261029.json.
Это исполнение поручения, без повторного запроса метода работы/субагентов/approval.

## Результат

Один Telegram-бот с русскими кнопками: личная работа сотрудника, команда руководителя,
проверки HR, регистрации/адаптация HRD, сводка компании. Короткие объяснения, статус,
срок, явный следующий шаг, прогресс и небольшие таблицы. Slash commands — совместимость,
знать их не нужно. Mini App/public Academy HTTPS/Bitrix/WhatsApp не входят в этот выпуск.
Quiz/SCORM/Studio сохраняют native UI; просмотр сообщения не означает completion.

## Подход

Выбран тонкий Python transport/rendering над private подписанным Moodle bridge.
Business rules, actor/scope и все writers остаются в local_ustar. Отдельный bot backend
со своей кадровой/учебной БД отвергнут как источник расхождений. Перенос существующего
native UI в текстовые копии тоже отвергнут: используем его services, а не HTML scraping.
Входящие команды получают одну новую разрешённую операцию bot.update; прежние wire
notification/ACK/link и ключи не расширяются. Feature по умолчанию выключен, отдельный
bot-only credential/recipient fence при квалифицированной поставке.

## Контракты и безопасность

- Только personal Bot API updates, sender=chat, не bot/forward/business/group; свежесть120s.
- Actor определяется активной Academy link, актуальными employment/capability/scope.
  Клиент не выбирает userid, роль, PHP метод, URL или SQL. Global USER ограничен вызовом
  canonical adapter и восстанавливается finally; view-as/loggedinas не допускается.
- Оpaque одноразовые callback tokens привязаны к identity, link revision, состоянию,
  объекту/версии и TTL15мин. Старые кнопки не перезаписывают новую версию.
- Dialog state и receipt — metadata в Academy DB, не второй task/grade/progress store.
  Mutation+receipt в одной DB transaction; регистрация сохраняет свою email lock/commit.
  Повтор Telegram update/кнопки возвращает прежний результат, не вторую запись.
- Чувствительные решения (регистрация, оценка, адаптация, повторный курс) подготавливаются
  в боте и отдельно подтверждаются текущим actor в защищённой LAN Academy форме с sesskey.
  Это выбранный автором способ независимого подтверждения без нового TOTP/password store.
  Публичный HTTPS не нужен; из дома подтверждение ожидает офисного доступа. Обычные
  личные отчёты/чек-листы и scoped task review работают в боте. Это явно показано в UI.
- Новичок заполняет анкету без пароля. Canonical pending account создаётся с непередаваемым
  случайным паролем, устанавливается force-password-change. HRD approval не сообщает
  пароль в Telegram; первый вход/восстановление выполняется внутри Академии. Это авторское
  допущение до отдельного указания владельца, не заявленная приёмка такой регистрации.
- Личные HR рефлексии/секреты не выводятся в сводки. Файлы — только разрешённые отчёты,
  bounded upload, Moodle File API; никаких произвольных upstream URLs или private file URLs.
- Отзыв link/аккаунта запрещает дальнейшие reads/writes/replay; Telegram-копии уже
  разрешённого текста не объявляются автоматически удалёнными.

## Сценарии

Сотрудник: меню/задачи/статусы/форма отчёта/личные чек-листы/прогресс/свои уведомления,
настройки opt-in и отключение связи. Руководитель: scoped сводка/очередь проверки,
принять/вернуть с причиной, создать задачу через отдел→должность→сотрудник→срок.
HR: очереди завершённых аттестаций, подготовка оценки и повторного курса по правам.
HRD: pending registrations, кадровые заявки, адаптация/cases и существующие решения.
Гендиректор/company actor: разрешённые агрегаты, без автоматического HRD write и
новой terminal CEO policy. Не создавать новые сроки эскалации этим транспортом.

## Поставка и проверки

Один coherent application+worker release поверх принятого2803; прежние installer SHA
immutable. Source tests по новым сценариям, native PostgreSQL ACL/revision/idempotency,
old receiver/ACK compatibility, mandatory context/source/infra/release gates один раз
на итоговом SHA. Не повторять весь gate без изменений/новой причины. Agent SSH,
provider sends, subagents и automatic merge отсутствуют; owner runtime acceptance отдельно.
