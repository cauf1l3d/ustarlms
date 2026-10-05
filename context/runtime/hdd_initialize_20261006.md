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

Скрипт: `scripts/infra/hdd_initialize.py`. Локальный SHA-256 фиксируется в delivery
PR вместе с 16 offline failure/order tests; реальный HDD execution до команды оператора
не выполнен.

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

## Следующий операторский шаг

Сначала установить helper по опубликованному commit/sha, выполнить `--check` и
прислать полный вывод. Только после `EMPTY_HDD_CHECK_PASS` разрешён отдельный
`--initialize`. Вывод с UUID необходим для mount по UUID и последующей автоматизации.

Текущий HDD остаётся локальной копией на том же сервере, а не независимым disaster-
recovery экземпляром. SMART `Offline_Uncorrectable=1` и старые ATA errors сохраняются
как риск; успешные write/read и новый extended self-test — только положительный scope
теста, не гарантия диска.
