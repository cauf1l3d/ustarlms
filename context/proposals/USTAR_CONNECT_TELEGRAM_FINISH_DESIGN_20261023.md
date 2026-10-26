# CON-02 Telegram — завершение уведомлений и личного ACK

23.10.2026, Europe/Moscow. Scope approved by owner: «да резерв подключим потом лучше
закончить полностью контур в телеграмме». Это сужение предыдущего B.1: primary/fallback,
второй провайдер и новый route store отложены. Повторное согласование этого решения
не требуется. [Owner baseline](../runtime/connect_con02_server1_owner_20261023.json).

## Результат

Сотрудник связывает Telegram в Академии, управляет нынешними личными категориями,
срочностью, тихими часами/TZ и интервалом, получает понятное новое событие и нажимает
«Принял». После canonical ACK в сообщении остаётся «✓ Принято · время UTC» и ссылка
в Академию. Назначение задачи и напоминание явно различаются. В личной истории
видны отдельно состояние отправки и личное подтверждение с первым временем.
ACK не завершает задачу и не отключает будущие бизнес-напоминания.

Подход уже задан существующей системой: одна Academy notification/delivery и один
Telegram receiver. Новая маршрутизация и изменение v1 preferences для этого не нужны.
Отдельная таблица, второй receiver и worker-side inbox лишь дублировали бы состояния.

## Контракты

- Рабочие v1/v2 permits и installed4394 остаются совместимыми. В v3 добавляется только
  bounded `event` enum из существующей notification_policy; запрет произвольных subject,
  body/metadata/action URL сохраняется. Новая операция notification.begin_v3 требует
  отдельного scoped sender key, ack-enabled и presentation-enabled opt-in; default OFF.
- ACK response/операция/token/schema сохраняются. Время для видимого статуса берётся
  только из canonical acknowledged_at. Telegram edit failure не отменяет receipt и
  не разрешает автоматический повтор business write/notification send.
- Новая sender config принимает explicit contract3. Старые конфиги остаются v1/v2;
  downgrade/extra fields/type mismatches отвергаются до send. Probe проверяет scope,
  не берёт lease и не расходует update cursor.
- В «Мессенджеры» сохраняются текущие preferences без новых обязательных полей;
  отображаются текущая связь, личный ACK и понятные отправка/uncertain/cancelled.
- Ни расписание reminder, ни бизнес-эскалации не меняются по двум строкам owner SQL.
  Известно лишь, что notices179/180 были разными assigned/reminder, по одной отправке.
- Group live acceptance остаётся deferred до полного запуска. Текущий UID/subject
  fence не расширяется этим обновлением. Group activation controller — отдельный
  operational slice ранее принятого rollout design, а не повод повторять принятый pilot.

## Проверка и поставка

TDD transport/worker/callback, native Moodle/PG scoped v3 and existing v1/v2, freshness,
consent/revoke/concurrency/ACK idempotency. Native upgrade/repeat/paired restore и
actual UID33/systemd/private TLS; provider synthetic. Мобильная native форма проверяется
существующим Chromium gate. Полная CI-квитанция относится к точному source commit.

Разработка до готового source; затем отдельный coordinated release с immutable
app/worker/source identities, fresh pair, cron target, сохранением HTTPS+AutoReport,
правами private files, ACK keys/cutoffs, cursor и существующими runtime journals.
Обычный .py для оператора, read-only check/status/recovery. Прежний4394 не переписывается
и не используется как установщик нового payload. Нет agent SSH/provider sends/main merge.
Удалённый Mini App/PWA с external trusted HTTPS и native handoff — следующий отдельный этап;
данный scope завершает Telegram notification/ACK контур, не заменяет весь LMS мобильным ботом.
