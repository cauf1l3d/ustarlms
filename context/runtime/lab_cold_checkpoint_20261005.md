# INF-01/08: согласованный checkpoint и отдельный patch workspace

Owner output получен **05.10 20:46:23 UTC / 23:46:23 MSK**. PR86 planning helper реально прошёл в **20:44:34.950875 UTC**: 526 USTAR hashes, 57 addon metadata, private Moodledata boundary и loopback isolation PASS; production containers unchanged. [Полный sanitized report](lab_planning_result_20261005.json). Это read-only planning, без Moodle bootstrap, созданного rollback или core upgrade.

Свободно **11280769024 bytes / 10,51 GiB**, inodes2784282. Первый planning budget8752834560 bytes /8,15GiB учитывал одну rollback copy. Новый helper добавляет **отдельную mutable workspace copy**: предварительно10526347264 bytes /9,80GiB, включая candidate/work allowance, DB growth и4GiB reserve. Перед операцией размеры/free/inodes пересчитываются; reserve контролируется во время копирования. Не считать этот live estimate гарантией будущей ёмкости. Payload/images/archive/key не дублируются.

| Tree | Logical bytes | Copy budget bytes | Files / directories |
|---|---:|---:|---:|
| Code | 381790447 | 503394304 | 33380 / 10842 |
| Moodledata | 603267766 | 647258112 | 7670 / 6014 |
| Physical PostgreSQL | 214286218 | 214470656 | 5491 / 28 |

Один regular Moodledata file с group/world write bits учтён metadata-only preflight; исходные mode/owner не исправлялись. Новый helper **читает содержимое для копирования**, используя тот же private0700/33:33 boundary и entry guards. Это отдельная операция, а не расширение read-only planning.

## Helper и границы

[`lab_cold_checkpoint.py`](../../scripts/infra/lab_cold_checkpoint.py), SHA-256 **`9c81abb6b30bea611f99fb7a3e336e12d0a7827d4ea462a4d3d63e6f8aec9012`**. Exact implementation SHA/CI — в Git history и PR этой поставки. Branch `codex/ustar-lab-cold-rollback-20261005`, base main `a826a73c052a72c94ad5c6367b9cb9bcc1e115f0`. Deployment **pending operator execution**.

Default plan only. `--check` — fresh read-only guards и budget. `--prepare-stage`:

1. SHA-validates installed PR86 preflight `075c454aa58be4209466864dae734ea140c024d7ed5b82863ebfac7e08cc3d8b`, reviewed resume `59dec234262dd98fac0aa09a81cc3af9649ab8518580f4d977f5659a18042282`, engine `71f6a1c45c25bfe97fe0be7d0a362bb5a72e742b77c167f3783f526c85950dac`, checker и unchanged profile. Existing lock EX/NB без создания; source config/hashes/readonly SQL/images/mounts/network проверяются до остановки. Stage names/unit/port должны быть свободны, MemAvailable≥6GiB.
2. Останавливает **только owned source lab web, затем PG**, проверяя exact IDs/labels/images/mounts. Exit0/no OOM/no error и отсутствие postmaster.pid обязательны. Production — inspect before/after, без exec/SQL/stop.
3. Создаёт `patch-rollback-20261005` внутри retained private root. Согласованно копирует full code/config/Moodledata/physical PG и private controls; read-only source, новые файлы/inodes, без links/reflinks. Проверяет type/owner/mode/size/stream SHA-256/source identity before/after, filesystem boundaries, полный inventory и hashes; fsync files/directories. Private manifest получает complete только после проверки. Leaf names/секреты/content не печатаются и не публикуются.
4. В `finally` запускает **те же source PG/web container IDs**, проверяет DB readiness, application versions/disabled tasks и offline network. При ошибке копирования source restart всё равно выполняется. Incomplete files сохраняются без удаления/overwrite; принятый manifest отсутствует. Если restart не завершён, ошибка указывает `--resume-source`, который запускает только проверенные существующие source containers.
5. Делает **вторую полную копию** в `patch-stage-20261005`, сверяет её с frozen manifest; original checkpoint не монтируется в running containers. Меняет только workspace wwwroot18084→18085/sessioncookie, сохраняя остальные bytes reviewed config.
6. Запускает отдельные `ustar_patch_stage_20261005_pg/web` из уже существующих immutable image IDs (`--pull never`), с собственным token/label/state. PG network none, web в его namespace, code RO/Moodledata RW, resource/log limits прежнего reviewed engine. Проверяет core/addon DB versions/disabled tasks, реальные Moodle file contenthashes, PHP syntax/bootstrap/needs_upgrade=false, loopback-only namespace и login HTTP200. Отдельный relay `ustar-patch-stage-20261005-http.service` — **127.0.0.1:18085**,7200s.
7. Проверяет frozen checkpoint снова и production/source status; пишет только private workspace report/state. Любая ошибка запуска или поздней проверки снимает relay и останавливает/удаляет только verified stage containers; checkpoint/workspace files сохраняются. Исходные source files/state и production не переустанавливаются.

Accepted result: `LAB_PATCH_STAGE_BASELINE=PASS`, HTTP_LOGIN200, checkpoint/cold-copy restore bootstrap PASS. **Это запуск исходного core из согласованной копии**, не upgrade5.1.8 и не доказательство восстановления после DB upgrade. Upgrade/repeat/paired rollback, full addon compatibility и manual role/SCORM acceptance пока false/NOT_TESTED. Все 19 addon roots и23 extra paths сохраняются в full source copy; неизвестные PHP helpers не запускаются.

## Проверки и operator handoff

18 meaningful tests используют настоящие filesystem copies/hashes/modes/inodes; Docker/PHP/systemd и portable fixture ownership mocked. Проверены paired copy, source restart при failure/unclean shutdown/pid/restart error, nonshared workspace inodes, corruption/missing/extra/symlink/hardlink/FIFO/writable-code refusal, source identity change, path injection, second-copy budget/reserve, config scope, stage bootstrap/HTTP/production drift/late report cleanup, redaction и SHA dependency tamper. Full local r16 **95 tests PASS**. Exact uploaded engine отдельно проверен на synthetic adapter: реальные generator/owned/mount/relay функции с mocked calls, source module namespace остаётся прежним. Real local Docker/PHP/SQL не выполнялись.

Local source checks JS24/templates56/XMLDB93 проходят с **явным skip-PHP**; remote PHP8.2/8.3/frontend/context CI фиксируется PR. Full DB/rollback RC в этой helper-only поставке не запускается; PR83 candidate CI остаётся distinct historical evidence. Application PR76/original audit/frontend/release/stage fixtures/workflows сохранены.

Operator: immutable raw download по implementation SHA → SHA-256 check → root0600 install `/usr/local/sbin/USTAR_LAB_COLD_CHECKPOINT_20261005.py` → `sudo -n python3 -I -B /usr/local/sbin/USTAR_LAB_COLD_CHECKPOINT_20261005.py --prepare-stage`. Authentication отдельным `sudo -v`, каждая команда одной physical line. Source lab будет недоступен на время cold copy, production продолжит работу. Передать весь sanitized stdout/error. Existing checkpoint/workspace дают refusal без overwrite; после failure сначала разобрать output, не удалять/повторять наугад.

## HDD latest evidence отдельно

SMART **20:44:52UTC**, exact WWN0x50014ee200c1ee59 /WD-WCAS82914317 /500106780160bytes: **newest Extended offline Completed without error /00% /lifetime16385h**. Previous read failure16359h/LBA752045700 сохранена как history и помечена smartctl outdated. Reallocated/events/pending/CRC0, power16386h, temperature48°C, ATA errors9/latest1970h unchanged. `Offline_Uncorrectable=1` остаётся: причина/очистка не доказаны. Auto offline collection suspended by host относится к отдельной активности, не отменяет successful extended self-test.

Surface write/read zero errors + newest long — **PASS в области этих тестов**. Не повторять badblocks/long. HDD partition/mount/encryption/schedule ещё не настроены, trusted-final backup target не принят и не нужен для этого SSD lab checkpoint. Регулярные копии остаются финальным этапом; Moodle/core/Ubuntu/HTTPS продолжаются в порядке1→7→6.
