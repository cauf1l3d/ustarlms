# Свежие server facts и сверка контекста — 17 октября 2026

Оба ZIP получены от владельца04:16MSK. Проверены ZIP members/CRC, все четыре payload-файла, schema/checksums и совпадение с вложенным `report.tar.gz`. Пакеты импортированы по времени без редактирования исходных данных. [Provenance](../../runtime/server_facts_received_20261017.json), [current](../../current.yaml), [остаток аудита](../../roadmap/BACKLOG.yaml).

| Снимок UTC | Покрытие | Что изменилось |
|---|---|---|
| [01:00:21](../../runtime/server-facts/20261017T010021Z-d5152f1e/manifest.json) |17 ok,13 error, SMART not requested| Docker unavailable, packages invalid_data,11 зависимых probes не выполнялись |
| [01:12:27](../../runtime/server-facts/20261017T011227Z-6b7c0628/manifest.json) |28 ok,2 error, SMART not requested| Docker/code/DB/package probes восстановились; query stats и scheduled task list недоступны |

Источник — владелец, запустивший pinned collector на заявленном server1. Self hashes совпали с опубликованными cee34b13/1bdabba3. Это свежие owner-executed observations, без независимой идентификации хоста агентом и без functional acceptance. `ok` означает успешное получение структурированных данных, а не отсутствие риска в них.

## Исходники и версии

Свежая [сверка](../../runtime/server_facts_source_comparison_20261017T011227Z-6b7c0628.json): **526/526 plugin/theme files совпали** с e1d57f5bc28f6964afd20aeff7620345b34a80fe; changed/missing/extra/skipped0. Проход не atomic; core, config content, moodledata и database schema не покрываются этими hashes. Прежний05Oct manifest сохранён в датированном evidence, новый указан в STATE как last exact source observation.

Moodle DB release5.1.8/build20261005 и core disk2025100608.00 согласованы; local_ustar2026100204/theme_ustar2026100202 совпадают на диске и в DB. PHP CLI8.3.33, PostgreSQL server16.15, kernel6.8.0-147 подтверждены. OS сообщает24.04 без point release; пакеты docker-ce29.9.0/containerd2.4.1 установлены. Это не новое измерение Docker daemon version или полного набора host packages.

Public root `/opt/ustar/data/moodle/public/public`; config отдельно `/opt/ustar/data/moodle/public/config.php`; moodledata `/opt/ustar/data/moodle/moodledata`; DB/prefix `moodle`/`mdl_`. Статические config literals, DB config и effective web settings различаются: DB cookiesecure=1 не доказывает secure cookies на клиенте; отсутствующий literal не означает false.

## Что подтверждено и что требует работы

| Область | Наблюдение | Вывод / задача |
|---|---|---|
| PostgreSQL role |moodle superuser/create_role/create_db/replication/bypass_rls; единственный login role в полученном pg_roles| INF-02 остаётся открыт; сначала отдельный admin и проверенный rollback, затем минимизация прав |
| Code/config |www-data writable: local/ustar и config.php; config UID/GID33, mode0755| INF-03 подтверждён. Config доступен по обычным mode bits и на запись runtime; полный ACL/parent/replace audit не выполнен |
| Theme/public root |selected dirs www-data readonly; ACL unavailable| Не распространять результат на все дочерние файлы и hardening всего дерева |
| Containers |selected web/PG running, restart0/OOMfalse, unless-stopped; healthchecks нет, CPU/RAM caps0; selected log limits not_configured| INF-05 открыт. Значение0 означает отсутствие заданного Docker лимита; global daemon defaults не измерены |
| SSD/HDD |root available54.3GiB/109.7GiB; HDD394.4GiB/457.4GiB, ext4, nodev/noexec/nosuid; inode use root15.97%| Нет измеренного inode pressure; full per-object storage breakdown и SMART/аппаратный резерв ещё открыты |
| Backup |03:31:32MSK17Oct success/readback reported, archive bytes1,146,734,045 совпали со stat; recovery marker absent; timer active/enabled| Текущий backup contract подтверждён receipt/stat. Архив заново не хешировался; independent restore/RTO/offsite не доказаны |
| Cron |04:12:02MSK worker0/new failures0/USTAR error lines0; adhoc total0| Сам worker свежий. Aggregate failed2/overdue18 не скрываются: нет списка задач и active/disabled breakdown |
| HTTPS |Apache inventory видит80; отдельный443 listener и public cert до21Dec2026| Наличие listener/cert не подтверждает Academy DNS/proxy/trust/login; INF-07 остаётся открыт |
| Network/SSH |UFW inactive; global password auth/X11/forwarding enabled, root without-password; Match context не проверен| INF-10; UFW status не равен всем iptables/nft/perimeter policies. Не менять общие mail/DNS/ISPConfig предположением |
| TCP2019 |есть listener scope=all и отдельный loopback; Caddy установлен, старая карта упоминала Caddy management| Приоритетно установить owning process/address family и сетевую доступность. Это ещё не доказательство публично доступного admin API |
| Performance |одиночный load1≈0.005/8cores, RAM total31.3GiB; DB≈132MiB, selected table counters/settings| Одно измерение не задаёт capacity/p95/SLO; units части PG settings не экспортированы, Web SAPI не измерен |

Caddy использует2019 для admin API по умолчанию на localhost ([официальная документация](https://caddyserver.com/docs/api)); связь конкретного all-address listener с API нужно подтвердить. Не выполнять GET полного Caddy config и не публиковать его для этой проверки.

## Почему остаётся partial

1. **pg_query_stats**: в application DB список extensions содержит только plpgsql. pg_stat_statements там отсутствует; соответствующий query unavailable ожидаем. Ничего не устанавливалось и не перезапускалось. Это не доказательство отсутствия всей performance-работы или extension в других DB.
2. **scheduled_tasks**: unavailable — ошибка SQL-запроса, не свидетельство остановленного cron. В collector найден прямой `smallint::boolean` cast. [Moodle5.1.8 XMLDB](https://github.com/moodle/moodle/blob/v5.1.8/public/lib/db/install.xml) объявляет disabled как int length1/not-null. В изолированном PostgreSQL WASM engine старый cast даёт42846 для smallint, новый `(disabled <> 0)` даёт bool для smallint/integer. PG16 source catalog тоже имеет int4→bool, но не int2→bool. Тип колонки на самом server1 не запрашивался отдельно; точная причина его отказа остаётся гипотезой до повторного probe. Исправление SQL опубликовано вместе с этим контекстом; реальные task rows пока неизвестны.
3. **Clock**: блок помечен ok, но timezone=unknown и оба NTP flags=null. Это пробел измерения. Причина в выборе нескольких timedatectl properties одним comma-joined именем; в source v255 properties добавляются по одному. Getter исправлен на повторяющиеся options и больше не принимает пустой ответ за измерение. Исходный snapshot сохранён с исходным статусом/значениями.
4. **SMART** намеренно не запрашивался; новые тесты/нагрузка диска не запускались.

[SQL validation](runtime_validation.json) отличает локальный PostgreSQL18/WASM от production16.15; production query после исправления ещё не выполнялся. Первичные источники: [PG16 casts](https://github.com/postgres/postgres/blob/REL_16_STABLE/src/include/catalog/pg_cast.dat), [systemd v255 timedatectl](https://github.com/systemd/systemd/blob/v255/src/timedate/timedatectl.c).

## Граница готовности

Контекст готов для повторного аудита владельца **с перечисленными пробелами**. INF-01/OPS-01 обновлены по завершённой manifest-части, но не закрыты целиком; INF-02/03/05/07/10/11/14/PERF и clean-host restore сохраняют свои gates. Неизвестное не превращено в healthy/zero.

После повторного review — repo work и INF-06/PR103: auth/read-only implementation и Grafana-style prototype уже существуют, deployment/bot/jobs ещё требуют приёмки. Для пульта особенно важны backup age/readback, cron heartbeat и task failures отдельно, code/DB privilege findings, disk/inode trend, freshness/NoData и внешний observer. Различать active maintenance и отсутствие данных. Moodle5.2/PG17 и Privacy deferrals сохраняются.
