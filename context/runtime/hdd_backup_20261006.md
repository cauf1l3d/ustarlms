# Новые согласованные копии USTAR на HDD — 06.10.2026

Статус: **producer installed / preflight and fresh manual HDD trial PASS**.
Producer PR91/main `d884e402948af91b5c9f2645e07fdaf1b1838e03` подтверждён
owner terminal output received06Oct01:16:21UTC/04:16:21MSK;
[точный report](hdd_backup_trial_20261006.json). Владелец ранее подтвердил
`FSTAB_CONFIGURED=PASS` и `HDD_ARCHIVE_COPY=PASS`; сообщение получено
06Oct03:33:41MSK/00:33:41UTC, timestamps выполнения команд не напечатаны.
`/srv/ustar-storage` — ext4 UUID `359a2bae-4e79-461a-ab72-1597f605d801`,
df458G/913Mused/452Gavailable/1%. Fstab backup:
`/etc/fstab.before-ustar-hdd-c2kb8T`. Перезагрузка/mount-after-boot не проверялись.

Существующий05Oct encrypted snapshot скопирован в `backups/manual` с source и
copied-file SHA-256 `42b87cca41623d3433943989c4ab5049b93d375cbcd5a7ddf43038d840b676a8`.
Это прежняя точка данных; её copy не заменяет свежий capture ниже.
Source на SSD сохранён. SMART198=1 остаётся отдельным аппаратным риском; локальный
HDD не считается независимым экземпляром для потери host.

## Пробная свежая копия на сервере

| Поле | Owner report06Oct01:10..01:12UTC |
|---|---|
| Install/check/capture | HDD_BACKUP_INSTALLED / HDD_PREFLIGHT / HDD_BACKUP = PASS |
| Archive | `ustar-recovery-20261006T011112Z-8f332c59.tar.gz.age` под `backups/managed` |
| SHA256 | `d9352e3ce5e237a6ac0ab14c72b9da2648d6906fe7e95cd5c88bea726c965af3` |
| Размер | 957829597bytes ≈913.46MiB |
| Полный цикл | 100.389s по started/finished timestamps |
| Пауза Moodle | 24.111s; MOODLE_RUNNING=YES, Apache login200 |
| Final hash | readback_verified=true |
| Staging/архивы | staging_retained=false, archive_deletion=false |
| Timer/external/restore | timer_unit_present=false; external_copy_verified=false; restore_verified=false |

Это реальный owner-run pipeline Docker/PG/age на HDD, не прямой доступ агента.
Missing-disk/full-disk/interruption negative cases на production не запускались.
Free485129400320bytes — pre-capture measurement; fresh post-capture df не получен.

## Исполняемые зависимости

| Объект | Проверенный контракт |
|---|---|
| Новый source | `scripts/infra/hdd_backup.py` |
| SHA-256 source | `75ca974f1d763f6fc84281501a99936894f1b487c1cc4d0c1d71697e67c79d75` |
| Устанавливаемый путь | `/usr/local/sbin/USTAR_HDD_BACKUP_20261006.py`, root:root0750 |
| Existing engine | `/usr/local/sbin/USTAR_BACKUP_SFTP_20261004.py` |
| Dependency SHA | `0d31e694db8bee2309072bd00dee2423022f35e18ad2c11ea1cf788fc63ef47f` |
| Exact HDD | WWN0x50014ee200c1ee59/serialWD-WCAS82914317/500106780160bytes |
| Production images | Reviewed Moodle6d462302…/PGf1c3376c…; полные identities в source и baseline |

Подлинный engine повторно получен из предоставленного owner source и проверен по
SHA/13689bytes. В Git не включается. Adapter загружает его только после root-owned
path/type/mode/link/SHA guards, без запуска legacy main. Capture/age/DB/tar/inner
checksums/archive format сохраняют исходный reviewed pipeline; изменения ограничены
HDD directories/budget, private export, SSD control state, stop/resume и readiness.

## Paths и блокировки

| Путь | Назначение |
|---|---|
| `/srv/ustar-storage/backups/.staging/work-*` | Большие private payloads, image export, DB/files, plaintext bundle и encrypted intermediate |
| `/srv/ustar-storage/backups/managed` | Завершённые encrypted archive + JSON metadata; directories0700, root:root files0640 под private parent |
| `/srv/ustar-storage/backups/manual` | Уже проверенная ручная копия; producer её не изменяет |
| `/var/lib/ustar-backup/moodle-was-running.json` | Durable SSD marker, доступный при потере HDD; совместимая container identity и stop phase |
| `/var/lib/ustar-backup/last-hdd-export.json` | Последний опубликованный HDD archive manifest |
| `/var/lib/ustar-backup/last-hdd-attempt.json` | Последний запущенный capture attempt, phase/failure/retained-work status |
| `/var/lib/ustar-backup/last-hdd-success.json` | Последний capture с final readback и login readiness PASS |
| `/run/ustar-backup/lock` | Existing EX backup lock против cron SH; inode не удаляется/не заменяется; bounded15s acquisition |

Legacy SSD archives и `last-local-export.json` сохраняются. HDD staging/export
ведутся относительно held directory descriptor/CWD нужной filesystem: detach
mount не переводит записи в пустой SSD mountpoint. До любых HDD writes и повторно
непосредственно перед `docker stop` проверяются mount UUID/source/type/options,
WWN/serial/capacity/child partition, system-root exclusion и обе production identities.

Required space — max6GiB и4×(image bytes + apparent site bytes + DB size)+4GiB,
не менее10000 free inodes. Это conservative estimate, не гарантия будущего роста.
Managed export quota100GiB; достигнутая quota/неизвестный file/partial требует
разбора, автоматического удаления нет. Whole-host services и host recovery inputs
не добавлены в archive этой версии; их recovery остаётся отдельной работой перед OS.

Во время capture нельзя выполнять concurrent deploy/upgrade/host CLI write.
Checkpoint проверяет отсутствие других DB sessions; это не блокировка произвольного
последующего внешнего writer. Phase/status записываются на SSD по ходу операции.

## CLI и следующий операторский шаг

| Команда | Эффект |
|---|---|
| Default / `--check` | Проверяет source, диск, live identities, public recipient и budget; может создать private directories/lock. Не останавливает Moodle, не создаёт snapshot; SQL только READ ONLY. |
| `--backup --acknowledge-outage` | Повторяет preflight; согласованный capture с паузой только production Moodle, PG остаётся running; затем packaging/encryption/publication/readback и Apache login200. |
| `--status` | Читает bounded SSD control JSON, без engine load, HDD requirement, directories/locks/SQL/stop. Timer-unit presence не доказывает enabled/active/success. |
| `--recover` | Resume по SSD marker/exact container ID без HDD. Не archive restore; inflight stop при ещё running Moodle сохраняет marker и требует проверки завершения Docker stop. |

Catchable SIGINT/SIGTERM/SIGHUP отложены во время Docker stop до reply и durable
stop-complete flag; pending interruption затем идёт в finally/resume. Во время
resume повторные catchable signals игнорируются. SIGKILL/power-loss не объявлены
автоматически восстановленными: marker остаётся для проверки/возобновления.
Не применять legacy resume к новому ambiguous inflight marker ради обхода guard.

Установка — immutable Git source с проверкой SHA до root0750 install; точная
one-line download команда фиксируется в delivery ответе/PR. SCP не требуется.
Authenticate отдельно `sudo -v`, затем:

```bash
sudo -n python3 -u /usr/local/sbin/USTAR_HDD_BACKUP_20261006.py --check
```

Ниже выполненный trial CLI; новый ручной запуск нужен только для новой точки данных,
в выбранное окно паузы Academy:

```bash
sudo -n nice -n 10 ionice -c 2 -n 7 python3 -u /usr/local/sbin/USTAR_HDD_BACKUP_20261006.py --backup --acknowledge-outage
```

Ожидаем `HDD_BACKUP=PASS` и полный report со SHA/bytes, временем паузы,
HDD path и login HTTP200. HTTP200 не подтверждает
authenticated business acceptance; encrypted readback не заменяет decryption/restore.
При failure — полный безопасный console output и `--status`; retained work не удалять.

## Проверки и ещё открытые acceptance

29 local tests PASS:19 portable boundary/kernel-lock/filesystem cases и10
integration cases через точный private source, real tar/files/copy/hash,
mocked Docker/age/mount/fixture ownership. Проверены success, capture/encryption/
readback/readiness failures, real SIGINT during stop, injected capture interruption,
failed resume, disconnect без SSD fallback и сохранение uncertain marker.
В обычном CI19 boundary cases выполняются,10 private-source cases явно skipped
без `USTAR_TEST_BACKUP_ENGINE`; private engine не публикуется. Это не настоящий
Docker/PG/age encryption или HDD power-loss/recovery rehearsal.

Server install/check/manual trial подтверждены. Fresh archive independent-copy/
decryption/restore ещё pending. [Начальный scheduler](hdd_backup_schedule_20261006.md)
подготовлен; server activation/first scheduled run и внешняя notification delivery
ещё не подтверждены. Рекомендуемый initial daily03:30 Europe/Moscow — без daytime
catch-up и без archive deletion; retention7daily/4weekly/3monthly не принят/не включён.
Перед освобождением SSD snapshots нужны consumer/independent-copy checks; перед
SSD GPT/root growth — separate recovery/partition-table copy/procedure.
