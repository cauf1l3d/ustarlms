# Реестр кода, проверок и deployment evidence

Сводка Git/CI от 02.10.2026; дополнено owner runtime evidence 05.10.2026. Application source в main консолидирован на PR76 `e1d57f5bc28f6964afd20aeff7620345b34a80fe`. История main сохранена: прежний main `99248772a91526c87c1a31f425d1a07906727152` является предком этого SHA. Полный CI #456 проверен через GitHub API: **source, frontend, rollback, prepare-rc, moodle-db, gate — success**. [Run](https://github.com/cauf1l3d/ustarlms/actions/runs/37023122306). Счётчики из описания PR76: 210 Moodle tests / 891 assertions, 60 Chromium scenarios; это CI на synthetic данных.

## Последние версии

| Поставка | Exact SHA | local / theme | Уровень подтверждения |
|---|---|---|---|
| PR16 / G00 | `0e1eba2ca08b11f929730ab09df7bc91732f0e62` | 2026092601 / 2026092601 | Пользовательский manifest 27.09: 424 совпадения, нулевой drift; [запись](20260927_prod_g00.md) |
| PR48 | `7cc5587d300c799fc43e3f95c2ebc01cf3e50988` | 2026092709 / 2026092702 | В доступной истории владельца DEPLOY_OK и LOGIN_HTTP=200, 28.09 |
| PR50 | `ccae2ca209bf93ca0d8ca7273cb4d366f8c6b2a8` | 2026092710 / 2026092703 | В доступной истории владельца DEPLOY_OK, LOGIN=200, FEED=303; redirect не проверяет авторизованный UI |
| PR64 | `06b0b75496b9e3fe613b1001fa7ae599cf7ced80` | 2026093001 / 2026092703 | [Isolated gate #390](20260930_task_workspace_rc.md); отдельное точное подтверждение установки здесь отсутствует |
| PR67 RC2 | `0b369f626a0bdfc03446a305ba4ce902444f4ef1` | 2026093002 / 2026092703 | Пользовательский серверный отчёт в истории 30.09: успешный deploy; последующие изменения ниже |
| PR70 | `301cc7229246e0f212733221acc4659b05418cc8` | 2026093004 / 2026093002 | Пользовательский отчёт 30.09: DEPLOY_SUCCESS=YES, 493 совпадения, backup/CSS/maintenance checks |
| PR71, установленная ранняя ревизия | `4cbb8ac37ef99051b9432e1e5c7c462f1472ad36` | 2026093006 / 2026093004 | Исторический exact source manifest: 505 совпадений, no drift, DEPLOY_SUCCESS=YES. [Сохранённое свидетельство](../../tests/stage/production.json). После deploy выявлен signed-in layout defect; эта версия не является рекомендацией отката |
| PR71, последующий hotfix | `c77ba822423855ea822be461efc91793e3189afe` | 2026100101 / 2026100101 | Код исправления; отдельный manifest установки в доступных материалах не найден |
| PR72 mobile/messages | `971be902c4554539650aafc39e81fbc61354de7b` | 2026100102 / 2026100102 | Принятие владельцем записано в ADR0013; это документированное сообщение, не свежий полный manifest |
| PR73 season/team | `fb4036f53928749f42cbaffbfc2f925ada8a7551` | 2026100201 / 2026100102 | Source/CI release; отдельное exact deployment evidence отсутствует |
| PR74 avatars/login | `dd6e06efc0d4b2a9cab68cce4745393dfc572b21` | 2026100202 / 2026100201 | Full CI #441 по PR; exact deployment manifest отсутствует |
| PR75 app icon | `79be8529f949cc86164d287c5089f7d4271a9ca5` | 2026100203 / 2026100202 | Full CI #443 по PR; после иконки пользователь сообщил «хорошо вроде работает». Это подтверждение поведения, не точный manifest |
| PR76 adaptation reset | `e1d57f5bc28f6964afd20aeff7620345b34a80fe` | 2026100204 / 2026100202 | Full CI #456 проверен; owner manifest 03.10: 526 plugin/theme matching, versions 2026100204 / 2026100202; core и reset/reassign acceptance отдельно не подтверждены |

Промежуточные ветки и PR сохранены в [инвентаре](../archive/handoff_20261002/pr_inventory.json). Номер PR не идентифицирует immutable code: PR71 имел несколько существенно разных SHA.

## Историческое наблюдение владельца 02.10

02.10.2026 в запросе на консолидацию репозитория: академия работает более-менее стабильно, в ходе работы багов пока не выявлено. Это актуальная оценка эксплуатации. Она не устанавливает точный installed SHA, DB schema, состояние cron, нагрузочные показатели или результат восстановления.

История чата использована как свидетельство владельца; raw журналы этой сессией с сервера не получены. Git/CI проверены непосредственно. При handoff 02.10 current_source_commit/current_versions были null; новые датированные свидетельства 03–04.10 отражены ниже и в STATE. `tests/stage/production.json` и `runtime.json` остаются историческим fixture/preflight input и не переписаны ради более нового номера версии.

## Дополнение 05.10 — что подтверждено после консолидации

Owner-supplied evidence из [аудита](../audits/USTAR_INFRASTRUCTURE_AUDIT_20261005_RU.md) и рабочего диалога; прямого подключения этой сессии к production нет. [Машинный паспорт](evidence_20261005.yaml) и [сверка](../audits/AUDIT_RECONCILIATION_20261005_RU.md).

| Контроль | Свидетельство / граница |
|---|---|
| Application source | 03.10 02:56:02 UTC: PR76, 526 matching plugin/theme, changed/missing/extra/errors/skipped пусты; не полный core |
| Production versions | Moodle 5.1.1+ Build 20251219, PHP 8.3.33, PostgreSQL 16.15, local/theme 2026100204 / 2026100202, maintenance 0 |
| Follow-up manifest | Не состоялся из-за отсутствия origin/main; не drift и не новая successful сверка |
| Cron | v3, every minute, one worker; 04.10 23:57: exit 0 / 1.374 с / new failures и USTAR errors 0; H5P/registration disabled |
| Manual recovery | Snapshot 04.10, checksum/external key/isolated restore подтверждены в своей области; full shared-host restore не проверен |
| Открытые риски | DB superuser, writable code/config, HTTP, no unified monitoring, old core patch, root 76%, failed HDD; регулярных копий нет |

GitHub-поставка DOC-02 не меняла application code и production. Исходный аудит сохранён без изменения байтов, runtime/harness/roadmap pointers и полный план опубликованы PR78. [Проверка поставки](context_update_20261005.md). Исходное поручение требовало остановиться после публикации; следующая команда владельца 1 → 7 → 6 приведена ниже.

## Продолжение 05.10 — выбранные пункты 1 → 7 → 6

Владелец разрешил baseline → Moodle/CI/ОС → HTTPS; остальные пункты после них. INF-01 в работе. [Новый evidence](evidence_20261005_baseline.yaml), [baseline и tool scope](baseline_20261005.md), [component inventory](plugin_inventory_20261005.json).

| Контроль UTC | Результат / граница |
|---|---|
| Manifest 02:32:35.997389 | 526 files PR76, changed/missing/extra/errors/skipped/uncompared 0; independently checked vs actual Git blobs; non-atomic plugin/theme only |
| Inventory 02:43:33 | 468 disk/readonly DB component versions identical; USTAR 2 roots and 17 external roots / 55 components; no core Git HEAD |
| Core metadata | 3 SHA-256 match official weekly commit `1cd17816c56a7df7ee796892efccaf1ed5347340`; runtime core comparison still pending |
| Runtime images | PHP CLI 8.3.33 Debian13/Trixie, PG16.15; exact IDs/RepoDigests in new evidence; moving PHP FROM requires target pin decision |
| Cron 02:13 | v3 exit 0 / 1.070 s / schedule and container match; exceptions retained; installed wrapper hash confirmed |
| AppArmor/packages | snapd rc + stale profile include; Docker still uses docker-default; no repair/update/reboot performed |

Read-only preflight helper and context are repository work; application source, DB/data, historical fixtures and host wrappers unchanged. New full CI on the chosen runtime and isolated/production upgrade are not yet run. Official 5.1.7 candidate is identified, not deployed. Old manual recovery copy precedes latest cron writes; fresh copy still required before mutation. Exact implementation/CI of this repository delivery are recorded in its PR/Git history.

## Core comparison 05.10 08:28 UTC

Владелец выполнил immutable helper PR79 и передал архив private preflight directory. 24 678 matching / 1 changed (`public/index.php`) / 0 missing / 23 extra / errors и skipped 0. Coverage и expected changed OID проверены по exact official reference tree; PHP/application source из изменённого файла в архиве отсутствует. [Сводка и следующий шаг](core_review_20261005.md). Это complete comparison указанной области с обнаруженным drift, не full equivalence всего deployment. Точный SHA server helper совпал; самостоятельно server files не читались. Production writes/repair/cleanup не выполнялись, INF-01 остаётся in_progress.

## Перед следующим релизом

Получить свежие versions/scoped manifest и отдельно core/plugins/images, Apache/HTTPS/cron identity. При неизменном PR76 повторная установка не требуется. При другом baseline или drift — разобраться, не обходить preflight. Старые installers ограничены своими исходными версиями; docs main не разрешает слепо запускать historical deploy.

OPS-01 сохраняет проверку HRD reset/manager reassign как отдельную business acceptance. tests/stage/production.json и runtime.json остаются историческими fixtures; не переписаны для совпадения с текущей сводкой.

## Source review 05.10 — index и existing wrappers

Отдельный index upload: 93 bytes, SHA/OID verified; config bootstrap и redirect на `/local/ustar/home.php`. Matching/drift counts core snapshot 08:28 не меняются. Source uploads backup/cron получены 09:48:49 UTC: полное статическое чтение, hashes совпали; [контракт](recovery_contract_20261005.md). Default preflight/backup outage/resume-only recover, local export, lock coordination и real-pass installer различены. Локальные self-test/offline gates PASS; свежая production копия, archive restore и candidate stage ещё не выполнены. Application source и исторические fixtures сохранены; INF-01 остаётся in_progress.

## Свежий backup/cron и retained lab 05.10

Владелец выполнил fresh encrypted snapshot 05Oct; local wrapper/independent hash совпали, Moodle running YES, stop-to-running24.097s. Cron scheduled 10:24 UTC exit0/1.25s/new failures0; Apache root303. Старый restore/manual PASS подтверждён, authenticated admin feed screenshot учтён в его области; новая external/decryption verification отдельно открыта. Docker -a 10:59:56 UTC подтвердил удалённые lab containers; retained DB/code/data/state/report есть. [Конкретные evidence и resume helper](lab_resume_20261005.md). Новый helper source/offline failures проверены; на сервере ещё не запускался. Application/core/OS/HTTPS без изменений, INF-01 in_progress.

## Retained lab resume / running HDD test / patch CI 05.10

PR82 implementation `62ef096ee2f0b96c84b69ad20ff9a0225d1d7214`, main merge `7a6eefde8a3662373a62f5c455b499cfe6a51c80`; context/source/frontend PASS, DB/rollback skipped for that helper delivery. Owner subsequently executed resume11:30:58UTC: lab PASS/login200/production unchanged PASS. Existing restoration acceptance stands; no core upgrade performed.

Owner HDD evidence11:56:46UTC: long SMART read failure, pending/offlineUNC1/1, exact WD5000AAKS serial/capacity confirmed, only88bytes Windows service files. Owner/sysadmin explicitly authorized full rewrite/format.12:28:57UTC guarded systemd badblocks write/read one-zero-pattern pass started; no completion/health PASS yet. No new partition/filesystem/storage destination configured.

Separate pinned Moodle5.1.8/PHP8.3.33/Trixie/PG16.15 CI runtime and source PHP8.3 added without changing historical fixtures/application source. Scope is synthetic USTAR fresh/plugin-upgrade/repeat/DB compatibility, not full production core/addon upgrade/rollback. Exact implementation/full RC in PR/Actions; [details and next inputs](patch_runtime_20261005.md). Production core/OS/HTTPS unchanged.

## Root/lab budget 05.10 13:11 UTC и patch planning preflight

PR83 merged main156110de2a5cc305a57fd9d94fc74753e7f0e5f0; full RC37311910916 SUCCESS, context37311903412 SUCCESS. Actual patch artifact verified: Moodle5.1.8/PHP8.3.33/PG16.15, PHPUnit210/891/failures0/errors0/skips0, schema parity/authenticated shell PASS. Previous rollback artifact exact SHA PASS.

Owner HDD active/running, success/0 not completion; root49G/36used/11free/78percent, retainedlab2.3G/export~1.9G rounded. New [read-only planning helper](lab_patch_preflight_20261005.md) measures component budgets, validates USTAR/addon metadata/config/isolation and bounded clone SQL without new restore, upgrade, backup or production mutation. On server not executed. Application PR76/original audit/historical fixtures retained.
## Owner checkpoint 05.10, сообщение получено 17:11:36 UTC

HDD unit inactive/dead, success/exit0; write0x00/read-compare done, zero bad blocks и0/0/0errors. Это завершение surface test из owner log, не post-write SMART/health qualification. Новые SMART attributes и повторный long self-test pending. `du` подтвердил lab2,3G/export1,9G с полным path; новый `df` не предоставлен.

Pinned PR84 helper/profile hashes OK, root install и `--check` реально выполнены владельцем; результат **refused Untrusted retained input**, область/права не сообщены, successful planning budget отсутствует. Diagnostic revision добавляет только redacted fixed scopes/UID/GID/mode/type/links/failed guards; guard predicates, profile/dependency pins, SQL, isolation и operations не изменены. Server rerun/исправление точной причины pending; Moodle upgrade/paired rollback ещё не выполнялись. Exact implementation/CI diagnostic supply — в PR/Git. Local71 tests PASS, source JS24/templates56/XMLDB93 PASS с явным skip-PHP; local real Docker/PHP/SQL не запускались.

## Owner checkpoint 05.10, сообщение получено 17:40:58 UTC

Owner checksum/root install/PR85 diagnostic execution подтверждены: обычный data file33:33/0666/type_regular/links1 отказал по permissions; private leaf name/origin неизвестны. Отдельная metadata-only data policy для exact retained Moodledata root0700/33:33 подготовлена, per-entry ownership/links/type/non-executable files и code/control/PG guards сохраняются; no chmod/chown/data-content reads. Policy script SHA `075c454aa58be4209466864dae734ea140c024d7ed5b82863ebfac7e08cc3d8b`; server successful report pending. Local25 helper/77 r16 tests PASS, source JS24/XMLDB93/templates56 PASS с явным skip-PHP; real local Docker/PHP/SQL не исполнялись. Exact implementation/CI — в PR/Git; application PR76/original audit/historical fixtures сохранены.

Fresh SMART snapshot17:36:49UTC exactWWN/serial: reallocated0/pending0/offlineUNC1/CRC0/temp47/power16383h; прежний long read-failure16359h остаётся. Новый long start successful/154min/expected20:11:04UTC=23:11:04MSK, actual result pending; HDD trusted=false. Root free11G по-прежнему датирован13:11UTC, candidate core upgrade/rollback/production patch/OS/HTTPS не выполнялись.

## Owner20:44UTC planning/SMART и подготовка cold checkpoint/workspace

База maina826a73c052a72c94ad5c6367b9cb9bcc1e115f0 (PR86), branchcodex/ustar-lab-cold-rollback-20261005. Owner output получен20:46:23UTC: PR86 checksum/install/check20:44:34UTC PASS,526 USTAR/57 addon metadata, free10,51GiB/first budget8,15GiB. [Sanitized report](lab_planning_result_20261005.json) сохранён. SMART20:44:52UTC newest extended Completed without error at16385h, pending/reallocated0/residual198=1; old read failure retained/outdated. Surface+long test-scope PASS, no target partition/mount/schedule configured.

[Новый helper](lab_cold_checkpoint_20261005.md) делает paired cold code/config/Moodledata/physicalPG checkpoint при остановке только lab и always same source IDs restart, затем separate mutable workspace/new IDs/loopback18085. Frozen checkpoint never mounted; hashes/metadata/full inventory/fsync checked, source untouched, productioninspect before/after only. Second-copy budget9,80GiB/reserve4GiB, no overwrite/deletion of files, failure preserves copies and removes only owned stage containers. Core upgrade/repeat/paired restore after upgrade not executed.

18 filesystem/failure tests/full95 r16 PASS; real pinned uploaded engine adapter checked with Docker/ownership/relay calls mocked. Local source JS24/XMLDB93/templates56 PASS with explicit skip-PHP, real local Docker/PHP/SQL not run. Exact implementation/source-context CI recorded in this delivery PR/Git. STATE/ACTIVE/BACKLOG/evidence/entrypoints/index updated; application PR76/original audit/frontend/release/stage/workflows unchanged. Next operator: immutable SHA download/check/root600 install and --prepare-stage, full sanitized result, then candidate5.1.8 with preserved addons/extras and upgrade/repeat/paired rollback. Owner order1→7→6 remains active.

## 2026-10-06 — владелец перенёс storage/map/автобэкапы перед стендом

Owner00:45:07MSK/05Oct21:45:07UTC явно включил оптимизацию хранения, актуальную карту сервера и HDD automatic backups в проход до нового стенда. PR87 implementation40e7d13e197cd59321e5e89e3f477539eb265b19 merged37f9f676ee434498f011d26be0db5fae7a83195c; source8.2/8.3/frontend37375193949 и context95tests37375193850 SUCCESS, server --prepare-stage теперь paused, deployment evidence отсутствует.

Свежие df/lsblk/du: root49G/36Gused/11Gavailable/78%, SSD111.8GiB/root partition50GiB; около61.8GiB вне показанных размеров partitions, free extent ещё не подтверждён. Home15GiB включает около13.1GiB snapshot candidates; backup-export1.9G, restore-lab2.3G, cron-lab2.2G, opt/backups1.8G. Parent/child размеры не суммируются повторно. HDD465.8GiB без показанных partitions/FSTYPE/mount. [Карта и следующий read-only шаг](server_map_20261006.md).

Existing manual backup source повторно проверен: SSD staging/export,6GiB quota, no rotation/timer/mount identity guard. Новый backup contract предусматривает HDD payloads/encrypted archives, persistent mount guard, recovery marker на SSD, cron coordination, trial/verification/status/retention до schedule. Schedule03:30MSK/7daily4weekly3monthly пока proposal, не установлен. Никаких partition writes, moves, deletions или schedule changes пока не выполнено. Текущие STATE/ACTIVE/BACKLOG/project/entrypoints обновлены; старое backup-last правило superseded, прочие hardening/product задачи не расширены.

## 2026-10-06 — server inventory and HDD initializer prepared

Fresh owner inventory: SSD GPT `sda1` BIOS boot 2048–4095, `sda2` root 4096–104855551,
stale GPT backup boundary and confirmed 61.79GiB free tail; production and retained
lab containers running; UTC/NTP synchronized; 18 system timers and no managed USTAR
backup timer. `hdd_initialize.py` is exact-device guarded and tested offline. No
partition, filesystem, mount, fstab, SSD, container or schedule mutation performed.

## 2026-10-06 — HDD initialized and temporarily mounted by owner

База main `89fc5d49f1ac89c29e99d0095f8482f17f22f60d` (PR89), ветка
`codex/ustar-hdd-mounted-context-20261006`. Owner confirmed initializer SHA
`ef3a5699f54384428d40e1c9b96b2707e117ad188b1f36628c95fb823be49aa2`,
`EMPTY_HDD_CHECK_PASS` и `FILESYSTEM_READY`. GPT/sdb1/ext4 labelustar-hdd,
UUID `359a2bae-4e79-461a-ab72-1597f605d801` созданы. Mount output получен
06Oct03:07:20MSK/00:07:20UTC; execution timestamp не напечатан. Отдельная
owner команда смонтировала UUID на `/srv/ustar-storage` с
`rw,nosuid,nodev,noexec,relatime`, root:root0700; df458G/28Kused/453Gavailable/1%.
Повторный initialize не требуется. Residual SMART198=1 сохраняется;
полная hardware/recovery qualification не заявляется.

Подготовлены one-line operator commands: guarded candidate fstab/private backup
и copy/flush/readback SHA существующего encrypted05Oct archive. Bash syntax и12
offline failure/success cases PASS с mocked mount/device/systemctl/sync и реальным
fixture copy/hash. Это подготовка; fstab, archive copy, fresh HDD backup trial,
producer/timer и SSD repair/growth ещё без operator execution evidence.
Исходники/архивы/контейнеры не удалены и не перемещены агентом; прямого SSH нет.
STATE/ACTIVE/BACKLOG/server map/backup entrypoint и generated index актуализированы.
Application source, workflows/helpers/harness code и байты оригинала аудита
не меняются; проверка контекста и точный implementation SHA/CI — в delivery PR/Git.

## 2026-10-06 — HDD fstab/copy PASS и новый backup producer

База main `4d1378ab01a6afd9526917681f5afcd54d3127df` (PR90), ветка
`codex/ustar-hdd-backup-producer-20261006`. Owner output received03:33:41MSK/
00:33:41UTC подтверждает FSTAB_CONFIGURED=PASS, backup `/etc/fstab.before-ustar-hdd-c2kb8T`,
HDD_ARCHIVE_COPY=PASS и matching SHA прежнего05Oct archive; df458G/913Mused/452Gfree/1%.
Source archive остаётся на SSD; reboot/mount-after-boot и fresh capture не выполнены.

Actual private backup source13,689bytes повторно получен/полностью прочитан, dependency
SHA0d31e694… совпал. Новый `scripts/infra/hdd_backup.py` — SHA
`75ca974f1d763f6fc84281501a99936894f1b487c1cc4d0c1d71697e67c79d75` — использует
его reviewed capture pipeline без публикации/изменения установленного engine.
Anchored HDD staging/export100GiB quota, private paths, exact disk/mount/live guards,
existing EX lock, SSD marker и отдельные HDD status/manifests; legacy last-local-export
не переписывается. Stop reply/durable completion под blocked catchable signals;
resume работает без HDD, uncertain inflight marker не снимается ради обхода.
Final readback/fsync + local Apache login200; failed staging/partial сохраняются.

29 local tests PASS:19 boundary/kernel/filesystem и10 actual pinned-engine fixture cases
с real tar/files/hash, mocked Docker/age/mount/fixture ownership; настоящий SIGINT
во время fixture stop отложен и resume выполнен. Это не real Docker/PG/encryption/HDD
power-loss acceptance. В обычном CI10 private-source cases явно skipped; remote
context/source checks и exact implementation SHA фиксируются delivery PR/Git.
Application source, original audit bytes, historical runtime/fixtures и cron v3
не меняются. STATE/ACTIVE/BACKLOG/server map/entrypoints/index обновлены.
Server producer install/check/fresh trial, timer/retention, source deletion и SSD
repair/growth pending. Next operator: pinned install → --check → chosen-window
--backup --acknowledge-outage/report, then policy/timer and SSD recovery steps.

## 2026-10-06 — Owner HDD trial PASS и начальный ежедневный timer prepared

База main `d884e402948af91b5c9f2645e07fdaf1b1838e03` (PR91), ветка
`codex/ustar-hdd-backup-schedule-20261006`. Owner upload received04:16:21MSK/
01:16:21UTC подтверждает pinned producer installation/preflight и fresh
HDD_BACKUP=PASS06Oct01:10:22..01:12:02UTC. Archive957829597bytes, SHA256
`d9352e3ce5e237a6ac0ab14c72b9da2648d6906fe7e95cd5c88bea726c965af3`,
pause24.111s,readback/login200,staging removed, archive deletionfalse.
Sanitized report `context/runtime/hdd_backup_trial_20261006.json` добавлен;
это manual trial, не scheduled run/restore/external copy/agent server access.

`scripts/infra/hdd_backup_schedule.py`, SHA256
`54533f6a629699e031a877b4a57f68401477a61cf20cab39512c79a2e0477b7d`,
готовит только три systemd units: daily03:30Moscow/window03:30..03:40,
no catch-up/retry/archive deletion, existing100GiB quota, main45min,
post-stop/boot resume по SSD marker с точным producer/container guard,
journal и отдельный scheduled report. Trial<=48h/readback/preflight и
unit conflict/override/active-worker/verify guards до activation;
operator installation/first scheduled run/boot recovery ещё pending.
Предлагаемый initial policy станет действующим после owner install команды;
retention7daily/4weekly/3monthly и Telegram delivery ещё не приняты/не настроены.

24 local tests PASS, включая настоящий systemd255 calendar и unit parsing;
реальные files/modes/inodes и producer uncertain-marker guard;
systemctl/mount/Docker/fixture ownership подменены. Не заявлены production
negative/power-loss/recovery acceptance. SHA/CI фиксируются в delivery PR/Git.
STATE/ACTIVE/BACKLOG/server map/backup entrypoints/index обновлены; исходный
аудит, application source, workflow/harness, private engine и cron v3 прежние.
Source deletion, SSD repair/growth, fresh archive independent copy/restore pending.

CI correction within this delivery: the first context/source164-test run found
one fixture calling root-only producer main under a non-root runner. The test
now mocks effective UID explicitly; production root guard/source stays unchanged.
24 focused tests also PASS with simulated non-root caller. Frontend audit reported
GHSA-68fv-2mgg-jv7q/source-map-js1.2.1; npm regenerated only that lock entry to1.2.2
(version/resolved/integrity), verified against registry/upstream release.
Production frontend is unused; Moodle/plugin/theme/runtime code is unchanged.
Clean npm ci and npm audit --omit=dev --audit-level=moderate PASS (zero runtime
vulnerabilities); frontend4 unit tests PASS. Build/new exact-head CI recorded in PR.
No audit threshold or required check disabled.

## 2026-10-06 — Owner установил HDD timer; следующий шаг SSD/consumer preflight

База main `040c671236cb4d7f4a225700bd288ced7e6f14f3` (PR92), ветка
`codex/ustar-hdd-timer-evidence-20261006`. Owner terminal output received
11:02:24MSK/08:02:24UTC: scheduler source/install PASS, timer loaded/active/enabled,
backup service inactive/static, recovery service inactive/enabled. Next event
07Oct00:30UTC/03:30MSK совпадает в status/list-timers; last scheduled null,
recovery marker false, last successful backup remains manual06Oct01:12:02UTC.
Execution timestamps команд не напечатаны; это owner evidence, не live access.
Sanitized `hdd_backup_schedule_install_20261006.json` сохраняет область/пределы.

Начальный daily03:30Moscow/no catch-up/no deletion/100GiB quota активирован
оператором; повторный install/manual capture не требуется. First scheduled run,
boot recovery/mount, fresh independent copy/restore и alerts ещё pending.
INF-04/INF-14 остаются in_progress, закрытие всех acceptance не приписывается.
PR92 exact implementation7501440b476bf720658f5bf039efc3e4f1237d9c CI context
37400747178 и review37400747176 SUCCESS; source8.2/8.3/frontend PASS,
r16 164tests/10private-engine skips, scheduler24PASS; ordinary PR DB/rollback/
prepare-rc/gate skipped. Production application/cron/private engine прежние.

Обновлены текущие STATE/ACTIVE/BACKLOG/entrypoints/server map/backup contract;
старые SESSION_LOG/RELEASE_LEDGER и audit не переписываются. Следующий документ
`ssd_storage_preflight_20261006.md`: bounded metadata/layout/SMART, all-container
mounts, named copy sizes и control-path filenames. Нет partition writes, package
install, service stop, backup invocation, relocation или удаления данных.
Перед GPT/root growth нужны свежая identity/layout, проверенные partition-table
copies и host/boot recovery/console; Academy archive не заменяет shared-host rescue.

## 2026-10-06 — SSD/consumer read-only вывод получен; rescue доступ уточняется

База main dc1e8cfee35ed479a9a314dd86c9e46c8c8bf9fe (PR93). Owner attachment
received08:19:59UTC, first inventory08:18:26UTC, subsequent command times absent.
Root78%/11G available; HDD post-trial1,8Gused/451Gavailable; root filesystem UUID,
quota mount options и GPT UUID/starts/sizes зафиксированы. PMBR mismatch и stale
backup GPT ещё присутствуют; dump не является записью или binary recovery copy.
SMART overallPASSED/raw1,5,196,198=0; packed vendor raw не интерпретирован как
fail counts/wear. lsblk model/serial columns усечены, zero WWN не identity pin.
Four containers running, retained lab has active binds. Named du snapshots сохранены;
reference grep fallback matches six wrappers but emits missing-link errors, no full
consumer clearance/active shared-service impact claim. Sanitized result JSON добавлен.
STATE/ACTIVE/BACKLOG/server map актуализированы; console/rescue explained in plain
language, unknown; next full JSON identity/ext4 header query read-only/syntax checked.
Никаких source moves/deletions, SSD GPT/root writes, service stops/reboots или backup
relaunch не выполнено. Existing timer first run07Oct03:30MSK всё ещё pending.


## 2026-10-06 — Full SSD identity/ext4 follow-up; table-copy command prepared

База main7fbf17c34e1b7a3abe58d5888e8c4ae684696083 (PR94). Owner terminal
output received08:36:58UTC, command timestamp not printed. lsblk JSON resolves
full model WALRAM120GB/serial2203JPDG120GB6000933,120034123776bytes; WWN zero
not used as unique pin. Partition UUIDs unchanged. Mounted ext4 header reports
13106432×4096 =53683945472bytes exactly equal sda2; resize_inode/64bit/etc recorded,
not offline fsck/SMART/GPT repeat. Header last-write is not query timestamp.
New sanitized evidence preserves this scope without rewriting previous dated JSON.

One-line guarded backup command prepared: verify SSD serial/capacity/root UUID/raw
GPT boundary/partition starts and GUIDs, exact HDD UUID/WWN/CWD filesystem0700,
fresh private directory, raw first2MiB/old-tail1MiB/physical-tail1MiB, native
sgdisk --pretend backup and metadata, source cmp then HDD sync/hash readback.
Raw copies avoid relying only on utility in-memory GPT interpretation. No block
device output, GPT repair/grow/resize, stop services, source cleanup or backup rerun
in this step. Bash/Python syntax, geometry/source-copy checks and context validation
use regular sparse-file fixture (three ranges4MiB/root excluded); native sgdisk
not executed locally. Production execution and console/rescue remain pending. Timer first
scheduled event07Oct00:30UTC unchanged. Current state/queue/server map/index updated;
application/private helpers/scheduler/cron untouched, original audit preserved.
