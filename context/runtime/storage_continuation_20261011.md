# Продолжение хранения после PR96 — 11.10.2026

**Текущая точка:** перенос резервной GPT выполнен владельцем; корневой раздел и
ext4 пока прежнего размера. Следующий операторский шаг — предварительный расчёт
увеличения `/dev/sda2` и проверка статуса ночных HDD-копий.

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

## Ближайшие команды: без записи разметки

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
показывает предполагаемую новую разметку без её записи. Он может создавать
временные служебные файлы; это не запуск resize. Синтаксис Bash обеих команд
проверен локально. Утилиты на реальном SSD агент не выполнял, dry-run на сервере
ещё не подтверждён. Нужен полный вывод обеих команд, без config/env/ключей/dumps.

## Следующие отдельные изменения

1. По actual dry-run сверить GPT UUID, sda1 целиком, root start/PARTUUID/type и
   новый end не выше usable_end=234441614. Исходный sda2 end=104855551;
   добавляемая ёмкость около 61,8 GiB. Проверить актуальный recovery scope общего
   хоста и практический rescue path с уже подтверждённым доступом сисадмина.
2. Подготовить отдельную guarded command увеличения только конца sda2, сохранить
   relocated pre-growth layout на HDD. Проверить независимые raw CRC/entry array,
   boot bytes, FS UUID, on-disk и kernel-visible geometry. Если ядро оставит
   меньший размер, дальнейший resize не запускать; определить путь reread/reboot.
3. После подтверждения kernel size отдельно увеличить ext4, сохранив UUID,
   mount/quota settings. Измерить df и ошибки ядра; не обещать итоговую ёмкость
   filesystem по одному размеру устройства. После роста FS старая меньшая
   partition table уже не является безопасным откатом.
4. Проверить Academy login/files/SCORM/cron, production/lab containers и нужные
   Apache/mail/DNS/ISPConfig/MariaDB службы. Корень общий: дополнительное место
   доступно всем путям на `/`; автоматического переноса данных на HDD нет.
5. Обновить карту/бюджет, вернуться к PR87 cold checkpoint и отдельному workspace,
   full-addon upgrade/repeat/paired rollback для patch5.1.8, затем production
   patch, Ubuntu maintenance/reboot и HTTPS в согласованном порядке.

Изменяющие команды growpart/resize2fs в этой поставке не подготовлены к запуску:
сначала требуется actual dry-run и свежий backup status. Перенос GPT, initializer,
fstab, producer trial и scheduler install не повторять. Источники и лаборатории
не удалялись. Fresh independent archive/restore, HDD residual SMART198=1,
reboot-mount и full shared-host recovery остаются открытыми.

## Остаток полного аудита

Приоритет владельца не изменён импортом [стратегических вложений](../strategy/20261011/README.md).
После выбранных storage/stage/Moodle/ОС/HTTPS остаются минимальные права БД и
защита кода/секретов; ротация/readiness; единый пульт и доставка alerts; H5P/SMTP и
полный cron cycle; ролевая/сценарная безопасность; performance baseline/load;
restore свежей scheduled copy и аварийное восстановление общего хоста.
Privacy/mobile/B2B и major/LTS — последующие отдельные задачи из BACKLOG.
