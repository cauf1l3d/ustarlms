# Текущая дорожная карта

05.10.2026: DOC-02 завершён PR78. Новая команда владельца — **1 → 7 → 6**: baseline, Moodle/CI/ОС, HTTPS; остальные пункты после них. **INF-01 в работе**. [Свежий baseline и следующий шаг](../runtime/baseline_20261005.md). Прежняя остановка после публикации больше не действует.

- [STATE](STATE.yaml) — source SHA, датированные серверные свидетельства и execution gate.
- [ACTIVE](../tasks/ACTIVE.md) — разрешённый объём и точка остановки.
- [BACKLOG](BACKLOG.yaml) — зависимости и acceptance текущих/последующих задач.
- [Полный план](USTAR_AUDIT_REMEDIATION_PLAN_20261005_RU.md) — все вопросы аудита, шаги, приёмка, откат и coverage matrix.
- [Аудит и сверка](../audits/README.md) — исходный документ и последующие уточнения.
- [MOBILE_CLIENT](MOBILE_CLIENT.md) — спецификация будущего Android/iOS.
- [AGENT_PROTOCOL](AGENT_PROTOCOL.md) — правила продолжения.

Текущий инфраструктурный цикл завершается регулярными копиями/restore, после security/monitoring/HTTPS/platform/acceptance этапов. Healthy HDD нужен вместо неисправного; автоматизация на SERVEREXPRESS запрещена прежним предписанием сисадмина. Future mobile/B2B/Privacy/major и измеренный refactor планируются отдельно и не откладывают этот финал бессрочно.

Исторические этапы и старые R/G/N не закрыты массово. [Архив](../archive/handoff_20261002/README.md) сохранён; реальные остатки traced в AUDIT-01/TECH-01 и текущем плане.
