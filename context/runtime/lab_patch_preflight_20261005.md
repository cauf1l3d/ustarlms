# INF-01/08: подготовка обновления retained lab и бюджета отката

Источник нового checkpoint — owner terminal output, полученный **05.10 13:11:41 UTC / 16:11:41 MSK**. Прямого server access у агента нет.

## Свежий storage/HDD checkpoint и CI

HDD unit `ustar-hdd-check-20261005.service`: **ActiveState=active / SubState=running**. `Result=success / ExecMainStatus=0` во время работы — ещё не exit/completion. Log по-прежнему `Testing with pattern 0x00:`; write/read surface pass не завершён, новые SMART результаты не получены. HDD остаётся не доверенным backup target; запущенную проверку не прерывать/не дублировать.

Root `/dev/sda2` ext4: **49G total / 36G used / 11G available / 78%**. Retained lab `20261004T123406Z-741e3f72`: **2,3G**; второй аргумент `du` (backup export): **1,9G**, его path label в сообщении обрезан. Это округлённые owner values, не точные байты. Размер lab измерен: гипотеза, что именно он объясняет весь расход root, не подтверждена. Ничего не удалено/не перенесено. HDD нельзя подставлять в storage plan до завершения qualification.

PR83 merged main `156110de2a5cc305a57fd9d94fc74753e7f0e5f0`, implementation `9206f844e12350b9b8ab8ee2d28ed4e63a6dd90a`; full RC [37311910916](https://github.com/cauf1l3d/ustarlms/actions/runs/37311910916) **SUCCESS**, context37311903412 SUCCESS. Обе source PHP8.2/8.3, frontend, historical rollback, prepare-rc, historical DB и patch-518 DB, aggregate gate PASS. Patch artifact ZIP hash verified: `012a41218dce0503083469e4219ffe2aa256d1761f35c100bc52fa0ecbac9471`.

Actual patch preflight: Moodle **5.1.8 Build20261005**, PHP **8.3.33**, PG version_num **160015**; upstream commit совпал. PHPUnit **210 tests / 891 assertions / failures0 / errors0 / skipped0**. Schema upgraded/repeat/fresh byte-equal, authenticated shell/chat/adaptation reset HTTP PASS. Initial curl connection retry в smoke log сменился полноценным final PASS; не объявлять его новым server error. Historical rollback artifact также independently checked с exact candidate SHA. Это synthetic USTAR на candidate core, не server core upgrade/full-addon acceptance.

## Конкретный read-only preflight

[`lab_patch_preflight.py`](../../scripts/infra/lab_patch_preflight.py) + [`pinned reference`](../../scripts/infra/lab_patch_profile_20261005.json) подготовлены для **уже running resumed lab**, без нового archive restore. Default plan only; `--check`:

- SHA-pins установленный resume helper, его reviewed engine/checker и новую reference JSON; не создаёт pyc/private logs/state/report.
- SH/NB lock читает существующий old coordination lock, без O_CREAT; concurrent engine EX operation останавливает check.
- Проверяет retained private paths/config, IDs/images/labels/mounts/RO code, running clone и loopback-only namespace. Production — только Docker inspect before/after; production SQL/exec/stop отсутствуют. Relay/HTTP не изменяет, expiry старого relay не требует повторного baseline restore.
- Измеряет code/Moodledata/physical PG отдельно, logical/allocated/copy allowance, file/inode counts; rejects symlinks/hardlinks/special entries/cross-filesystem trees/writable code. Live measurement не атомарно; disappearing file даёт refusal/retry.
- Сверяет **526 USTAR file hashes** с PR76, **57 addon version metadata** и literal minimum-core values там, где они известны, **3 baseline core metadata SHA**, старый reviewed USTAR index SHA. Version metadata не доказывает full addon/core code equivalence. Nonliteral block_rbreport requires остаётся explicit unknown.
- Только clone SQL: BEGIN READ ONLY, statement15s/lock3s, core version, addon DB versions и disabled scheduled tasks. PHP CLI читает version/SAPI/max_input_vars/soap/exif без Moodle bootstrap; CLI values не заменяют Apache SAPI acceptance. Возвращает только sanitized counts/budget/flags, без config/env/tokens/passwords/личных rows.

План места использует non-sparse copy allowance code+data+PG с 25% запасом и64MiB control allowance; отдельно candidate code, равноценный work allowance, PG growth и **4GiB незатронутого резерва**. Immutable payload/images не дублируются в этой cold-copy оценке. Это консервативный planning guard, не гарантия будущего размера: перед mutations budget пересчитывается, физическая PG rollback copy допустима только после controlled остановки lab PG, сохраняется согласованно с code/config/Moodledata. Сам `--check` ничего не останавливает/не копирует/не обновляет; его PASS не означает созданный или проверенный rollback.

Hashes до operator root install: script `d106ae44502dc6fa591853efacc34780b90301ccca451c75032cc689642daff4`, profile `772541013b8f4d5f5d6f49969557f52f89c4032b8e10a18d5552b88be5cf4972`. Install paths: `/usr/local/sbin/USTAR_LAB_PATCH_PREFLIGHT_20261005.py`, `/usr/local/lib/ustar-recovery/lab-patch-profile-20261005.json`, root0600; existing directory/old scripts/configs не заменяются.

## Проверки и следующий шаг

15 новых offline safety tests: sanitized/no-file-write success, network/mount/config/image/code/addon drift, links/permissions, storage/inode shortage, SQL/core/task/version drift, PHP/production drift, logical sparse-copy budget, path/query injection guard, profile tamper guard и existing shared/exclusive lock. Fixture UIDs/engine interfaces mocked; actual Docker/PHP/SQL здесь не исполнялись. Полная local suite: **67 tests PASS**, включая15 новых. Exact repository CI фиксируется в поставке; application source/original audit/historical stage fixtures сохранены.

На сервере этот preflight **ещё не запускался**. Следующий operator шаг — immutable download обоих артефактов, SHA до root install, затем `sudo -n python3 -I -B /usr/local/sbin/USTAR_LAB_PATCH_PREFLIGHT_20261005.py --check`. Команды одной физической строкой; sudo authentication отдельно. После report подготовить полный lab candidate с сохранёнными addon roots/extra consumers, USTAR home/login и paired cold rollback, проверить upgrade/repeat/restore. Production patch, Ubuntu и HTTPS — затем в выбранном порядке1→7→6. Оngoing HDD test не требует блокировать независимую metadata/CI подготовку.
