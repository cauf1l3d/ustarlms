# ACL, SSH, Caddy 2019, extra files и размеры — 17 октября 2026

Owner gaps ZIP получен вместе с terminal output установки acl и сбора. [Provenance](../../runtime/server_gaps_received_20261017.json), [проверенные наблюдения](../../runtime/server-gaps/20261017T105916Z-86133045/observations.json), [validation](gaps_validation.json). Terminal message датирован 14:03:05 MSK; все probes записали 10:59:16 UTC / 13:59:16 MSK с точностью 1s. Данные не atomic; это owner-executed measurements, без независимой идентификации server1 агентом.

ZIP 284 182 bytes, SHA256 `86133045248a78d56c49b4562483adac1ed11b256920b54ab47c0e9b2697de53`, 26 members. Проверены bounded/safe names/types/size, CRC и все 24 checksums: six probes × out/err/exit/meta. Все exit 0, stderr пуст. В ZIP передана папка, вложенного tar нет. Inline collector из предыдущего ответа не имеет self-hash в архиве; pinned source identity не приписывается. Uploaded scripts не выполнялись, original ZIP не изменён. Производный JSON прошёл проверку полей и внутренней согласованности; полный raw ACL не публикуется.

## ACL и риск замены кода

`getfacl -R -P -e -n -p` экспортировал **44 175 уникальных code objects** под внешним `/opt/ustar/data/moodle/public`; физический проход пропускает symlinks. Это число файлов и каталогов, не новый source manifest. Именованных ACL, default ACL и mask entries в этой выгрузке — 0. Отдельно получены семь родителей/data anchors, также без расширенных entries. Наблюдение не распространяется на все filesystem objects хоста.

| Группа | Наблюдение |
|---|---|
| UID/GID 0:0 | 39 449 объектов |
| UID/GID 33:33 | 4 726 объектов, все owner-writable |
| group-writable base permissions | 439 объектов |
| other-writable base permissions | 0 объектов |
| local/ustar subtree | 492 объекта UID/GID33:33 |
| theme/ustar subtree | 72 объекта UID/GID0:0 |

Выбранные anchors: внешний code root и `config.php` UID/GID33:33 с базовыми permissions0755; вложенный public root и theme UID/GID0:0/0755. `/opt/ustar/data/moodle` UID/GID33:33/0775, `/opt/ustar/data`1000:1000/0775, `/opt/ustar`1000:1000/0755. Moodledata33:33/0770, PG data999:1000/0700. Статистика полных mode bits из ACL не выводится: это базовые access permissions, без утверждения об immutable/file capabilities.

**INF-03 сохраняется:** владелец33 может менять directory entries во внешнем code root и Moodle parent по POSIX permissions. Это допускает замену поддерева даже при root ownership вложенных объектов. Прежнее `test -w=false` на отдельном root-owned theme/public path не доказывает защиту всех родителей. Текущий контейнерный actor/groups, mount/LSM/AppArmor, immutable bits, sticky constraints и реальные negative write/rename/replace operations новым сбором не проверялись. Действительная возможность операции требует проверки этих условий; на production ничего не переименовывалось и права не менялись.

## Дополнительные файлы и происхождение

Все **23 extra paths** из core report 10:00 UTC получили UID/GID/mode/bytes/mtime_ns/SHA256. Нет пропусков, oversized files или ошибок. UID/GID/permissions всех23 согласованы с независимо полученной ACL-выгрузкой. `wwwroot` — пустой regular file, hash SHA256 пустых bytes. `public/config.php.before_ip_change_20260830T203238Z` —1027 bytes/0755/33:33; `public/index.php.backup_ustar` —5550 bytes/0755/0:0. Содержимое config/token/helper/PDF не выгружалось.

Это закрывает отсутствие metadata/hashes, но не устанавливает безопасность/происхождение кода или доступность файлов по HTTP. Особого внимания INF-03 требуют публичные config/source backups, legacy helper/handler PHP и report PDFs; назначение и vhost/routes/access rules ещё проверить. Mtime не является надёжным временем создания, deployment или исходной версии; копирование могло изменить его. Ни GET этих handlers, ни удаления, chmod/chown или выполнения PHP не было. Excluded vendor/сторонние plugin roots требуют собственной provenance-сверки.

## SSH текущего контекста

Owner script вызвал `/usr/sbin/sshd -T -C` с user текущей aduk-сессии и addr/laddr/lport из SSH_CONNECTION; host передан как source IP. Эти адреса не экспортированы, поэтому точный tuple нельзя восстановить из архива. **UseDNS=no**, дополнительное reverse-DNS имя в этой проверке не использовалось. Полученные settings относятся к конфигурации на диске для переданного контекста; command-line overrides/фактически загруженное daemon state и другие user/IP contexts не проверялись.

Password/pubkey auth yes; kbdinteractive no; root without-password; TCP/agent/X11 forwarding yes; authenticationmethods any; strictmodes yes; empty passwords no; tunnel no; maxauthtries6/port22. Это не полная SSH admission matrix: AllowUsers/Groups/keys/real login/negative paths отдельно не принимались. INF-10 не закрывается; без отдельного основания не выполнять reload/restart, особенно shared services.

## Кто слушает 2019 и как проверить внешний адрес

Оба `ss -H -ltnp 'sport = :2019'` sockets принадлежат **process comm caddy, PID862**: `127.0.0.1:2019`/fd3 и `*:2019`/fd7. Процесс-владелец по socket metadata теперь установлен. Executable path/digest, конкретный HTTP endpoint/admin identity, auth, address family, firewall/NAT/perimeter и external reachability не установлены. `*` не доказывает отдельно IPv4+IPv6 Internet reachability. Management API requests и config dump не выполнялись.

Для owner external TCP test использовать публичный адрес server1 из PuTTY Host Name/IP либо панели хостинга/NAT. Академия может иметь другой proxy/DNS target; его автоматически не подставлять. Source SSH local address при NAT может оказаться private; public inbound IP нельзя достоверно вывести из этой выгрузки. External result ещё не получен. `Test-NetConnection -ComputerName <public-server-target> -Port 2019` с внешнего ПК проверяет только установление TCP; failure не доказывает глобальную блокировку для всех источников, success не доказывает отсутствие API authorization. Открывать порт ради теста не требуется.

## Выбранные размеры и установленный пакет

`ionice -c3 nice -n19 du -sx --block-size=1` измерил allocated bytes, не apparent size:

| Каталог | Bytes | MiB |
|---|---:|---:|
| code outer root |500744192|477.55|
| moodledata |758067200|722.95|
| PostgreSQL data dir |247816192|236.34|
| /var/lib/docker |29286400|27.93|

Это четыре выбранных пути, сумма1 535 913 984 bytes; не весь SSD и не полное потребление Docker images/containerd. Docker data-root, containerd store, labs/checkpoints, временные каталоги и другие shared services не охвачены. С ранним df snapshot01:39Z нельзя обращаться как с atomic balance. Live PG directory size не является согласованным physical backup или SQL logical DB size.

Отдельный owner apt transcript сообщает: новый acl2.3.2-1build1.1, upgraded0/removed0. Это реальная установка пакета владельцем, а не полностью неизменившийся host. Needrestart перечислил deferred services (dbus/docker/networkd-dispatcher/logind/unattended-upgrades); transcript не является поручением перезапустить Docker/Академию. Сбор после установки только читал источники и писал новый private report. Actual service restarts агентом не измерялись/не выполнялись.

## Остаток и порядок

Метаданные ACL/родителей, selected SSH context, comm владельца2019, hashes23 extras и четыре directory sizes получены. Открыты external2019/API identity/auth, ACL replacement hardening/native negatives, broader SSH/live settings, TLS/cookie/client checks, runtime component overrides, SQL stats, full storage/vendor provenance, SSD wear units и длительный baseline. Clean-host restore/RTO/key/offsite custody ожидают сисадмина. Старые plugin526/core24742/NTP/SMART/backup snapshots и валидированные originals сохранены, новый сбор их не переизмеряет.

Контекст готов к повторному аудиту владельца с явными пробелами. После review — repo work и INF-06/PR103/Telegram по прежней очереди; Moodle5.2/PG17 и Privacy остаются отложены. [Coverage](../../coverage.yaml), [STATE](../../roadmap/STATE.yaml).
