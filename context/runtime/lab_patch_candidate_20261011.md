# Отдельный каталог Moodle5.1.8 с дополнениями — 11.10.2026

Следующий operator step: установить проверенный `lab_patch_candidate.py` и выполнить
`--prepare-candidate`. Это **сборка кода**, stage ещё работает на прежнем core.
Production upgrade, stage switch/upgrade/repeat и paired restore этой поставкой не выполнены.

## Принятые результаты владельца

- SSD GPT relocation14:40UTC, sda2 growth15:21UTC, online ext4 resize15:35UTC PASS;
  root110G/37G used/69G available/35%, UUID и mount/quota options сохранены.
  [GPT](ssd_gpt_relocate_result_20261011.json), [partition](ssd_partition_grow_result_20261011.json),
  [ext4](ssd_ext4_resize_result_20261011.json), [post-checks](post_resize_checks_20261011.json).
- HDD daily03:30Europe/Moscow:11Oct00:30..00:31:44UTC complete/success/exited0,
  last backup success00:31:43UTC, marker absent, timer active/enabled, next12Oct00:30UTC.
  No catch-up/archive deletion. Independent archive restore и host boot/recovery остаются открыты.
- [Cold checkpoint + independent baseline workspace](lab_stage_prepare_result_20261011.json)
  owner18:55:53..19:17:02UTC PASS:63431 checkpoint entries,
  `77672fd0b5b7bc5a27d1bd71d536f2bf47449455623589b53e0605005584b4c0`,
  526 USTAR hashes,57 addon metadata,4910 content hashes, login200,
  DB core2025100601.03 unchanged, cron off/email disabled/loopback only.
- [Authenticated SCORM screenshot](lab_stage_browser_result_20261011.json) received19:53UTC:
  USTAR navigation and lesson text/images visible at localhost18085, section6/20/progress30%.
  Fresh login, all roles, completion/progress persistence и homepage redirect этим не доказаны.
- [Official tgz download](moodle_518_package_download_result_20261011.json) owner20:09:23UTC,
  SHA strict OK, marker `MOODLE_518_PACKAGE=PASS; NO_CORE_UPGRADE=YES`.
  Directory `SOURCE/moodle-518-package-20261011-nnixp4se`, archive `moodle-5.1.8.tgz`.
  No extract/activation/upgrade. Finish timestamp не печатался.

`SOURCE=/var/lib/ustar-restore-lab/20261004T123406Z-741e3f72`.
Checkpoint `SOURCE/patch-rollback-20261005`; mutable baseline workspace
`SOURCE/patch-stage-20261005`. Эти действия **не повторять**.
Полный датированный continuation и четыре strategy originals:
[PR98](https://github.com/cauf1l3d/ustarlms/pull/98). Это не разрешение расширять текущие задачи.

## Проверенный артефакт

Source: [scripts/infra/lab_patch_candidate.py](../../scripts/infra/lab_patch_candidate.py).
SHA256: `4850e17699ab32705b938358dc1f53ffb095daf4fe875b994298482f90e8f735`.
Branch `codex/lab-patch-candidate-20261011` от fresh main
`c53723c8d669a1013c518f34abf392143d211d9d`. Exact implementation SHA — commit этого
файла (`git log -1 --format=%H -- scripts/infra/lab_patch_candidate.py`); оператору
выдаётся raw URL на конкретный commit, не изменяемый branch head.

Helper default — PLAN_ONLY, без чтения private inputs. `--check` read-only;
`--prepare-candidate` создаёт новый root0700 `SOURCE/patch-candidate-518-20261011-*`.
Existing lock открывается read-only/NOFOLLOW, shared check или exclusive prepare,
nonblocking; отсутствующий lock не создаётся. SHA-проверенные installed cold,
preflight, resume, private engine/checker и profile импортируются; используются
только static config/comment parsing и Docker ownership/network/state inspect.
Production inspect, без exec/SQL. Installed dependencies остаются прежними.

Input guards: private parents/control files, accepted manifest digest, selected
frozen files metadata/hash/no symlink/hardlink/nested filesystem; exact19 addon
roots/57 metadata/526 USTAR profile; current baseline configs, running owned
source/stage identities/images/start times и offline namespace. Root-owned lock
может быть0644: в нём нет secrets, он не должен быть group/world writable.
Relay service не требуется активным; временный HTTP expiry не останавливает сборку.

Archive фиксирован SHA `0e8e2cdeecf24512b75e24158fa224ed08a20c608b4e1065cb2068832467665c`:
HTTPS official [package](https://packaging.moodle.org/stable501/moodle-5.1.8.tgz),
[checksum](https://packaging.moodle.org/stable501/moodle-5.1.8.tgz.sha256),
upstream `cc0bf17da8b5fbd290faf95b2b14a03d64331923`, version2025100608.00.
[Four exact upstream metadata blobs and package inventory](moodle_518_package_verification_20261011.json)
не объявляются полной Git tree comparison. SHA фиксирует все bytes пакета.
Catalog принимает только regular files/directories внутри `moodle/`, canonical
unique paths, bounded sizes/counts, все parents присутствуют. Extract не использует
`extractall`; сначала новые directories, затем files в archive order, без лишнего
gzip backtracking. Fresh output files O_EXCL/NOFOLLOW, fsync и полный hash readback.

## Содержание и границы candidate

Чистый stock core в `candidate/code`, без смешивания с прежним core. Все29409 stock
files остаются исходными; collision addon/extra со stock path вызывает refusal.
Переносятся19 full addon trees,23 extra files из accepted frozen code; каждый
overlay file — новый inode, тот же content hash/UID/GID/mode. Extras могут содержать
private backup configs/PDF/unknown PHP helpers: они не исполняются и не публикуются.
Source `vendor` и прежние core/index/public config shim остаются в checkpoint;
новый code не получает старый root dev vendor. Official tgz не содержит root vendor,
composer.lock содержит0 production/68 dev packages. Runtime зависимости сторонних
addons этим ещё не проверены; перед activation нужна отдельная проверка.

Candidate root config берётся из проверенного generated offline config;
wwwroot/cookie точно совпадают с reviewed stage substitutions. Перед штатным
setup добавляется `$CFG->defaulthomepage = '/local/ustar/home.php';`.
Stock `public/index.php` и stock `public/config.php` сохранены. Поддержка homepage
подтверждена static upstream source; runtime redirect пока не принят.
Root config mode0640/root:www-data; code parents0755 независимо от umask077.

Никаких container start/stop/create, pull, HTTP requests, PHP bootstrap/lint/upgrade,
SQL, cron/email, network/download, live code/data/PG writes, source deletion.
Existing lab container identities/config rechecked в конце; mutable DB/Moodledata
не объявляются byte-for-byte unchanged, пока работающие labs используют их.
Fresh candidate copy budget измеряется перед записью и сохраняет4GiB reserve;
это не бюджет дальнейшего upgrade/DB growth. Private manifest/report0600 fsync.
Contents/config/manifest не загружать публично: достаточно printed sanitized report.

При ошибке/прерывании сохраняется partial directory, accepted manifest/report
при позднем отказе переименовываются в `unaccepted-*`, PASS не печатается.
Ничего автоматически не удалять/повторять/активировать. SIGKILL/power loss не
доказывают завершение: будущий switch должен заново проверить receipt/inventory.

## Выполненные локальные проверки

- `python3 -m unittest discover -s tests/r16 -p test_lab_patch_candidate.py -v`:
  30 archive/filesystem/copy/hash/lock/config/budget/failure/interrupt tests PASS.
  Fixture UID/ancestor portability, Docker inspect/dependency adapter mocked;
  реальные server/private inputs не использовались.
- Full suite с pinned harness requirements `mcp==1.30.0`, `PyYAML==6.0.3`:
  194 tests OK/10 skipped. Default runtime без MCP сначала дал dependency import
  errors; установлен отдельный local test environment, повтор с requirements PASS.
  10 пропусков — integration tests HDD producer: exact private backup engine source
  здесь не предоставлен. Candidate tests не пропущены; реальный engine этим не принят.
- [Настоящий official archive](lab_patch_candidate_local_archive_result_20261011.json):
  SHA/catalog/extract/full readback PASS,39376 entries/29409 files/9967 directories,
  313711622 logical file bytes,9.052s до удаления только собственной temporary copy.
  Не full addon server runtime test.
- `build_code_map.sh e1d57f5bc28f6964afd20aeff7620345b34a80fe`,
  `build_context_index.py`, `check_context.py`, `git diff --check`.
  Application map/source526/tables93/tasks32 и frontend unchanged.
  Новый remote CI status фиксируется отдельно после publication; старый PR83 RC
  не заменяет проверки этого helper или реального full-addon stage.

## Выполнение и следующий результат

В SSH `aduk@server1:~$` после `sudo -v` скачать helper по конкретному implementation
commit, проверить SHA и установить root:root0600 в
`/usr/local/sbin/USTAR_LAB_PATCH_CANDIDATE_20261011.py`. Затем одна physical line:

```bash
sudo -n bash -c 'set -euo pipefail; umask 077; printf "%s  %s\n" 4850e17699ab32705b938358dc1f53ffb095daf4fe875b994298482f90e8f735 /usr/local/sbin/USTAR_LAB_PATCH_CANDIDATE_20261011.py | sha256sum --check --strict -; python3 -I -B -u /usr/local/sbin/USTAR_LAB_PATCH_CANDIDATE_20261011.py --prepare-candidate'
```

Не ставить короткий outer timeout на30k file/fsync copy. Дождаться prompt,
`LAB_PATCH_CANDIDATE_CODE=PASS; NOT_ACTIVATED; NO_CORE_UPGRADE` и report.
Передать stdout/error с `candidate_directory` и `candidate_manifest_sha256`.
После принятия: bounded candidate dependency review, guarded stage-only
switch/upgrade/repeat, согласованный restore **code/config/Moodledata/physicalPG**
после clean stop только owned stage; затем browser acceptance candidate и rollback.
Эта последующая runtime implementation пока не подготовлена и не выполнена.
