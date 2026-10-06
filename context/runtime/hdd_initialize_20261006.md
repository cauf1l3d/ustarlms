# Разовая подготовка HDD для USTAR — 06.10.2026

Этот helper подготавливает **только** заранее проверенный и разрешённый владельцем диск
`/dev/disk/by-id/wwn-0x50014ee200c1ee59` (WDC WD5000AAKS-00YGA0,
`WD-WCAS82914317`, 500106780160 bytes). Он не монтирует диск, не меняет `/etc/fstab`,
не трогает SSD, контейнеры, данные Академии или расписания.

## Границы

- `--check` — read-only: serial/WWN/capacity/sector size, отсутствие разделов,
  файловых сигнатур, mount/holder/swap/open users, zeroed surface edges.
- `--initialize` — после всех тех же проверок создаёт GPT и один ext4-раздел
  `ustar-hdd`, затем проверяет kernel partition, UUID, label и filesystem.
- Любое несоответствие, занятость, существующая сигнатура, ошибка `sfdisk`/udev или
  несовпадение UUID останавливает процесс. `--force`, wipe signatures, SSD paths,
  mount/fstab/systemd/docker/backup actions отсутствуют.
- Разметка и ext4 — единственная запись этого шага. После неё диск остаётся
  **не смонтированным**; UUID сначала фиксируется в карте, затем отдельными проверенными
  командами добавляются mount guard и пробная копия.

Скрипт: `scripts/infra/hdd_initialize.py`, опубликован PR89 вместе с16 offline
failure/order tests. Владелец затем выполнил `--check` и `--initialize`; результат
`FILESYSTEM_READY` подтверждён. Следующая отдельная mount-команда тоже выполнена;
результаты и граница следующих шагов приведены ниже.

## Полученное состояние server1

Свежий вывод владельца подтверждает:

- `/dev/sda` — 234441648 sectors / 111.79 GiB, GPT; `sda1` 1 MiB BIOS boot,
  `sda2` начинается на 4096 и заканчивается на 104855551 (50 GiB).
- `fdisk` предупреждает, что PMBR/GPT backup table остались на старой границе
  104857599; запись пока не выполнялась. После сохранения копий это требует отдельной
  процедуры `relocate GPT → grow partition 2 → online ext4 resize → fsck policy`.
- Свободный хвост после `sda2` до стандартного GPT резервного диапазона —
  129586062 sectors ≈ 61.79 GiB. Это подтверждённый свободный partition range,
  а не причина немедленно менять таблицу.
- Сейчас запущены production `ustar_moodle`/`ustar_postgres` и retained lab
  `ustar_recovery_lab_20261004_web`/`_pg`. Lab имеет absolute bind paths под
  `/var/lib/ustar-restore-lab/...`; обычный `mv` запрещён.
- Host timezone `Etc/UTC`, NTP synchronized; расписание по Москве должно быть
  задано с явной timezone-конверсией (предложение 03:30 Europe/Moscow пока не timer).

## Подтверждённая инициализация и temporary mount

Initializer SHA-256 `ef3a5699f54384428d40e1c9b96b2707e117ad188b1f36628c95fb823be49aa2`
и owner output `FILESYSTEM_READY` подтверждены; повторный initialize не требуется.
Helper завершился с `mounted=false`, `fstab_changed=false`, `ssd_changed=false`,
`backups_changed=false`. После него владелец отдельно выполнил mount по UUID.
Вывод mount получен06Oct03:07:20MSK/00:07:20UTC; command execution timestamp отсутствует.

| Поле | Подтверждено владельцем |
|---|---|
| Раздел | `/dev/disk/by-id/wwn-0x50014ee200c1ee59-part1` → `/dev/sdb1` |
| Файловая система | ext4, label `ustar-hdd` |
| UUID | `359a2bae-4e79-461a-ab72-1597f605d801` |
| Mountpoint | `/srv/ustar-storage` |
| Mount flags | `rw,nosuid,nodev,noexec,relatime` |
| Mount root | `root:root/0700` установлен выполненной командой |
| `df -hT` | 458G total /28K used /453G available /1% |
| Пока не подтверждено | fstab/persistence, archive copy/hash, fresh HDD backup producer/trial, timer |

## Следующий операторский шаг

Сначала persistent fstab с проверкой текущего UUID и candidate-конфигурации,
затем copy/hash готового encrypted archive05Oct на HDD. Академия при этих двух
операциях не останавливается. Исходный архив на SSD сохраняется. Это проверка
записи и целостности копии, не новый DB snapshot, decryption или restore.

Текущий HDD остаётся локальной копией на том же сервере, а не независимым disaster-
recovery экземпляром. SMART `Offline_Uncorrectable=1` и старые ATA errors сохраняются
как риск; успешные write/read и новый extended self-test — только положительный scope
теста, не гарантия диска.

## Подготовленные команды: execution ещё не подтверждён

Команды выдаются одной физической строкой. Сначала отдельно `sudo -v`;
последующие `sudo -n` не запрашивают пароль внутри paste-block.
При `STOP` не выполнять зависимые шаги; прислать полный вывод.

### 1. Постоянная запись fstab

Проверяет текущий mounted UUID, отсутствие конфликтующей записи и parsability/usability
candidate fstab. Сохраняет private копию исходного fstab, заменяет его атомарно и
перечитывает systemd unit metadata; не выполняет mount-a/remount/reboot. `nofail`
позволяет host продолжить загрузку без HDD; backup producer всё равно обязан
отказать при отсутствии нужного mount. `FSTAB_CONFIGURED=PASS` — configuration
checkpoint; фактический reboot/persistent-mount verification остаётся впереди.

```bash
sudo -n bash -c 'set -euo pipefail; ustar_mount=/srv/ustar-storage; ustar_uuid=359a2bae-4e79-461a-ab72-1597f605d801; test ! -L /etc/fstab && test -f /etc/fstab || { echo "STOP: fstab type"; exit 1; }; test "$(findmnt -nro UUID --mountpoint "$ustar_mount")" = "$ustar_uuid" || { echo "STOP: HDD not mounted"; exit 1; }; ustar_line="UUID=$ustar_uuid $ustar_mount ext4 defaults,nosuid,nodev,noexec,nofail,x-systemd.device-timeout=10s 0 2"; ustar_match=$(grep -E "^[[:space:]]*(UUID=$ustar_uuid[[:space:]]|[^[:space:]#]+[[:space:]]+$ustar_mount([[:space:]]|$))" /etc/fstab || test "$?" -eq 1); if test -n "$ustar_match"; then test "$ustar_match" = "$ustar_line" || { echo "STOP: conflicting fstab entry"; exit 1; }; else ustar_tmp=$(mktemp /etc/.fstab.ustar-XXXXXX); trap "rm -f -- \"$ustar_tmp\"" EXIT; ustar_backup=$(mktemp /etc/fstab.before-ustar-hdd-XXXXXX); cp --preserve=all /etc/fstab "$ustar_tmp"; cp /etc/fstab "$ustar_backup"; cmp -s "$ustar_tmp" "$ustar_backup" || { echo "STOP: fstab changed"; exit 1; }; printf "\n%s\n" "$ustar_line" >> "$ustar_tmp"; findmnt --verify --tab-file "$ustar_tmp" >/dev/null 2>&1 || { echo "STOP: candidate fstab verification"; exit 1; }; cmp -s /etc/fstab "$ustar_backup" || { echo "STOP: fstab changed"; exit 1; }; sync -f "$ustar_tmp"; mv -T -- "$ustar_tmp" /etc/fstab; sync -f /etc/fstab; echo "FSTAB_BACKUP=$ustar_backup"; fi; systemctl daemon-reload; echo "FSTAB_CONFIGURED=PASS"; findmnt --fstab --target "$ustar_mount" -o SOURCE,TARGET,FSTYPE,OPTIONS'
```

### 2. Проверка копии существующего encrypted snapshot

Источник — ранее проверенный `ustar-recovery-20261005T101659Z-7ea0ad99.tar.gz.age`
из `/var/lib/ustar-backup-export`, SHA-256
`42b87cca41623d3433943989c4ab5049b93d375cbcd5a7ddf43038d840b676a8`.
Команда проверяет mounted UUID и filesystem identity до записи, остаётся cwd на HDD,
проверяет SHA исходника и flush/readback SHA копии, создаёт private directories0700
и archive0600, не перезаписывает существующий target и не удаляет исходник.
Готовый target — `/srv/ustar-storage/backups/manual/` с тем же archive basename.
Низкий I/O/CPU priority ограничивает влияние копирования; возможна дополнительная
дисковая нагрузка, без остановки Academy или shared services.

```bash
sudo -n bash -c 'set -euo pipefail; umask 077; ustar_uuid=359a2bae-4e79-461a-ab72-1597f605d801; test "$(findmnt -nro UUID --mountpoint /srv/ustar-storage)" = "$ustar_uuid" || { echo "STOP: HDD not mounted"; exit 1; }; cd /srv/ustar-storage; test "$(stat -c %d .)" = "$(stat -Lc %r /dev/disk/by-uuid/$ustar_uuid)" || { echo "STOP: wrong filesystem"; exit 1; }; ustar_file=ustar-recovery-20261005T101659Z-7ea0ad99.tar.gz.age; ustar_hash=42b87cca41623d3433943989c4ab5049b93d375cbcd5a7ddf43038d840b676a8; ustar_src=/var/lib/ustar-backup-export/$ustar_file; test -f "$ustar_src" && test ! -L "$ustar_src" || { echo "STOP: source archive missing or symlink"; exit 1; }; printf "%s  %s\n" "$ustar_hash" "$ustar_src" | sha256sum --check -; test ! -L backups && test ! -L backups/manual || { echo "STOP: target symlink"; exit 1; }; install -d -m 0700 -o root -g root backups backups/manual; ustar_dst=backups/manual/$ustar_file; test ! -e "$ustar_dst" && test ! -L "$ustar_dst" || { echo "STOP: target archive already exists"; exit 1; }; ustar_tmp=$(mktemp backups/manual/.hdd-copy-XXXXXX); trap "rm -f -- \"$ustar_tmp\"" EXIT; ionice -c 3 nice -n 19 cp --reflink=never --sparse=never -- "$ustar_src" "$ustar_tmp"; sync -f "$ustar_tmp"; printf "%s  %s\n" "$ustar_hash" "$ustar_tmp" | sha256sum --check -; mv -nT -- "$ustar_tmp" "$ustar_dst"; test ! -e "$ustar_tmp" || { echo "STOP: destination appeared during copy"; exit 1; }; sync -f "$ustar_dst"; echo "HDD_ARCHIVE_COPY=PASS"; df -hT .'
```

Ожидаемые markers: `FSTAB_CONFIGURED=PASS`, затем `HDD_ARCHIVE_COPY=PASS`.
Реальный operator output пока не получен. Затем нужны guarded HDD backup producer,
свежая trial copy и recovery/consumer checks перед отдельной SSD операцией и timer.

## Проверки подготовленных команд

Обе команды прошли Bash syntax check. Offline temporary-filesystem checks:

- fstab: новая запись, повтор без duplicate, конфликт, duplicate, missing mount,
  invalid candidate —6 PASS; отказ сохраняет исходный fstab, temporary candidate
  удаляется, backup0600 и final fstab644 проверены на fixture;
- copy: правильная копия, missing mount, wrong filesystem, corrupt source,
  existing target, corrupt copied data —6 PASS; source неизменен, failure не
  публикует completed archive, temporary copy удаляется, success SHA/mode0600 проверены.

Mount/device identity, systemctl/sync и priority calls в offline checks замоканы;
реальный source copy и SHA выполнены на временных локальных файлах. Это проверка
команд, не server execution, power-loss durability, HDD qualification или restore.
