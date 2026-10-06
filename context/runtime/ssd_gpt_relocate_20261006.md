# Первый изменяющий шаг SSD: перенос резервной GPT

Статус: **команда подготовлена и локальные guards проверены; выполнение на сервере ещё не подтверждено**.
[Исходная копия](ssd_partition_table_copy_result_20261006.json) подтверждена владельцем
06Oct09:06:01UTC: `GPT_COPY_READBACK=PASS`, девять hash checks `OK`, каталог
`/srv/ustar-storage/ssd-layout-20261006-06NYpg`. Время capture сохранено в private
файле, в присланном выводе не напечатано. Owner09:03:36UTC подтвердил доступ
сисадмина к консоли; способ доступа и конкретный rescue medium отдельно не названы.
Это не повторный запрос разрешения: storage work уже в согласованном scope.

## Изменение и проверка

Единственная записывающая в SSD команда — `sgdisk --move-second-header /dev/sda`.
Она переносит secondary GPT к физическому концу SSD и обновляет связанные header/PMBR
поля. Оба существующих раздела, их start/end/type/GUID/attributes/labels и ext4 size
на этом шаге сохраняются. `growpart` и `resize2fs` здесь не вызываются.
Службы и containers командой не останавливаются; ожидаемый downtime не требуется.
Root остаётся прежних50GiB; добавленная usable GPT range ещё не используется FS.

Основание: [Ubuntu Noble sgdisk manual](https://manpages.ubuntu.com/manpages/noble/man8/sgdisk.8.html)
для `-e`/`--move-second-header` и `--pretend`. Это документация контракта, не
подтверждение точной версии installed binary. CRC читаются независимо от utility:
выход `sgdisk --verify` сам по себе не является единственным PASS gate.

Перед write проверяются SSD serial/capacity/sector, root FS UUID, mounted HDD UUID
и WWN path, CWD filesystem/0700, точный private copy directory и её hash manifest.
Все три raw source regions должны совпасть с HDD copies. Python читает raw primary
и old secondary GPT: header CRC, обе table CRC, mutual pointers, usable bounds,
GPT GUID, pinned starts/PARTUUID и kernel-visible geometry. Hybrid MBR не принимается.
Advisory nonblocking `flock` блокирует только cooperating operations, не все чужие
partition tools; одновременно другую работу с разметкой SSD не запускать.

После write проверяются raw primary/secondary headers и arrays на новом конце,
оба header/table CRC, exact entry array против original copy, прежние kernel sizes,
MBR boot code/signature bytes0..445 и boot area sectors34..4095 против копии.
Для этой pinned128×128 GPT usable end ожидается234441614; далее выводится actual
`sfdisk --dump`. Это ожидаемая геометрия текущего шага, не выполненный root growth.

Локальная проверка: Bash/Python syntax PASS и17 guard scenarios PASS на sparse
**regular file**: original/симулированная relocated geometry, raw-copy mismatch,
header/table corruption, wrong GUID/start, kernel mismatch, PMBR type, changed
boot bytes и post-write corruption. Root-data sentinel сохранён, precheck read-only.
Native `sgdisk` здесь не исполнялся; действие `-e` проверено по первичной документации,
реальные device/kernel results должен прислать владелец. CI этой docs delivery не
подменяет проверку server write.

## Команда владельцу

Authenticate отдельно:

```bash
sudo -v
```

Следующий блок — одна физическая строка:

```bash
sudo -n timeout 45s flock --exclusive --nonblock /dev/sda bash -c 'set -euo pipefail; umask 077; test "$(lsblk -dnro SERIAL /dev/sda)" = 2203JPDG120GB6000933 && test "$(blockdev --getsize64 /dev/sda)" = 120034123776 && test "$(blockdev --getss /dev/sda)" = 512 || { echo "STOP: SSD identity"; exit 1; }; test "$(findmnt -nro SOURCE --mountpoint /)" = /dev/sda2 && test "$(blkid -p -s UUID -o value /dev/sda2)" = fb5cc206-93f8-4601-a415-8d418970cf31 || { echo "STOP: root identity"; exit 1; }; ustar_uuid=359a2bae-4e79-461a-ab72-1597f605d801; test ! -L /srv/ustar-storage && test "$(findmnt -nro UUID --mountpoint /srv/ustar-storage)" = "$ustar_uuid" && test "$(readlink -f /dev/disk/by-uuid/$ustar_uuid)" = "$(readlink -f /dev/disk/by-id/wwn-0x50014ee200c1ee59-part1)" || { echo "STOP: HDD mount/device"; exit 1; }; cd /srv/ustar-storage; test "$(stat -c %d .)" = "$(stat -Lc %r /dev/disk/by-uuid/$ustar_uuid)" && test "$(stat -c %u:%g:%a .)" = 0:0:700 || { echo "STOP: HDD filesystem/permissions"; exit 1; }; test ! -L ssd-layout-20261006-06NYpg && test "$(stat -c %u:%g:%a ssd-layout-20261006-06NYpg)" = 0:0:700 || { echo "STOP: backup directory"; exit 1; }; cd ssd-layout-20261006-06NYpg; sha256sum --check --strict SHA256SUMS; ustar_check="import os,sys,struct,zlib,uuid,json; from pathlib import Path; stage=sys.argv[1]; stop=lambda ok,msg: ok or sys.exit(\"STOP: \"+msg); end=104857599 if stage==\"before\" else 234441647; fd=os.open(\"/dev/sda\",os.O_RDONLY); get=lambda off,n: os.pread(fd,n,off); u32=lambda x,o: struct.unpack_from(\"<I\",x,o)[0]; head=Path(\"raw-lba-0-count-4096.bin\").read_bytes(); stop(len(head)==2097152,\"backup head size\"); stop(all(get(start*512,count*512)==Path(\"raw-lba-\"+str(start)+\"-count-\"+str(count)+\".bin\").read_bytes() for start,count in [(0,4096),(104855552,2048),(234439600,2048)]) if stage==\"before\" else get(0,446)==head[:446] and get(17408,2079744)==head[17408:],\"raw source or boot bytes differ\"); h=get(512,512); b=get(end*512,512); stop(all(len(x)==512 and x[:8]==b\"EFI PART\" and struct.unpack_from(\"<II\",x,8)==(65536,92) for x in [h,b]),\"GPT headers\"); stop(all(zlib.crc32(x[:16]+bytes(4)+x[20:92])==u32(x,16) for x in [h,b]),\"GPT header CRC\"); stop(struct.unpack_from(\"<QQQQ\",h,24)==(1,end,34,end-33) and struct.unpack_from(\"<QQQQ\",b,24)==(end,1,34,end-33),\"GPT header bounds\"); stop(struct.unpack_from(\"<QII\",h,72)==(2,128,128) and struct.unpack_from(\"<QII\",b,72)==(end-32,128,128),\"GPT table bounds\"); stop(h[56:72]==b[56:72] and str(uuid.UUID(bytes_le=h[56:72]))==\"074edcd7-fe7c-4674-9865-6425931232d7\",\"GPT UUID\"); t=get(1024,16384); stop(t==head[1024:17408] and t==get((end-32)*512,16384) and zlib.crc32(t)==u32(h,88)==u32(b,88),\"partition table or CRC differs\"); m=get(0,512); stop(m[510:]==bytes([85,170]) and m[450]==238 and struct.unpack_from(\"<II\",m,454)==(1,end) and m[462:510]==bytes(48),\"protective MBR\"); stop(struct.unpack_from(\"<QQ\",t,32)==(2048,4095) and struct.unpack_from(\"<QQ\",t,160)==(4096,104855551),\"partition bounds\"); stop(str(uuid.UUID(bytes_le=t[16:32]))==\"4b6f77ed-4dce-4b46-9a59-f05ce93d11bc\" and str(uuid.UUID(bytes_le=t[144:160]))==\"e32f3b41-9dde-4d67-8c64-57540c2518cb\",\"partition UUIDs\"); stop(int(Path(\"/sys/class/block/sda1/start\").read_text())==2048 and int(Path(\"/sys/class/block/sda1/size\").read_text())==2048 and int(Path(\"/sys/class/block/sda2/start\").read_text())==4096 and int(Path(\"/sys/class/block/sda2/size\").read_text())==104851456,\"kernel partition geometry\"); os.close(fd); print(\"GPT_CHECK=\"+stage+\"; CRC=PASS; PARTITIONS_UNCHANGED=PASS; usable_end=\"+str(end-33),flush=True)"; python3 -c "$ustar_check" before; printf "GPT_RELOCATE_START=%s\n" "$(date -u +%FT%TZ)"; sgdisk --move-second-header /dev/sda; python3 -c "$ustar_check" after; test "$(blkid -p -s UUID -o value /dev/sda2)" = fb5cc206-93f8-4601-a415-8d418970cf31; sgdisk --pretend --verify /dev/sda; sfdisk --dump /dev/sda; df -hT /; printf "GPT_RELOCATED=PASS; ROOT_PARTITION_SIZE_UNCHANGED=PASS\n"'
```

Ожидаемые markers:

```text
GPT_CHECK=before; CRC=PASS; PARTITIONS_UNCHANGED=PASS; usable_end=104857566
GPT_CHECK=after; CRC=PASS; PARTITIONS_UNCHANGED=PASS; usable_end=234441614
GPT_RELOCATED=PASS; ROOT_PARTITION_SIZE_UNCHANGED=PASS
```

Перед `GPT_RELOCATE_START` ошибка означает остановку до вызова записывающей
команды. После этого marker STOP/timeout/error не трактовать как rollback: write
мог уже состояться. Сохранить полный вывод и остановить dependent partition/FS
growth; не повторять автоматически и не восстанавливать старую table вслепую.
При проблеме загрузки использовать подтверждённый доступ сисадмина и private copy;
до дальнейшего увеличения root уточнить практический rescue path, если он нужен.

Для этого первого шага проверена побайтовая raw recovery copy GPT/PMBR/BIOS boot,
но это не full SSD image или проверенный boot restore общего host. Academy archive
не содержит все shared mail/DNS/ISPConfig данные; HDD в том же server не external DR.
После ext4 growth возврат старой меньшей table может повредить FS: recovery зависит
от достигнутой фазы. Никакого automatic rollback здесь нет.

После PASS получить полный вывод. Затем подготовить отдельный шаг увеличения sda2
с неизменными start/UUID/sda1, сверить on-disk и kernel-visible size, и только после
этого — online ext4 resize и post-check web/shared services/cron. Эти операции пока
не выполнялись; timer first event07Oct03:30MSK не меняется.
