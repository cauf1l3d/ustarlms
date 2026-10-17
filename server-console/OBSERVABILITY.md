# Пульт: метрики, health и алерты

**Текущая реализация первого LAN запуска17Oct:** [LAN выпуск и границы](LAN_ROLLOUT_20261017.md), [установка](INSTALL.md). Ниже сохраняется полный целевой дизайн16Oct; Prometheus/Alertmanager, web user admin, расширенные rules/restore/logs не объявляются поставленными. Первая LAN версия использует native bounded history/rules и отдельный fixed-operation broker.

**Реализация17Oct:** [сверка по аудиту](AUDIT_FOLLOWUP_20261017.md) — cron v3 receipt/сохранённый последний наблюдавшийся success, текущие SSD/HDD capacity+inodes и отдельная свежесть источников подключены в коде. Telegram/rules/independent observer/DB monitor ещё не поставлены; это не server1 acceptance.

Спецификация16Oct2026, не live-отчёт. [Архитектура](ARCHITECTURE.md) · [UI](DESIGN.md). Ниже стартовые пороги; после7дней измерений скорректировать по нормальной нагрузке и владельцу. Не выдавать их за гарантию SLA или уже настроенные правила.

## Health без ложного «всё хорошо»

Четыре независимых домена:

| Домен | Что подтверждает | Чего не подтверждает |
|---|---|---|
| Академия | Внешний/локальный HTTP, required containers, DB и cron | Успешность всех ролей/Quiz/SCORM без отдельной сценарной приёмки |
| Сервер | Достаточные CPU/RAM/IO/место, mounts, системные службы | Сохранность данных при потере всего сервера |
| Данные и бэкапы | Свежая completed копия, hash/manifest, HDD identity, recovery state | Проверенное восстановление, offsite и boot recovery, если evidence отсутствует |
| Мониторинг | Fresh scrape, правила, доставка и внешний observer | Доступность Академии при пропавшей телеметрии |

Состояния: **норма**, **внимание**, **авария**, **нет данных**, **обслуживание**. У каждого причина, время измерения, начало проблемы и переход к деталям. Ожидаемо выключенный стенд имеет нейтральный статус «остановлен», не warning. Unknown и stale никогда не зелёные. Если есть critical, он главный; иначе неизвестность обязательной проверки, затем warning, затем healthy. Все причины остаются видимыми, итог не заменяет компоненты.

Maintenance задаётся на конкретные объекты/проверки с deadline/причиной/actor. Временная HTTP-пауза backup может не рассылаться, но отображается. Disk failure, OOM, recovery_required, просрочка окна не подавляются. Ack означает «увидел», а не «исправлено». Backup stale не превращает Academy HTTP в Down: заголовок может быть «Академия доступна · Бэкап требует внимания».

Недоступный обязательный probe → failed; сломанный collector → NoData. Первый запуск с отсутствующей историей → «накапливаем данные», без выдуманного uptime. Общий health не выражается искусственным процентом97%.

## Метрики и начальные правила

W — внимание, C — авария. `% CPU` нормирован на все ядра; GB в UI — явно GiB/ГиБ для ресурсов. Пороги с временем должны непрерывно выполняться, если не указано окно событий.

| Объект / интервал | Что показываем | W | C |
|---|---|---|---|
| CPU /15s | Busy%, cores, load1/5/15÷cores, iowait/steal, CPU PSI | busy>85%10мин; normalized load>1 на10мин | busy>95%5мин вместе с очередью/pressure; один load не доказывает отказ |
| RAM /15s | Total, available GiB/%, working use, cache отдельно, swap in/out, memory PSI | available<15%5мин | <8%3мин; любой OOM немедленно |
| SSD и HDD /15s | Free bytes/%, inodes, growth, прогноз исчерпания с размером окна | <20% или root<8GiB10мин; inodes<10% | <10% или root<4GiB3мин; inodes<5%; недостаточен проверенный budget следующего backup |
| Mount/IO /15–30s | UUID/device/options, readonly, IO latency/queue/PSI, kernel IO errors | Sustained IO pressure + рост latency; конкретный порог после baseline | HDD отсутствует/не тот UUID/readonly или подтверждённые новые filesystem/IO errors |
| SMART/temp /15мин | Observation age, pending/uncorrectable/reallocated delta, drive health/temp | Новые pending/reallocated или превышен паспортный temp limit | SMART failed/uncorrectable; отсутствие доступа = NoData, не «диск здоров» |
| HTTP probes /30s | Local/external status, connect/TLS/TTFB/total, expected redirect target | total>1s5мин | 3 последовательных отказа≈90s; total>3s5мин; восстановление3успеха |
| Реальный HTTP /15–30s | RPS, запросы за окно, p50/p95/p99, 5xx, endpoint class | p95>1.5s10мин; 5xx>1%5мин | p95>3s5мин; 5xx>5%5мин |
| Сеть /15–30s | Rx/Tx, retransmits/drops; внешний RTT/loss если ICMP разрешён | Потери>2%5мин при≥20пробах; RTT>2×сопоставимого baseline10мин | ICMP сам по себе не означает отказ web; критичность по HTTP/связности |
| PostgreSQL /30s | Connect, active/max connections, blocks/oldest transaction, size/growth, deadlocks delta | connections>80%5мин; block>30s2мин; txn>5мин | Connect fail3раза; connections>90%2мин; block>120s2мин |
| Cron /30–60s | Last success/duration/exit, new task failures, effective-enabled overdue, adhoc oldest | Нет успеха>3мин, новые task errors | Нет успеха>10мин вне maintenance; растущая очередь с отказом worker |
| Prod containers /15s | Expected/running, ID/image/policy, uptime/exit/OOM, CPU/RAM/IO, restarts | ≥3рестарта10мин; неожиданный drift ID/image требует review | Unexpected stopped2опроса; OOM; ≥5рестартов10мин |
| Shared services /30s | Apache/Caddy/SSH/mail/DNS/MariaDB/etc: observed vs expected | Непроизводственный вспомогательный unit failed | Необходимый unit failed; продуктовый эффект отдельно; process up не доказывает SMTP/DNS функцию |
| Backup /60s | Last attempt и last completed отдельно, snapshot age/size/SHA, run phase, timer | Нет завершения к03:30MSK+30мин; age>26ч; failed attempt при наличии valid copy | Age>48ч; hash mismatch; recovery_required/службы не возобновлены; failed attempt и нет пригодной копии |
| Backup duration /60s | Elapsed/current phase/bytes, нормальная длительность | >3×p95 последних≥10успешных или >15мин до baseline | Истёк budget конкретного helper/maintenance; не убивать process по alarm |
| Restore/offsite /60s | Last verified restore/evidence, independent copy timestamp | «Не проверено/не настроено»; цель DR≤30дней только после принятия политики | Провал последней попытки restore отображается отдельно от hash-valid backup |
| Labs /30s | Name/purpose/source snapshot/URL/exposure, expiry, actual availability/resources | TTL+2мин, но ещё работает; expected-running probe failed | Неожиданная публичная публикация/смешение production target; stale data отдельно |
| TLS/clock /60s | Certificate validity для проверенного endpoint; time offset | TLS<14дней; clock offset>5s | TLS<3дней/invalid; certificate failure считается HTTP availability failure |
| Monitoring /15–60s | Last scrape/collector/rule eval, backlog, observer heartbeat | Источник старше3периодов; доставка>2мин | Старше6периодов; доставка>5мин; внешний heartbeat отсутствует120s |

У HTTP p95 и error-rate минимальная выборка100запросов/5мин. При меньшей выборке показываем n и абсолютное число ошибок; ≥5ошибок/5мин — отдельный W, доступность подтверждает probe. Длинные SCORM/download responses не смешивать с login/API; histogram buckets и low-cardinality route classes фиксируются при реализации. p99 при малой выборке не показывается как надёжная оценка.

PSI доступен не в каждой конфигурации; отсутствие → unsupported, не0. Swap used без активности не авария. Postgres long transaction не завершается автоматически. Forecast disk exhaustion появляется только при достаточной истории и устойчивом тренде, не на основании двух точек. Переполнение HDD не должно привести к записи бэкапа в пустую mountpoint на SSD: проверка UUID обязательна и в job preflight.

Исторические17/18 overdue и2 failed/disabled H5P/registration — зафиксированный остаток, не новые инциденты и не основание обнулить ошибки. Считать новые события и effective-enabled tasks; cron exit0 не равен успеху каждой задачи. Текущее число ошибок до измерения не известно.

## Что такое «пинг всей платформы»

1. **Сетевая задержка:** RTT/loss из указанной внешней точки. ICMP может быть запрещён; это явно показано.
2. **Ответ Академии:** HTTP connect/TLS/TTFB/total изнутри и снаружи; неожиданное redirect/login-loop считается отказом, arbitrary redirect host не разрешён.
3. **Опыт пользователей:** фактические RPS/p95/5xx на proxy. Browser rendering/SCORM interaction здесь не измерены; позже отдельный синтетический сценарий с тестовым аккаунтом и контролем writes.
4. **Внутренние зависимости:** DB/cron/containers/диски объясняют ухудшение. Локальный probe не доказывает доступность из интернета.

Availability за24ч/7дней = successful probes / evaluated probes с подписью покрытия. Отдельно показать failed, missing и maintenance duration. Missing не считается success; без достаточного покрытия не выдавать процент за SLA. Внешний observer хранит свой журнал, иначе потеря host стерла бы свидетельство outage.

## Доставка, группировка и жизненный цикл

Инцидент имеет стабильный ID/fingerprint, объект, severity, reason, first_seen/last_seen, observed values, окно, источник, related logs и действие владельца. Правила живут в Prometheus; API не пересчитывает другие пороги. Alertmanager группирует связанные alerts и подавляет дочерние уведомления при host/DB outage, сохраняя их детали в UI.

Начальная политика: group_wait30s, critical host-loss без дополнительной задержки после подтверждения observer; повтор C30мин, W4ч; recovery после2мин стабильности (HTTP минимум3успеха). Сохранять историю кратких флапов. Silence по умолчанию1ч, обязательно причина/actor/expiry. Не подавлять все аварии глобальным переключателем «обслуживание».

Проверять отдельно failure, recovery, NoData, Telegram delivery timeout, потерю server1 и недоступность самого observer. Внешний observer контролируется с другой стороны heartbeat/health, но полный отказ обоих требует отдельного канала. Не обещать абсолютную доставку при общем outage Telegram/сети.

## Важные логи

Источник: выбранные journald units, Docker stdout/stderr, proxy access/error, PHP errors, PG error events, USTAR cron/backup/job receipts. Первая страница≤200записей/256KiB, cursor pagination, max query range24ч; tail с backpressure. Не загружать весь journal в браузер.

Группировка по fingerprint и счётчику, фильтры объект/уровень/время. Для incident — окно до/после, correlation ID и sanitized message. Не каждый log ERROR создаёт alert: правила связаны с влиянием, частотой и новизной. PHP8.3 работает через Apache handler; не рисовать вымышленные PHP-FPM workers. Прочитанные private raw logs не хранятся в metrics labels/outbox.

## Проверка реализации

Тестовые fixtures: stale/missing, expected-off lab, exit0 с task failure, corrupt hash, отсутствующий HDD, очередь доставки, insufficient samples, clock skew, maintenance expiry. Отдельно sandbox tests auth/CSRF/path traversal/role limits/job idempotency. На native server — сверка нескольких CPU/RAM/FS/HTTP/backup значений с независимым CLI и измерение overhead. Реальные outage/restore тесты только в согласованном scope; документация не заменяет такую приёмку.

Основания: [Prometheus alert rules](https://prometheus.io/docs/prometheus/latest/configuration/alerting_rules/), [Alertmanager](https://prometheus.io/docs/alerting/latest/alertmanager/), [Linux PSI](https://www.kernel.org/doc/html/latest/accounting/psi.html). Числовые пороги выше — собственная начальная политика USTAR.
