# Контекст обновлён 02.10.2026

Проверены Git history, PR metadata, source и full CI #456 на PR76. Исправлены устаревшие entrypoints, runtime pointers и code map. Сохранены уникальные материалы документационных PR9/19/23/26/65 и прежние записи; [архив](../archive/handoff_20261002/README.md).

Runtime не измерялся в этой сессии. Точный live SHA не подменён последним Git SHA. Версии production в STATE остаются unknown до OPS-01; latest owner observation и исторический manifest описаны в [ledger](RELEASE_LEDGER.md).

Generated maps воспроизводятся из exact Git tree; машинный индекс отражает содержимое файлов, не время checkout. Текущий next task: MOB-01.

## Проверки этой консолидации

- 526 application files, 356 PHP files, 172 class/interface/trait symbols, 93 XMLDB tables, 30 USTAR external functions сопоставлены с exact source.
- `git diff --exit-code e1d57f5bc28f6964afd20aeff7620345b34a80fe -- moodle frontend tests/stage/production.json tests/stage/runtime.json`: изменений нет.
- `scripts/check_context.py`: paths, schema names, versions, task dependencies, current Markdown links и content hashes прошли.
- Повторная генерация code map и content index дала побайтно одинаковый результат.
- Локальный structural source check прошёл; PHP CLI в этой среде отсутствует. PHP/DB/browser/rollback evidence для неизменного application source — full CI #456.
- Новый workflow `USTAR context integrity` проверяет воспроизводимость карт и целостность текущего handoff на PR/main.
- Harness/source suite: 33 теста прошли в отдельном окружении с pinned dependencies; stdio handshake требовал запуска вне sandbox (в sandbox timeout).
