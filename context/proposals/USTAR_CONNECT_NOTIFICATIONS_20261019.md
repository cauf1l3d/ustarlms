# Уведомления Академии: CON01C.3

Разработка PR106, не инструкция установки на production. Схема local_ustar2026101904,99таблиц. Включение бота и внешний HTTPS требуют отдельной эксплуатационной проверки. Токен из переписки не использован; замена вводится только в приватный файл на сервере.

## Пользователь

Личный кабинет → Мессенджеры → подтвердить свою связь Telegram → Уведомления в Telegram. Включение добровольное. Категории: регистрация, задачи, лента, грейды. Важность: все, требующие действия, только важные. Тихие часы используют IANA timezone и фактические переходы DST; одинаковое начало/конец отключает их. Общий минимальный интервал1–1440мин ограничивает отправки одному получателю, а не каждой категории по отдельности. События не группируются в искусственную сводку; отложенное событие отменяется при потере актуальности или срока.

Уведомление Telegram содержит только категорию, важность, время UTC и инструкцию /start. Названия задач, ФИО, оценки, причины кадровых решений и ссылки с секретами не выносятся из Академии. Детали требуют текущего входа и прав Академии. Пока удалённый Academy actor не реализован, /start не является полным входом в учебную Moodle session.

Доставка моих уведомлений показывает последние20строк: очередь, проверяется, отправляется, принято Telegram, ожидает повтора, результат неизвестен, не доставлено, отменено. «Принято Telegram» доказывает ответ sendMessage с message_id и private chat получателя, **не** прочтение или выполнение. Неопределённые отправки автоматически не повторяются.

## Источники и атомарность

Оба существующих writer: target_core::notify и workflow_notifications::enqueue. Их транзакция включает canonical notification/local delivery/managed Telegram delivery. Ошибка записи откатывает всё вместе. Повтор того же бизнес-ключа возвращает существующее событие и не создаёт историческую доставку. Никакого сканирования старого pending/Bitrix или старых unread.

Явный набор canonical типов: registration_requested/approved/rejected/adaptation_ready; task_assigned/revised/reassigned/started/sent_for_review/completed/approved/returned/cancelled/reminder/escalation; feed_comment/reply; grade_request/approved/returned. Неизвестные типы остаются локальными. Проверка не создаёт новых бизнес-событий и не утверждает, что все учебные аттестации уже имеют notification writers.

При enqueue и перед begin проверяются актуальные user flags/business account/employment, текущая linked связь и revision, согласие/категории/важность и domain recipient. Заявки — текущая canonical request/статус/HRD capabilities; задачи — canonical task и assignee/reviewer scope; напоминания/эскалации также проверяют текущий cycle/trigger/lane/level и получателей; лента — feed_access::readable; грейды — собственная заявка или текущий менеджер. Решения/изменения ролей сами не выполняются.

## Очередь и воркер

Расширяется существующая local_ustar_notify_delivery, marker connectversion=1. Старые строки имеют0. Таблиц и второй бизнес-очереди не добавлено. status расширен до32символов,8managed полей + ready index; upgrade/fresh/repeat должны совпасть.

Private bridge: notification.lease(limit='1'), notification.begin(delivery,lease), notification.finish(delivery,lease,outcome,provider_ref,reason,retry_after). Все scope из проверенного service key; он не выбирает userid/роль. Выдаётся один ud1_256bit lease60сек с hash-at-rest. Begin фиксирует sending до ответа и выдаёт10сек разрешение с минимальным пакетом. Право действительно на момент begin: уже разрешённая внешняя отправка не может быть отозвана задним числом.

Истёкший leased без begin можно забрать с новым секретом. Истёкший sending становится uncertain, не возвращается в очередь. Один получатель не имеет двух активных отправок. Временная потеря finish оставляет результат неизвестным; поздний корректный receipt той же аренды может честно записать принятое провайдером сообщение, даже если связь уже отозвана. Это не восстанавливает доступ.

429 → общий для tenant durable retry_after1–86400сек;401 → durable retry300сек и остановка процесса;403/400 → failed;5xx/timeout/невалидный ответ → uncertain. Не более5попыток и TTL1час для feed/reminder,24часа для остальных поддержанных типов. Provider receipt никогда не вызывает acknowledge_notification. Повтор одинакового receipt идемпотентен, конфликт отклоняется.

notification_runtime использует приватную receiver-конфигурацию. Требуется onboarding_enabled=true для загрузки bridge; это **не** включает доставку в Moodle. При отдельном runtime нужны отдельные config/key ID с allowlist ровно notification.lease,notification.begin,notification.finish; receiver/BFF key остаётся со своим явным списком. Не включать автоматически все OPERATIONS. DefaultCLI проверяет локальные файлы без сети; --run явно разрешает исходящие API-вызовы. getMe сверяет bot ID/username. Этот процесс не делает getUpdates и не забирает polling у receiver. Параллельные экземпляры защищены канонической арендой, но общий bot-wide Telegram rate limit учитывается ответами429, а не распределённым лимитером.

В Moodle runtime-config нужны явные enabled=true и положительный int cutoff ustar_connect_notify_since (точное имя ниже). По умолчанию всё выключено; миграция настройки не включает. Названия переменных: `$CFG->ustar_connect_notifications_enabled`, `$CFG->ustar_connect_notify_since`, `$CFG->ustar_connect_channels['telegram']['tenant']`. Tenant — проверенный ID бота; username — публичное имя. Это контракт конфигурации, не команда активации.

## Проверки разработки

Из корня checkout; не запускать как установщик на server1:

```bash
python -m unittest discover -s tests/r16 -p test_connect_notifications.py -v
python -m unittest discover -s tests/r16 -v
node tests/mobile/connect-link.cjs
PYTHONPATH=connect-worker python -m ustar_connect.notification_runtime --help
python scripts/check_context.py
```

Локально нет PHP/Chromium; требуются exact CI Moodle5.1.8/PostgreSQL16 с PHPUnit, upgrade/fresh/repeat и независимой гонкой lease/begin/receipt, Chromium actual Mustache/theme. Synthetic provider/API тесты не доказывают реальную доставку или доступность внешнего HTTPS. Privacy export включает настройки и безопасные receipt fields; удаление стирает managed доставки и настройки выбранного пользователя, сохраняя старый Bitrix/canonical notice. Старые домены плагина требуют отдельного полного privacy audit.

Следующая работа: canonical remote login/recovery/MFA и независимый step-up для чувствительных действий; role-specific read/actions; Bitrix/WhatsApp adapters и provider credentials; упаковка/службы/restart/restore и живая приёмка внешнего ingress. Console journal/heartbeat и обновление платформы отложены владельцем.
