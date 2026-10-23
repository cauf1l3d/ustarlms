# INF02: потребители DB privileges,23Oct2026

Owner accepted ustar_pg_admin with two required-SCRAM sessions9976/9995. Production
moodle flags remain unchanged. Scope is reuse of accepted stages plus missing
privilege checks in existing CI, not a new owner/server1 stand.

## Exact backup source recovered and verified

Earlier supplied `USTAR_BACKUP_SFTP_20261004.py` recovered privately and reconstructed
byte-for-byte:13689B, SHA256
`0d31e694db8bee2309072bd00dee2423022f35e18ad2c11ea1cf788fc63ef47f`.
Matches the installed-engine pin in `scripts/infra/hdd_backup.py` and original
[review contract](recovery_contract_20261005.md). This is source review, not a fresh
agent host query. Full private source/inspect/dumps/role hashes are not committed.

| Потребитель | Фактический доступ / источник | Вывод для снижения cluster flags |
|---|---|---|
| Backup db_sql | BEGIN READ ONLY, count pg_stat_activity for moodle and own plugin versions; POSTGRES_USER fallback postgres | Owner app can retain ordinary DB reads; native delta checks own DB operation |
| Backup database.dump | PGOPTIONS default_transaction_read_only=on; pg_dump -w -U POSTGRES_USER -d moodle --format=custom | App owns observed tables/sequences; actual ordinary-owner dump/restore must pass native gate |
| Backup postgres-globals-private.sql | pg_dumpall -w -U POSTGRES_USER --globals-only, no --no-role-passwords | **Blocker:** full roles/password capture reads pg_authid; pass this command to separate backup admin before production demotion |
| HDD adapter/schedule | SHA-pinned engine; guarded execute wrapper preserves its DB/globals commands | Merely creating admin does not change backup's effective role; coordinated successor/guard handoff required |
| production_patch_readiness/upgrade and Connect contract | SQL via POSTGRES_USER; current read-only versions/schema/index metadata and application-owned records; upgrade is native PHP app connection | No new cluster writer introduced by reviewed wrappers; test app DDL/upgrade/repeat separately from admin harness |
| production_restore.py | ustar_restore_admin creates DB owned by ordinary ustar_app; pg_restore --role=ustar_app --no-owner/--no-acl | Existing role separation reused; source/older modeled proof not relabeled a fresh production restore |
| Cron | Reviewed repo handoffs preserve original helper and patch version line; PHP runs under CFG dbuser; selected native scheduled task tested in delta | Historical original v3 SHA66fd51 differs from available23776B Library v2 source; do not use that older file as exact current-v3 source evidence |

The ordinary app must not receive SELECT on pg_authid/read-server-files or a privileged
role merely to preserve globals capture. Dropping role passwords with
`--no-role-passwords` changes recovery content and is not the accepted fix.
Both backup entrypoints and frozen controller preflight/helper hashes need a coherent
handoff; do not patch one service's environment and assume manual captures also work.

Primary PostgreSQL16 behavior reviewed:
https://www.postgresql.org/docs/16/app-pg-dumpall.html (`--globals-only`, `--no-role-passwords`)
https://www.postgresql.org/docs/16/functions-admin.html (server-file restrictions)

## Native qualification

Plan: [INF02 privilege delta](../proposals/INF02_PRIVILEGE_DELTA_PLAN_20261023.md).
RED commit df3ee0ef8498c0ff3b0163cdcf10fce15454d20f introduces an actual app-role
assertion in the existing PG16.15/Moodle518 fixture. Review run40012838091,
connect-db120619281630 failed as intended: previous158tests/830assertions and
TELEGRAM_PRESENTATION_UPGRADE_RECOVERY passed, then new assertion refused with
APP_ROLE_HAS_CLUSTER_PRIVILEGES. Source PHP8.2/8.3 and runtime jobs succeeded.
GREEN reduced-role implementation is prepared; native result remains pending.
Host evidence verifier6 focused tests already observed RED missing verifier → GREEN.
This does not substitute for native DB assertions.

New gate must demonstrate actual app session/all five flags0, no privileged inherited
memberships, owner DDL and business/file operations, selected scheduled task,
2200→2300/repeat, actual app dump and admin restore SET ROLE app, record/schema equality,
SQLSTATE42501 cluster denials, failed old globals capture as app and successful private
globals capture as admin. Synthetic dumps/globals remain /stage tmpfs; only selected
sanitized JSON/logs are eligible for artifacts.

## Production gate remains closed

No production ALTER ROLE/chmod/chown/HBA/service/container action in this CI change.
Before owner demotion: qualified native result, coordinated backup globals/admin
handoff for all entrypoints and exact guards, current consumer/source acceptance and
reviewable bounded apply/rollback via already verified ustar_pg_admin. Code/config
permissions remain separate with HTTPS saved-metadata handoff. Existing restore/UI
acceptance is reused and not restarted as a generic prerequisite.
