# Runtime и эксплуатация

Текущая сводка — [RELEASE_LEDGER](RELEASE_LEDGER.md), команды — [OPERATIONS](OPERATIONS.md). Датированные snapshots описывают наблюдение на свою дату. Отсутствие свежего manifest означает неизвестный exact SHA, а не неработоспособность системы.

На 02.10.2026 нет самостоятельного подключения этого агента к production. Владелец сообщает о стабильной работе. Docker names/paths ниже известны из предыдущих серверных отчётов и release installers; перед операциями проверить их. CI использует отдельный synthetic runtime из `tests/stage/runtime.json`.

`git_state.yaml` описывает Git, `docker.yaml`/`moodle.yaml`/`server.yaml` — сведения с явно указанным источником. Старые значения от 07.09 сохранены в архиве; они не были свежим измерением. `scripts/refresh_context.py` создаёт отдельный audit bundle и не должен автоматически заменять каноническое состояние.
