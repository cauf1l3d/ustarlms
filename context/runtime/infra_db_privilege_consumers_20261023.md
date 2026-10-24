# INF02: потребители DB privileges,23Oct2026

Owner accepted ustar_pg_admin with two required-SCRAM sessions9976/9995. Production
moodle flags remain unchanged. Scope is reuse of accepted stages plus missing
privilege checks in existing CI, not a new owner/server1 stand.

**Bootstrap correction20:36UTC:** native80b refused ALTER ROLE ustar_fixture
NOSUPERUSER: The bootstrap user must have the SUPERUSER attribute. PG16 cannot
demote OID10. Do not give production demotion commands before checking server1 OID.
Revised route: keep bootstrap/POSTGRES_USER administrative; create ordinary ustar_app,
move application public objects and CFG dbuser to it, preserve system catalogs.
CI models that route; real credential/config/ownership handoff is still future work.
Owner24Oct confirmed moodle/OID10/superuser and ustar_pg_admin/OID109344/superuser.
OID query is accepted and must not repeat; [owner receipt](infra_readonly_owner_20261023.json),
bootstrap_role_confirmation. Owner also created ustar_app/OID110395 and verified its
required-SCRAM login: five flags false/login true/no membership/no DB ownership.
app_login_preparation_success in the same receipt is accepted; do not recreate roles.
[Next read-only block](infra_readonly_baseline_20261023.md#app-handoff-preflight-24oct)
has passed: controller2300/ACK ready, all3479public relations mdl_/moodle; monitor definer app USAGE0/EXECUTE0. Do not repeat the manual batch.

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
| Backup postgres-globals-private.sql | pg_dumpall -w -U POSTGRES_USER --globals-only, no --no-role-passwords | Full roles/password capture reads pg_authid. Keep existing POSTGRES_USER administrative while application moves to a different ordinary role; exact old command gets a private positive control |
| HDD adapter/schedule | SHA-pinned engine; guarded execute wrapper preserves its DB/globals commands | Creating admin alone does not change backup role. New app connection leaves existing administrative POSTGRES_USER and helper pins intact |
| production_patch_readiness/upgrade and Connect contract | SQL via POSTGRES_USER; current read-only versions/schema/index metadata and application-owned records; upgrade is native PHP app connection | No new cluster writer introduced by reviewed wrappers; test app DDL/upgrade/repeat separately from admin harness |
| production_restore.py | ustar_restore_admin creates DB owned by ordinary ustar_app; pg_restore --role=ustar_app --no-owner/--no-acl | Existing role separation reused; source/older modeled proof not relabeled a fresh production restore |
| Cron | Reviewed repo handoffs preserve original helper and patch version line; PHP runs under CFG dbuser; selected native scheduled task tested in delta | Historical original v3 SHA66fd51 differs from available23776B Library v2 source; do not use that older file as exact current-v3 source evidence |

The ordinary app must not receive SELECT on pg_authid/read-server-files or a privileged
role merely to preserve globals capture. Dropping role passwords with
`--no-role-passwords` changes recovery content and is not the accepted fix.
The initial in-place demotion proposal would have broken globals capture. The revised
bootstrap-safe route retains the existing administrative POSTGRES_USER for every
backup entrypoint, so no backup helper/source/profile mutation is planned. Actual
application credential/config/public ownership handoff still requires a guarded tool.

Static entrypoint/guard map (source evidence, not a fresh host hash probe):

- `ustar-hdd-backup.timer` → service →
  `/usr/local/sbin/USTAR_HDD_SCHEDULE_20261006.py --run` → producer `--backup`.
  Manual/current-update fresh captures call the producer; recovery uses `--recover`.
- Current HTTPS-compatible producer pin is
  `a604fbcd6a0733d29142bb3130dc4bea2e7da1797c6995699d9d5516f21b05c3`;
  scheduler pin is `a97039a8111dfa4d68fa0a851e17dec9a82d8f1096b7b61b8a71609859134e8c`.
  Producer loads the original engine at the exact SHA above.
- `connect_con02_contract.Host.preflight` validates all three helpers;
  `backup_https_prepare` also records the producer/scheduler pair and before-images.
  Changing producer SQL routing alone would trigger accepted_backup_helper_changed
  or backup_helper_drift in existing profiles. Revised role separation preserves
  these helpers and frozen journals; do not change their SQL merely for app separation.

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
Candidate80b3ccfb/Review40017305504 native120640978099 passed old158/830 and
presentation recovery but failed at bootstrap demotion before app delta. This is an
implementation assumption error, not target-app qualification. Both source PHP jobs
and runtime passed; source83 required one unchanged-code retry after15min timeout
caused by Chromium install. At that checkpoint the bootstrap-safe profile still
awaited native GREEN. Host verifier8tests passed, including observed RED→GREEN for missing
bootstrap/system-catalog/scoped-ownership evidence.

Native e59d partial result24Oct: all3159 public relations transferred to ustar_app,
zero public relations left bootstrap-owned, zero app-owned pg_catalog relations.
Actual app OID126161/all five flags0/DBowner1/membership0; temporary CREATEDB
membership produced1 and was refused/revoked. Distinct app password rejected for
bootstrap; upgrade/repeat passed. Then enrolment probe failed an API argument:
Moodle enrol_user expects an existing stdClass instance; add_instance returned null
because create_course already installed it. Probe now fetches that record. That failed
attempt did not reach scheduled task/denials/dump/restore; it remains partial evidence.

**Qualified24Oct:** corrected code933497e033448feb8a7aac9e29ece351f06600ac,
Review40043036549/connect-db120719333841 and Context40043036469 SUCCESS.
Both PHP8.2/8.3 source jobs passed1085r16/153environment skips and browser scenarios;
runtime passed. Old158native tests/830assertions and presentation recovery passed
before the new handoff. The new ordinary-role delta then passed all14operation checks:
actual app OID126144/five flags0/DBowner1/no dangerous membership;3159public relations
transferred, no app-owned pg_catalog; privileged membership refusal/revoke; distinct
app password cannot authenticate bootstrap; XMLDB/domain/FileAPI writes, selected
scheduled task,2200→2300/repeat, app pg_dump and admin restore SET ROLE app with selected
records/file and100USTAR-schema equality. Five cluster denials returned exactly42501.
App globals capture was denied; independent admin and unchanged legacy POSTGRES_USER
both captured globals privately. Artifact12540597495,771349B, SHA256
`342249e89d786f742f2ad87364fc09c012c07fd356a517ff572615d2d641a20a`
was downloaded/hash-verified and its individual JSON records cross-checked. No SQL,
dumps, environment files or private directory were uploaded. Full proof and seven
qualified code-file hashes are in the [receipt](infra_db_privilege_ci_20261023.json).
This is selected reduced-role coverage, not a claim that the old158tests ran under it.

The qualified gate keeps bootstrap OID10 SUPERUSER, creates ordinary ustar_app, transfers only
public app relations (tables/views/sequences and automatic index ownership) and switches
synthetic CFG. It proves equal public relation ownership counts and zero app-owned
pg_catalog relations, actual app session/all five flags0, membership refusal/revoke,
owner XMLDB/domain/FileAPI writes, selected scheduled task,2200→2300/repeat, app dump
and admin restore SET ROLE app, selected record and100USTAR-schema equality, five42501
cluster denials. Globals capture as app must fail; both separate admin and unchanged
legacy POSTGRES_USER must succeed privately. SQL/globals/dumps stay in /stage tmpfs;
only selected sanitized JSON/logs are eligible for artifacts.

Primary bootstrap sources:
https://www.postgresql.org/message-id/20230915205301.GB2005906@nathanxps13
https://www.postgresql.org/docs/16/glossary.html (bootstrap owns system catalogs)

## Production gate remains closed

No production role/config/ownership/HBA/service/container action in this CI change.
Owner OID query and independent admin login already accepted. Before real apply:
app credential isolation against bootstrap, exact app-object ownership/grant/definer inventory and guarded config/owner
handoff preserving HTTPS controls/metadata and rollback. Never use broad REASSIGN OWNED
from bootstrap: it owns system catalogs and shared cluster objects. Retained backup
POSTGRES_USER remains administrative and old helper pins unchanged. Full INF02/03
remain open; existing owner restore/UI acceptance is reused. Current cron-v3 original
source is not inferred from the older v2 Library copy.

## Guarded production handoff

[Implementation plan24Oct](../proposals/INF02_APP_HANDOFF_PLAN_20261024.md) follows
accepted role creation and native owner preflight24Oct. Coordinator source is implemented; generated SQL/strict UID33 CI qualification is pending, so apply is not yet an operator-ready handoff. Preserve old Connect journals during the handoff.
Canonical HTTPS state requires an explicit config hash/metadata acceptance update
with private before-images. A compatibility view for frozen runtime recovery must
verify the exact post-handoff state before returning original accepted descriptors;
never normalize unknown drift or rewrite the old snapshots to make a guard pass.

Public custom routines and SECURITY DEFINER grants/search_path need classification
before object transfer. The role metadata/login proves flags/memberships only; native
fixture14checks do not prove absence of arbitrary production delegation. Metadata
query prints no routine body or unrelated GUC, and executes no custom function.
No blanket REVOKE/GRANT/ALTER FUNCTION is authorized merely by a nonempty result.
Primary references: https://www.postgresql.org/docs/16/sql-createfunction.html and
https://www.postgresql.org/docs/16/ddl-priv.html.
