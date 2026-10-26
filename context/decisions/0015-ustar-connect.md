# ADR-0015: USTAR Connect

Дата:26.10.2026. Статус: принято в рамках текущей сверки; описывает границы, а не новый deploy.

Connect worker принимает и доставляет сообщения каналов, а local_ustar остаётся
владельцем учётки, связей, уведомлений, предпочтений и подтверждений. Provider identity
не даёт Academy capability. Private bridge использует ограниченный подписанный
контракт, проверяет текущего actor/link/scope, размер тела и повтор.

Receiver/notifier имеют отдельные права и локальные secrets; токены не входят в
source или артефакты. Telegram принят владельцем на payload6c56a5b6/local2300;
другие каналы, группы и резерв не считать поставленными. Нельзя создавать второй
message/employee store ради интеграции.

Основание: connect-worker/ustar_connect, moodle/local/ustar/classes/connect,
context/runtime/connect_telegram_finish_owner_20261023.json. Последствие: новую
операцию сначала выражать Academy-контрактом, затем адаптировать канал.
