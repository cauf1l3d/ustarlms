# Активная работа — 02.10.2026

**Следующий приоритет: MOB-01 — подготовить проверяемую спецификацию отдельного приложения Android/iOS.**

Код и контекст собраны в main. Application baseline PR76 `e1d57f5bc28f6964afd20aeff7620345b34a80fe`, plugin/theme `2026100204 / 2026100202`, full CI #456. Владелец сообщает о стабильной работе; свежий exact production manifest не получен. Подробнее: [STATE](../roadmap/STATE.yaml), [release ledger](../runtime/RELEASE_LEDGER.md).

Для MOB-01 прочитать [план](../roadmap/MOBILE_CLIENT.md), инвентарь `db/services.php` и существующие native controllers. Составить матрицу сценарий → service → API → права → файлы → offline/retry → acceptance. Затем выбрать мобильный стек отдельным ADR с учётом внутреннего DNS/HTTPS/VPN и способа установки на реальные Android/iPhone.

Параллельно по смыслу, без блокировки source-анализа: OPS-01 — пользовательская read-only сверка текущего production, особенно PR76 и его schema migration. Готовый порядок — [OPERATIONS](../runtime/OPERATIONS.md). Не использовать старую PR71 запись как доказательство, что именно она всё ещё установлена.

Стабильный web сохранять. Новый redesign логина, новая модель чатов, массовые кадровые миграции, чистка истории или внедрение внешней доставки не входят в это задание.
