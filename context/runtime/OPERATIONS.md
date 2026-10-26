# Операции — текущий вход26Oct

Не применять старые команды повторно по историческому журналу. Текущие версии и
принятые изменения: [evidence26Oct](evidence_20261026.yaml), [STATE](../roadmap/STATE.yaml).
Точные инструменты/хэши/фазы находятся в соответствующей dated owner-квитанции.

- DB-role: [принятый handoff](infra_app_handoff_owner_20261025.json).
- Code/config: [hardening](infra_code_hardening_owner_20261025.json).
- Compose0600/control backup: [receipt](compose_source_privacy_owner_20261025.json).
- Connect2300: [поставка](connect_telegram_finish_owner_20261023.json).
- Ротация/recreate: [отложено до Moodle upgrade](docker_runtime_successor_20261026.md).
- Backup/restore: [границы](BACKUP_RESTORE.md); внешний restore unknown по решению владельца.

Агент не выполняет SSH и server mutations в задаче Git reconciliation. Для нового
server change сначала конкретный проверенный артефакт, scoped check и owner execution.
Первоначальный журнал сохранён в ../archive/reconciliation_20261026.
