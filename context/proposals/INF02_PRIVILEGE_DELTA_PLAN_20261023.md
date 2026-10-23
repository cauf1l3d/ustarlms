# INF02: проверка новых прав в существующем CI

> Execution: superpowers:executing-plans, самостоятельно,0subagents. Owner approved
> narrowed scope «ок давай»23Oct; no additional design/approval loop.

**Goal:** квалифицировать current2300 при снятых пяти cluster flags и установить
зависимости existing backup/upgrade consumers до изменения production роли.
**Spec:** infra_readonly_baseline_20261023.md + INF02 remediation05Oct.
**Architecture:** дополнить существующий Connect PG16.15/Moodle518 fixture после
его принятого2200 paired restore. App остаётся DB/object owner ustar_fixture;
отдельный fixture admin занимается cluster setup/restore/globals. Не создавать
owner/server1 стенд и не менять приложение, frozen installers, secrets/HBA/production.

## Задачи и интерфейсы

1. **Native role assertion, RED.** Create `tests/stage/db_privileges_probe.php`:
   refuses non-/stage/Moodle/db host/name/prefix/explicit fixture flag; role mode
   requires actual session/current app and all five flags false plus DB ownership.
   Hook into existing runner/workflow scope/artifact list. Native existing fixture
   must fail `APP_ROLE_HAS_CLUSTER_PRIVILEGES`, not environment/setup errors.
2. **Новый профиль и native assertions, GREEN.** Create `run-db-privileges.sh`:
   separate fixture admin, demote app, role/DDL/domain/file writes, current2200→2300/
   repeat, selected scheduled task, actual app pg_dump/restore with admin cluster
   actions, five SQLSTATE42501 denials. Prove old globals app dump refused and admin
   succeeds privately. Keep synthetic dumps/globals under /stage tmpfs, sanitized
   JSON/logs only under artifacts. Native gate uses exact current source, no provider.
   Refuse temporary CREATEDB-role membership despite zero app flags; revoke it and
   retain sanitized negative-control metadata before positive app operations.
3. **Evidence verifier, consumer report, checks.** Create host JSON verifier and
   focused r16 tests: reject elevated flags/admin masquerade/wrong denial/absent
   upgrade or restore proof; RED→GREEN. Review exact pinned original backup source
   privately, record required admin globals handoff. Full app permission reduction
   remains blocked pending coordinated backup successor/host acceptance.

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
- [ ] Focused verifier RED→GREEN, Bash/Python/PHP syntax + context checks.
- [ ] Native limited-role gate GREEN in existing pinned CI, inspect artifacts/logs.
- [ ] Record exact commit/run/result and consumer limitation; no server1 mutation.

User/AGENTS rules override reviewer dispatch/pause instructions: author-only review,
no main merge/provider sends/SSH. Existing general CI gates remain intact; no new
manual server1 full-cycle request. Original private backup source is not committed.
