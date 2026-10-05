# INF-01: проверенные backup/cron wrappers

Владелец передал исходники 05.10.2026 **09:48:49 UTC / 12:48:49 MSK**. Оба файла прочитаны полностью и совпали с ранее измеренными server SHA-256. Это проверка предоставленного кода, не прямое измерение текущего production. Установленные файлы не менялись; private uploads не добавлены в Git.

| Скрипт | SHA-256 | Размер |
|---|---|---:|
| `USTAR_BACKUP_SFTP_20261004.py` | `0d31e694db8bee2309072bd00dee2423022f35e18ad2c11ea1cf788fc63ef47f` | 13 689 bytes |
| `USTAR_CRON_SETUP_20261005.py` v3 | `66fd51d364beb59a8874a63c695c122dca5452a720e61b86d070beb0b1939852` | 24 095 bytes |

## Backup CLI и область

| Вызов / флаг | Проверенное действие |
|---|---|
| Без флагов | Preflight: зависимости, private paths, age public recipient, live paths/mounts, оба running containers, unresolved interruption marker, image IDs, свободное место и export quota. Moodle не останавливается. Может создать private directories, lock и local export directory. |
| `--backup --acknowledge-outage` | Создание согласованной зашифрованной копии; Moodle останавливается на время dump/files capture, PostgreSQL остаётся запущенным. |
| `--backup` без acknowledgement | argparse error до capture/остановки; private state directories могут быть подготовлены ранее. |
| `--recover` | Возобновление ранее running Moodle по private marker и проверенному container ID. **Не восстановление DB/files из архива.** |

Work directories: `/var/lib/ustar-backup/work-*`; export: `/var/lib/ustar-backup-export`, root:operator-group 0750, архив/JSON 0640. Encryption использует `/etc/ustar-backup/recipients.txt`; private recovery key этому процессу не нужен и не включается в архив. Export quota **6 GiB**, проверяется до подготовки и перед публикацией. Existing copies не удаляются. Reserve: максимум из 6 GiB и `3 × image size + 4 GiB`; preflight не является точной оценкой произвольного будущего роста site-files.

Состав: `docker save` двух exact image IDs, private container inspect, compose/.env/USTAR Apache route при наличии, custom-format DB dump, PostgreSQL globals, **полные public + moodledata** с numeric owners, versions и checksums. Поэтому extra files из [core comparison](core_review_20261005.md) входят в code/files archive независимо от исключений collector. Private payload включает credentials/role hashes и остаётся внутри защищённого staging/encrypted archive; не публиковать его.

После остановки Moodle checkpoint требует ноль других DB sessions; чужие sessions не завершаются. Одновременный deploy или host CLI writer не разрешается этим backup contract. В `finally` вызывается resume; ошибка/прерывание оставляет private marker/staging для разбора. Завершённый архив проверяется readback SHA-256 и публикуется через `.partial` rename. Успешный staging удаляется; ошибочный сохраняется. `pg_restore --list` проверяет структуру dump, **не доказывает успешный restore**.

Строка `EXTERNAL_COPY_VERIFIED=NO; download ... SERVEREXPRESS` — подсказка старого скрипта. Сетевой передачи, SMB/SFTP connection, HDD mount или регулярного расписания в коде нет: target этого ручного вызова — **local SSD export**. Внешний экземпляр и decryption/restore проверяются отдельно. Предписание healthy HDD для будущей автоматизации сохраняется.

В runtime.json baseline PR76 указан константой; это label последнего audit, не новая live hash verification. Container Running после resume не доказывает HTTP/login readiness. Архив не охватывает весь shared host и не включает host cron/backup scripts, их `/etc`/`/var/lib` state или все почтовые/DNS/ISPConfig services. OS recovery требует отдельного host/service набора до соответствующих изменений.

## Cron CLI и совместная блокировка

| Флаг | Проверенное действие |
|---|---|
| `--self-test` | Локальные synthetic parser/acceptance/symlink/kernel-lock проверки; production cron не запускается. |
| `--status` | Private state, bounded readonly SQL metrics и Docker identities; без Moodle PHP bootstrap, запуска задач и изменения расписания. |
| `--check` | Preflight с historical lab evidence, конфигурацией через Moodle PHP bootstrap и readonly SQL; задачи не запускает. Bootstrap side effects требуют отдельного учёта. |
| `--install` | Записывает installation state, выполняет **реальный** production cron pass и после acceptance устанавливает каждую минуту schedule/logrotate. Это запись и бизнес-задачи, не обычный status/rebind. |
| `--tick` | Один real worker `www-data`, `--keep-alive=0`; проверяет script hash, schedule и container ID/image binding. |
| `--disable` | Удаляет только managed schedule; уже запущенный worker может продолжаться. |

Cron удерживает EX lock `/run/ustar-cron/lock` и SH lock `/run/ustar-backup/lock` на время worker. Backup берёт EX lock того же backup path. Блокировки nonblocking: при backup cron tick пропускается, при уже работающем cron backup может отказать до остановки Moodle. При таком отказе сначала проверить running pass/status; не запускать параллельные workers и не удалять lock files.

Флага `--rebind` нет. Worker при смене ID/image направляет к `--check`/`--install`; сам ничего не перепривязывает. После container recreation нужен контролируемый protocol с сохранением одного расписания, проверкой ongoing worker и отдельным acceptance первого real pass. Повторный installer сейчас не нужен. Версионная пара USTAR и historical lab SHA в wrapper фиксированы; при новом application release контракт должен быть пересмотрен, а не обойдён.

## Выполненный операторский шаг и продолжение

Владелец выполнил reviewed backup: `ustar-recovery-20261005T101659Z-7ea0ad99.tar.gz.age`, SHA-256 `42b87cca41623d3433943989c4ab5049b93d375cbcd5a7ddf43038d840b676a8`; независимый server sha256sum совпал. Moodle running YES, stop-to-running 24.097 s. Cron scheduled 10:24 UTC exit0/1.25s, schedule/container match true, new failures и USTAR errors0. Root Apache HTTP303 — redirect smoke.

Вчерашний archive/decryption/isolated restore/manual login/functionality PASS сохраняется и дополнительно подтверждён владельцем; повтор полной baseline-репетиции не требуется. Новый archive не наследует его external/decryption acceptance. [Свежие evidence и retained lab resume helper](lab_resume_20261005.md): lab containers удалены, DB/files сохранены; следующий шаг — controlled resume для candidate patch/rollback. Source uploads трёх lab scripts прочитаны полностью и их dependency hashes совпали.

Операторские команды одной физической строкой; `sudo -v` отдельно с ожиданием prompt, затем `sudo -n`. Новый helper `--resume-lab` включает preflight; старый `--restore-lab` не открывает existing ROOT и не должен запускаться повторно. Production core/AppArmor/packages/HTTPS этой поставкой не меняются.

## Выполненная проверка

- Полный статический review обоих uploads, CLI/mode dispatch и matching SHA-256.
- Локальный `--self-test` cron: PASS, включая реальные kernel SH/EX conflicts на temporary files; внешние службы не вызывались.
- Backup `--help`: PASS. Offline main dispatch с запрещёнными external commands: default не входит в backup, acknowledgement обязателен, recover идёт только в resume; PASS. Это не failure rehearsal настоящего backup.
- Original audit/application trees/historical fixtures сохраняются; context integrity и точный delivery SHA/CI фиксируются в Git/PR.

INF-01 остаётся **in_progress**: fresh production backup с встроенным preflight выполнен владельцем; retained lab resume и candidate isolated upgrade/rollback ещё впереди. Index review завершён в [core review](core_review_20261005.md); consumers/provenance дополнительных файлов ещё проверяются. Сохранён порядок **1 → 7 → 6**.
