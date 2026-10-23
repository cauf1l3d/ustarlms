# Telegram: исходное состояние следующего полного сценария, 23Oct2026

Это фактический preflight по source88c928f8 и существующему RFC, не новый утверждённый
проект реализации. Owner принял notification2300 и просит перейти к полному Telegram
контуру, по возможности одним законченным выпуском. [Принятая owner receipt](connect_telegram_finish_owner_20261023.json).

## Что работает и что ещё нужно

| Часть | Проверенный факт | Оставшаяся работа |
|---|---|---|
| Привязка/уведомления/ACK | Owner подтвердил новую задачу, постоянное время ACK и собственную историю | Сохранить этот рабочий runtime |
| Регистрация и профиль | BFF/actor backend и браузерная форма существуют; `actor_service::status` даёт `features=['profile']` | Внешняя установка и полноценный учебный/рабочий вход не приняты |
| Задачи/операционные чек-листы | Канонические `task_workspace::service` и `checklist_service` существуют в Moodle | Bridge не имеет операций чтения/записи этих объектов; нужны авторизованные adapters и UI |
| Мобильный UI Connect | `connect-worker/web/index.html` — регистрация/состояние/свой профиль | Рабочий кабинет, задачи, чек-листы, обучение/результаты и inbox ещё не реализованы в Mini App |
| Права и вход | Сессия `actor_read` ограничена профилем и блокирует native MFA/forced password/alternative auth | Политика допуска к рабочим действиям и native step-up/handoff требуют реализации; read session нельзя молча превратить в write |
| Полное обучение и роли | Существующие native Moodle/USTAR UI и writers — владельцы данных/прав | Доступный trusted canonical Moodle origin, auth/files/cookies/WebView/Quiz/SCORM и защищённые ролевые действия — отдельные обязательные проверки полного сценария |
| Подключение сотрудников | Принят один сотрудник; group controller остаётся design-only | Явный состав/opt-in/cutoff и согласованный финальный rollout; live group acceptance пока deferred |
| Внешний вход | `ustar.local` принят в LAN; em2025.netcraze.pro/CrazeDNS остался кандидатом | Нет принятого рабочего public HTTPS origin, маршрута/edge и внешней mobile-проверки |

Source anchors: `classes/connect/bridge_service.php`, `actor_service.php:status/native_policy`,
`task_workspace/service.php:detail/report`, `checklist_service.php:list_for/submit`,
`connect-worker/ustar_connect/onboarding.py`, `connect-worker/web/index.html`.
Приведённые PHP paths относительны `moodle/local/ustar/`.

## Граница результата

Исходный [RFC](../proposals/USTAR_CONNECT_RFC_20261018.md), разделы8/10, разделяет CON03
(общий Mini App/quick actions) и CON04 (роли и full native LMS handoff). Заявление о
полном Telegram-контуре должно явно учитывать обе границы: внешний запуск, реальную
авторизацию, разрешённые действия, материалы/файлы и обучение. Узкий кабинет задач
сам по себе не доказывает готовность Quiz/SCORM или всех HR действий.

Один согласованный выпуск может объединять работу над кодом, UI, тестами и поставкой.
Реальное внешнее включение зависит от выбранного размещения/адреса и owner-команд;
его нельзя подтвердить synthetic CI. Подтверждённый HTTPS-пульт или Bot API egress
не является принятой внешней доступностью Академии.

Первое недостающее инфраструктурное решение — какой публичный HTTPS адрес и узел
использовать: имеющийся допустимый company ingress либо внешний edge с ограниченным
исходящим connector, оба варианта уже описаны в RFC. Доступность CrazeDNS из разных
сетей/сертификат/forward rule не измерены; провайдер и публичный адрес не выбирались
агентом. Для полной native Academy нужен отдельный origin/cookie/file gate с
сохранением офисного доступа. Текущие DNS/routes/VPN и AutoReport не изменялись.

Telegram Main Mini App URL/menu и серверная проверка initData:
https://core.telegram.org/bots/webapps (официальная документация проверена23Oct).
BotFather настройка выполняется владельцем после рабочего HTTPS; секреты остаются
в приватной конфигурации сервера. Reserve/Bitrix/новые CEO policies остаются вне
текущего Telegram-приоритета.0subagents; нет agent SSH/provider sends/main merge.
