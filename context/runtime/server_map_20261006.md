# Карта server1 и хранение перед новым стендом

Текущая команда владельца **06.10.2026 00:45:07 MSK /05.10 21:45:07 UTC**: до нового стенда оптимизировать хранение, обновить карту сервера и настроить автобэкапы на HDD. Это заменяет прежний порядок «регулярные копии в самом конце». `PR87 --prepare-stage` приостановлен. Прямого SSH у агента нет; ниже датированный вывод владельца, а не live query.

## Диски и размещение

| Объект | Полученные данные | Вывод / следующее действие |
|---|---|---|
| SSD `/dev/sda` | WALRAM 120GB,111,8GiB; GPT, stale backup boundary | Sector inventory получен; перед отдельной repair/growth операцией сохранить recovery и partition-table copy |
| `/dev/sda1` | 1MiB, BIOS boot, sectors2048–4095 | Сохранить; не удалять |
| `/dev/sda2` → `/` | partition50GiB, ext4; df49G/36G used/11G available/78%; lsblk10,5G available | Home, USTAR, export и labs расположены здесь |
| Ёмкость SSD вне показанных размеров разделов | 61.79GiB free tail по start/end sectors | Подтверждённый свободный partition range; GPT repair/partition growth/fs resize ещё не выполнялись |
| HDD `/dev/sdb1` → `/srv/ustar-storage` | ext4 `ustar-hdd`, UUID `359a2bae-4e79-461a-ab72-1597f605d801`; latest df458G/913M used/452G available/1% | WWN0x50014ee200c1ee59,serial WD-WCAS82914317,500106780160bytes; mount/fstab/existing-copy SHA PASS; producer install/fresh trial/timer pending |

Свежий `fdisk -l` уточнил SSD: `/dev/sda` — 234441648 sectors; GPT имеет устаревшую
backup-table boundary (PMBR mismatch `104857599 != 234441647`), а `/dev/sda2`
заканчивается на 104855551. Поэтому свободный хвост до стандартного GPT usable end
составляет 129586062 sectors ≈ 61.79 GiB. Это хороший кандидат на расширение root,
но сначала нужны сохранённая partition-table copy, HDD/backup readiness и отдельная
операция relocate GPT → grow partition → ext4 resize. `/dev/sda1` подтверждён как
BIOS boot и не подлежит удалению.

Владелец выполнил [one-time initializer](hdd_initialize_20261006.md):
`EMPTY_HDD_CHECK_PASS` → `FILESYSTEM_READY`, GPT/ext4 и UUID созданы. Повторять
initializer не нужно. Следующий owner output, полученный06Oct03:07:20MSK/00:07:20UTC,
подтвердил отдельный mount по UUID: `rw,nosuid,nodev,noexec,relatime`,453G доступны.
В выполненной команде mount root установлен `root:root/0700`. Время выполнения
самой команды отдельно не напечатано. Следующее owner сообщение06Oct03:33:41MSK
подтвердило `FSTAB_CONFIGURED=PASS`, private fstab backup
`/etc/fstab.before-ustar-hdd-c2kb8T`, source/copy SHA и `HDD_ARCHIVE_COPY=PASS`;
452G доступны. Reboot/mount-after-boot не проверялись. [Новый HDD producer и trial](hdd_backup_20261006.md)
подготовлены; их server execution ещё не подтверждён.

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

Свежий container inventory показывает четыре running containers: production
`ustar_moodle` (127.0.0.1:8082) и `ustar_postgres` (без host port), retained lab web/PG
под `/var/lib/ustar-restore-lab/20261004T123406Z-741e3f72`. Production Apache слушает
80/443; локальные/shared services включают Postfix/Dovecot, BIND, MariaDB,
memcached, spamd/postgrey и Caddy management 2019. Никаких остановок или cleanup
ради storage inventory не выполнялось.

Свежая временная зона host — `Etc/UTC`, NTP synchronized. В системных timers 18
записей; managed USTAR backup timer отсутствует. Предложение ежедневного 03:30
Europe/Moscow означает 00:30 UTC и будет установлено только после HDD trial.

Cron v3 — `/usr/local/sbin/USTAR_CRON_SETUP_20261005.py`; один worker, EX `/run/ustar-cron/lock` и SH `/run/ustar-backup/lock`. Backup EX того же backup lock. Installation/schedule/container bindings не менять ради storage inventory. Источники: [wrapper contract](recovery_contract_20261005.md), [server](server.yaml), [Docker](docker.yaml), [dated evidence](evidence_20261005_baseline.yaml).

## Автобэкапы: подготовка, ещё не установка

Existing `/usr/local/sbin/USTAR_BACKUP_SFTP_20261004.py`, SHA0d31e694db8bee2309072bd00dee2423022f35e18ad2c11ea1cf788fc63ef47f, повторно проверен по полному source. Старый самостоятельный вызов использует SSD staging/export и quota6GiB. [Новый adapter](hdd_backup_20261006.md) загружает точный installed engine без изменения его файла; anchored HDD staging/managed export100GiB quota, SSD marker и отдельные HDD manifests/status подготовлены/29 local tests PASS. Server install/check/fresh trial pending; timer/retention пока не установлены.

Целевой контракт:

- HDD mount и fstab configuration уже подтверждены; boot verification ещё впереди. Producer проверяет exact device identity, filesystem и mounted source до staging и повторно перед паузой Moodle; missing/changed mount отказывает, anchored filesystem не допускает SSD fallback. Server execution этой защиты ещё не проверен.
- Private staging больших payloads и encrypted archives на HDD; public age recipient используется для encryption, private recovery identity в архив не включается. Recovery marker должен оставаться доступен на SSD при пропадании HDD, чтобы Moodle можно было возобновить. Нельзя слепо перенести весь старый STATE вместе с marker.
- DB/code/config/Moodledata/exact images копируются согласованно с existing backup/cron lock; во время capture возможна короткая пауза Academy, затем resume и HTTP readiness. Shared mail/DNS/ISPConfig не останавливаются.
- Предварительная schedule policy: ежедневно03:30 Europe/Moscow; retention7 daily/4 weekly/3 monthly. Это предлагаемые настройки, **не включённый timer**. Оценить first-copy size/duration/free quota; не выполнять пропущенный ночной backup днём без отдельного окна.
- Сначала успешная пробная копия на HDD, readback hashes/archive verification и проверка ошибок missing disk/full disk/busy lock/resume; затем расписание. Rotation только recognized completed archives после успешного нового capture, never last successful copy, failed staging отдельно ограничено и заметно.
- Статус last success/failure/age виден оператору; внешние уведомления не объявлены настроенными. Fresh full boot/restore последней копии проверяется на последующем стенде и остаётся отдельным acceptance.
- HDD в том же host — не независимый внешний DR экземпляр. Автоматические uploads на SERVEREXPRESS не вводятся.

## Порядок продолжения

1. Выполненные fstab и existing05Oct archive copy/hash не повторять. Установить [HDD producer](hdd_backup_20261006.md), выполнить guarded check и fresh capture trial; получить report/hash/bytes/pause/login status.
2. Проверить consumers кандидатов и recovery inputs, сохранить partition-table copy; sector range61.79GiB уже подтверждён. Подготовить отдельную SSD GPT repair/root expansion процедуру; expansion ещё не выполнялось.
3. Переносить named архивы через copy→hash/metadata verification→consumer update→проверку независимой copy→точечное освобождение исходников. Retained labs со строгими absolute-path guards не перемещать обычным `mv`.
4. Проверить и включить автобэкапы; обновить карту путей/UUID/schedules/recovery procedure. Затем пересчитать budget и вернуться к стенду/Moodle/OS/HTTPS.

Owner authorization покрывает этот storage/map/automatic-backup проход. Production DB hardening, массовая очистка Docker, продуктовые изменения и удаление истории не добавлены в scope. HDD GPT/ext4/mount/fstab и копирование прежнего архива **выполнены владельцем**. Fresh producer/trial, удаление исходников, расширение SSD и timer пока без execution evidence.
