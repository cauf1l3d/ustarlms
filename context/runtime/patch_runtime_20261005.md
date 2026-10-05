# INF-08/09: отдельный runtime для текущего Moodle patch

Подготовка выбранного пункта 7 продолжается параллельно длительной HDD-проверке. Application source остаётся PR76, production core/containers/OS/HTTPS не обновлены. CI не является приёмкой server upgrade.

## Кандидат и границы CI

Официальный patch **5.1.8 (Build: 20261005)** опубликован 05.10; tag `v5.1.8`, tag object `31700fd584969ba1178c433b62a3c90576e235af`, peeled commit `cc0bf17da8b5fbd290faf95b2b14a03d64331923`, core version `2025100608.00`. Metadata и stock index получены с exact upstream commit. SHA-256 `public/version.php`: `8e8ca75d913bf1f0b0a73bd029d476d827f50855ecf5d1a99a891e65c498ee02`; `public/index.php`: `3af0034674ac5b59e8926ebd63e1a0bf3360a495675d82de7b800e0514c467f7`.

Latest major — 5.3 LTS, также выпущенный 05.10. Его обсуждение не является командой на major migration: требуется PostgreSQL 17 и отдельная совместимость дополнений. Выбран текущий patch 5.1.8; major/LTS остаётся последующим проектом. Источники: [5.1.8](https://moodledev.io/general/releases/5.1/5.1.8), [5.3 requirements](https://moodledev.io/general/releases/5.3).

[`patch-runtime.json`](../../tests/stage/patch-runtime.json), отдельные Dockerfile/Compose и `run-patch.sh` добавляют **дополнительный** isolated runtime PHP **8.3.33 CLI / Debian 13 Trixie**, PostgreSQL **16.15 / Trixie**, Moodle **5.1.8**. Официальные PHP/PG multiarch image digests закреплены; это не local `stack-moodle` production image и не проверка Apache SAPI. Before fixture install проверяются actual PHP/core/PG versions, required extensions, max_input_vars и core commit. Артефакты включают runtime profile/preflight, image digests, логи и JUnit.

Обычный source gate теперь выполняется на PHP 8.2 и 8.3. Full RC event требует обе DB matrix cells: историческую и patch-518. Разные Compose projects, internal networks, без опубликованных ports; source RO, DB/stage tmpfs, synthetic-only credentials/data. Исторические runtime.json/Dockerfile/Compose, baseline commits и plugin rollback fixture сохранены. Historical artifacts сохраняют имя; patch evidence публикуется отдельно.

Новый прогон выполняет существующие fresh/install/plugin-upgrade/repeat/schema parity, PR63 corporate-role upgrade, authenticated shell и local_ustar PHPUnit **на одном core 5.1.8**. Это ещё не переход production weekly core → 5.1.8, не coordinated core/DB/moodledata rollback и не проверка остальных 55 внешних components. Historical rollback проверяет свой прежний переход plugin versions; его не переименовывать в core rollback.

Stock 5.1.8 поддерживает `$CFG->defaulthomepage = '/local/ustar/home.php'` через `get_home_page()` / `get_default_home_page_url()`. Это кандидат на сохранение проверенного USTAR входа без правки core. На stage отдельно проверить anonymous login/return URL, authenticated root и admin upgrade/registration behavior; конфигурация production ещё не менялась. `customfrontpageinclude` идёт после header и для такого redirect не подходит. Extra helpers/config backups/PDF и остальные addon roots автоматически не удалять/не переносить без обзора consumers.

## Новые owner evidence

**11:30:58 UTC**: установленный helper `59dec234262dd98fac0aa09a81cc3af9649ab8518580f4d977f5659a18042282` возобновил retained recovery lab:

`LAB_RESUME=PASS; HTTP_LOGIN=200; PRODUCTION_CONTAINERS_UNCHANGED=PASS`.

Report: `/var/lib/ustar-restore-lab/20261004T123406Z-741e3f72/resume-report-20261005T113058Z-a0dc0bf6.json`. Loopback relay на `127.0.0.1:18084` ограничен 7200 s; после его завершения недоступность relay сама по себе не означает потерю lab data. Это reopening старой копии, без archive restore/core upgrade/production outage. Размеры retained lab и fresh export пока не измерены; перед созданием paired rollback нужны актуальные df/du, а не удаление копии по предположению.

**HDD:** owner SMART 11:56:46 UTC подтверждает WDC WD5000AAKS-00YGA0, serial `WD-WCAS82914317`, WWN `0x50014ee200c1ee59`, capacity **500106780160 bytes**, 512-byte logical sectors. Global threshold PASSED не отменяет long self-test `Completed: read failure`, first LBA `752045700`, pending/offline_uncorrectable **1/1**; reallocated/CRC **0/0**. ATA Error Count 9 — исторические entries, не ошибки нового теста.

RO file listing `/dev/sdb1` содержал только Windows `System Volume Information/IndexerVolumeGuid` (76 bytes) и `WPSettings.dat` (12 bytes). Sysadmin разрешил использовать/форматировать весь этот HDD; владелец явно подтвердил полную перезапись и проверку. Старое запрещение этого конкретного форматирования отменено, широкая очистка SSD/других данных не разрешена.

**12:28:57 UTC** owner сообщил фактический запуск `ustar-hdd-check-20261005.service`, invocation `511f8302af174dd18f5fc9bf457c3b86`. Guarded target by-id/serial/exact capacity, без mounts; отдельный flock, Nice=19/I/O idle. `badblocks -wv -b 512 -c 65536 -t 0 -e 10`: один полный zero-write/read-compare pass, **976771055** logical sectors. NTFS/старое содержимое перезаписываются. Журнал пока только `Testing with pattern 0x00:` — начало, **не completion/health PASS**. Сервис работает вне SSH; пока идёт тест, target не монтировать/не форматировать и не запускать concurrent surface/long SMART test. Исходные logs/results сохранять.

Log `/var/log/ustar-hdd-check-20261005.log`, bad list `.bad`. Условие законченного surface pass: unit завершился успешно, полный log подтверждает write/read completion и `.bad` пустой. После этого нужны новые SMART attributes и long self-test; ранее failed диск пока **не доверенный backup target**. Любые найденные bad sectors/новая read failure требуют отказа от его роли единственного backup storage, а не маскировки форматированием. Будущие автокопии — healthy HDD, не SERVEREXPRESS.

## Проверки и продолжение

Local: 52 tests/r16 PASS; context526files/93tables/32tasks PASS, content index163files. Source structural JS/XMLDB/templates checks PASS с явным --skip-php; shell syntax и JSON/YAML/profile/isolation/historical fixture equality PASS. Original audit SHA unchanged. Exact source/full CI status и implementation SHA фиксируются в PR/GitHub Actions этой поставки; Docker/PHP проверяются отдельно. В local executor Docker/PHP отсутствуют; real matrix проверяется Actions на exact head через новый `full-rc` label event. Скipped job не PASS.

Следующие server inputs: completion evidence HDD и df/du retained lab/export. Параллельно проходит CI. Затем подготовить isolated full-addon candidate upgrade с coordinated code/DB/data rollback и USTAR home checks, после приёмки — controlled production patch, Ubuntu maintenance, HTTPS. Внешняя verification новой encrypted copy и recovery общих host services остаются условиями соответствующей операции; не повторять уже пройденный baseline restore без причины.
