# CON-02 coordinated deployment Implementation Plan

**23Oct compatibility correction after owner preflight:** accepted production2000 has
exactly two absent legacy UNIQUE indexes (coin_accounts.userid/test_results.attemptid)
while ordinary indexes/all other signatures match. Controller4394b994 accepts strict
fresh XMLDB OR this exact paired historical profile, preserving saved old99 definitions
and legacy rows through upgrade/repeat/restore. No index repair/unknown schema adoption.
Both actual native profiles and all required source/runtime/HTTPS CI verified:
Context39947880072/Review39947880106 SUCCESS. [Incident and current handoff](../runtime/connect_con02_index_compat_20261023.md).
Original cause of absent constraints unknown; source534/worker534 and schema2200 unchanged.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Owner forbids subagents; implementation and author review stay in this session/current isolated feature checkout.

**Goal:** Дать оператору проверенный обычный.py: direct2000+accepted-overlay→2200 с fresh pair/cron2200, затем отдельный управляемый Telegram ACK enable/disable без расширения получателей.

**Architecture:** Один новый controller с раздельными application transaction и runtime generation. Fixed app/worker534 source, новая tool revision, существующие pure copy/metadata/HTTPS/backup primitives. Frozen controllers остаются неизменными и отказывают после передачи владения.

**Tech Stack:** Python3.12 stdlib, systemd/LoadCredential/Unix metadata, существующие Docker/Moodle5.1.8/PHP8.3.33/PostgreSQL16.15/Apache fixtures, r16/Chromium. Новых продуктовых зависимостей нет.

**Spec:** [Согласованный письменный проект](USTAR_CONNECT_CON02_DEPLOYMENT_DESIGN_20261022.md), owner22Oct: «потверждаю». Метод уже выбран: самостоятельно,0subagents. Этот план подготовлен для review; implementation ещё не начата. Финальный design checkpoint f0c00d5e: Context39789336484/Review39789336561 SUCCESS, FullRC jobs skipped, не passed.

## Global Constraints

- PR106/design/ustar-connect-20261018; main unmerged. Текущий isolated worktree уже существует; чужие/старые dirty checkout не трогать. Нет agent SSH/provider sends.
- App/worker fixed534764821c4f65437ab50b3c7f25578d538e9504: local2026102200/theme2026100202,557files/100tables. Tool commit отдельный. Product A.2 не менять ради упаковки.
- Baseline1e8a8819f50785ebb4fb2640eeb9386c969d59d1: local2026102000/theme2026100202,552files/99tables + только notification_delivery.php overlay SHA2562f6c0c8b25941ac435237fc9df4c4de738b885f467ccbab80b9988c0bb42e498.
- Worker/tool baseline a86e208029b6104150e85d0e90384f84da214c22. Exact old UID/subject fence/notify_since/preferences/revoke сохраняются; UID130 последний раз revoked. Ни relink, ни группа, ни повтор пилота.
- Direct official upgrade2000→2200 и repeat; промежуточный live2100/его savepoint не выдумывать. Единственная новая таблица local_ustar_conn_acktokens. Все old99 schema/indexes/task flags сохраняются.
- Cron принимает полный ae5fa1b075ff5f97300f5d5675d51367b95d821e0acf73c7c4458200af7e9751. Ровно одна literal VERSIONS2000→2200, theme0202; matching installation pair. Schedule/history/tick не менять.
- HTTPS owner, config.php/vhosts/health/observability/tool/units/state/CA/leaf неизменны внутри операции. Действующее HTTPS renewal между операциями разрешено своим owner. Existing backup pair a604fbcd/a97039a8 сохраняется.
- Mutating lock order: new controller→Academy update→chat-link update→HTTPS, затем cron→backup→receiver-install. Fresh HDD child вызывается до захвата последней группы; никогда под уже удерживаемым backup lock.
- Namespace /var/lib/ustar-connect-con02-update root0700. Pre-public error восстанавливает matching cold pair. После public_release нет автоматического DB rollback; explicit late restore требует discard flag и outage acknowledgement.
- Один receiver, SQLite schema1/cursor/uncertain сохраняются. Runtime fallback меняет только свою generation на2200. Legacy journals/guard constants/helpers не переписываются.
- Новый sender tg_notify_v2: lease/begin_v2/finish; отдельный tg_ack_v1: notification.ack только receiver/probe. Old link key/token/proxy/CA сохраняются. ACK cutoff=now+1 один раз на generation, notify cutoff прежний.
- Владелец получает один обычный.py/SHA256/короткие команды. Внутренний штатный worker PYZ остаётся внутренним форматом. Никаких пользовательских архивов/ручной распаковки.
- Live group acceptance deferred до полного запуска, не passed. Установщик/новая production версия не заявляются готовыми до новых gates. Старые source/runtime/native PASS не заменяют installer evidence.

## Review Focus

1. UID130 уже revoked: подготовка/upgrade/enable не требуют relink и не включают получателя; Task1/4/8.
2. HTTPS успел штатно обновить leaf между app upgrade и ACK enable: принять новый verified owner snapshot, удержать его во время операции; Task1/8.
3. Fresh HDD сам получает backup lock: controller не вызывает его под backup/install lock; contention не останавливает контейнеры; Task2/3.
4. Процесс погиб после public_release или начала polling: recovery не откатывает новые DB записи/cursor, повтор использует прежнюю generation/cutoff; Task3/5/8.
5. Изменился custom/disabled task либо старая колонка при новой100-й таблице: не применять legacy NEW_TASK=[0,0] assumption, отказ до public release; Task1/3/7.

## File structure and interfaces

| Файл | Ответственность |
|---|---|
| scripts/infra/connect_con02_contract.py | Fixed profile, EmbeddedBundle/Context, safe baseline/target/HTTPS contracts и read-only SQL guards |
| scripts/infra/connect_con02_state.py | Verified file images, durable app/runtime journal, phased locks и public status |
| scripts/infra/connect_con02_application.py | Fresh HDD/cold pair/direct upgrade/cron/recovery; свой version validator |
| scripts/infra/connect_con02_profiles.py | Pure exact include/config/drop-in generation; keys и v1/v2 profile bounds |
| scripts/infra/connect_con02_runtime.py | Stage/switch/enable/disable/recover одной runtime generation |
| scripts/infra/connect_con02_update.py | Тонкий CLI/mode/root/artifact guard и dispatch |
| scripts/infra/build_connect_con02_update.py | Immutable Git export, plain.py loader/manifest и artifact hashes |
| tests/r16/test_connect_con02_{contract,state,application,profiles,runtime,package}.py | Focused negative/transition/filesystem/TDD evidence |
| tests/stage/run-con02-update.sh,con02_update_probe.php,con02_update_native.py | Actual direct2000→2200/repeat/fresh/paired recovery и проверки на native SQL snapshots |
| tests/stage/con02_update_systemd.py,con02_update_https.py | Actual UID33/credentials/private TLS/handoff и Apache/renewal; synthetic provider |

`EmbeddedBundle` — frozen dataclass Task1: reference:dict, modules:dict[str,bytes],
payload:dict[str,bytes] (`local/ustar/...`,`theme/ustar/...`), worker_archive:bytes,
legacy:dict[str,bytes]. Reference содержит schema1/tool_commit/app_commit/worker_commit,
baseline/candidate objects с files:dict[path,sha256],tables:list[str],versions:dict,
schema_fields:dict и baseline_commit/app_commit соответственно; новый XMLDB contract
в candidate. Reference не содержит runtime секретов.
`Context` Task1 содержит bundle, root:Path, uid:int, host:Host и engine:SimpleNamespace.
engine имеет проверенные p/cold/u/c/tx/w/probe modules, reference/payload/schema_fields,
web/trees/base/marker/identities для accepted pure primitives. Это adapter, не вызов
старого bundle()/dependencies()/checks()/config_guard()/verify_versions()/validate_marker()
или полного recover()/restore_pair(), привязанных к прежним версиям. App adapter
использует только fresh_hdd/activate/source/capacity/reference/file-copy primitives;
legacy record() пишет в отдельный per-run primitive journal, не в новый root Journal.
Context.w — собственная копия accepted helper profile с принятой backup pair,
без изменения глобальных constants/старого helper manifest.
Host methods: inspect()->dict; database(prefix:str,addons:dict|None=None)->dict;
policy(value:str)->None; stop_web(*,clean:bool=True)->None; stop(*,clean:bool=False)->None;
start()->None; cli_upgrade()->None; restore_policies(policies:dict)->None; login()->int;
action(argv:list[str],timeout:int)->None; service_active(unit:str)->bool;
service_enabled(unit:str)->bool; service(action:str,unit:str)->None; probe(profile:str)->None.
Production делегирует accepted Host adapters, fixtures подставляют этот Host.
probe использует существующий no-poll receiver/probe flow; sender scope проверяется
его штатным ExecStartPre при восстановлении active sender. Если sender оставлен
inactive, конфигурация validated, а live sender probe/delivery не заявляются.
Положительный lease/send не используется как probe и временный второй sender не создаётся.
CLI не получает --root/--fixture и всегда использует production root.

Все названия unittest тестов ниже имеют префикс `test_`; приведённые before/after
значения принадлежат их synthetic fixture. Верификация Python — из repo root: `python -m unittest discover -s tests/r16 -p 'test_connect_con02_*.py' -v`.
Native — disposable CI, не server1. Existing compose build/up/cleanup и fixture exports
сохраняются в .github/workflows/ustar-review-gate.yml. Недоступные Git historical
objects/PHP/Docker/systemd локально — явные environment skips/CI gates, не product RED/PASS.

---

### Task 1: Frozen release profile and read-only contracts

**Files:** Create connect_con02_contract.py, initial build_connect_con02_update.py reference export,
tests/r16/test_connect_con02_contract.py. Read accepted installer/HTTPS/cron primitives; do not edit them.

**Interfaces:** `reference_from_git(repo:Path,tool_commit:str)->EmbeddedBundle` in builder;
`make_context(bundle:EmbeddedBundle,*,root:Path=Path('/'),uid:int=0,host:Host|None=None)->Context`;
`check(ctx:Context)->dict`, `verify_database(current:dict,baseline:dict,reference:dict,*,target:bool)->None`,
`https_snapshot(ctx:Context)->dict`. Check uses existing locks/read-only SQL, no PHP bootstrap,
signed nonce/provider call/state init. Missing journal permits only exact2000+overlay baseline.

- [x] **Step 1 — RED tests.** Add named tests frozen_counts_and_hashes, revoked_pilot_is_valid_baseline,
  readonly_has_no_business_or_nonce_writes, unknown2200_is_not_adopted,
  authorized_renewal_between_operations, target_preserves_old99_and_custom_tasks.
  ```python
  b,c = bundle.reference['baseline'],bundle.reference['candidate']
  self.assertEqual((552,557,99,100), (len(b['files']),len(c['files']),len(b['tables']),len(c['tables'])))
  self.assertEqual({'local_ustar_conn_acktokens'}, set(c['tables'])-set(b['tables']))
  self.assertEqual(before['tasks'], after['tasks'])  # includes prune task [1,1].
  ```
  Assert wrong overlay/extra file, prefix injection, wrong tool/CA/peer/units,
  old99 column/index mutation, missing/new extra table all refuse before effects.
- [x] **Step 2 — Run RED.** Run contract test pattern; expect missing new interface assertions/import,
  not missing historical Git objects. Record exact RED command/source; use full-history CI if needed.
- [x] **Step 3 — Implement contracts.** Export baseline with the one exact overlay, target557/100 from534,
  worker separately534 and frozen a86/legacy refs. Derive XMLDB table/field/index contract;
  current table99 metadata must remain exact, new table must match fresh-target normalized metadata.
  Compare full addon registry/core/tasks; do not reuse legacy task-reset/version assumptions.
  Each operation accepts current verified HTTPS owner state then freezes it under its lock,
  rather than pinning a previous leaf forever. No global helper hash/profile monkeypatch.
- [x] **Step 4 — GREEN.** Contract pattern passes; read-only effects counters all zero, no root state
  initialized, revoked fixture retained. Existing installer/HTTPS/cron tests unchanged.
- [x] **Step 5 — Commit** `feat: define fixed CON-02 deployment contracts`; progress ledger and generated index reflect only observed results.

### Task 2: Durable images, phased locks and recovery journal

**Files:** Create connect_con02_state.py and tests/r16/test_connect_con02_state.py.

**Interfaces:** `Journal(ctx:Context)` with `load()->dict|None`,
`begin(scope:str,before:dict,desired:dict,*,generation:str)->dict`,
`checkpoint(scope:str,phase:str,**fields)->dict`, `put(scope:str,name:str,side:str)->None`,
`guard(scope:str)->None`, `public()->dict`.
`Locks(ctx).prebackup()` holds new/deployment/HTTPS EX;
`Locks(ctx).cold()` nests cron/backup/install EX only inside prebackup.
`Locks(ctx).check()` acquires existing shared controls, creates nothing.
scope exactly app/runtime, side exactly before/after; unknown shapes reject.

- [x] **Step 1 — RED tests.** Add durable_partial_pair, replaced_lock_inode,
  backup_not_held_in_prebackup, metadata_uid33_root_gid_acl, unknown_admin_drift,
  check_status_create_nothing. Test root-required branch under USTAR_REQUIRE_CON02_OWNER=1.
  ```python
  self.assertEqual(['deployment','https'], host.locks_at_backup)
  self.assertEqual(before_meta, restored_meta)
  self.assertEqual([], host.mutations_after_drift)
  ```
  Use real file locks/atomic replacements and actual UID33 in required CI; modeled SQL is labelled.
- [x] **Step 2 — Run RED.** State pattern fails for missing Journal/Locks; actual-owner gate is a
  reported skip locally unless root, and mandatory failure if its CI environment cannot run it.
- [x] **Step 3 — Implement journal/locks.** Root0700 state,0600 images; preserve uid/gid/mode/xattrs.
  Reuse accepted secure-read/atomic/replace/fsync primitives. Validate all live entries against
  known before/after before any recovery write; record source IDs/pointer/service states.
  Original app2000 baseline and last runtime generation are distinct; secrets only in images.
  Bounded busy refusal, never unlink locks. New lock directory created only by mutating mode.
- [x] **Step 4 — GREEN.** Real filesystem/crash/lock tests and strict root owner fixture pass;
  status excludes keys,subjects,private messages and tracebacks. Scope mismatch never writes.
- [x] **Step 5 — Commit** `feat: journal CON-02 deployment and phased control locks`.

### Task 3: Direct application upgrade and matched cron transaction

**Files:** Create connect_con02_application.py, tests/r16/test_connect_con02_application.py.

**Interfaces:** `Application(ctx:Context,journal:Journal,locks:Locks)`:
`upgrade()->dict`, `recover()->dict`, `restore(*,discard:bool=False,outage:bool=False)->dict`.
`patch_cron(raw:bytes,installation:dict)->tuple[bytes,dict]` pure helper.
Phases: prepared,fresh_backup_complete,workers_stopped,pair_complete,source_activated,
upgrade_1,upgrade_2,cron_written,public_release_committed,app_ready,baseline_restored,recovery_required.

- [x] **Step 1 — RED tests.** Add fresh_backup_child_before_cold_locks,
  direct_upgrade_repeat_preserves_custom_task, partial_cron_pair_recovers,
  before_public_restore_exact_pair, after_public_keeps_new_writes,
  interrupted_run_recovers_before_new_upgrade, sender_disabled_stays_disabled, readonly_check_never_stops.
  ```python
  self.assertEqual(2, host.upgrade_calls)
  self.assertEqual('2026102200', cron_after['versions']['local_ustar'])
  self.assertEqual(0, host.db_restore_calls_after_public)
  ```
  Fail backup/readback/clean stop/other DB clients/capacity/SQL/source guard independently.
- [x] **Step 2 — Run RED.** Application pattern must fail at missing transaction/cron interface;
  source/reference tests from Task1 remain GREEN. No host operation uses server1.
- [x] **Step 3 — Implement transaction.** Reuse accepted fresh_hdd/paired_copy/activate/reference checks
  and physical tree copy primitives through Context. Own restore sequence uses tx.read_pair,
  roots/inventory/verify_tree plus u.move_children/copy_contents, then new verify_database;
  it never invokes the fixed-OLD_CORE restore_pair/recover entrypoints. Root Journal records
  possible mutation before calling a legacy primitive; primitive record() stays per-run.
  Preserve prior target-path uid/gid/mode/xattrs via the verified metadata adapter; new paths
  use reviewed parent ownership and0755/0644. Prebackup phase invokes the accepted
  producer before cold lock set; require fresh timestamp/readback/no pending resume marker.
  Capture controls, stop sender then receiver, close journal writer; stop web/PG and verify clients,
  roots, capacity, clean exits. Record paired baseline before source mutation. Official direct2200
  upgrade/repeat under maintenance; Task1 validator preserves custom tasks and old99/schema.
  Apply exact single-line cron patch plus installation pair/readback before public boundary.
  Keep include/v1/a86/ACKoff, immutable HTTPS bytes, restore prior service/restart policies.
  Pre-public failure restores full pair/controls; post-public recovery only verifies2200/restarts.
  Late restore refuses unless both discard and outage are true; no independent DB downgrade.
  Repeated successful upgrade is verify-only; an interrupted pre-public run is recovered
  under its original ID to terminal baseline_restored before a later explicit new upgrade.
  An unfinished run is never silently replaced with a new backup/run or runtime generation.
- [x] **Step 4 — GREEN.** Transaction/root-UID33/partial-write cases pass; full trace confirms no
  DB restore after public commit, no cursor/lease reset and no fresh HDD under backup lock.
  Metadata/file tests model Docker/SQL explicitly; native direct-upgrade proof is Task7.
- [x] **Step 5 — Commit** `feat: upgrade Academy and cron to2200 as a recoverable pair`.

### Task 4: Exact v2/ACK profiles and immutable worker stage

**Files:** Create connect_con02_profiles.py, tests/r16/test_connect_con02_profiles.py;
prepare stage helper in connect_con02_runtime.py without starting services.

**Interfaces:** `profiles(ctx:Context,before:dict,*,generation:str,cutoff:int,notify_secret:bytes,ack_secret:bytes)->dict[str,bytes]`;
secrets exactly64lowercase hex ASCII bytes; key IDs tg_notify_v2/tg_ack_v1.
`stage_worker(ctx:Context)->Path` validates fixed534 archive/unit bytes and publishes immutable0755/0644 root-owned release.

- [x] **Step 1 — RED tests.** Add preserves_revoked_uid_subject_and_notify_cutoff,
  keys_are_distinct_from_link_and_each_other, separate_mount_paths,
  new_release_access_under_umask077, rejects_release_collision_and_unknown_override.
  ```python
  self.assertEqual(before_fence, after_fence)
  self.assertEqual(before_notify_since, after_notify_since)
  self.assertEqual(2, sender_cfg['notification_contract'])
  self.assertNotIn('ack_bridge_key_file', sender_cfg)
  ```
  Actual-private credential tests include hardlink/symlink/ACL and readonly service UID access.
- [x] **Step 2 — Run RED.** Profiles pattern fails on missing functions; frozen old runtime config
  tests remain GREEN. No network, DB or service writes from pure profiles function.
- [x] **Step 3 — Implement profiles/stage.** Append only exact scoped ACK/v2 settings to accepted
  owned include, preserve old v1 key and all notify fence/cutoff/flags. Receiver/probe get
  their own credential paths and ACK key; sender only v2 key. Include root:GID33/0640,
  root-private secrets/config0600, public drop-ins0644/dirs0755. Validate/lint before switch;
  candidate units byte-identical to existing managed units. Unknown settings/overrides reject.
  No relink/recipient lookup requiring a currently linked UID130 and no key regeneration on resume.
- [x] **Step 4 — GREEN.** Profiles/stage/root metadata tests pass, exact old config loading still
  works and sender cannot load ACK credential. Snapshot comparison proves prior fields unchanged.
- [x] **Step 5 — Commit** `feat: prepare isolated CON-02 callback runtime profiles`.

### Task 5: Runtime enable, disable and safe recovery

**Files:** Complete connect_con02_runtime.py; create tests/r16/test_connect_con02_runtime.py.

**Interfaces:** `Runtime(ctx:Context,journal:Journal,locks:Locks)`:
`enable()->dict`, `disable()->dict`, `recover()->dict`.
Phases: prepared,workers_stopped,private_files,include_handoff,pointer_switched,probed,
runtime_active,runtime_disabled,recovery_required. App must already be app_ready2200.

- [x] **Step 1 — RED tests.** Add enable_does_not_touch_business_pair,
  generation_cutoff_and_keys_survive_process_loss, fallback_restores_previous_runtime,
  disabled_sender_not_enabled, polling_cursor_never_rewinds,
  probe_failure_does_not_claim_active, revoked_recipient_unchanged.
  ```python
  self.assertEqual(first_generation, journal_after_resume['runtime_generation'])
  self.assertEqual(0, host.application_upgrade_calls + host.business_restore_calls)
  self.assertGreaterEqual(cursor_after_recover, cursor_before_switch)
  ```
  Cover loss after each durable boundary, startup/egress failures and unknown drift before recovery.
- [x] **Step 2 — Run RED.** Runtime pattern fails on missing transitions; profiles and Application
  tests stay GREEN. No direct provider message is sent by the controller in any test trace.
- [x] **Step 3 — Implement runtime operations.** Verify app/cron/HTTPS/exact fence; pre-stop no-poll
  identity/scope probes, stage worker and persist one generation/cutoff=now+1 and key images.
  Ordered locks, sender→receiver stop, apply private profile/include/pointer, daemon-reload,
  no-poll candidate probes, restart only prior enabled/active services. Preserve SQLite schema1/
  cursor and delivery uncertainty. Disable restores saved v1 profile/a86 on app2200;
  next enable gets a new generation, not a DB/token-table cleanup. Failure restores prior
  runtime only; outage preventing restart reports recovery_required. Never rewrite legacy journals.
- [x] **Step 4 — GREEN.** Fault matrix proves no app rollback/cursor rewind/widened fence,
  stable resume generation, explicit unsuccessful recovery and no controller send/positive lease.
  Existing receiver/callback/notification tests pass unchanged; actual systemd proof is Task8.
- [x] **Step 5 — Commit** `feat: switch callback runtime with guarded v1 fallback`.

### Task 6: Plain Python artifact and strict CLI

**Files:** Complete build_connect_con02_update.py; create connect_con02_update.py,
tests/r16/test_connect_con02_package.py.

**Interfaces:** `build(tool_commit:str,output:Path,*,repo:Path=ROOT)->Path` returns
ustar-con02-update-<tool_sha8>.py plus.sha256/.metadata.json.
`load_embedded(manifest:dict,sources:dict,assets:dict)->EmbeddedBundle`;
`main(argv:list[str]|None=None)->int` accepts exactly spec§7 modes, no fixture/root override.
Artifact<=128MiB, decoded total<=256MiB, entries<=1024, embedded module count<=64;
all paths canonical/unique, no traversal or imports from neighboring files.

- [x] **Step 1 — RED tests.** Add default_no_root_no_host_no_network,
  exact_commits_and_reproducible_plain_bytes, tamper_and_inventory_denial,
  no_adjacent_module_import, invalid_combined_modes_before_host,
  recover_and_restore_flags_respect_public_boundary.
  ```python
  self.assertEqual(built_once.read_bytes(), built_twice.read_bytes())
  self.assertTrue(built_once.name.endswith('.py'))
  self.assertEqual([], default_host_calls)
  self.assertEqual(0, late_restore_without_consent_writes)
  ```
  Decode bombs/duplicate entries/noncanonical base64/mismatched refs/dirty builder dependencies refuse.
- [x] **Step 2 — Run RED.** Package pattern fails at missing plain artifact/CLI modes, not an
  intentionally corrupt PHP/bootstrap fixture. Prior Tasks1–5 remain GREEN.
- [x] **Step 3 — Implement builder/CLI.** Immutable Git export only; UTF-8 module literals and
  base64 binary assets with sorted SHA manifest, bounded decode and all digests checked before
  embedded module compile/import. Manifest JSON rejects duplicate keys before typed load_embedded;
  source/assets namespaces are disjoint. Default PLAN_ONLY exits before any host/root/network check.
  Other modes validate root-owned0600/single-link/non-symlink artifact/root parents, no ambient
  import path; reviewed owner command verifies outer SHA before execution. Fixed app/worker534
  refs, separate tool commit. Mutating argparse constraints precede host actions. Check/status
  delegate Task1/2 read-only contracts. SIGTERM/INT/HUP use durable recoverable transitions;
  only fixed public errors, private traceback log. No PYZ/ZIP user deliverable or runtime installer
  embedded CLI execution bypass; reuse only reviewed helpers behind the new controller.
- [x] **Step 4 — GREEN.** Real `python3 -I -B artifact.py` default emits PLAN_ONLY/no host access;
  clean fixed-revision build reproduces SHA, malicious sibling ignored, all CLI refusal tests pass.
  Verify full557 payload/worker bytes against534 and frozen helpers unchanged.
- [x] **Step 5 — Commit** `feat: package CON-02 deployment as a verified plain Python command`.

### Task 7: Native direct2000→2200 lifecycle and paired recovery

**Files:** Create tests/stage/run-con02-update.sh,con02_update_probe.php,con02_update_native.py;
modify tests/stage/run-connect.sh and .github/workflows/ustar-review-gate.yml;
add native-wiring assertions to test_connect_con02_application.py.

**Interfaces:** Export frozen baseline1e8+a86 policy to .stage-input/con02-update-baseline;
target534 to .stage-input/con02-update-target. Fixture artifacts prefix con02-update-;
result CON02_UPDATE_NATIVE=PASS. Python probe calls Task1/3 validators on actual native
SQL snapshots. Logical DB restore inside disposable runner is labelled logical; root file
tests cover physical-tree copy primitive. No claim of complete remote physical-PG restore.

- [x] **Step 1 — RED tests.** Add test_native_direct_upgrade_gate_is_wired asserting workflow/run-connect
  invoke the new runner and upload con02-update-* evidence. Define native seeded rows:
  existing task [1,1], marker/history/ACK/provider receipt, old99 schema, revoked link and preferences.
  Expected target100/repeat/fresh exact, same rows/task flags; paired restore to exact2000/99.
- [x] **Step 2 — Run RED.** Wiring test must fail before adding invocation. Record exact RED;
  absence of a callback/update marker is not silently accepted as whole source PASS. Full-history
  CI supplies frozen sources; missing Git history locally is an environment limit, not native RED.
- [x] **Step 3 — Implement native fixture.** Install actual baseline2000 with accepted overlay,
  snapshot private source/config/data/DB, upgrade directly to2200/repeat and compare fresh100.
  Feed native snapshots through new validators; actual mutation of old column/index/task must deny.
  Preserve old A.1/A.2 runners byte-for-byte and invoke new runner separately. Verify actual native
  history/fence/revoke and matched cron transformation with synthetic host cron paths; restore
  exact2000/source/config/data/DB and compare99. Only sanitized evidence, never dumps/config/secrets.
- [x] **Step 4 — GREEN.** Required connect-db reports CON02_UPDATE_NATIVE=PASS plus all earlier
  native/wire/race markers; exact versions/99→100→100→99/history/task assertions verified from
  artifact JSON. Distinguish actual Moodle/PG, logical pair and modeled host controls in receipt.
- [x] **Step 5 — Commit** `test: verify direct CON-02 upgrade and native paired recovery`.

### Task 8: Actual credentials, private TLS, legacy rejection and HTTPS ownership

**Files:** Create tests/stage/con02_update_systemd.py,con02_update_https.py;
modify .github/workflows/ustar-review-gate.yml and runtime/package test wiring assertions.

**Interfaces:** CI requires GITHUB_ACTIONS=true,USTAR_CONNECT_CON02_FIXTURE=1,root/systemd
and no pre-existing production /opt/ustar/data. Build packages before sudo. Real service UID33,
LoadCredential and local synthetic provider/store; actual Moodle dispatch remains Task7/A.2 native.
Results CON02_UPDATE_RUNTIME=PASS and CON02_UPDATE_HTTPS_OWNER=PASS, artifact con02-update-runtime.

- [x] **Step 1 — RED tests.** Add test_runtime_and_https_gates_required: workflow must call both
  fixtures and upload results. Fixture assertions cover app-ready/v1→ACK→disable→re-enable,
  stable cursor, failed service restart, wrong CA/peer/HMAC and credentials inaccessible outside
  their service. Add frozen CLI rejection cases requiring expected ownership/policy drift, not
  missing file/helper/refusal unrelated to handoff; assert no stop/write/DB effect.
- [x] **Step 2 — Run RED.** Wiring assertions fail before new steps are added; run actual probes in
  disposable CI once implemented. A mocked systemctl or missing systemd does not count as PASS.
- [x] **Step 3 — Implement actual fixtures.** Reuse current callback/Apache fixture techniques without
  weakening production checks. Exercise new controller handoff on actual systemd/UID33 and private
  TLS, same journal/schema1/cursor; synthetic Telegram only. Execute exact frozen a86 pilot/link
  CLI against guarded disposable inputs and require expected early drift refusal before effects.
  Run actual Apache/private bridge health, unchanged owned-file hashes during operations, then
  authorized renewal between app-ready and ACK enable; accept its new verified owner snapshot.
  Concurrent renewal is serialized, reload/renewal failure recovers through existing HTTPS owner.
  New controller never writes TLS-owned state/vhost/tool. Keep fixture roots/logs private and cleanup exact.
- [x] **Step 4 — GREEN.** Both required markers pass with downloaded JSON/hash review; separate actual
  systemd/TLS/Apache, synthetic provider/store and actual native PHP dispatch. Prior callback
  runtime/HTTPS tests remain GREEN. No production CA/token, provider message or group acceptance.
- [x] **Step 5 — Commit** `test: prove CON-02 runtime ownership and HTTPS-compatible recovery`.

### Task 9: Regression, exact artifact, owner commands and continuation

**Files:** Update this plan/design; create context/runtime/connect_con02_deployment_20261023.md
and connect_con02_deployment_ci_20261023.json; update AGENTS/START_HERE/context index/project/
ACTIVE/STATE/BACKLOG/SESSION_LOG and PR106. Add final plain artifact build/upload to workflow.

**Interfaces:** Receipt distinguishes fixed app/worker534 from final tool SHA; exact artifact SHA,
all completed jobs/skips/fixture types, known guards and recovery commands. Runbook contains
the actual ordinary.py install/root SHA check, readonly --check, controlled --upgrade/outage,
status and separate enable/disable/recover/late restore. No commands with guessed hashes.

- [x] **Step 1 — Self-review evidence gaps.** Map spec§1–9 and Review Focus1–5 to Tasks1–8 assertions;
  missing direct native lifecycle/legacy refusal/HTTPS owner/phased backup/actual credentials
  blocks delivery. An actual uncovered behavior gets focused RED/GREEN before final publication.
- [x] **Step 2 — Verify regression.** Run focused new suite then existing affected infra/runtime tests,
  required real-UID/native/systemd/Apache jobs, both PHP source/browser gates and whole r16 once
  at final source. Repeat only after changes/failures. Regenerate development manifest from staged
  tree, context index, check_context and diff --check. Record unavailable local environments honestly.
- [x] **Step 3 — Author review final diff.** Confirm source534 product unchanged, no global guard
  weakening/legacy journal edit/secret log/unapproved recipient/nonce bypass, one consumer,
  deterministic generation and no public-write DB/cursor rollback.0subagents/author-only review;
  no independent reviewer claim. Fix findings with affected checks.
- [x] **Step 4 — Publish and inspect exact CI/artifact.** Expected-head lease, local/published tree match,
  exact plain.py rebuild/hash and all557 addon/worker hashes. Download required sanitized evidence;
  completed required jobs only PASS, skipped FullRC explicitly skipped. Stop if artifact/tool/app
  source differ or installer paths remain unverified; old successful A.2 is not installer acceptance.
- [x] **Step 5 — Commit/publish final handoff.** Mark actual steps complete; publish reviewed operator
  commands/recovery and exact receipts. Production remains2000+a86 until owner executes the
  reviewed upgrade; ACK activation is separate. Do not repeat accepted pilot/revoke or request
  live group tests. Then record actual owner output separately; B/C remain subsequent product slices.

## Plan self-review and execution handoff

Coverage: spec§1–3→Tasks1/3/7; §4→Tasks1/2/5/8; §5→Tasks2/3/7;
§6→Tasks4/5/8; §7→Tasks2/3/5/6; §8→Tasks6–9; §9→Task9.
All five Review Focus conditions name their owning tests. Interfaces match across tasks;
Context adapter excludes old config_guard/task-reset/OLD_CORE restore checks and isolates
legacy primitive markers from the root Journal. Journal scopes/phases and source
IDs stay distinct, package bounds/default/flag order explicit. Actual native logical restore,
physical file-tree primitive and synthetic controls are labelled separately. No new product
feature/dependency, full-snapshot collection or user/group action is hidden in implementation.

Owner approved this plan and implementation22Oct. Completed23Oct (MSK): all9tasks/45steps
as source and handoff, self implementation/author review,0subagents. Toolb971bc38 required
CI SUCCESS: Context39844480447/Review39844480386; source8.2/8.3 each1040/153skips,
strictroot11/11, actualruntime/HTTPS and native154/800+direct2000→2200/repeat/restore PASS.
Exact CI/local ordinary.py, all557payload/worker/legacy assets and downloaded native metadata
verified. Commands/limits/rulings in [final handoff](../runtime/connect_con02_deployment_20261023.md)
and [CI receipt](../runtime/connect_con02_deployment_ci_20261023.json). Task1/2 foundations
share one checkpoint because their interfaces depend on Journal; this ruling and cost recorded.
Owner execution/production acceptance is subsequent, not claimed complete. Production2000+
policy overlay/a86 unchanged, pilot not repeated/group deferred. No new approval loop.
