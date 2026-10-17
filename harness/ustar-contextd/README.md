# USTAR context harness

Код и контекст совместно поддерживаются в **main**. Единственный порядок чтения — в [START_HERE](../../START_HERE.md). Старые integration/release mirrors — история, не текущая база.

## Контракт

Локальный MCP **stdio**, без listener, startup service, shell, Docker/DB вызовов или production mutations. ROOT определяется от расположения server.py. ContextReader разрешает только опубликованный UTF-8 `context/` с лимитами; traversal, symlink/hardlink, device/FIFO и unsupported files запрещены. Внешние входы не разрешают чтение config.php/dumps/keys.

- `get_current_handoff`: актуальные STATE + BACKLOG + ACTIVE, включая разрешённый владельцем порядок и execution status. Ready task сама по себе не расширяет запрос владельца.
- `get_audit_context`: исходный датированный audit + reconciliation + full plan + owner evidence. Original theme statement исправляется только в reconciliation; runtime timestamps не превращаются в live status.
- Existing project/runtime/architecture/decisions/code-map/index/search tools остаются read-only. `get_health` сообщает присутствие context, не здоровье Академии.

DOC-02 завершён PR78. Последняя команда17Oct — проверить приложения, собрать server facts, обновить контекст и передать на повторное review перед продолжением repo/пульта/Telegram. Прежний порядок1→7→6 — история; SSD/Moodle patch/ОС и HDD backup уже имеют поздние receipts. Runtime tools читают текущие пути из STATE/project. Один minute cron v3 сохраняется; Moodle5.2/PG17, native mobile/B2B/Privacy — отдельно.

## Локальная проверка

Из корня checkout с отдельным virtualenv:

```bash
python3 -m venv /tmp/ustar-context-test-venv
/tmp/ustar-context-test-venv/bin/pip install -r harness/ustar-contextd/requirements.txt
/tmp/ustar-context-test-venv/bin/python -m unittest discover -s tests/r16 -v
/tmp/ustar-context-test-venv/bin/python scripts/build_current_context.py --check
/tmp/ustar-context-test-venv/bin/python scripts/build_agent_inventory.py --check
/tmp/ustar-context-test-venv/bin/python scripts/check_context_secrets.py
/tmp/ustar-context-test-venv/bin/python scripts/check_context.py
```

requirements.txt pin MCP/PyYAML. Проверка SDK выполняет реальный handshake только на временном synthetic repository; сервер на production не запускается. Deployment-specific `.mcp.json` указывает `/opt/ustar/git/ustarlms`; эта поставка не подтверждает наличие virtualenv/регистрацию сервиса там и не меняет server-host cron scripts.

build_context_index и build_code_map воспроизводят Git content. refresh_context дополнительно обращается к локальному Docker host: запускать только в разрешённой среде, просматривать/обезличивать bundle; не заменять canonical roadmap или dated evidence автоматически.
