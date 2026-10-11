# Продолжение хранения после PR96 — 11.10.2026

**Текущая точка:** GPT relocation, рост раздела и online ext4 resize выполнены
владельцем. Root `/` теперь110G/37used/69available/35%; FS29304689×4096bytes,
UUID/mount/quota/raw GPT прежние. Kernel в supplied resize-window сообщил
успешное расширение без ошибок. Последний scheduled backup11Oct успешен.
Следующий шаг — read-only host/container/HTTP/cron checks и короткий browser
smoke, затем fresh stage budget и продолжение PR87 отдельного workspace.

Прямого SSH у агента нет. Основание — полный вывод владельца в сообщении
11.10.2026; время начала операции напечатано `2026-10-11T14:40:15Z`
(17:40:15 МСК). Время завершения отдельно не напечатано.
[Машиночитаемый результат](ssd_gpt_relocate_result_20261011.json).

## Точная преемственность

Изучен общий диалог [до сообщения о слиянии PR96](https://chatgpt.com/share/6acb9acf-2794-83ed-9aab-ed8de32d81b9).
В нём агент подтвердил GPT_COPY_READBACK=PASS, сообщил о слиянии
[PR96](https://github.com/cauf1l3d/ustarlms/pull/96) и дал только перенос GPT;
следующим отдельным шагом назвал увеличение корня. Владелец выполнил эту команду.
Также учтён последующий слитый [PR97](https://github.com/cauf1l3d/ustarlms/pull/97)
с полной редакцией аудита на 06.10. База этой поставки — main
`c53723c8d669a1013c518f34abf392143d211d9d`; application baseline PR76 не изменён.

| Проверка из вывода владельца | Подтверждено |
|---|---|
| HDD-копия исходной разметки | Все девять SHA256SUMS checks OK |
| До записи | GPT_CHECK=before; CRC=PASS; PARTITIONS_UNCHANGED=PASS; usable_end=104857566 |
| Записывающая операция | sgdisk --move-second-header завершился успешно |
| После записи | GPT_CHECK=after; CRC=PASS; PARTITIONS_UNCHANGED=PASS; usable_end=234441614 |
| Native verify | No problems found |
| Контрольный marker | GPT_RELOCATED=PASS; ROOT_PARTITION_SIZE_UNCHANGED=PASS |
| `/dev/sda1` BIOS boot | start=2048, size=2048 sectors, PARTUUID прежний |
| `/dev/sda2` ext4 root | start=4096, size=104851456 sectors, PARTUUID и FS UUID прежние |
| `df -hT /` | 49G total / 37G used / 11G available / 78% |

Выполненная команда дополнительно сравнивала исходную partition entry array и
boot bytes, raw CRC обеих GPT и kernel geometry. PASS относится к этому объёму,
не к восстановлению всего сервера. Предупреждение sgdisk об использовании старой
таблицы ядром не отменяет проверенный результат: границы разделов здесь не менялись.
При следующем изменении конца sda2 размер, видимый ядру, проверяется заново.

## Выполненные read-only проверки 11.10

Сначала authenticate отдельно:

```bash
sudo -v
```

Статус установленного расписания, без install/run/backup/recovery. Проверяется
точный SHA установленного scheduler. `--status` читает SSD status и systemd;
producer при загрузке также проверяет свой SHA и владельцев пути.

```bash
sudo -n timeout 20s bash -c 'set -eu; printf "%s  %s\n" 54533f6a629699e031a877b4a57f68401477a61cf20cab39512c79a2e0477b7d /usr/local/sbin/USTAR_HDD_SCHEDULE_20261006.py | sha256sum --check --strict -; python3 -u /usr/local/sbin/USTAR_HDD_SCHEDULE_20261006.py --status'
```

После первого расписания 07.10 уже прошло несколько дней. Проверить timestamp и
результат последнего scheduled report, `last_backup_success_utc`, отсутствие
recovery marker и active/enabled timer. Нельзя задним числом утверждать, что
прошли все ночные запуски: последний status не является всей историей. При ошибке
или marker остановить зависимый изменяющий storage-шаг и разобрать результат.

Предварительный расчёт расширения, также одной физической строкой. Если growpart
не установлен, команда остановится до dry-run; установка пакетов сюда не включена.

```bash
sudo -n timeout 30s flock --exclusive --nonblock /dev/sda bash -c 'set -euo pipefail; test "$(lsblk -dnro SERIAL /dev/sda)" = 2203JPDG120GB6000933 && test "$(blockdev --getsize64 /dev/sda)" = 120034123776 && test "$(blockdev --getss /dev/sda)" = 512 || { echo "STOP: SSD identity"; exit 1; }; test "$(findmnt -nro SOURCE --mountpoint /)" = /dev/sda2 && test "$(blkid -p -s UUID -o value /dev/sda2)" = fb5cc206-93f8-4601-a415-8d418970cf31 || { echo "STOP: root identity"; exit 1; }; command -v growpart >/dev/null || { echo "STOP: growpart absent"; exit 1; }; command -v resize2fs >/dev/null || { echo "STOP: resize2fs absent"; exit 1; }; date -u +%FT%TZ; sgdisk --pretend --verify /dev/sda; sfdisk --dump /dev/sda; lsblk -b -o NAME,SIZE,FSTYPE,MOUNTPOINTS /dev/sda; df -hT / /srv/ustar-storage; growpart -N /dev/sda 2'
```

`growpart -N` по [Ubuntu Noble manual](https://manpages.ubuntu.com/manpages/noble/man1/growpart.1.html)
показывает предполагаемую разметку без её записи. Владелец предоставил actual
вывод: `CHANGE`, start4096, old size104851456/end104855551, new
size234437519/end234441614. Disk GUID, sda1, root start/type/PARTUUID одинаковы
в old/new dump. Dry-run не менял размер раздела/FS. Владелец также выполнил
установленный scheduler напрямую с `--status`; отдельный SHA в этом выводе
не напечатан. [Точный новый evidence](ssd_growth_preflight_result_20261011.json).

Последний scheduled run11Oct00:30:00.847420..00:31:44.442026UTC: complete/success,
service exited0; last backup success00:31:43.444339UTC. Marker=false; timer
active/enabled, следующий12Oct00:30UTC (03:30МСК). Inactive backup oneshot после
успешного завершения ожидаем. Это подтверждает последний запуск11Oct, а не всю
историю07–10Oct и не восстановление свежего архива. Boot recovery не исполнялся.

## Выполненное увеличение раздела — не повторять

Ниже сохранена уже выполненная владельцем команда (started15:21:56UTC). Команда одной
физической строкой сохраняет **текущую relocated** разметку в новом защищённом
каталоге HDD `ssd-grow-20261011-XXXXXX`, fsync/hash/source readback до записи.
Исходную копию06Oct не перезаписывает. Проверяет identity SSD/root/HDD,
исходный массив GPT entries, обе GPT CRC/bounds и boot bytes; после изменения
требует ровно одну разницу в entry array — новый end sda2. Проверяет неизменный
FS UUID и старый ext4 block count13106432, sda1 и новый kernel size.

Внешний lock здесь — отдельный operator lockfile. Диск заблокирован для
pre/post checks и capture; перед growpart этот disk lock освобождается, поскольку
[growpart0.33 сам открывает и блокирует диск](https://sources.debian.org/src/cloud-utils/0.33-1/bin/growpart/).
Внешний `flock /dev/sda` вокруг growpart создал бы конфликт с его внутренним lock.
`--update=on` требует поддержки kernel update через partx; успешный exit утилиты
дополнительно проверяется непосредственно в sysfs/blockdev. Не меняет mount/quota
настройки, не запускает resize2fs, не останавливает службы и не перезагружает хост.

```bash
sudo -n timeout 60s flock --exclusive --nonblock /run/lock/ustar-ssd-grow.lock bash -c 'set -euo pipefail; umask 077; test "$(lsblk -dnro SERIAL /dev/sda)" = 2203JPDG120GB6000933 && test "$(blockdev --getsize64 /dev/sda)" = 120034123776 && test "$(blockdev --getss /dev/sda)" = 512 || { echo "STOP: SSD identity"; exit 1; }; test "$(findmnt -nro SOURCE --mountpoint /)" = /dev/sda2 && test "$(findmnt -nro FSTYPE --mountpoint /)" = ext4 && test "$(blkid -p -s UUID -o value /dev/sda2)" = fb5cc206-93f8-4601-a415-8d418970cf31 || { echo "STOP: root identity"; exit 1; }; ustar_uuid=359a2bae-4e79-461a-ab72-1597f605d801; test ! -L /srv/ustar-storage && test "$(findmnt -nro UUID --mountpoint /srv/ustar-storage)" = "$ustar_uuid" && test "$(readlink -f /dev/disk/by-uuid/$ustar_uuid)" = "$(readlink -f /dev/disk/by-id/wwn-0x50014ee200c1ee59-part1)" || { echo "STOP: HDD mount/device"; exit 1; }; cd /srv/ustar-storage; test "$(stat -c %d .)" = "$(stat -Lc %r /dev/disk/by-uuid/$ustar_uuid)" && test "$(stat -c %u:%g:%a .)" = 0:0:700 || { echo "STOP: HDD filesystem/permissions"; exit 1; }; test ! -L ssd-layout-20261006-06NYpg && test "$(stat -c %u:%g:%a ssd-layout-20261006-06NYpg)" = 0:0:700 || { echo "STOP: original backup directory"; exit 1; }; cd ssd-layout-20261006-06NYpg; sha256sum --check --strict SHA256SUMS; exec 8</dev/sda; flock --exclusive --nonblock 8; ustar_check="import os,sys,struct,zlib,uuid; from pathlib import Path; stage=sys.argv[1]; stop=lambda ok,msg: ok or sys.exit(\"STOP: \"+msg); end=234441647; size=104851456 if stage==\"before\" else 234437519; fd=os.open(\"/dev/sda\",os.O_RDONLY); get=lambda off,n: os.pread(fd,n,off); u32=lambda x,o: struct.unpack_from(\"<I\",x,o)[0]; head=Path(\"/srv/ustar-storage/ssd-layout-20261006-06NYpg/raw-lba-0-count-4096.bin\").read_bytes(); stop(len(head)==2097152,\"backup head size\"); stop(get(0,446)==head[:446] and get(17408,2079744)==head[17408:],\"boot bytes differ\"); h=get(512,512); b=get(end*512,512); stop(all(len(x)==512 and x[:8]==b\"EFI PART\" and struct.unpack_from(\"<II\",x,8)==(65536,92) for x in [h,b]),\"GPT headers\"); stop(all(zlib.crc32(x[:16]+bytes(4)+x[20:92])==u32(x,16) for x in [h,b]),\"GPT header CRC\"); stop(struct.unpack_from(\"<QQQQ\",h,24)==(1,end,34,end-33) and struct.unpack_from(\"<QQQQ\",b,24)==(end,1,34,end-33),\"GPT header bounds\"); stop(struct.unpack_from(\"<QII\",h,72)==(2,128,128) and struct.unpack_from(\"<QII\",b,72)==(end-32,128,128),\"GPT table bounds\"); stop(h[56:72]==b[56:72] and str(uuid.UUID(bytes_le=h[56:72]))==\"074edcd7-fe7c-4674-9865-6425931232d7\",\"GPT UUID\"); expected=bytearray(head[1024:17408]); stop(struct.unpack_from(\"<QQ\",expected,32)==(2048,4095) and struct.unpack_from(\"<QQ\",expected,160)==(4096,104855551),\"backup partition bounds\"); stop(str(uuid.UUID(bytes_le=bytes(expected[16:32])))==\"4b6f77ed-4dce-4b46-9a59-f05ce93d11bc\" and str(uuid.UUID(bytes_le=bytes(expected[144:160])))==\"e32f3b41-9dde-4d67-8c64-57540c2518cb\",\"partition UUIDs\"); struct.pack_into(\"<Q\",expected,168,4096+size-1); t=get(1024,16384); stop(t==expected and t==get((end-32)*512,16384) and zlib.crc32(t)==u32(h,88)==u32(b,88),\"partition table or CRC differs\"); m=get(0,512); stop(m[510:]==bytes([85,170]) and m[450]==238 and struct.unpack_from(\"<II\",m,454)==(1,end) and m[462:510]==bytes(48),\"protective MBR\"); stop(int(Path(\"/sys/class/block/sda1/start\").read_text())==2048 and int(Path(\"/sys/class/block/sda1/size\").read_text())==2048 and int(Path(\"/sys/class/block/sda2/start\").read_text())==4096 and int(Path(\"/sys/class/block/sda2/size\").read_text())==size,\"kernel partition geometry\"); s=get(4096*512+1024,1024); stop(len(s)==1024 and struct.unpack_from(\"<H\",s,56)[0]==61267 and u32(s,24)==2 and u32(s,4)+(u32(s,336)<<32)==13106432 and str(uuid.UUID(bytes=s[104:120]))==\"fb5cc206-93f8-4601-a415-8d418970cf31\",\"ext4 size or UUID differs\"); os.close(fd); print(\"PARTITION_CHECK=\"+stage+\"; CRC=PASS; BOOT_BYTES=PASS; KERNEL_SECTORS=\"+str(size)+\"; EXT4_SIZE_UNCHANGED=PASS\",flush=True)"; python3 -c "$ustar_check" before; ustar_copy=$(mktemp -d /srv/ustar-storage/ssd-grow-20261011-XXXXXX); cd "$ustar_copy"; dd if=/dev/sda of=raw-lba-0-count-4096.bin bs=512 count=4096 conv=fsync status=none; dd if=/dev/sda of=raw-lba-234439600-count-2048.bin bs=512 skip=234439600 count=2048 conv=fsync status=none; sgdisk --backup=sgdisk.bin /dev/sda; sfdisk --dump /dev/sda > layout.sfdisk; LC_ALL=C dumpe2fs -h /dev/sda2 > ext4-header.txt 2>/dev/null; date -u +%FT%TZ > captured-utc.txt; sha256sum raw-lba-*.bin sgdisk.bin layout.sfdisk ext4-header.txt captured-utc.txt > SHA256SUMS; sync -f .; sha256sum --check --strict SHA256SUMS; cmp -n 2097152 raw-lba-0-count-4096.bin /dev/sda; dd if=/dev/sda bs=512 skip=234439600 count=2048 status=none | cmp raw-lba-234439600-count-2048.bin -; printf "PRE_GROW_COPY=PASS; DIRECTORY=%s\n" "$ustar_copy"; flock --unlock 8; printf "PARTITION_GROW_START=%s\n" "$(date -u +%FT%TZ)"; growpart --update=on /dev/sda 2; flock --exclusive --nonblock 8; python3 -c "$ustar_check" after; test "$(blockdev --getsize64 /dev/sda2)" = 120032009728 && test "$(blkid -p -s UUID -o value /dev/sda2)" = fb5cc206-93f8-4601-a415-8d418970cf31; sgdisk --pretend --verify /dev/sda; sfdisk --dump /dev/sda; df -hT /; printf "PARTITION_GROWN=PASS; KERNEL_SIZE=PASS; EXT4_NOT_RESIZED=YES\n"'
```

Полученный конечный marker: `PARTITION_GROWN=PASS; KERNEL_SIZE=PASS;
EXT4_NOT_RESIZED=YES`. Новый partition размер120032009728bytes (~111,8GiB),
добавлено129586063sectors (~61,8GiB). `df /` на этом этапе остаётся прежним:
растёт block device, ext4 ещё не увеличивается.

При STOP/error/timeout не повторять пишущую команду, не запускать resize2fs,
не делать автоматический rollback/reboot; передать полный вывод, чтобы сначала
определить actual on-disk и kernel state. Сам growpart может попытаться вернуть
таблицу при своей ошибке; wrapper не добавляет rollback. Сохранённая GPT/boot
копия не заменяет backup/restore общих mail/DNS/ISPConfig служб.

Local: Bash/Python syntax, exact Bash→Python quoting и15 sparse regular-file
raw-guard сценариев PASS: before/after и отказы при boot/BIOS bytes, CRC,
ext4 size/UUID, kernel size, изменённом entry array с валидными CRC и прежнем
размере. Native growpart агентом не исполнялся; владелец предоставил успешный server результат ниже.

## Owner результат роста раздела — 15:21:56UTC / 18:21:56МСК

[Точный результат](ssd_partition_grow_result_20261011.json): nine original иsix
новых copy checksum checks OK; pre/post raw CRC и boot bytes PASS. Actual
growpart `CHANGED`: start4096, size104851456→234437519, end104855551→234441614.
Kernel size234437519; ext4 unchanged. Native verify «No problems found», remaining
free2014sectors (1007KiB в промежутке до sda1). `df /`49G прежний — это ожидаемо.
Finish timestamp не напечатан. Новая pre-growth копия на HDD:
`/srv/ustar-storage/ssd-grow-20261011-AiJI89`; она содержит старый меньший раздел.

В native verify есть caution про end alignment2048sectors и некоторые encryption
tools. По [объяснению автора GPT fdisk](https://www.rodsbooks.com/gdisk/walkthrough.html)
end alignment важен для LUKS encryption block size. По фактическому mount/UUID/
raw superblock корень здесь обычная ext4 на `/dev/sda2`, не crypto mapper; вывод
о неприменимости encryption-specific caution к текущему resize — интерпретация
этого установленного layout. Начало4096 сохранено; GPT CRC и native verify PASS.
Повторной правки границ ради caution в текущем ext4 шаге нет.

## Выполненный online ext4 resize — не повторять

Ниже сохранена уже выполненная владельцем команда15:35:13UTC. По [Ubuntu Noble resize2fs manual](https://manpages.ubuntu.com/manpages/noble/man8/resize2fs.8.html)
mounted ext4 можно увеличивать online при поддержке ядра; без size используется
размер раздела. Здесь kernel6.8 и ext4, новый размер partition уже проверен.
Команда проверяет exact SSD/root/kernel identity и старый block count, сохраняет
**уже выросшую GPT** в новом HDD0700 каталоге и проверяет hashes/source readback.
Затем `resize2fs /dev/sda2`, syncfs и raw superblock проверки. Ожидается
29304689×4096=120032006144bytes;3584 trailing partition bytes не составляют целый
ext4 block. Actual owner результат ниже подтвердил ровно этот block count.
Mount options/UUID и raw primary/secondary GPT сравниваются до/после; df/kernel
messages выводятся отдельно. Marker говорит о size/identity, не о full service QA.

Внешнего `timeout` на изменяющий resize нет: wrapper не обрывает online growth
по произвольному короткому сроку. Отдельный operator lockfile не конфликтует с
disk lock. При failure не повторять resize/не восстанавливать старую разметку;
сначала передать full output и определить actual состояние. Старые GPT copies
06Oct иAiJI89 после роста ext4 не являются безопасной таблицей для rollback.

```bash
sudo -n flock --exclusive --nonblock /run/lock/ustar-ssd-grow.lock bash -c 'set -euo pipefail; umask 077; test "$(lsblk -dnro SERIAL /dev/sda)" = 2203JPDG120GB6000933 && test "$(blockdev --getsize64 /dev/sda)" = 120034123776 && test "$(blockdev --getss /dev/sda)" = 512 || { echo "STOP: SSD identity"; exit 1; }; test "$(findmnt -nro SOURCE --mountpoint /)" = /dev/sda2 && test "$(findmnt -nro FSTYPE --mountpoint /)" = ext4 && test "$(blkid -p -s UUID -o value /dev/sda2)" = fb5cc206-93f8-4601-a415-8d418970cf31 || { echo "STOP: root identity"; exit 1; }; test "$(cat /sys/class/block/sda2/start)" = 4096 && test "$(cat /sys/class/block/sda2/size)" = 234437519 && test "$(blockdev --getsize64 /dev/sda2)" = 120032009728 || { echo "STOP: kernel partition size"; exit 1; }; ustar_options=$(findmnt -nro OPTIONS --mountpoint /); case ",$ustar_options," in *,rw,*) ;; *) echo "STOP: root is not writable"; exit 1;; esac; ustar_check="import os,sys,struct,uuid; stage=sys.argv[1]; stop=lambda ok,msg: ok or sys.exit(\"STOP: \"+msg); fd=os.open(\"/dev/sda2\",os.O_RDONLY); s=os.pread(fd,1024,1024); os.close(fd); u32=lambda off: struct.unpack_from(\"<I\",s,off)[0]; stop(len(s)==1024 and struct.unpack_from(\"<H\",s,56)[0]==61267 and u32(24)==2,\"ext4 header/block size\"); stop(str(uuid.UUID(bytes=s[104:120]))==\"fb5cc206-93f8-4601-a415-8d418970cf31\",\"ext4 UUID\"); blocks=u32(4)+(u32(336)<<32); stop(blocks==(13106432 if stage==\"before\" else 29304689),\"unexpected ext4 block count: \"+str(blocks)); print(\"EXT4_CHECK=\"+stage+\"; BLOCKS=\"+str(blocks)+\"; BLOCK_SIZE=4096; UUID=PASS\",flush=True)"; python3 -c "$ustar_check" before; ustar_uuid=359a2bae-4e79-461a-ab72-1597f605d801; test ! -L /srv/ustar-storage && test "$(findmnt -nro UUID --mountpoint /srv/ustar-storage)" = "$ustar_uuid" && test "$(readlink -f /dev/disk/by-uuid/$ustar_uuid)" = "$(readlink -f /dev/disk/by-id/wwn-0x50014ee200c1ee59-part1)" || { echo "STOP: HDD mount/device"; exit 1; }; cd /srv/ustar-storage; test "$(stat -c %d .)" = "$(stat -Lc %r /dev/disk/by-uuid/$ustar_uuid)" && test "$(stat -c %u:%g:%a .)" = 0:0:700 || { echo "STOP: HDD filesystem/permissions"; exit 1; }; ustar_copy=$(mktemp -d /srv/ustar-storage/ssd-ext4-20261011-XXXXXX); cd "$ustar_copy"; dd if=/dev/sda of=raw-lba-0-count-34.bin bs=512 count=34 conv=fsync status=none; dd if=/dev/sda of=raw-lba-234441615-count-33.bin bs=512 skip=234441615 count=33 conv=fsync status=none; sgdisk --backup=sgdisk-grown.bin /dev/sda; sfdisk --dump /dev/sda > layout-grown.sfdisk; LC_ALL=C dumpe2fs -h /dev/sda2 > ext4-before.txt 2>/dev/null; printf "%s\n" "$ustar_options" > root-mount-options.txt; date -u +%FT%TZ > captured-utc.txt; sha256sum raw-lba-*.bin sgdisk-grown.bin layout-grown.sfdisk ext4-before.txt root-mount-options.txt captured-utc.txt > SHA256SUMS; sync -f .; sha256sum --check --strict SHA256SUMS; cmp -n 17408 raw-lba-0-count-34.bin /dev/sda; dd if=/dev/sda bs=512 skip=234441615 count=33 status=none | cmp raw-lba-234441615-count-33.bin -; printf "GROWN_GPT_COPY=PASS; DIRECTORY=%s\n" "$ustar_copy"; ustar_started=$(date -u +%FT%TZ); printf "EXT4_RESIZE_START=%s\n" "$ustar_started"; resize2fs /dev/sda2; sync -f /; python3 -c "$ustar_check" after; test "$(findmnt -nro SOURCE --mountpoint /)" = /dev/sda2 && test "$(findmnt -nro FSTYPE --mountpoint /)" = ext4 && test "$(blkid -p -s UUID -o value /dev/sda2)" = fb5cc206-93f8-4601-a415-8d418970cf31 && test "$(findmnt -nro OPTIONS --mountpoint /)" = "$ustar_options" || { echo "STOP: mount/UUID changed"; exit 1; }; cmp -n 17408 raw-lba-0-count-34.bin /dev/sda; dd if=/dev/sda bs=512 skip=234441615 count=33 status=none | cmp raw-lba-234441615-count-33.bin -; test "$(blockdev --getsize64 /dev/sda2)" = 120032009728; findmnt -no SOURCE,FSTYPE,OPTIONS --mountpoint /; df -hT /; df -B1 --output=source,fstype,size,used,avail,pcent,target /; journalctl -k --since "$ustar_started" --no-pager -o short-iso; printf "EXT4_RESIZED=PASS; UUID_MOUNT_GPT_UNCHANGED=PASS; FINISHED=%s\n" "$(date -u +%FT%TZ)"'
```

Local syntax/quoting и11 regular-file ext4 guard cases PASS; native online resize
выполнен владельцем, не агентом. Утилита не запускает e2fsck на mounted `/`, не меняет quotas,
не останавливает services, не перезагружает хост и не очищает источники/лаборатории.

## Owner результат ext4 — 15:35:13UTC / 18:35:13МСК

[Exact evidence](ssd_ext4_resize_result_20261011.json). Native resize2fs1.47.0
online: old_desc_blocks7→new14; filesystem now29304689(4k)blocks. Pre/post raw
superblock guards PASS; UUID/mount options и primary/secondary GPT bytes прежние.
Новый grown GPT snapshot `/srv/ustar-storage/ssd-ext4-20261011-MCyX9n`: seven hashes
OK/source readback PASS. Эта копия хранит выросшую таблицу, не весь filesystem/host.
Start и finish stamps оба15:35:13UTC, точная длительность отдельно не измерялась.

| Current owner observation | Result |
|---|---|
| Root partition | 120032009728bytes,234437519sectors |
| Ext4 raw size | 29304689×4096=120032006144bytes;3584 trailing bytes |
| `df -hT /` | 110G total /37G used /69G available /35% |
| `df -B1 /` | size117815881728 /used38711189504 /avail73749991424bytes |
| Mount/quotas | rw,relatime,errors=remount-ro,jqfmt=vfsv0,usrjquota=quota.user,grpjquota=quota.group; unchanged |
| Kernel supplied window | resizing13106432→29304689, resized29304689; no error messages in this window |
| Marker | EXT4_RESIZED=PASS; UUID_MOUNT_GPT_UNCHANGED=PASS |

Raw FS bytes и df total отличаются из-за filesystem accounting; available df
также учитывает reserved blocks. Не менять reserved percentage/quotas ради цифр.
Дополнительное место принадлежит общему `/`, HDD остаётся backup target.
GPT/partition/resize команды повторно не запускать. Старые меньшие GPT copies
06Oct/AiJI89 не применять как table rollback после роста FS.

## Следующий шаг: post-resize проверки

`sudo -v` отдельно; каждая команда ниже — одна physical line. Это inventory/
status/HTTP GET, не worker/install/backup/restart/upgrade. Host services собираются
по actual loaded units, без предположения об exact имени ISPConfig/DNS unit.
Failed units и kernel warnings анализируются с timestamp; historical failure
не автоматически считается последствием resize. Ports/listeners лишь transport
evidence, не authenticated mail/DNS/panel acceptance.

```bash
sudo -n timeout 30s bash -c 'date -u +%FT%TZ; df -hT / /srv/ustar-storage; df -i /; systemctl --failed --no-pager; systemctl list-units --type=service --all --no-pager --plain --no-legend | awk "tolower(\$0) ~ /apache|docker|cron|mariadb|mysql|postfix|dovecot|bind9|named|php.*fpm|ispconfig|amavis|clamav|rspamd/"; docker ps -a --format "{{.Names}}\t{{.Status}}"; ss -lntup; journalctl -k --since "2026-10-11 15:21:56 UTC" -p warning --no-pager -o short-iso'
```

Cron v3 exact SHA из reviewed uploaded source; `--status` выполняет bounded
readonly SQL/status, без Moodle PHP task execution. Single cron не переустанавливать.

```bash
sudo -n timeout 30s bash -c 'set -eu; printf "%s  %s\n" 66fd51d364beb59a8874a63c695c122dca5452a720e61b86d070beb0b1939852 /usr/local/sbin/USTAR_CRON_SETUP_20261005.py | sha256sum --check --strict -; python3 -u /usr/local/sbin/USTAR_CRON_SETUP_20261005.py --status'
```

HTTP два известных пути: host Apache с Hostustar.local и backend127.0.0.1:8082.
Curl не следует redirects; status/ошибку рассмотреть, не объявлять любой redirect
failure. Login200 не доказывает authenticated session/file/SCORM.

```bash
bash -c 'curl --noproxy "*" --connect-timeout 3 --max-time 10 -sS -o /dev/null -w "ACADEMY_PROXY_LOGIN_HTTP=%{http_code}\n" -H "Host: ustar.local" http://127.0.0.1/login/index.php; curl --noproxy "*" --connect-timeout 3 --max-time 10 -sS -o /dev/null -w "ACADEMY_BACKEND_LOGIN_HTTP=%{http_code}\n" -H "Host: ustar.local" http://127.0.0.1:8082/login/index.php'
```

Owner browser smoke: обычный вход в Академию и открытие одного существующего
файла/SCORM, без переустановки/назначений/повторения полного baseline QA. Затем
по actual unit/listener inventory проверить необходимые mail/DNS/ISPConfig
контрольные точки. Полные env/config/inspect, ключи и dumps не публиковать.

После этих результатов — fresh PR87 `--check`/budget/identity. Current df69G
available существенно выше старого estimate9.80GiB, но fresh source/space/inodes/
memory/port guards выполняются перед cold copy. Отдельный immutable checkpoint и
mutable workspace, full-addon patch5.1.8 upgrade/repeat/paired rollback, production
patch, OS maintenance/reboot и HTTPS сохраняют согласованную очередь. Источники/
старые snapshots не удалялись; их cleanup/moves без scope решения не выполняются.
Fresh independent archive/restore, HDD residual SMART198=1, reboot-mount и full
shared-host recovery открыты отдельно.

## Остаток полного аудита

Приоритет владельца не изменён импортом [стратегических вложений](../strategy/20261011/README.md).
После выбранных storage/stage/Moodle/ОС/HTTPS остаются минимальные права БД и
защита кода/секретов; ротация/readiness; единый пульт и доставка alerts; H5P/SMTP и
полный cron cycle; ролевая/сценарная безопасность; performance baseline/load;
restore свежей scheduled copy и аварийное восстановление общего хоста.
Privacy/mobile/B2B и major/LTS — последующие отдельные задачи из BACKLOG.
