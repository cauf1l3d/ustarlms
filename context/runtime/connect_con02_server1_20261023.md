# CON-02 на server1: приложение, runtime и личные ACK приняты

23.10.2026, Europe/Moscow. [Структурированная квитанция](connect_con02_server1_owner_20261023.json).
Evidence — терминал/SQL владельца и предоставленный скриншот, не agent SSH.

Владелец установил exact ordinary controller4394b994 с проверенным SHA331c5c54…;
после узкого принятия отдельного AutoReport9443 в HTTPS topology получил
baseline_ready / server1_legacy_indexes_20261023 / local2000 /552files, без host changes.
Затем --upgrade --acknowledge-outage завершился app_ready/local2026102200:
fresh_backup_complete, cold pair, source activation, upgrade_1/upgrade_2, cron_written,
public_release_committed. --enable-ack завершился runtime_active/ack_enabled=true.
--status подтвердил database_available и active+enabled receiver/notify, theme2026100202.
Application/worker payload53476482;557files/100tables относятся к fixed payload,
не являются новым независимым whole-host manifest. Agent main не менял.

## Живые результаты и границы

| Notice | Событие | Created МСК | ACK МСК | Delivery | Attempts |
|---|---|---|---|---|---|
|179|task_assigned|16:13:23|16:13:44|176 provider_accepted|1|
|180|task_reminder|16:15:26|16:15:42|178 provider_accepted|1|

Оба canonical status=acknowledged, ackat сохранён. Это два различных события,
не повторная доставка одного notice. Транспортный provider_accepted остаётся
отдельным фактом. UID130 last-revoked checkpoint21Oct теперь исторический:
успешный current-scope callback доказывает действительную связь в момент операций,
но не заменяет новый полный inventory ссылок/предпочтений.

При нажатии кнопка исчезла; подтверждение в коде transient answerCallbackQuery.
Постоянной отметки в текущем сообщении нет. Обе карточки показывают общий текст
«Задачи / Новое событие»; это UX debt. Owner SQL не содержит dueat/policy и потому
не устанавливает причину напоминания через2минуты. Выполнение задачи и устойчивость
первого времени при повторном нажатии этим evidence отдельно не проверены.
Новый source с этой документацией на сервер не устанавливается.

## Изменение HTTPS owner state

--check сначала отказал generic controller_operation_failed. Sanitized frame trace
выявил frozen academy_https.Refusal/apache_bindings_changed. Старые сохранённые
привязки Академии80/443 совпадали; private bridge127.0.0.1:18443 проверялся отдельно.
Добавилась только192.168.1.60:9443 из ustar-autoreport.conf:3. Владелец выполнил
узкую state reconciliation с pinned tool SHA, точным allowlisted row, неизменностью
остальной topology, проверкой Apache/managed files+units/CA/live TLS и private
before-image. Изменено только сохранённое поле topology HTTPS owner state;
Apache config не редактировался, сервисы этой операцией не перезапускались.
Последующий baseline_ready и успешный upgrade/runtime подтверждены владельцем.
Не превращать это в произвольный rebaseline неизвестных vhosts; сохранить защиту.

## Продолжение

По поручению «ок идем дальше тогда» подготовлен [проект B.1](../proposals/USTAR_CONNECT_CON02B_ROUTING_DESIGN_20261023.md):
личные настройки, primary и pre-begin fallback в существующей канонической истории.
Новый routing code ещё не написан; конкретный проект ожидает review. Затем отдельный
implementation plan, source/tests/CI и отдельная поставка. Реальный Bitrix — CON-02C;
digest отдельная политика. Live группа deferred до полного запуска. Прежний one-user
pilot и два успешных ACK не переоткрываются;0subagents/author review, нет provider sends.
