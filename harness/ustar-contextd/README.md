# USTAR context harness

Код и контекст совместно поддерживаются в **main**. Начать с [START_HERE](../../START_HERE.md), [STATE](../../context/roadmap/STATE.yaml), [ACTIVE](../../context/tasks/ACTIVE.md), [аудита и сверки](../../context/audits/README.md), [полного плана](../../context/roadmap/USTAR_AUDIT_REMEDIATION_PLAN_20261005_RU.md). Старые integration/release mirrors — история, не текущая база.

## Контракт

Локальный MCP **stdio**, без listener, startup service, shell, Docker/DB вызовов или production mutations. ROOT определяется от расположения server.py. ContextReader разрешает только опубликованный UTF-8 `context/` с лимитами; traversal, symlink/hardlink, device/FIFO и unsupported files запрещены. Внешние входы не разрешают чтение config.php/dumps/keys.

- `get_current_handoff`: STATE + BACKLOG + ACTIVE, включая `awaiting_owner_instruction`. Ready task не даёт разрешение выполнять её.
- `get_audit_context`: исходный датированный audit + reconciliation + full plan + owner evidence. Original theme statement исправляется только в reconciliation; runtime timestamps не превращаются в live status.
- Existing project/runtime/architecture/decisions/code-map/index/search tools остаются read-only. `get_health` сообщает присутствие context, не здоровье Академии.

После DOC-02 GitHub-поставки **остановиться до новой команды**. Следующая намеченная задача INF-01, один установленный cron v3 сохраняется. Автокопии финалом цикла на healthy HDD, не SERVEREXPRESS/failed HDD; future mobile/B2B/Privacy/LTS отдельно.

## Локальная проверка

Из корня checkout с отдельным virtualenv:

```bash
python3 -m venv /tmp/ustar-context-test-venv
/tmp/ustar-context-test-venv/bin/pip install -r harness/ustar-contextd/requirements.txt
/tmp/ustar-context-test-venv/bin/python -m unittest discover -s tests/r16 -v
python3 scripts/build_context_index.py
python3 scripts/check_context.py
```

requirements.txt pin MCP/PyYAML. Проверка SDK выполняет реальный handshake только на временном synthetic repository; сервер на production не запускается. Deployment-specific `.mcp.json` указывает `/opt/ustar/git/ustarlms`; эта поставка не подтверждает наличие virtualenv/регистрацию сервиса там и не меняет server-host cron scripts.

build_context_index и build_code_map воспроизводят Git content. refresh_context дополнительно обращается к локальному Docker host: запускать только в разрешённой среде, просматривать/обезличивать bundle; не заменять canonical roadmap или dated evidence автоматически.
