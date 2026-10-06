# Копия исходной разметки SSD перед GPT/root growth

Статус: **выполнено владельцем, GPT_COPY_READBACK=PASS; copy не повторять**.
Вывод received06Oct09:06:01UTC: `/srv/ustar-storage/ssd-layout-20261006-06NYpg`,
девять payload hash checks `OK`, source cmp/sync завершены в guarded command.
[Sanitized result](ssd_partition_table_copy_result_20261006.json); время capture
находится в private файле и отдельно не напечатано. SSD write этим шагом не выполнялся.
Основание — [первый SSD inventory](ssd_storage_result_20261006.json) и
[полная identity/ext4 geometry](ssd_identity_ext4_result_20261006.json),
owner message received06Oct08:36:58UTC. Прямого SSH у агента нет.

## Что сохраняется

SSD pin: WALRAM120GB, serial `2203JPDG120GB6000933`,120034123776bytes,
sector512; root UUID `fb5cc206-93f8-4601-a415-8d418970cf31`, GPT UUID
`074edcd7-fe7c-4674-9865-6425931232d7`. Zero WWN не используется как pin.
Первый проход ещё показывал old alternate GPT boundary104857599, first/last
usable34/104857566. Перед чтением команда проверяет эти raw primary-header fields;
при изменении identity/geometry останавливается без partition write.

| Файл / диапазон | Назначение |
|---|---|
| `raw-lba-0-count-4096.bin`, sectors0..4095 | Исходный PMBR, primary GPT, boot gap и содержимое BIOS boot `/dev/sda1`; root начинается4096 и не захватывается |
| `raw-lba-104855552-count-2048.bin`, sectors104855552..104857599 | Исходный gap после конца root104855551 и GPT у старого disk boundary |
| `raw-lba-234439600-count-2048.bin`, sectors234439600..234441647 | Исходные последние1MiB физического SSD, включая место будущего secondary GPT |
| `sgdisk.bin` | Дополнительный native GPT backup; это in-memory representation программы, не замена raw regions |
| `layout.sfdisk`, `layout.json`, `identity.json`, `ext4-header.txt`, `captured-utc.txt` | Логическое описание, точные UUID/размеры и время capture |
| `SHA256SUMS` | Hash manifest всех перечисленных файлов, проверенный после HDD sync |

Raw regions занимают4MiB. HDD должен уже быть mounted по exact UUID
`359a2bae-4e79-461a-ab72-1597f605d801`, root:root0700. После `cd` проверяется
`st_dev` CWD против UUID block-device `st_rdev`; дальнейшие output paths относительны
этому CWD. Fresh private `mktemp -d` не перезаписывает существующую копию.
Отсутствующий mount не заменяется записью в root fallback directory.

`dd` читает SSD и пишет только новые regular files; `cmp` повторно читает те же
source regions и сравнивает их побайтово. `sgdisk --pretend --backup` не записывает
изменения на block device. Не вызываются GPT repair, partition growth, ext4 resize,
reboot, stop services, cron или новый Academy backup. Живые FS counters не атомарны;
комплект не является full SSD image, согласованной копией файлов или shared-host DR.

Native backup semantics: [Ubuntu sgdisk manual](https://manpages.ubuntu.com/manpages/noble/man8/sgdisk.8.html)
(backup текущих in-memory structures; `--pretend` исключает disk changes).
[util-linux v2.39.3 GPT source](https://github.com/util-linux/util-linux/blob/v2.39.3/libfdisk/src/gpt.c)
пересчитывает alternate LBA during probe; поэтому отдельно сохраняются raw sectors
исходного old boundary. [sfdisk manual](https://man7.org/linux/man-pages/man8/sfdisk.8.html)
описывает text dump и binary sector backups как разные способы.

Локально проверены Bash/Python syntax, identity/geometry guard и три raw region
captures/cmp на sparse **regular file**, включая исключение root data и общий4MiB
size. Native `sgdisk` локально не исполнялся: его mode проверен по manual;
реальный owner device/mount/copy result теперь получен. Это не локальный native test
и не проверка GPT CRC — эти checks входят в [следующий шаг](ssd_gpt_relocate_20261006.md).

## Команда владельцу

Authenticate отдельно:

```bash
sudo -v
```

Следующий блок — одна физическая строка:

```bash
sudo -n timeout 45s bash -c 'set -euo pipefail; umask 077; test "$(lsblk -dnro SERIAL /dev/sda)" = 2203JPDG120GB6000933 || { echo "STOP: SSD serial"; exit 1; }; test "$(blockdev --getsize64 /dev/sda)" = 120034123776 && test "$(blockdev --getss /dev/sda)" = 512 || { echo "STOP: SSD size/sector"; exit 1; }; test "$(findmnt -nro SOURCE --mountpoint /)" = /dev/sda2 && test "$(blkid -p -s UUID -o value /dev/sda2)" = fb5cc206-93f8-4601-a415-8d418970cf31 || { echo "STOP: root identity"; exit 1; }; python3 -c "import os,struct,uuid; f=os.open(\"/dev/sda\",os.O_RDONLY); h=os.pread(f,512,512); assert h[:8]==b\"EFI PART\" and struct.unpack_from(\"<QQQQ\",h,24)==(1,104857599,34,104857566) and struct.unpack_from(\"<QII\",h,72)==(2,128,128) and str(uuid.UUID(bytes_le=h[56:72]))==\"074edcd7-fe7c-4674-9865-6425931232d7\",\"STOP: GPT identity/geometry changed\"; t=os.pread(f,256,1024); assert struct.unpack_from(\"<QQ\",t,32)==(2048,4095) and struct.unpack_from(\"<QQ\",t,160)==(4096,104855551) and str(uuid.UUID(bytes_le=t[16:32]))==\"4b6f77ed-4dce-4b46-9a59-f05ce93d11bc\" and str(uuid.UUID(bytes_le=t[144:160]))==\"e32f3b41-9dde-4d67-8c64-57540c2518cb\",\"STOP: partition layout changed\"; os.close(f)"; ustar_uuid=359a2bae-4e79-461a-ab72-1597f605d801; test ! -L /srv/ustar-storage && test "$(findmnt -nro UUID --mountpoint /srv/ustar-storage)" = "$ustar_uuid" || { echo "STOP: HDD mount"; exit 1; }; test "$(readlink -f /dev/disk/by-uuid/$ustar_uuid)" = "$(readlink -f /dev/disk/by-id/wwn-0x50014ee200c1ee59-part1)" || { echo "STOP: HDD device"; exit 1; }; cd /srv/ustar-storage; test "$(stat -c %d .)" = "$(stat -Lc %r /dev/disk/by-uuid/$ustar_uuid)" && test "$(stat -c %u:%g:%a .)" = 0:0:700 || { echo "STOP: HDD filesystem/permissions"; exit 1; }; ustar_copy=$(mktemp -d ssd-layout-20261006-XXXXXX); cd "$ustar_copy"; date -u +%FT%TZ > captured-utc.txt; lsblk --json --bytes -o PATH,TYPE,SIZE,MODEL,SERIAL,WWN,UUID,PARTUUID /dev/sda > identity.json; sfdisk --dump /dev/sda > layout.sfdisk; sfdisk --json /dev/sda > layout.json; dumpe2fs -h /dev/sda2 > ext4-header.txt 2>&1; for ustar_region in 0:4096 104855552:2048 234439600:2048; do ustar_start=${ustar_region%:*}; ustar_count=${ustar_region#*:}; ustar_raw=raw-lba-$ustar_start-count-$ustar_count.bin; dd if=/dev/sda of="$ustar_raw" bs=512 skip="$ustar_start" count="$ustar_count" iflag=fullblock conv=fsync status=none; test "$(stat -c %s "$ustar_raw")" -eq "$((ustar_count*512))"; cmp -- "$ustar_raw" <(dd if=/dev/sda bs=512 skip="$ustar_start" count="$ustar_count" iflag=fullblock status=none); done; sgdisk --pretend --backup=sgdisk.bin /dev/sda; test -s sgdisk.bin; sha256sum raw-*.bin sgdisk.bin layout.sfdisk layout.json identity.json ext4-header.txt captured-utc.txt > SHA256SUMS; sync -f .; sha256sum --check SHA256SUMS; printf "GPT_COPY_DIR=%s\nGPT_COPY_READBACK=PASS\n" "$PWD"'
```

Ожидаемые final markers: `GPT_COPY_DIR=/srv/ustar-storage/ssd-layout-20261006-...`
и `GPT_COPY_READBACK=PASS`, все строки `SHA256SUMS` заканчиваются `OK`.
GPT warnings из readonly `sfdisk` и `sgdisk` сохраняются; фраза про future write
не означает выполненный repair. При STOP/timeout/error partial folder сохраняется
для разбора, successful copy не приписывается. Предоставить final output; бинарные
boot/GPT files и полный header log в Git не публиковать.

## Перед следующим изменяющим шагом

Owner confirmation копии и доступ сисадмина к консоли уже получены. Конкретный
rescue boot medium и фактический boot recovery отдельно не проверены. Academy
archive не содержит всю почту/DNS/ISPConfig; GPT copy их не заменяет.
Копия на HDD в том же server не является independent DR экземпляром, residual
SMART198=1 остаётся в [карте](server_map_20261006.md). Не уменьшать выросшую ext4
возвратом старой таблицы: rollback depends on phase и требует отдельной процедуры.
Записывающая команда вынесена в [GPT relocation runbook](ssd_gpt_relocate_20261006.md).

Последовательность затем: проверить GPT CRC/layout → guarded relocate secondary
GPT/PMBR → перечитать usable end/сохранить sda1 и start/UUID sda2 → grow partition →
сверить on-disk и kernel-visible size → online ext4 growth, если её verified features
поддерживаются установленными kernel/tools → df/mount/службы/cron/post-check.
Ни один из этих изменяющих шагов ещё не объявлен выполненным.
