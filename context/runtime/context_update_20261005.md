# DOC-02 — проверка актуализации GitHub

Дата: 05.10.2026. Ветка: `codex/ustar-infrastructure-context-20261005`, PR в main. Точный documentation commit и результат публикации определяются по Git history/PR; application baseline остаётся `e1d57f5bc28f6964afd20aeff7620345b34a80fe`. Main до этой поставки: `1dfa124ef9a3585b0d36133ee576bce9f7e9692a`.

## Изменение

Исходный аудит опубликован побайтно, SHA-256 `aa8b717719f3f1a3b4131e5c56590667a5731186be4025d00151f94bc8c380e4`. Отдельно уточнены boost, root test-copy hypothesis и healthy HDD/no SERVEREXPRESS requirement. Добавлены полный пошаговый план, dated evidence и operational contracts. Entry points, STATE/ACTIVE/BACKLOG/runtime/ledger/memory актуализированы; история и fixtures сохранены.

MCP stdio получает `get_current_handoff` и `get_audit_context` через существующий безопасный ContextReader. Integrity gate проверяет согласованность дат/версий/reference SHA/audit hash/путей и indexed files, запускает boundary/SDK checks. ROOT/transport не создают host service и не выполняют Docker/DB commands.

## Проверено локально

- `python -m unittest discover -s tests/r16 -v` с pinned MCP 1.30.0 / PyYAML 6.0.3: **34 tests, OK**. Включает реальный SDK stdio handshake в temporary synthetic repo, новые handoff/audit tools и запрет выхода через audit pointer.
- Source/application + stage fixtures: **609 tracked files** в `moodle/`, `frontend/`, `tests/stage/` побайтно равны main до этой поставки. Application source manifest соответствует PR76; release mirrors/история не менялись.
- Исходный audit attachment и опубликованный файл имеют одинаковые bytes/SHA-256.
- Source/code map и context index воспроизводятся; `check_context.py` проверяет YAML/JSON, source hashes, XMLDB/domain paths, current document links, task dependency DAG, evidence pointers и audit hash. Эти же проверки предусмотрены в GitHub context workflow.
- `git diff --check` и проверка scope перед публикацией. Для воспроизведения — [harness README](../../harness/ustar-contextd/README.md).

CI относится к exact commit PR; GitHub checks и merge result — в PR/Actions. Прежний full application CI #456 — историческое свидетельство PR76, не новый прогон этой поставки.

## Граница

Production connection/deploy, host cron/backup script changes, DB role/file permissions, HTTPS, monitoring install, cleanup/partitioning, scheduled backups и personnel writes **не выполнялись**. Moodle DB/load/full RC/production browser и full host restore этой сессией не запускались: application tree неизменён, scope — context/harness. Серверные результаты 03–04.10 принадлежат owner evidence, не новым самостоятельным измерениям.

После GitHub-публикации и проверки — **stop and wait**. INF-01 определён как следующий шаг, но не исполняется до новой команды. Открытые audit risks сохранены; DOC-02 done закрывает только repository delivery.
