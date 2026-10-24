# INF02: проверка новых прав в существующем CI

> Execution: superpowers:executing-plans, самостоятельно,0subagents. Owner approved
> narrowed scope «ок давай»23Oct; no additional design/approval loop.

**Goal:** квалифицировать current2300 при снятых пяти cluster flags и установить
зависимости existing backup/upgrade consumers до изменения production роли.
**Spec:** infra_readonly_baseline_20261023.md + INF02 remediation05Oct.
**Architecture:** дополнить существующий Connect PG16.15/Moodle518 fixture после
его принятого2200 paired restore. После native bootstrap refusal20:36UTC профиль исправлен: OID10 ustar_fixture остаётся
административным, отдельная обычная ustar_app получает DB/public app objects и CFG
connection; independent fixture admin занимается setup/restore/globals. System
catalogs не передавать, bootstrap не демотировать. Это CI, не production apply. Не создавать
owner/server1 стенд и не менять приложение, frozen installers, secrets/HBA/production.

## Задачи и интерфейсы

1. **Native role assertion, RED.** Create `tests/stage/db_privileges_probe.php`:
   refuses non-/stage/Moodle/db host/name/prefix/explicit fixture flag; role mode
   requires actual session/current app and all five flags false plus DB ownership.
   Hook into existing runner/workflow scope/artifact list. Native existing fixture
   must fail `APP_ROLE_HAS_CLUSTER_PRIVILEGES`, not environment/setup errors.
2. **Новый профиль и native assertions, GREEN.** Create `run-db-privileges.sh`:
   separate fixture admin, bootstrap-safe public ownership + synthetic CFG handoff
   to ordinary ustar_app, role/DDL/domain/file writes, current2200→2300/
   repeat, selected scheduled task, actual app pg_dump/restore with admin cluster
   actions, five SQLSTATE42501 denials. Prove old globals app dump refused and admin
   succeeds privately. Unchanged legacy POSTGRES_USER remains admin and must also
   capture globals privately; no backup helper rewrite required. Keep dumps/globals
   under /stage tmpfs, sanitized
   JSON/logs only under artifacts. Native gate uses exact current source, no provider.
   Refuse temporary CREATEDB-role membership despite zero app flags; revoke it and
   retain sanitized negative-control metadata before positive app operations.
3. **Evidence verifier, consumer report, checks.** Create host JSON verifier and
   focused r16 tests: reject elevated flags/admin masquerade/wrong denial/absent
   upgrade or restore proof; RED→GREEN. Review exact pinned original backup source
   privately, record administrative backup caller retention. Full production change
   requires separate app credential/config/ownership handoff and HTTPS state acceptance.

## Review focus

- Admin harness privileges cannot become app privileges: assert actual session/
  current role and memberships; DROP/CREATE DATABASE only separate admin.
- Do not mistake unrelated CLI/network failure for permission denial: SQLSTATE
  42501 and globals stderr pg_authid/permission check, positive admin control.
- Real DDL support must be tested even though2300 migration adds no new table.
- Dump/role password hashes stay private tmpfs; no broad artifact upload.
- Restore proves records/files/source/schema retention, not merely pg_restore --list.

## Verification / progress

- [x] Native role assertion RED on current superuser fixture: df3ee0ef,
      Review40012838091/connect-db120619281630, APP_ROLE_HAS_CLUSTER_PRIVILEGES
      after the previous158tests/830assertions and presentation recovery passed.
- [x] Focused verifier8tests RED→GREEN and Bash/Python checks pass; revised native
      PHP/SQL profile executed successfully on PostgreSQL16.15/Moodle5.1.8.
- [x] Native limited-role gate GREEN: code933497e0, Review40043036549,
      connect-db120719333841. Artifact12540597495 downloaded/hash-verified;
     14operation checks, five42501 denials and100USTAR-schema equality passed.
- [x] Exact code/run/proof and consumer limitations recorded in
      infra_db_privilege_ci_20261023.json; no server1 mutation in this CI work.

User/AGENTS rules override reviewer dispatch/pause instructions: author-only review,
no main merge/provider sends/SSH. Existing general CI gates remain intact; no new
manual server1 full-cycle request. Original private backup source is not committed.

Native incident80b/Review40017305504/job120640978099 proved bootstrap cannot be
demoted. First architecture assumed this was possible; corrected route above supersedes
in-place NOSUPERUSER. Owner24Oct read-only result confirms server1 moodle/OID10 and
ustar_pg_admin/OID109344, both superuser; no new production change. Query is accepted.

This narrowed CI/consumer-review plan is complete24Oct. Full INF02/03 remain open:
production needs a distinct private app credential and guarded config/public ownership
handoff preserving HTTPS state and the administrative backup caller. Existing owner
stands remain accepted; no new manual full-cycle stand is requested.
