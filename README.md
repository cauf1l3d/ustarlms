# USTAR Academy

**Актуальная работа: [начать здесь](START_HERE.md) → [roadmap](context/roadmap/README.md) → [статус и следующий шаг](context/roadmap/STATE.yaml).**

Приоритет: надёжный production, консистентная студия маршрутов, награда за каждое новое подтверждённое прохождение точки, геймификация и инженерный порядок. Проверенная база кода указана в START_HERE: публикация плана не переносит feature-код в main.

---

## Историческая запись исходного baseline

# USTAR 1.5.1 production baseline

Canonical source repository for USTAR.

Production Moodle custom source was captured from:
/opt/ustar/apps/moodle/moodle/public/local/ustar
/opt/ustar/apps/moodle/moodle/public/theme/ustar

Frontend source:
/opt/ustar/source/frontend

DGMJS is maintained as an external pinned dependency.

IMPORTANT:
bitrix_bot_handler.php is intentionally excluded from this baseline commit.
The live handler contains credential-related configuration that must first be moved to protected environment configuration.
The live handler remains protected by the production snapshot/recovery system.

