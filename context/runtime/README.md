# Runtime и эксплуатация

Дополнительно проверены два core/extra ZIP: [разбор13:05MSK](../reviews/20261017/core_extra_reconciliation.md), [provenance](core_extra_received_20261017.json). Core24742 match/23 extras, ntpsec peer/offset, SMART attributes/history и component-skip explanations дополняют три старых snapshots, сохраняя их даты/ошибки. ACL getfacl отсутствует; native gates открыты.

Датированный срез16Oct ниже сохранён по owner receipts. Свежие selected observations17Oct получены в трёх [owner archives](server_facts_received_20261017.json), без прямого подключения агента; [сверка17Oct](../reviews/20261017/runtime_reconciliation.md). Прежние [evidence](evidence_20261016.yaml), [карта сервера](server_map_20261016.md), [выполненное и остаток](../audits/AUDIT_RECONCILIATION_20261016_RU.md) сохраняют свои даты/границы.

- Moodle5.1.8, PHP8.3.33, PostgreSQL16.15; host Ubuntu24.04.5/kernel147/Docker29.9.0 приняты.
- HDD ежедневный03:30Moscow; успешные scheduled13/14Oct и postboot mount/timer подтверждены.
- [Архив получен и инструкция передана сисадмину](sysadmin_handoff_result_20261016.json); [runbook копии](production_copy_20261015.md). Restore ещё ожидается.
- [Пульт и Telegram](control_panel_telegram_20261016.md) — следующая задача. [Метрики](MONITORING.md), [backup contract](BACKUP_RESTORE.md).
- Moodle5.2 и PostgreSQL17 отложены. Старые5.3 docs/PR101 не выполнять автоматически.
- [CI](CI_SCOPE_20261016.md), [ветки](repository_state_20261016.json), [история](RELEASE_LEDGER.md), [операции](OPERATIONS.md), [security](SECURITY_BASELINE.md).
- [Review17Oct](../reviews/20261017/README.md) проверяет новые предложения; [три fresh server facts](server-facts/README.md) импортированы, [сверка и gaps](../reviews/20261017/runtime_reconciliation.md) готовы для повторного аудита. Порядок входа — только [START_HERE](../../START_HERE.md).

Dated reports05–15Oct сохраняются. Их pending/next-step относятся к своей дате; текущая очередь — STATE/ACTIVE. Не повторять SSD growth, Moodle patch, AppArmor repair или host install/reboot.
