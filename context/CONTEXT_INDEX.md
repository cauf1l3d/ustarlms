# Индекс текущего контекста

Порядок чтения задан только в [START_HERE](../START_HERE.md). Здесь тематическая навигация; [current.yaml](current.yaml) генерируется из STATE/BACKLOG.

- [Выполнено с06Oct и остаток по аудиту](audits/AUDIT_RECONCILIATION_20261016_RU.md), [полный backlog](roadmap/BACKLOG.yaml).
- [Пульт и Telegram](runtime/control_panel_telegram_20261016.md) — текущий INF-06, [архитектура](../server-console/ARCHITECTURE.md), [health](../server-console/OBSERVABILITY.md), [дизайн](../server-console/DESIGN.md); [передача сисадмину](runtime/sysadmin_handoff_result_20261016.json) — restore ожидается.
- [Установка пульта владельцем17Oct23:36MSK](runtime/console_native_install_20261017.json) — limited TLS/units/freshness gate пройден; HDD/SMART, браузер и остальные native gates открыты. TOTP/Telegram пропущены.
- [HDD/SMART диагностика18Oct](runtime/console_storage_probe_20261018.json), [исправление/update](../server-console/STORAGE_FIX_20261018.md) — namespace fault подтверждён; новый owner update/fresh receipt ожидаются.
- [Telegram network18Oct01:26MSK](runtime/console_telegram_network_20261018.json) — IPv4 TCP443 timeout до TLS, причина не локализована; [проверенный release3305ecc9](runtime/console_storage_release_20261018.json) — CI/artifact готовы, owner application ожидается.
- [Runtime evidence](runtime/evidence_20261016.yaml), [карта сервера](runtime/server_map_20261016.md), [реестр поставок](runtime/RELEASE_LEDGER.md).
- [CI scope](runtime/CI_SCOPE_20261016.md), [ветки](runtime/repository_state_20261016.json), [карта source](code_map/README.md).
- [Архитектура](architecture/overview.md), [границы](architecture/boundaries.md), [решения](decisions/README.md).
- [Ревью приложений17Oct](reviews/20261017/README.md), [сбор фактов](../scripts/infra/README_server_facts.md), [снимки](runtime/server-facts/README.md), [покрытие и неизвестное](coverage.yaml).
- [Свежие server facts: сверка и риски](reviews/20261017/runtime_reconciliation.md), [provenance двух архивов](runtime/server_facts_received_20261017.json) — контекст для повторного аудита владельца.
- [Дизайн и слои темы](design/README.md), [source tokens](design/tokens.yaml), [capability references](architecture/access_matrix.yaml), [датированная стратегия11Oct](strategy/20261011/README.md).

Domain maps: [organization](domains/organization.yaml), [registration](domains/registration.yaml), [adaptation](domains/adaptation.yaml), [routes](domains/routes.yaml), [learning](domains/learning.yaml), [evidence](domains/evidence.yaml), [grades](domains/grades.yaml), [work](domains/work.yaml), [economy](domains/economy.yaml), [feed](domains/feed.yaml), [messaging](domains/messaging.yaml), [mobile](domains/mobile_web.yaml).

[Прежние указатели](archive/reconciliation_20261016/README.md) и [архив02Oct](archive/handoff_20261002/README.md) — история. Moodle5.2/PG17 отложены; подготовка5.3 не является текущей задачей.
