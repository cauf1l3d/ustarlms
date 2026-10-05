# Полный пошаговый план устранения вопросов аудита USTAR

Дата: 05.10.2026. Основание — [аудит владельца](../audits/USTAR_INFRASTRUCTURE_AUDIT_20261005_RU.md), [сверка и последующие уточнения](../audits/AUDIT_RECONCILIATION_20261005_RU.md), [серверные свидетельства](../runtime/evidence_20261005.yaml). Application baseline: `e1d57f5bc28f6964afd20aeff7620345b34a80fe`, `local_ustar / theme_ustar = 2026100204 / 2026100202`.

Этот документ выполняет поручение подготовить **полный план**, а не только починить backup. Публикация плана не означает выполнения перечисленных исправлений. DOC-02 завершён PR78; **последующая команда владельца: сначала 1 → 7 → 6, остальные после них**. Прежняя остановка после GitHub больше не действует. Реальные статусы — [STATE](STATE.yaml) и [BACKLOG](BACKLOG.yaml).

## Порядок и границы

Текущая очередь по новой команде: **baseline (`INF-01`) → patch/CI/ОС (`INF-09`, `INF-08`, `INF-10-OS`) → HTTPS (`INF-07`)**. Это пункты 1, 7, 6 краткого списка, а не номера INF. Оставшиеся пункты полного плана выполняются после выбранных; не навязывать DB/file hardening, пульт или disk cleanup как предварительные зависимости. Fresh recovery copy, изолированная репетиция и сохранность shared services остаются условиями самих выбранных изменений. Broad SSH/MFA/firewall часть `INF-10` отложена, OS часть выделена в `INF-10-OS`. [Свежий baseline](../runtime/baseline_20261005.md).

Остальная последовательность разделов описывает полный инфраструктурный цикл: минимальные привилегии, диск/журналы/наблюдение, эксплуатационная приёмка и решения → **регулярные копии и восстановление финалом**. После него отдельные проекты: major/LTS, Privacy, Android/iOS, B2B и измеренный рефакторинг. Полная реализация этих продуктов не является зависимостью включения регулярных копий.

Сисадмин предписал автоматизацию на HDD, не на SERVEREXPRESS. Существующий `/dev/sdb` непригоден: long SMART завершился ошибкой чтения. Нужен исправный HDD и отдельно выбранная защита от потери всего хоста. Ручной внешний экземпляр на SERVEREXPRESS остаётся свидетельством уже выполненной страховки; расписание туда этим планом не разрешается.

Владелец предполагает, что `/` занят полной тестовой копией Академии. Сначала измеряется реальный расход. Ни удаление лаборатории, ни форматирование HDD, ни расширение разделов, ни массовые кадровые изменения не следуют из этой гипотезы.

До каждой изменяющей операции: записать точный объект и текущие значения; получить актуальную **разовую** согласованную копию, проверить возможность отката; отрепетировать на изолированной копии; согласовать окно общего сервера; определить stop conditions. Это не включение регулярного расписания раньше финального этапа. Оператор production и ответственные назначаются владельцем; имена, сроки, SLO/RPO/RTO и retention сейчас не утверждены.

Каждый шаг закрывается артефактом: дата UTC, exact Git SHA / image digest / версии, метод, результат, область проверки, ограничения и откат. Raw данные и секреты остаются у оператора; в Git — проверенная сводка. Нельзя выдать CI или агрегат cron за полную production-приёмку.

## Уже выполнено — сохранить

| Результат | Доказательство | Остаток |
|---|---|---|
| Файлы plugin/theme PR76 | 05.10 02:32:35.997389 UTC: 526 matching, нулевые различия/ошибки/пропуски | Повторять после поставок; core и сторонние плагины отдельно |
| Согласованная ручная копия | Snapshot 04.10, SHA-256, внешний экземпляр, отдельный ключ, isolated restore | Сохранить; покрытие всего хоста и автоматический график не проверены |
| Cron v3 | Минутный schedule, один worker; 04.10 23:57: exit 0, 1.374 с, adhoc/USTAR failures 0 | Наблюдать полный цикл; H5P/registration отключены, сеть не исправлена |
| Read-only прикладная сверка | 72 аккаунта, 71 ready, одно исключение защищённого legacy-профиля, нет недостающих enrolments | Не менять профиль ради счётчика; не считать полной приёмкой всех доменов |

## Этап 0. Вход и точная исходная точка — INF-01

1. После новой команды владельца проверить, что оператор на нужном сервере; записать время, версии, текущую ветку/commit и действующие контейнеры. Проверить частные права каталога отчётов.
2. Снять read-only baseline: `df` по месту и inode; аппаратные устройства/SMART; container IDs/digests/mounts/ports/logging/health; Apache vhosts; роль БД и размеры; статус установленной cron-обёртки. Команды и ограничения уже описаны в §§9А–9Б аудита и [OPERATIONS](../runtime/OPERATIONS.md). Не переустанавливать cron и не запускать reconcile/sync.
3. Повторить manifest по **существующему exact SHA**, не по отсутствующему `origin/main`. Проверить complete и все категории; сравнение неатомарное, без одновременного deploy. Отдельно инвентаризировать полный core, plugins, тему Boost и возможный отдельный Boost Union, image origin и изменения относительно upstream.
4. Определить зависимости необходимых почты/DNS/ISPConfig и канал аварийного доступа. Зафиксировать режим изолированного стенда: loopback-only, безопасные/защищённые данные, отключённая внешняя почта и внешние интеграции, независимые volumes/ports. Не включать второй общий worker на production.
5. Перед первым изменением проверить доступность актуальной разовой копии и ключа, объём для временных файлов, порядок отката. Снимок 04.10 предшествует последующим записям cron; он не заменяет копию непосредственно перед изменением.

**Приёмка:** единый dated baseline и реестр отклонений; установленные файлы сопоставлены в указанной области; последствия каждого следующего шага понятны. Ошибка manifest — незавершённая проверка, не разрешение перезаписать production. **Откат:** изменений приложения нет; отчёты не заменяют канонический контекст автоматически.

## Этап 1. Минимальные привилегии

### INF-02 — разделить приложение и администратора PostgreSQL, P0

1. Read-only собрать roles/attributes, владельцев database/schema/tables/sequences/functions/extensions, grants/default privileges, применяемую auth policy и потребителей текущего подключения. Не выводить пароли.
2. Создать и проверить отдельный административный путь в двух сессиях; документировать защищённое хранение реквизитов. Наличие роли `postgres` не обязательно: текущая роль `moodle` — единственный найденный в раннем снимке пользовательский admin-путь.
3. На копии подготовить минимально достаточные права приложения и отдельное обслуживание. Явно решить DDL/ownership/extension privileges для upgrade; не считать, что обычный SELECT/INSERT полностью покрывает Moodle install/upgrade.
4. Проверить login, все доменные записи, File API, cron, XMLDB upgrade/repeat, дамп/restore и administrative recovery. Снять negative check: приложение не может выполнять запрещённые cluster-wide операции.
5. Применить в окно. Убрать лишние `SUPERUSER`, `CREATEROLE`, `CREATEDB`, `REPLICATION`, `BYPASSRLS` у runtime-роли в согласованной модели; сохранить только обоснованные права. Убедиться, что сборщик метрик использует ограниченную роль, а не новые admin credentials.

**Приёмка:** runtime не cluster superuser; отдельный admin проверен; приложение/cron/upgrade/backup не теряют необходимые права; ownership/grants зафиксированы. **Откат:** через проверенный admin восстановить прежние атрибуты/grants или конфигурацию подключения; при изменениях данных — согласованный snapshot. Слепой `ALTER ROLE ... NOSUPERUSER` до проверки admin запрещён порядком работ.

### INF-03 — закрыть runtime-запись в код и доступ к секретам, P0

1. Инвентаризировать owners/groups/mode/ACL для config.php, plugin/theme, всего пути родителей, Moodle data/cache/session/temp, архивов и старых dumps. Проверять возможность замены файла через writable родителя, не только mode самого файла.
2. На копии выделить владельца релизной поставки и runtime-пользователя; код/конфигурация читаются только теми, кому нужны, не изменяются `www-data`. moodledata и необходимые рабочие каталоги сохраняют запись. Проверить, где release/upgrade/purge CSS нуждаются в write, не давать постоянную запись веб-процессу ради удобства deploy.
3. Закрыть локальное чтение dump/config/keys через проходные 0755 и файлы 0644 там, где они не нужны другим службам. Проверить резервную копию и доступ владельца ключа; не удалять единственный экземпляр. Решение о смене секретов принимать по фактической доступности/политике и перечню зависимостей.
4. Применить адресно и проверить `test -w` для config/plugin/theme плюс родителей/ACL, чтение runtime, upload/download, SCORM, сессии, CSS, cache purge, cron и очередную поставку.

**Приёмка:** runtime не меняет и не подменяет релизный код/config; data работает; посторонние локальные users не читают конфигурацию, дампы и ключи. **Откат:** сохранённые owner/mode/ACL по адресному списку. Не применять рекурсивный chown/chmod ко всему `/opt/ustar`.

## Этап 2. Место, журналы и единый пульт

### INF-04 — измерить занятость SSD, решить HDD и аппаратные риски

1. Сопоставить `df` с ограниченным по одному filesystem `du`, Docker disk usage и реестром volumes/images; отдельно измерить production, полную тестовую копию, recovery-архивы, временные restore-каталоги, Docker/system/service logs, почтовые данные и удалённые, но открытые файлы. Не раскрывать содержимое почты/персональных файлов.
2. Подтвердить или опровергнуть гипотезу владельца о тестовом стенде; записать путь, размер, назначение, зависимости, пригодность к восстановлению и нужность. Сохранить полезный restore evidence, config и необходимые уникальные результаты.
3. Подготовить **пообъектный** план освобождения/переноса с контрольными суммами и владельцем; применять только согласованное. Никаких `docker system prune --volumes`, чистки архивов или лаборатории по названию. Проверенный перенос предшествует удалению оригинала.
4. Снять таблицу разделов/boot layout/UUID/mounts. Оценить расширение `/` на SSD только после проверки свободного неразмеченного пространства, возможности отката и окна; 111.8G в lsblk не является достаточным основанием для growpart.
5. Заменить/выбрать исправный HDD под требование сисадмина; определить точный device identity по serial/UUID, проверку здоровья и подходящую файловую систему. Подготовка filesystem/форматирование требует точного устройства и согласованного содержимого. Старый WD5000AAKS не становится надёжным после форматирования.
6. Уточнить ИБП, состояние питания, безопасный shutdown и общий отказ хоста. Проверку аварии сначала выполнить на стенде; внезапное отключение production не является тестом.

**Приёмка:** известен расход каждого крупного объекта; выбран проверенный healthy target, достаточный запас по месту/inode и бюджет роста; решения по разделам/питанию записаны либо имеют явное исключение. **Откат:** вернуть проверенно перенесённые объекты/сохранённую конфигурацию; изменение разделов имеет собственный recovery plan. Отсутствие здорового target удерживает финальный backup gate открытым.

### INF-05 — ротация, healthcheck и управляемое пересоздание контейнеров

1. Снять текущие log drivers/options, log sizes/growth, service logrotate policies и права; cron.log уже имеет закрытую ротацию. Выбрать пределы хранения по измеренному объёму и доступному месту, а не выдуманной политике.
2. Проверить конфигурацию Docker rotation на копии. Изменение default daemon logging не меняет существующие контейнеры; их пересоздание требует exact images/mounts/config, окна и исправного rollback.
3. Ввести healthcheck, различающий running от готовности HTTP/PHP/DB. Согласовать read-only probes и стоимость проверки; не записывать бизнес-данные и не раскрывать пароль. Состояние unhealthy сначала вызывает инцидент; автоматические restart/cleanup не добавляются без решения.
4. Пересоздать адресно; проверить volume identity, версии, DB/web, журналы, restart/OOM counters. Штатно перепроверить/rebind cron wrapper к новым container IDs с сохранением single worker/backup-lock; не обходить защиту ожидаемых контейнеров.

**Приёмка:** рост журналов ограничен; проверена фактическая ротация существующих контейнеров; health описывает готовность; cron вновь автоматически выполняется. **Откат:** прежние exact Compose/image/log settings и штатная cron binding; не запускать второй cron для обхода ошибки.

### INF-06 — один дашборд и проверенные уведомления, P0

1. Принять архитектуру и место размещения пульта. Предложение аудита: Grafana + Prometheus + ограниченные host/PostgreSQL/HTTP/USTAR collectors; это ещё не установленный стек. Выбрать отдельную машину-наблюдателя и проверку потери самого пульта/основного хоста.
2. Установить минимальный сбор в пределах бюджета SSD/RAM/CPU; закрытый доступ, роли просмотра/управления, метрики без personal labels, limited DB grants/timeouts. Не выдавать full Docker socket, superuser или shell collector ради удобства.
3. Собрать общий обзор и разделы host/SMART/disk/inode, containers/restarts/OOM/logs, HTTP/PHP/errors/latency/TLS, PostgreSQL/connections/locks/vacuum/xid/growth, cron/adhoc/task outcomes, необходимые mail/DNS/ISPConfig и backup. Для ещё не включённого backup отображать «не настроено», а не success.
4. Определить каждую метрику, timestamp/freshness и NoData. Cron success, exit, failures и business aggregates разделить; 17 COMPONENT_DISABLED и два disabled external exceptions показывать отдельно. Overdue рассчитывать по effective-enabled, overrides/timezone и реальному расписанию девяти USTAR задач. Не требовать ночной/часовой задачи каждые пять минут. Backup-lock pause имеет причину, начало и допустимую длительность, не бесконечный mute.
5. Согласовать пороги/for windows, ответственность, maintenance silence и recovery. Предложения аудита: cron >5 мин без success вне окна; disk 80%/90% плюс абсолютный остаток/рост; новые SMART/integrity ошибки. Это настройки на согласование, не утверждённые SLA. Latency определяется после PERF-01; исторический failed24h не создаёт каждую минуту новый incident.
6. Выбрать получателей/канал Telegram, хранить bot token секретно, проверить исходящую доставку из данной сети и резервный канал. Отправлять компонент, проблему, время, ссылку; без персональных данных и токенов в URL. Исходящий Telegram не требует внешней публикации Академии.
7. Проверить controlled failure/recovery/NoData в копии или probe: недоступность HTTP, пропуск heartbeat, новая task failure, переполнение-порог, сбой отправки. Независимый наблюдатель должен обнаружить потерю хоста/пульта. Сохранить журнал фактической доставки и восстановления инцидента.

**Приёмка:** один пульт показывает свежие данные и неизвестность; failure/recovery действительно доставлены; независимость наблюдателя проверена в заявленной области. Если отказ площадки не покрыт, это явное исключение, не «полная отказоустойчивость». **Откат:** отключить новые collectors/rules, сохранив private cron logs; удалить только новые test resources. Подробный контракт — [MONITORING](../runtime/MONITORING.md).

## Этап 3. Доверенный HTTPS — INF-07

1. Выбрать с владельцем DNS-имя и доверие: внутренний CA для внутреннего имени либо принадлежащее компании зарегистрированное имя с внутренним DNS и подходящим сертификатом. Не обещать публичный сертификат для `.local`. Внешний доступ/VPN не включается автоматически.
2. На копии подготовить TLS на **действующем Apache**, не переносить вслепую маршрут на Caddy. Снять другие vhosts/сертификаты/службы; проверить корректность общей Apache-конфигурации и reload/rollback.
3. Согласовать `$CFG->wwwroot`, reverse proxy handling, redirect policy, secure cookies, scheme-aware links и renew/expiry monitoring. Внедрить доверие на рабочих ПК/Android/iPhone. Не использовать отключение TLS verification как приёмку.
4. Проверить реальный browser login/logout, HttpOnly/Secure/SameSite по выбранному session contract, redirects/deep links, upload/download/private File API, Quiz, SCORM/iframes, чаты, session continuity. `curl 000` означает transport failure; HTTP 200 headers не заменяют полный сценарий.
5. Переключить в окно и проверить доступ действующих users и остальных vhosts; записать сертификат/срок/renewal без private key. Доступ остаётся корпоративным, если владелец не выбрал другое.

**Приёмка:** реальные клиенты доверяют сертификату, пользуются HTTPS без предупреждений и broken links; cookie проверена в ответе и браузере; renewal/expiry alert работает. **Откат:** сохранённые vhost/config/DNS values и согласованное восстановление прежнего маршрута; промежуточный HTTP фиксируется как временный риск, не успешная конечная приёмка.

## Этап 4. Patch, CI и обслуживание общего хоста

### INF-08 — обновить текущий Moodle patch без ожидания major

1. Повторно проверить официальные выпуски/advisories **на дату выполнения**, точный core/build, provenance/images и все установленные plugins/themes. Датированный календарь аудита не доказывает доступность релиза сегодня.
2. Выбрать опубликованный поддерживаемый patch текущей ветки; составить compatibility matrix PHP/PostgreSQL/core/plugins/USTAR. Проверить core differences; сторонний Boost Union, если установлен, имеет отдельную версию. Не модифицировать Moodle core ради локального обхода.
3. На восстановленной копии с точными образами выполнить backup → upgrade → repeat-upgrade → full gate/role scenarios/files/Quiz/SCORM/chat/cron; проверить новую DB схему и restoration, не только PHP lint. Записать фактические права release; последующие INF-02/03 обязаны повторно проверить upgrade/rollback на новых правах, но владелец отложил эти два пункта до выбранных 1/7/6.
4. Выпустить exact candidate в окно; записать image digest/core version, post-deploy manifest plugin/theme и отдельную core identity, smoke и результаты очередного cron. Остановиться при schema mismatch, неполном manifest или сломанном критическом сценарии.

**Приёмка:** актуальный выбранный patch принят на stage и production; совместимость и provenance измерены. **Откат:** полный согласованный DB/code/config/moodledata комплект при изменённой схеме; просто вернуть PHP после upgrade недостаточно.

### INF-09 — выровнять CI и production

1. Зафиксировать целевой PHP/core/PostgreSQL matrix по актуальному production. Сегодня в свидетельствах PHP 8.3.33, source CI 8.2, stage 8.2.30; не выдавать старый gate за проверку parity.
2. Отдельным application/tooling PR добавить/перевести runtime jobs на целевую PHP 8.3; 8.2 оставить только как явно заявленную и поддерживаемую compatibility ветку. Pin точные зависимости/images по политике и учесть обновления patch.
3. Проверить fresh install, upgrade/repeat/schema parity, capabilities, negative role tests, files, native browser contracts и restore. Обычный source/frontend PR не равен full RC: нужны все предусмотренные jobs на **новом exact SHA**.
4. Обновить test fixtures как осознанное изменение synthetic стенда, не переписать исторические production evidence под текущий номер. Зафиксировать фактические jobs/conclusions/artifacts и ограничения.

**Приёмка:** воспроизводимый полный gate на целевом runtime; compatibility matrix и срок старых веток определены. **Откат:** предыдущая CI/image конфигурация; production этим PR автоматически не обновляется.

### INF-10 — ОС, firewall/SSH/MFA и необходимые службы

По новой очереди patch-ОС/AppArmor/reboot выполняются в **INF-10-OS**, часть выбранного пункта 7, до HTTPS. Broad SSH/firewall/MFA redesign остаётся INF-10 после выбранных работ. Сначала проверить остатки Snap, current profiles и required service consumers; не отключать AppArmor ради старого include error. Read-only baseline и history текущей причины — в [baseline](../runtime/baseline_20261005.md).

1. Снять pending/applied packages, kernel/reboot reason, listening ports, nftables/iptables/Docker/router ACL, SSH effective config с Match blocks, admin paths и policies. `ufw inactive` не доказывает отсутствие всех ограничений; баннер не требует do-release-upgrade.
2. Согласовать service matrix для Академии, почты/DNS/ISPConfig/MariaDB/amavis; ограничить доступ согласно реальным клиентам. Не закрывать SSH или важный порт до проверки резервного доступа. Не отключать нужные службы как «мусор».
3. Внедрить ключевой admin access и проверить вторую сессию/аварийный путь; затем согласованные SSH/firewall ограничения. Для привилегированных ролей инвентаризировать effective MFA policy и recovery, поэтапно включать с проверкой. Disabled MFA-factor tasks не доказывают статус всей MFA.
4. Отрепетировать совместимые OS/container/PHP patch updates, согласовать reboot общего хоста, выполнить в окно. Проверить startup order, Docker volumes, cron binding/schedule, mail send/receive/queue, DNS и панель. USTAR uptime smoke не заменяет проверку остальных служб.
5. Закрепить обновления/обслуживание/сроки security fixes и ИБП shutdown из INF-04; исключения получают ответственного и дату повторной проверки.

**Приёмка:** доступ администратора сохраняется, policy проверена, нужные службы работают после обслуживания; pending reboot обработан или документирован с окном. **Откат:** сохранённые сетевые/SSH/service configs, аварийный доступ и проверенный image/host recovery. Не делать одновременную замену всех параметров.

## Этап 5. Адресная приёмка, baseline и решения о развитии

### INF-11 — внешние задачи, почта и устойчивость фоновой обработки

1. Сохранить список disabled exceptions и причины. H5P: измерить DNS/connect/TLS/body transfer с bounded timeout, целостность/размер и валидный `contentTypes`; default и IPv4 ранее оба timeout, HTTP 200 не закрывает проблему.
2. Проверить существующий H5P-контент независимо от catalogue refresh. После устранения причины отрепетировать refresh на копии, затем выбрать re-enable либо документированное отключение с последствиями и review date. Аналогично Moodle.org registration, которая не является регистрацией сотрудников.
3. Выбрать тестовый email-адрес, проверить task → message → mail-server delivery → получение; исключить рассылку реальным сотрудникам при тесте. Cancelled login notification для отсутствующей учётки не переименовывать в SMTP outage. Внутренняя регистрация не приобретает требование email verification.
4. Наблюдать как минимум полный согласованный часовой/ночной task cycle с overrides/timezone, отсутствие наложения workers, adhoc age и ошибки конкретных классов. Не стирать историю faildelay/task_log ради зелёного дашборда. При зависании учитывать PHP внутри контейнера после Ctrl+C обёртки.

**Приёмка:** результаты внешних задач/доставки доказаны либо оформлено осознанное исключение; USTAR cycle работает; мониторинг различает новые и исторические ошибки. **Откат:** вернуть прежнюю disabled policy и расписания; не сбрасывать error history и не запускать второй scheduler.

### OPS-01 и INF-12 — приложение и сценарная безопасность

1. OPS-01: получить свежие versions/manifest перед новой поставкой; отдельно принять PR76 reset/reassign под реальными разрешёнными ролями на безопасном наборе. Reset сохраняет историю, повторное назначение создаёт новый cycle, completed cycle не сбрасывается.
2. Составить scenario/role matrix employee/manager/HR/HRD/executive/owner и guest/disabled/terminated. Покрыть регистрацию/staffing, organization/scope, adaptation/routes/Quiz/SCORM, evidence/grades, work/checklists, feed/moderation, messages/files, rewards/competitions, private notebook.
3. На копии проверить прямые URL/API/File API, чужую команду/файл, смену reporting/роли, actor vs view-as, CSRF/sesskey, ошибочный ввод/динамический SQL, HTML/XSS/uploads, outdated revisions, concurrent/retried requests, locks/idempotency. Проверять отказ и отсутствие побочного изменения, не только hidden button.
4. Проверить отсутствие двойных awards/messages/completion и сохранность ledger/evidence/history. Одна pending grade request имеет очередь руководителя; тест не принимает её автоматически. Использовать synthetic fixtures или явно согласованные тестовые accounts, не protected legacy profile.
5. Каждый найденный дефект получает отдельный PR, targeted negative/regression checks и соответствующий full gate; только затем проверенная поставка. Сохранить coverage и неохваченные пути: «SQL/XSS чисто» по счётчикам не является результатом.

**Приёмка:** согласованная матрица пройдена; реальные дефекты устранены либо имеют принятую ограниченную область и owner; PR76 behavior принят отдельно от manifest. **Откат:** для кода прежний exact release; при schema/data effects — coordinated restore с учётом новых записей. Production нельзя нагружать тестовыми кадровыми решениями без отдельного задания.

### AUDIT-01 — legacy, XMLDB и правила без кадровой автокоррекции

1. Read-only инвентаризировать типы аккаунтов, legacy readers/consumers, PRIMARY/ACTING/reporting/employment и domain-config provenance. 76 исторических строк без employment не означают 76 неисправных сотрудников; 72 assignment plans проверяли другую область.
2. Защитить исключённую служебную учётку и существующие роли/историю. Не назначать роль по названию должности и не запускать старые apply/migration scripts по наличию JSON-плана.
3. Дополнить проверку DB schema: таблицы/поля/типы/defaults/nullability/keys/indexes/constraints и семантика ссылок. Предыдущие no-missing/no-invalid и выборочные coin/assignment checks сохраняются как частичное evidence, не full schema parity.
4. Для фактического расхождения разработать explicit migration с preview affected rows, idempotency, install/upgrade/repeat parity и восстановлением; согласовать personnel effect отдельно. Не обрезать db/upgrade.php: сначала определить поддерживаемые исходные версии.
5. Связать старые R/G/N критерии с actual implementation/tests/runtime; закрывать по evidence. MATURITY_BETA/rc меняется только после согласованной release acceptance, не косметически.

**Приёмка:** реестр legacy exceptions/потребителей и schema gaps; доказанные дефекты закрыты либо имеют конкретный согласованный migration plan. **Откат:** coordinated snapshot для применённой миграции; история и прежние evidence сохраняются.

### PERF-01 — измеренная ёмкость и адресный тюнинг

1. Согласовать рабочие сценарии, число одновременно активных users и цели latency/error rate. 18 пилотных / 60–70 сотрудников / цель 200 — population, не measured concurrency.
2. Пассивно собрать обычный рабочий день: HTTP/PHP p50/p95/p99, errors/throughput; CPU/RAM/swap/I/O, web worker busy/queue/RAM, DB connections/locks/vacuum/query cost, cron/adhoc age, общий mail/antivirus load.
3. Проверить **web SAPI** OPcache hit/miss/scripts/memory/restarts, MUC/session stores и effective settings. CLI false не означает web-cache off. Не ставить validate_timestamps=0, пока deploy не гарантирует сброс web OPcache; Moodle cache purge сам по себе этого не доказывает.
4. На копии с representative dataset и think time выполнить login/home/team/route/Quiz/SCORM/chat/file/work/mass operation по ролям, cold/warm и с фоновыми задачами. Фиксировать exact SHA/images, объём, concurrency, duration и отсутствие двойных business effects.
5. Исправлять измеренный bottleneck: запросы/N+1/планы/индексы, web worker budget, DB параметры, media/log storage; Redis/разделение среды только при обосновании. work_mem учитывает parallel operations, RAM всех служб и пики.
6. Повторить сопоставимый сценарий; зафиксировать пределы/запас и условия роста, обновить monitoring thresholds. Не писать «ресурсов хватит на годы» и не запускать destructive load на production.

**Приёмка:** baseline и сравнимый after-report; performance цель/ёмкость заявлена только для измеренного сценария. **Откат:** прежние tuning/config/code values; отсутствие улучшения — причина откатить и пересмотреть bottleneck.

### INF-13 — сопровождение, security и ограниченный план развития

1. Назначить service/release/security/data владельцев и private reporting channel. Проверить фактические GitHub security features/branch rules; подготовить SECURITY.md/CODEOWNERS с реальными согласованными контактами, не выдуманными логинами.
2. Выбрать Dependabot/Renovate для применимых npm/Actions dependencies и проверку OS/PHP/Moodle/plugins/container advisories. Регламент реакции привязан к exact версии и функциям; автоматический PR не означает автодеплой. Next.js помечен как неиспользуемый и проверяется перед возвращением в эксплуатацию.
3. Записать решения/ответственные/критерии для поддерживаемой платформы и будущего LTS, mobile API/auth/network/distribution, privacy policy и модели B2B. Принять ADR там, где решение созрело; для открытого выбора зафиксировать варианты, недостающие данные и следующую дату решения.
4. Сформировать release checklist, incident runbooks, exception register и актуальный STATE/ledger/contexts. Отдельно спланировать поддерживаемые upgrade paths и измеренный refactor — TECH-01. Не переписывать архитектуру по размеру файла.
5. Зафиксировать **конечный объём** инфраструктурной приёмки и readiness к финальному backup-этапу. Полный mobile/B2B/Privacy/major delivery туда не входит; принятые временные исключения не маскируются словом «done».

**Приёмка:** процесс сопровождается реальными ответственными, риск/исключение имеет критерий и дату; планы будущих проектов сохранены; следующий финальный этап имеет проверяемые входные условия. **Откат:** вернуть прежние repository automation settings при шуме/ошибке, не отменять audit history. Нет автовключения сервисов и рассылки сообщений.

## Финальный этап текущего цикла. Регулярные копии — INF-14 и OPS-02

### INF-14 — внедрение согласованного расписания

1. После предыдущих этапов утвердить RPO/RTO, допустимую паузу, объём/рост, retention и владельца запуска/ключа/реакции. Посчитать capacity по измеренному полному snapshot, запасу и ожидаемому росту; «14 ежедневных/4 недельных» не утверждено.
2. Проверить исправный HDD из INF-04; определить mount по UUID, private ownership, доступность, свободное место/inode и поведение при отсутствии mount. **Fail closed:** скрипт не пишет в пустую директорию на SSD при пропавшем HDD. Текущий WD5000AAKS не target.
3. Согласовать независимую копию на другом подходящем носителе/хосте либо явно иной утверждённый механизм. Локальный HDD не покрывает потерю единственного сервера. Не настраивать автоматический перенос на SERVEREXPRESS; если независимый вариант не выбран, соответствующий disaster/RPO gate остаётся открытым.
4. Проверить сохранённый manual script/его hash и контракт на копии; не реконструировать установленную обёртку из пересказа. Согласовать DB + code + config + moodledata + точные images в одной точке. При cold Moodle / live PostgreSQL подтвердить отсутствие других writers и корректный consistent DB dump; независимые DB/files timestamps не образуют recovery point автоматически.
5. Реализовать encryption/integrity manifest, atomic completion marker, private temp paths и cleanup только собственных временных файлов, проверку передачи, bounded timeouts. Ключ отдельно от архива, проверенно доступен после потери хоста; никакие passwords/private keys не в Git или log.
6. Сохранить общий backup-lock и cron v3 single-worker contract, обработку running child, сигналы/таймаут, восстановление Moodle/maintenance при ошибке и журнал pause/resume. Не создавать второй cron. Не считать backup успешным до всех обязательных проверок выбранной политики.
7. На копии проверить missing mount, full disk, failed dump, interruption, failed transfer/checksum/decryption, lock contention и восстановление сервиса. Retention удаляет только проверенные завершённые backup по принятой политике; минимум необходимого восстановимого набора сохраняется.
8. Установить единственное согласованное расписание на HDD. Проверить несколько scheduled запусков и этап передачи: свежесть, checksums, latency/downtime и отсутствие наложения. Подключить backup/transfer/integrity/key/restore freshness к INF-06; проверить пропущенный запуск и recovery alert.

**Приёмка:** расписание прошло автоматически на здоровый target; согласованная копия и требуемый независимый экземпляр проверены; cron возобновился; ошибка не скрывается; retention/ключи/SLO определены. **Откат:** отключить только новый backup schedule, оставить manual snapshot и cron, вернуть прежнее состояние приложения; не удалить архивы для освобождения места автоматически. Подробный контракт — [BACKUP_RESTORE](../runtime/BACKUP_RESTORE.md).

### OPS-02 — доказать восстановление из результата расписания

1. Выбрать **копию, созданную расписанием**, желательно из независимого экземпляра; восстановить на чистую изолированную среду без production ports/volumes и исходящих писем. Проверить целостность, decryption и key access в сценарии потери основного сервера.
2. Зафиксировать время обнаружения/получения/восстановления и дату последней доступной recovery point. Проверить login, roles, user files, learning evidence, SCORM/Quiz, chats, ledger, cron и версии. 125 секунд прошлой lab не гарантируют полный RTO.
3. Дополнить восстановление **всего общего хоста**: host/container configs, необходимая почта/DNS/ISPConfig, зависимости, certificates/secrets и порядок восстановления. Если эти службы имеют отдельный backup владелец/система — проверить её и совместный runbook, не поглощать её молча Moodle-скриптом.
4. Повторить часть отказных сценариев и согласовать периодический restore-drill, назначение оператора и хранение отчётов. Restore не должно раскрывать данные другого будущего клиента.
5. Закрыть текущий инфраструктурный цикл только при фактических приёмках INF-14 + OPS-02 и согласованных исключениях. Обновить evidence/ledger/backlog; затем запросить следующий проект, не начинать его автоматически.

**Приёмка:** fresh scheduled copy реально восстановлена; RPO/RTO оценены в заданной области; общий host recovery и независимость подтверждены либо отмечены открытыми. **Откат:** удалить только isolated test environment после сохранения отчёта; production restore выполняется лишь при отдельном инциденте/команде.

## Последующие проекты — полное закрытие долгосрочных вопросов

### PLAT-01 — major/LTS Moodle

1. Проверить фактический стабильный релиз и официальные требования/advisories на дату начала; выбрать поддерживаемую целевую ветку до окончания поддержки текущей. Цифра «5.3» в аудите — кандидат, не команда поставить unreleased продукт.
2. Составить compatibility matrix всех core/plugins/themes/PHP/PostgreSQL/API и exact images; определить поддерживаемый путь и обратную совместимость клиентов. Parent USTAR остаётся boost, независимый Boost Union проверяется отдельно при наличии.
3. На копии выполнить fresh/upgrade/repeat, полноценную role/scenario/File API/cron/performance приёмку и restore. Найденные несовместимости закрыть отдельными PR без core hacks и потери history.
4. Согласовать окно и новую разовую копию, выпустить exact release, проверить manifest/core identity/мониторинг и recovery; закрепить lifecycle следующий deadline.

**Приёмка:** фактически выпущенный LTS принят на точной матрице и production. **Откат:** согласованный полный snapshot, включая схему; один file downgrade недостаточен. Не задерживает INF-14.

### PRIVACY-01 — Privacy API и политика данных

1. Владелец и ответственный за данные утверждают категории, права запросов, export/anonymize/delete/retention, сохранение учебного/кадрового evidence, исключения и сроки. Юридическое соответствие не определяется этим техническим аудитом.
2. Дополнить существующий inventory: таблицы/fields, Moodle files, notifications/chats, derived projections и внешние processors; не объявлять все 93 таблицы персональными без анализа.
3. Реализовать полноценный provider metadata/user discovery/export/deletion по утверждённой политике. Null provider для USTAR с собственными данными недостаточен. Учитывать shared conversations/history/ledger и пользователей в каждом context.
4. На копии проверить completeness экспорта, разрешённость операции, отсутствие удаления чужих данных/подрыва history, files и связанных records, повтор/частичный отказ. При destructive migration — новый schema gate и snapshot.
5. Опубликовать runbook запроса, operational responsibilities и tests; до первого B2B production принять data-policy и vendor/customer obligations.

**Статус:** ранее отложен владельцем как неблокирующий внутренний запуск; обязательный gate B2B. **Приёмка:** policy + implementation + scenario evidence, а не только наличие файла. **Откат:** прекращение новых destructive запросов и согласованный restore; до политики автоматическое удаление не вводится.

### MOB-01 → MOB-05 — полноценный Android/iOS

1. **MOB-01:** inventory каждого native сценария → service/API/ACL/files/retry/revision/offline; имеющиеся 30 external functions не доказывают full coverage. Выбрать ADR stack, auth/token lifecycle/revocation, deep links, distribution, network/VPN и допустимый offline. Доступ сейчас LAN; отдельная business DB не появляется.
2. **MOB-02:** реализовать недостающее versioned API поверх существующих доменов, error/retry/idempotency contracts, file scopes, permissions и revoked/terminated users; не создавать второй wallet/messages/completion store. Сохранить web compatibility и regression gate.
3. **MOB-03:** устанавливаемый signed vertical slice на реальных Android/iPhone: login, learning, server-confirmed progress, files/chat, logout/revocation на выбранной сети и доверенном HTTPS.
4. **MOB-04:** покрыть согласованную полную role/scenario matrix, accessibility/performance, offline conflict/retry и выбранный push scope. Хранение secrets на устройстве и потеря/смена роли требуют negative checks; PWA не подменяет полноценное приложение.
5. **MOB-05:** пилот, signed release/update channel, compatibility/version skew, diagnostics/support/rollback и мониторинг. Проверить установку/обновление устройств без потери данных и отзывать старые токены.

**Приёмка:** подтверждённый полный объём на реальных устройствах и штатном backend. **Откат:** предыдущая подписанная версия/ограничение rollout и совместимый server API; новые server schema changes требуют согласованного rollback. Неиспользуемый Next.js не запускается ради пункта аудита.

### B2B-01 — архитектурное решение; B2B-02 — поставка и клиентская приёмка

1. Утвердить **B2B-01 ADR**: отдельная установка на компанию либо multi-tenancy с доказанной изоляцией. Подразделения/роли одной компании не tenants. Определить hosting, domains, secrets/admin support boundary и договорённые SLA/RPO/RTO.
2. Для **B2B-02** реализовать разделение DB/business data/files/caches/queues/tokens/exports/logs и admin access по выбранной модели. На копии минимум двух компаний проверить cross-company API/IDOR/File API/background jobs и restore одного клиента без влияния на другого.
3. Подготовить versioned package/install/config outside code, migrations, upgrade/repeat/compatibility и uninstall/exit/export; работа с демо без настоящих данных сотрудников.
4. Организовать per-client monitoring/alerts, audited support, incident/update process, independent backup + restore-drill, resource/media limits и измеренную общую нагрузку. SLA не копировать из пилота 18 users.
5. До первого customer production закрыть Privacy/data-processing requirements, license/distribution review Moodle/темы/dependencies и конкретные terms выбранной поставки. Использовать официальные license texts и компетентную проверку условий; этот план не делает нового юридического заключения.
6. Пилот одной компании, подтверждённые gates/security/performance/recovery, затем контролируемый rollout. Kubernetes/microservices не являются обязательным следствием B2B.

**Приёмка:** выбранная изоляция доказана негативными и restore тестами; процессы поставки/поддержки/data/licenses согласованы. **Откат:** адресный клиентский snapshot/предыдущий package и совместимый contract без утечки/потери соседней компании. B2B delivery не зависимость регулярных копий текущей Академии.

### TECH-01 — управляемые миграции и рефакторинг

1. Выбрать фактический bottleneck/конфликт из PERF-01, AUDIT-01, security checks; определить supported upgrades и consumer/writer map. Large class/upgrade.php и `$DB` в foreach сами по себе не defect.
2. Разделять по доменному поведению с сохранением boundaries и one store; query optimizations подтверждать query count/plans/latency. Версионировать rule JSON и отделить historical apply scripts от штатного admin UI.
3. Для каждой правки использовать маленький PR, focused regression/concurrency tests, fresh/upgrade/repeat при schema changes и full gate точного SHA. Старые миграции/HR/evidence/ledger историю не удалять для сокращения исходников.
4. Перед declared STABLE/снятием rc выполнить согласованную release acceptance; обновить source maps/ADR/ledger с фактическим статусом.

**Приёмка:** измеримый эффект и сохранённые invariants; поддерживаемые paths воспроизводимы. **Откат:** previous exact release и coordinated restore при схеме/данных.

## Матрица полного покрытия аудита

| Вопрос / раздел аудита | Шаги и закрывающий результат |
|---|---|
| Runtime passport, §§1А, 2, 9, 10; E1–E9 | INF-01, dated evidence/manifest; текущие контекстные указатели DOC-02 |
| Роль БД / admin path, §§4.6, 7 / N1 | INF-02: minimal role, ownership, maintenance/restore acceptance |
| Код/config/старые dumps и ключи, §§4.6, 4.2, 7 / N2, N7 | INF-03: no runtime replace/write, protected secrets/archives |
| Cron, disabled tasks, H5P/SMTP, §§4.0, 1Б, 9Б / N4 | INF-05/06/11: single worker, binding, correct counters, external checks |
| Plugin/theme drift и core provenance, §§4.1, 9Б | INF-01, OPS-01, INF-08: exact scoped manifest + separate core identity |
| Backup/restore, §§4.2, 8 | INF-14 + OPS-02 **финал цикла**, согласованная scheduled копия и fresh restore |
| Один пульт, Telegram/NoData/observer, §4.3 | INF-06: verified failure/recovery delivery, independent heartbeat |
| Общие mail/DNS/ISPConfig, firewall/SSH/MFA, §§4.4, 7 | INF-10 + OPS-02: service matrix, protected admin path, host recovery |
| HTTP/cookies/внутренние клиенты, §4.5 / N3 | INF-07: trusted HTTPS и browser scenario acceptance |
| `/` 76%, лаборатория-гипотеза, logs, HDD/UPS, §4.7 / N5–N6 | INF-01/04/05/06: measured storage, safe inventory, healthy target, growth alerts |
| Moodle patch/lifecycle/major, §5.1 | INF-08/13, PLAT-01: available release + compatible stage/production gate |
| PHP parity, PG/OS/Next.js advisories, §§5.1, 5.3 | INF-09/10/13: runtime matrix и operational vulnerability process |
| Privacy/provider/data retention, §§5.2, 7 | PRIVACY-01: approved policy, export/delete tests; B2B gate |
| Dependabot/SECURITY/CODEOWNERS/security features, §5.3 | INF-13: реальные контакты, selected automation, no auto-deploy |
| Next.js unused, mobile/API/tokens, §§5.4, 7 | DOC-02/INF-13, MOB-01–05: separate client + existing business services |
| B2B isolation/install/support/data/licenses, §5.4 | B2B-01/02 + PRIVACY-01: ADR, two-company negative tests, packaging/DR |
| XMLDB/upgrade/schema/legacy/maturity/config, §5.5 | AUDIT-01 + TECH-01: explicit exception/migration gates и history retained |
| OPcache/workers/DB/MUC/sessions/latency/capacity, §6 | PERF-01: real web/passive baseline, isolated load, comparable tuning |
| SQLi/CSRF/XSS/ACL/IDOR/files/retries, §7 | INF-12 + MOB/B2B gates: concrete negative scenarios, no global clean claim |
| Registration/grades/protected account/ledger, §3, 1Б | INF-11/12, AUDIT-01: no automatic personnel/grade decisions; history/invariants |
| Выпуск/откат/ответственные/исключения и документация, §§8, 10 | Каждый шаг + INF-13: exact evidence, finite scope, owner/rollback register |

Незакрытые решения хранятся явно: оборудование/healthy HDD и независимая копия, наблюдатель и Telegram recipients, DNS/CA, реальные owners, окна и latency/concurrency/RPO/RTO/retention, supported platform, mobile stack, B2B hosting и data/licenses. Их отсутствие не заполняется догадками и не объявляется выполненной приёмкой.
