# Карта server1 и хранение перед новым стендом

Текущая команда владельца **06.10.2026 00:45:07 MSK /05.10 21:45:07 UTC**: до нового стенда оптимизировать хранение, обновить карту сервера и настроить автобэкапы на HDD. Это заменяет прежний порядок «регулярные копии в самом конце». `PR87 --prepare-stage` приостановлен. Прямого SSH у агента нет; ниже датированный вывод владельца, а не live query.

## Диски и размещение

| Объект | Полученные данные | Вывод / следующее действие |
|---|---|---|
| SSD `/dev/sda` | WALRAM 120GB,111,8GiB | Считать границы/тип partition table; не изменять до проверки свободного диапазона и recovery |
| `/dev/sda1` | 1MiB, filesystem/mount не показаны | Назначение проверить по типу раздела; не удалять |
| `/dev/sda2` → `/` | partition50GiB, ext4; df49G/36G used/11G available/78%; lsblk10,5G available | Home, USTAR, export и labs расположены здесь |
| Ёмкость SSD вне показанных размеров разделов | около61,8GiB, арифметическая разница | Возможный unallocated tail, **не подтверждённый free extent**; нужны start/end sectors |
| HDD `/dev/sdb` | WDC WD5000AAKS,465,8GiB; child partitions/FSTYPE/mount не показаны | Persistent identity из предыдущего evidence: WWN0x50014ee200c1ee59,serial WD-WCAS82914317,500106780160bytes; повторно сверить перед записью |

На HDD завершены one-zero-pattern write/read0errors и newest extended SMART Completed without error16385h. Последний SMART20:44:52UTC: reallocated/pending/CRC0, Offline_Uncorrectable198=1; причина residual не установлена. Это не гарантия долговечности. Новые копии на HDD дополняют source на SSD; единственные важные архивы нельзя удалять с SSD до проверки независимой копии. Старый отдельно сохранённый/decrypted04Oct архив не подтверждает наличие всех последующих исторических snapshots.

## Крупные каталоги

Размеры округлены `du`; вложенные строки **не суммировать повторно**. Имена backup-каталогов ещё не доказывают отсутствие live consumers.

| Путь | Размер | Назначение / план |
|---|---:|---|
| `/home/aduk` | 15G | Основной кандидат для разбора; прежние checkout/review/backup каталоги |
| `/home/aduk/ustar-backups` | 2,9G | Два tasks-rc snapshots2,3G/604M входят в эту сумму |
| Прочие показанные home snapshots | около10,2GiB | 18 каталогов PRE_RC43/backup,497–603MiB каждый; вместе с предыдущей строкой около13,1GiB кандидатов, не обещание освобождения |
| `/opt/ustar/backups` | 1,8G | Проверить consumers, комплектность и независимые copies |
| `/opt/ustar/releases` | 828M | Проверить active release/rollback references перед архивированием |
| `/opt/ustar/git` | 103M | Рабочий checkout; сохранить deployment references |
| `/opt/ustar/data` | 1,3G | Рабочие данные Академии, предварительно остаются на SSD |
| `/var/lib/ustar-backup-export` | 1,9G | Encrypted manual exports; перенос только с hashes и adaptation producer/consumers |
| `/var/lib/ustar-restore-lab` | 2,3G | Retained lab; source code/data/PG и pinned absolute paths связаны с helpers |
| `/var/lib/ustar-cron-lab` | 2,2G | Cron rehearsal/evidence; mounted/in-use status и references требуют проверки |
| `/var/lib/containerd` | 2,0G | Container images/snapshots; blanket prune не предусмотрен |
| `/var/log` | 760M | journal714M входит в сумму; второстепенный расход относительно snapshots |
| `/var/lib/mysql` | 209M | Shared-host service data, сохраняются |
| `/var/cache/apt` | 116M | Небольшой cache; не основной источник дефицита |

## Приложения, сеть и эксплуатационные точки

Эта часть перенесена из **предыдущего** evidence05Oct и должна быть сверена свежим Docker mount/port/service inventory. Ubuntu24.04.4,Linux6.8.0-139-generic,31GiB RAM. Host Apache обслуживает Academy HTTP/LAN через127.0.0.1:8082→`ustar_moodle`:80. PostgreSQL16.15 — `ustar_postgres`, опубликованный host port ранее отсутствовал. Важны также Apache/mail/DNS/ISPConfig/MariaDB/amavis; Caddy не был TLS proxy Академии. Текущие status/ports этих служб пока не переизмерены.

Production bind paths: `/opt/ustar/data/moodle/public`→`/var/www/html`, `/opt/ustar/data/moodle/moodledata`→`/var/www/moodledata`. PostgreSQL volume, все stopped/running container mounts и host consumers нужно получить из metadata. Не публиковать полные `docker inspect`, env/config contents или credentials.

Cron v3 — `/usr/local/sbin/USTAR_CRON_SETUP_20261005.py`; один worker, EX `/run/ustar-cron/lock` и SH `/run/ustar-backup/lock`. Backup EX того же backup lock. Installation/schedule/container bindings не менять ради storage inventory. Источники: [wrapper contract](recovery_contract_20261005.md), [server](server.yaml), [Docker](docker.yaml), [dated evidence](evidence_20261005_baseline.yaml).

## Автобэкапы: подготовка, ещё не установка

Existing `/usr/local/sbin/USTAR_BACKUP_SFTP_20261004.py`, SHA0d31e694db8bee2309072bd00dee2423022f35e18ad2c11ea1cf788fc63ef47f, проверен по полному source. Он использует SSD staging `/var/lib/ustar-backup/work-*`, export `/var/lib/ustar-backup-export`, жёсткую quota6GiB, не имеет schedule/rotation/HDD mount identity guard. Просто включить timer старого вызова недостаточно.

Целевой контракт:

- HDD mount по UUID с проверкой exact device identity, filesystem и mounted source перед backup; отсутствие/подмена HDD даёт отказ **до staging и остановки Moodle**, без fallback на SSD mountpoint.
- Private staging больших payloads и encrypted archives на HDD; public age recipient используется для encryption, private recovery identity в архив не включается. Recovery marker должен оставаться доступен на SSD при пропадании HDD, чтобы Moodle можно было возобновить. Нельзя слепо перенести весь старый STATE вместе с marker.
- DB/code/config/Moodledata/exact images копируются согласованно с existing backup/cron lock; во время capture возможна короткая пауза Academy, затем resume и HTTP readiness. Shared mail/DNS/ISPConfig не останавливаются.
- Предварительная schedule policy: ежедневно03:30 Europe/Moscow; retention7 daily/4 weekly/3 monthly. Это предлагаемые настройки, **не включённый timer**. Оценить first-copy size/duration/free quota; не выполнять пропущенный ночной backup днём без отдельного окна.
- Сначала успешная пробная копия на HDD, readback hashes/archive verification и проверка ошибок missing disk/full disk/busy lock/resume; затем расписание. Rotation только recognized completed archives после успешного нового capture, never last successful copy, failed staging отдельно ограничено и заметно.
- Статус last success/failure/age виден оператору; внешние уведомления не объявлены настроенными. Fresh full boot/restore последней копии проверяется на последующем стенде и остаётся отдельным acceptance.
- HDD в том же host — не независимый внешний DR экземпляр. Автоматические uploads на SERVEREXPRESS не вводятся.

## Порядок продолжения

1. Считать partition tables, Docker state/mounts/ports, listeners/timezone/timers; заполнить карту и проверить consumers кандидатов.
2. Подготовить HDD storage и копию необходимых recovery inputs; после sector evidence выбрать, нужно ли расширять ext4 root за счёт SSD tail. Expansion сейчас не выполнено и не выдано как команда.
3. Переносить named архивы через copy→hash/metadata verification→consumer update→проверку независимой copy→точечное освобождение исходников. Retained labs со строгими absolute-path guards не перемещать обычным `mv`.
4. Проверить и включить автобэкапы; обновить карту путей/UUID/schedules/recovery procedure. Затем пересчитать budget и вернуться к стенду/Moodle/OS/HTTPS.

Owner authorization покрывает этот storage/map/automatic-backup проход. Production DB hardening, массовая очистка Docker, продуктовые изменения и удаление истории не добавлены в scope. Форматирование, переносы, расширение SSD и timer **ещё не выполнены**.
