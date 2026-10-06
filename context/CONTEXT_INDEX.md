# Индекс контекста USTAR

Актуальность: 06.10.2026. **Канонический код и контекст — main.** Application SHA, dated evidence и выбранный владельцем порядок 1 → 7 → 6: [STATE](roadmap/STATE.yaml).

| Вопрос | Авторитетный источник |
|---|---|
| Что за продукт, какой стек | [project](project.yaml), [architecture](architecture/overview.md) |
| Какие ограничения приняты | [boundaries](architecture/boundaries.md), [ADR index](decisions/README.md), [constraints](constraints.yaml) |
| Как работают домены | [domains](domains/), [карта кода](code_map/README.md) |
| Где source и что реально установлено | [STATE](roadmap/STATE.yaml), [release ledger](runtime/RELEASE_LEDGER.md), [fresh baseline](runtime/baseline_20261005.md), [dated evidence](runtime/evidence_20261005_baseline.yaml) |
| Как проверить/выпустить/откатить | [operations](runtime/OPERATIONS.md), [patch 5.1.8 CI](runtime/patch_runtime_20261005.md), [planning PASS](runtime/lab_planning_result_20261005.json), [cold checkpoint / independent workspace / fresh HDD PASS](runtime/lab_cold_checkpoint_20261005.md) |
| Хранение, карта server1 и автобэкапы до стенда | [Карта06Oct / текущий scope](runtime/server_map_20261006.md), [HDD/fstab/existing-copy PASS](runtime/hdd_initialize_20261006.md), [HDD producer/trial PASS](runtime/hdd_backup_20261006.md), [timer active / first run pending](runtime/hdd_backup_schedule_20261006.md), [SSD preflight / identity received](runtime/ssd_storage_preflight_20261006.md), [GPT/boot copy PASS](runtime/ssd_partition_table_copy_result_20261006.json), [следующий GPT relocation](runtime/ssd_gpt_relocate_20261006.md) |
| Над чем работать дальше | [ACTIVE](tasks/ACTIVE.md), [BACKLOG](roadmap/BACKLOG.yaml), [полный план аудита](roadmap/USTAR_AUDIT_REMEDIATION_PLAN_20261005_RU.md) |
| Оригинал аудита и расхождения | [Аудит](audits/USTAR_INFRASTRUCTURE_AUDIT_20261005_RU.md), [сверка](audits/AUDIT_RECONCILIATION_20261005_RU.md) |
| Пульт, копии и открытые security риски | [Monitoring](runtime/MONITORING.md), [backup/restore](runtime/BACKUP_RESTORE.md), [security baseline](runtime/SECURITY_BASELINE.md) |
| Последующие Android/iOS, Privacy, B2B и LTS | [Полный план](roadmap/USTAR_AUDIT_REMEDIATION_PLAN_20261005_RU.md), [mobile](roadmap/MOBILE_CLIENT.md) |
| Как агенту продолжать | [astra](agents/astra.md), [protocol](roadmap/AGENT_PROTOCOL.md) |
| Какие классы/таблицы/API существуют | [generated manifest](code_map/generated/source_manifest.json), [schema](code_map/generated/database_tables.md), [API](code_map/generated/web_services.md) |
| Почему старый документ говорит другое | Дата документа, [архив консолидации](archive/handoff_20261002/README.md), [session log](roadmap/SESSION_LOG.md) |

`context/index/context_index.json` — машинный поисковый индекс с hash содержимого; он не определяет статус релиза. Датированные audit/release/runtime документы сохраняют свои исходные утверждения и не становятся текущими от того, что находятся в main.
