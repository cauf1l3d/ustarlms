# Свежие server facts и сверка контекста — 17 октября 2026

Два ZIP получены от владельца04:16MSK, третий `report.tar.gz` и console supplement —04:41MSK. Проверены ZIP members/CRC для первых двух, во всех трёх — bounded tar/gzip, четыре payload-файла, schema/checksums; первые два также совпали с вложенными tar. Пакеты импортированы по времени без редактирования исходных данных. [Provenance](../../runtime/server_facts_received_20261017.json), [follow-up](../../runtime/server_facts_followup_20261017.json), [current](../../current.yaml), [остаток аудита](../../roadmap/BACKLOG.yaml). Console supplement сохранён отдельно: он не входит в checksum-bound archive и агентом не повторялся.

| Снимок UTC | Покрытие | Что изменилось |
|---|---|---|
| [01:00:21](../../runtime/server-facts/20261017T010021Z-d5152f1e/manifest.json) |17 ok,13 error, SMART not requested| Docker unavailable, packages invalid_data,11 зависимых probes не выполнялись |
| [01:12:27](../../runtime/server-facts/20261017T011227Z-6b7c0628/manifest.json) |28 ok,2 error, SMART not requested| Docker/code/DB/package probes восстановились; query stats и scheduled task list недоступны |
| [01:39:24](../../runtime/server-facts/20261017T013924Z-285497b0/manifest.json) |30 ok,1 error, SMART requested| Scheduled147/clock/SMART измерены; unavailable только pg_query_stats |

Источник — владелец, запустивший pinned collector на заявленном server1. Self hashes совпали с опубликованными cee34b13/1bdabba3/4c524a52. Это свежие owner-executed observations, без независимой идентификации хоста агентом и без functional acceptance. `ok` означает успешное получение структурированных данных, а не отсутствие риска в них.

## Исходники и версии

Свежая [сверка01:39:24Z](../../runtime/server_facts_source_comparison_20261017T013924Z-285497b0.json): **526/526 plugin/theme files совпали** с e1d57f5bc28f6964afd20aeff7620345b34a80fe; changed/missing/extra/skipped0. Проход не atomic; core, config content, moodledata и database schema не покрываются этими hashes. Прежние05Oct/ранние17Oct manifests сохранены, новый указан в STATE как last exact source observation.

Moodle DB release5.1.8/build20261005 и core disk2025100608.00 согласованы; local_ustar2026100204/theme_ustar2026100202 совпадают на диске и в DB. PHP CLI8.3.33, PostgreSQL server16.15, kernel6.8.0-147 подтверждены. OS сообщает24.04 без point release; пакеты docker-ce29.9.0/containerd2.4.1 установлены. Это не новое измерение Docker daemon version или полного набора host packages.

Public root `/opt/ustar/data/moodle/public/public`; config отдельно `/opt/ustar/data/moodle/public/config.php`; moodledata `/opt/ustar/data/moodle/moodledata`; DB/prefix `moodle`/`mdl_`. Статические config literals, DB config и effective web settings различаются: DB cookiesecure=1 не доказывает secure cookies на клиенте; отсутствующий literal не означает false.

## Что подтверждено и что требует работы

| Область | Наблюдение | Вывод / задача |
|---|---|---|
| PostgreSQL role |moodle superuser/create_role/create_db/replication/bypass_rls; единственный login role в полученном pg_roles| INF-02 остаётся открыт; сначала отдельный admin и проверенный rollback, затем минимизация прав |
| Code/config |www-data writable: local/ustar и config.php; config UID/GID33, mode0755| INF-03 подтверждён. Config доступен по обычным mode bits и на запись runtime; полный ACL/parent/replace audit не выполнен |
| Theme/public root |selected dirs www-data readonly; ACL unavailable| Не распространять результат на все дочерние файлы и hardening всего дерева |
| Containers |selected web/PG running, restart0/OOMfalse, unless-stopped; healthchecks нет, CPU/RAM caps0; selected log limits not_configured| INF-05 открыт. Значение0 означает отсутствие заданного Docker лимита; global daemon defaults не измерены |
| SSD/HDD |root available≈54.3GiB/109.7GiB; HDD≈394.4GiB/457.4GiB, ext4, nodev/noexec/nosuid| Full per-object storage breakdown и аппаратный резерв ещё открыты |
| SMART |/dev/sda health=true/50°C/exit0; /dev/sdb health=true/47°C/exit0| Экспортированы health/temperature/exit status, без полного набора attributes и test history. Прежний HDD residual198=1 не перепроверен; новый self-test не запускался |
| Backup |03:31:32MSK17Oct success/readback reported, archive bytes1,146,734,045 совпали со stat; recovery marker absent; timer active/enabled| Текущий backup contract подтверждён receipt/stat. Архив заново не хешировался; independent restore/RTO/offsite не доказаны |
| Cron |04:39:03MSK worker0/new failures0/USTAR error lines0; adhoc total0;147 scheduled,15 disabled/132 по DB flag enabled| Faildelay2 оба у disabled registration/H5P, среди enabled flag —0.18 overdue>10min имеют last_run0; фактическая component eligibility/overrides не измерены |
| Время/NTP |timezone Etc/UTC; timedated NTP=false и NTPSynchronized=true; timesyncd/chrony diagnostics unavailable| UTC допустим и не требует смены зоны ради Moscow schedule. Флаги сохранены совместно; источник NTP, текущий client/offset и точность не установлены |
| HTTPS |Apache inventory видит80; отдельный443 listener и public cert до21Dec2026| Наличие listener/cert не подтверждает Academy DNS/proxy/trust/login; INF-07 остаётся открыт |
| Network/SSH |UFW inactive; global password auth/X11/forwarding enabled, root without-password; Match context не проверен| INF-10; UFW status не равен всем iptables/nft/perimeter policies. Не менять общие mail/DNS/ISPConfig предположением |
| TCP2019 |есть listener scope=all и отдельный loopback; Caddy установлен, старая карта упоминала Caddy management| Приоритетно установить owning process/address family и сетевую доступность. Это ещё не доказательство публично доступного admin API |
| Performance |одиночный load1≈0.036/8cores, RAM total31.3GiB; DB≈132MiB, selected table counters/settings| Одно измерение не задаёт capacity/p95/SLO; units части PG settings не экспортированы, Web SAPI не измерен |

Caddy использует2019 для admin API по умолчанию на localhost ([официальная документация](https://caddyserver.com/docs/api)); связь конкретного all-address listener с API нужно подтвердить. Не выполнять GET полного Caddy config и не публиковать его для этой проверки.

## Почему остаётся partial

Единственный error последнего архива — **pg_query_stats**. В application DB список extensions содержит только plpgsql. Owner console дополнительно сообщает `available=true`, `installed=false`, `shared_preload_libraries=""`, `compute_query_id=auto`, `tracking=null`, `preload_pending_restart=false`. Модуль доступен для установки, но текущего сбора его статистики нет; это не проблема пароля/имени БД. По [PG16](https://www.postgresql.org/docs/16/pgstatstatements.html) нужны preload с перезапуском сервера и `CREATE EXTENSION` в целевой БД. Такой операции в этой задаче не выполнялось. Прошлые запросы не станут исторической статистикой после будущего включения. Это не доказательство отсутствия всей performance-работы.

Ранние пробелы **scheduled_tasks/Clock/SMART** закрыты в области schema: исправленный SQL вернул147 строк на production16.15, повторяющиеся timedatectl properties дали реальные значения, `--smart` прочитал два диска. [Прежняя SQL validation](runtime_validation.json) остаётся отдельной локальной PostgreSQL18/WASM проверкой; [новая validation](runtime_followup_validation.json) относится к этому архиву. Исходные ранние snapshots с error/unknown не менялись.

Две строки с `fail_delay>0` — `\core\task\registration_cron_task` (60s) и `\core\task\h5p_get_content_types_task` (960s) — имеют `disabled=true`. Это Moodle.org registration, а не регистрация сотрудников.18 overdue определены только как `disabled=false AND next_run>0 AND next_run<collected_utc-600s`; у всех18 last_run=0. [Scheduler Moodle](https://github.com/moodle/moodle/blob/MOODLE_501_STABLE/public/lib/classes/task/manager.php) также проверяет `plugininfo->is_enabled()` и `get_run_if_component_disabled()`. Поэтому18 записей не объявляются18 реальными сбоями выполняемых задач; нужны enablement компонентов, overrides и согласованные исключения. Ничего не включалось и не сбрасывалось.

Остаются NTP server/source/offset и фактический client; unavailable обеих дополнительных команд не доказывает отсутствие любого клиента. Из SMART schema нельзя получить новые pending/reallocated/offline-uncorrectable/wear/test-history значения. Старый residual HDD не считается исправленным по одному health=true. Полные ACL/core/schema/functional/perimeter/restore scopes остаются отдельными.

## Граница готовности

Контекст готов для повторного аудита владельца **с перечисленными пробелами**. INF-01/OPS-01 обновлены по завершённой manifest-части, но не закрыты целиком; INF-02/03/05/07/10/11/14/PERF и clean-host restore сохраняют свои gates. Неизвестное не превращено в healthy/zero.

После повторного review — repo work и INF-06/PR103: auth/read-only implementation и Grafana-style prototype уже существуют, deployment/bot/jobs ещё требуют приёмки. Для пульта особенно важны backup age/readback, cron heartbeat и task failures отдельно, code/DB privilege findings, disk/inode trend, freshness/NoData и внешний observer. Различать active maintenance и отсутствие данных. Moodle5.2/PG17 и Privacy deferrals сохраняются.
