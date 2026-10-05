# Архитектура: фактическая реализация

Проверено по application source `e1d57f5bc28f6964afd20aeff7620345b34a80fe`, 02.10.2026. Это описание существующей системы, не новый дизайн.

| Слой | Ответственность | Источник |
|---|---|---|
| Moodle core | Пользователи, аутентификация/сессии, capabilities, курсы, Quiz/SCORM, completion, native messaging, File API, scheduled tasks | Внешний Moodle runtime; полный core не хранится в этом Git |
| local_ustar | Организация и lifecycle сотрудника, маршруты, evidence, адаптация, грейды, рабочие задачи, лента, награды | `moodle/local/ustar` |
| theme_ustar | Native layout, Mustache, SCSS, responsive navigation, бренд | `moodle/theme/ustar`; parent `boost` |
| Native browser UI | PHP controllers вызывают доменные сервисы и выводят шаблоны; JS/AMD выполняет разрешённые команды | `local/ustar/*.php`, `templates/`, `amd/`, `styles/` |
| Дополнительный web client | Next.js + React, server-side Moodle session/token adapter | `frontend/`; по ответу владельца 05.10 в production не используется |
| Context tooling | Индексы, source/schema maps, сбор runtime evidence | `scripts/`, `harness/ustar-contextd` |

## Жизненный цикл

Внутренняя регистрация создаёт Moodle user и pending employment вместе с кадровой заявкой. HRD подтверждает её с должностью; организация и кадровый статус меняются согласованно. Руководитель назначает адаптацию. Допуск к обучению проверяется при запуске; опубликованные версии маршрута и evidence определяют результат. Подтверждённые события питают грейды, KPI, XP и USCOIN по своим правилам, без второго completion store.

Рабочие задачи и чек-листы — отдельный процесс с отчётом, проверкой, календарём и эскалацией. Личные заметки остаются приватными. Чаты используют Moodle messages/conversations; лента имеет отдельную аудиторию и модерацию, но файлы всех контуров используют Moodle File API.

## Реальные переходные границы

- Каталог подразделений/должностей/навыков остаётся в `local_ustar_structure` и существующих readers. Штатные места и назначения — `local_ustar_staff_places` / `local_ustar_assignments`. ADR-0002 задаёт направление, но не доказывает полную миграцию каталога.
- `organization_identity::resolve()` использует legacy position только пока нет истории assignments. Завершённый PRIMARY не должен воскрешать прежнюю должность. ACTING не заменяет должность обучения.
- `employment` имеет pending/active/suspended/terminated. Для исторической учётки без строки действует legacy fallback; это не причина массово подтверждать новых pending пользователей.
- `evidence_rec`/`evidence_evt` и `completion_cycle` сохраняют подтверждения и циклы. `skill_evidence` не следует удалять по одному слову legacy: проверить потребителей и миграцию.
- USCOIN — существующий ledger и его account/balance projections; competition score, XP и KPI имеют самостоятельные смыслы.
- PWA кеширует только публичные assets/offline notice. Приватные страницы, файлы и команды требуют сети. Отдельного native приложения пока нет.

Точные точки входа, таблицы и тесты: [карта кода](../code_map/README.md). Решения и изменения политик: [ADR index](../decisions/README.md).

Уточнение 05.10: текущий вход — LAN/HTTP через Apache хоста и loopback 8082; Caddy не TLS-прокси Академии. Сервер также обслуживает нужные почту/DNS/ISPConfig; тип размещения не подтверждён (`systemd-detect-virt=none`). Источники и временные границы — [runtime evidence](../runtime/evidence_20261005.yaml). Архитектура мобильного клиента и B2B-изоляции ещё требует решения; текущие подразделения не доказывают multi-tenancy.
