# SSD и потребители копий: следующий read-only проход

После [подтверждённой установки HDD timer](hdd_backup_schedule_install_20261006.json)
следующий шаг — свежая разметка SSD, размеры и ссылки на кандидатов переноса.
PR92 implementation `7501440b476bf720658f5bf039efc3e4f1237d9c` опубликован в main
`040c671236cb4d7f4a225700bd288ced7e6f14f3`; timer active/enabled, first event
07Oct00:30UTC/03:30MSK. Первый scheduled run ещё не выполнен.

Предыдущие данные: WALRAM120GB/234441648 sectors, sda1 BIOS boot2048–4095,
sda2 ext4 root4096–104855551, stale GPT backup/PMBR boundary,
61.79GiB free tail. Это датированные сведения из [карты](server_map_20261006.md),
а не разрешение выполнять growth по имени `/dev/sda` без свежей identity/layout.

## Первый проход выполнен, что ещё уточнить

[Вывод владельца](ssd_storage_result_20261006.json) received06Oct08:19:59UTC,
initial inventory08:18:26UTC: расход/разметка/SMART/mounts/du получены;
reference search частичный с missing-link errors. Повтор всего прохода не нужен.

Под console подразумевается доступ сисадмина к экрану машины через монитор/
клавиатуру или management console. Rescue medium — загрузочная Ubuntu Live USB
или доступный аналог в панели размещения. Owner09:03:36UTC подтвердил доступ
сисадмина к консоли. Конкретный способ и rescue medium отдельно не названы;
фактический boot recovery не проверен. Повторно доступ/разрешение не запрашивать.

[Следующий owner output](ssd_identity_ext4_result_20261006.json) received06Oct08:36:58UTC
получен: WALRAM120GB, serial2203JPDG120GB6000933, UUID/PARTUUID совпадают; ext4
13106432 blocks×4096 = partition53683945472bytes. Zero WWN не unique pin. Features
resize_inode/64bit/extents/metadata_csum recorded; header прочитан на mounted root,
это не offline filesystem check. Needs_recovery/clean/header counters не объявлять
доказательством отсутствия повреждений. GPT/SMART этим follow-up не повторялись.
Выполненная команда JSON identity и ext4 header:

```bash
sudo -n timeout 20s bash -c 'set -eu; lsblk --json --bytes -o PATH,TYPE,SIZE,MODEL,SERIAL,WWN,UUID,PARTUUID /dev/sda; dumpe2fs -h /dev/sda2'
```

Lab сейчас running/actively mounted; его directories не освобождать по du alone.
GPT dump не является saved binary table copy. [Raw boot/GPT + native copy](ssd_partition_table_copy_result_20261006.json)
подтверждена owner09:06:01UTC: nine hash checks/source cmp и final PASS; повтор не нужен.
Следующая [команда GPT relocation](ssd_gpt_relocate_20261006.md) включает fresh raw
CRC/layout checks до записи и unchanged boot/partition/kernel checks после неё.
Server GPT write/root growth пока не выполнены.

## Выполненные команды первого прохода

Каждый блок — одна физическая строка. Authenticate отдельно:

```bash
sudo -v
```

Дата, текущий расход, источники mounts, device identity/size и наличие инструментов:

```bash
sudo -n timeout 20s bash -c 'set -eu; date -u; df -hT / /srv/ustar-storage; findmnt --mountpoint / -o SOURCE,TARGET,FSTYPE,OPTIONS; findmnt --mountpoint /srv/ustar-storage -o SOURCE,TARGET,UUID,FSTYPE,OPTIONS; lsblk -b -o NAME,PATH,PKNAME,TYPE,SIZE,FSTYPE,UUID,MOUNTPOINTS,MODEL,SERIAL,WWN; for ustar_tool in sfdisk sgdisk growpart resize2fs smartctl rg; do command -v "$ustar_tool" || true; done'
```

Разметка SSD, без её записи. `sfdisk --dump` печатает описание таблицы; этот вывод
ещё не является сохранённой binary recovery copy. GPT warnings не исправлять
автоматически. [Контракт util-linux](https://man7.org/linux/man-pages/man8/sfdisk.8.html).

```bash
sudo -n timeout 20s sfdisk --dump /dev/sda
```

Текущий SMART SSD, без запуска self-test:

```bash
sudo -n timeout 20s smartctl -H -A /dev/sda
```

Все running/stopped container mount sources; env/config contents не выводятся:

```bash
sudo -n timeout 30s bash -c 'set -eu; ustar_ids=$(docker ps -aq); if test -n "$ustar_ids"; then docker inspect --format "{{.Name}} status={{.State.Status}} {{range .Mounts}}{{.Type}}:{{.Source}}=>{{.Destination}}; {{end}}" $ustar_ids; fi'
```

Каталоги не менее100MiB, с малым IO priority и пределом60s:

```bash
sudo -n timeout 60s ionice -c 3 nice -n 19 du -x -h --max-depth=1 --threshold=100M /home/aduk /opt/ustar/backups /opt/ustar/releases /var/lib/ustar-backup-export /var/lib/ustar-restore-lab /var/lib/ustar-cron-lab
```

Файлы control plane, содержащие candidate path references; печатаются только
имена файлов. Это первый ограниченный поиск, не доказательство отсутствия
consumers в пользовательских scripts, open FDs или других namespaces.

```bash
sudo -n timeout 30s bash -c 'ustar_pattern="/home/aduk/[^[:space:]]*(backup|PRE_RC|snapshot)|/opt/ustar/(backups|releases)|/var/lib/ustar-(backup-export|restore-lab|cron-lab)"; if command -v rg >/dev/null 2>&1; then rg -l -i --follow --no-messages "$ustar_pattern" /etc/systemd/system /etc/cron.d /etc/crontab /usr/local/sbin /usr/local/bin /var/spool/cron/crontabs; else grep -RIlEi "$ustar_pattern" /etc/systemd/system /etc/cron.d /etc/crontab /usr/local/sbin /usr/local/bin /var/spool/cron/crontabs; fi'
```

Exit1 от reference search означает отсутствие совпадений, exit124 — timeout;
оба результата не превращать в полный consumer clearance. Missing command/path
также сохранить в выводе; пакеты этим проходом не устанавливаются. Блоки независимы.

## Что должно быть известно до изменяющей операции

- Root block-device identity, filesystem UUID, exact sector layout, current GPT
  diagnostics и SMART; disk image и partition-table backups — разные объекты.
- Recovery для общего host/boot, доступ сисадмина к консоли/rescue medium,
  проверенные binary GPT/PMBR copies и способ возврата. Academy archive не содержит
  всю почту/DNS/ISPConfig и не доказывает возможность восстановить загрузку хоста.
- Exact named candidates, active/stopped container mounts, control references,
  комплектность и independent copies перед любым удалением source.
- Раздельные guarded шаги GPT repair, partition growth с сохранением sda1 и start
  sda2, kernel-visible size check, ext4 resize и post-check; при неопределённой
  identity/layout или несовпадении kernel/on-disk table — остановка dependent work.

Этот документ не содержит write commands и не объявляет их выполненными.
Retained labs со strict absolute-path helpers не перемещать обычным mv;
не выполнять blanket Docker prune или массовое удаление snapshots. Состояние
таймера не требует повторного install/manual capture; первый scheduled результат
проверяется отдельно после07Oct03:30MSK.
