# Пульт: требования аудита и изменения17Oct

Владелец17Oct16:30:32MSK подтвердил переход к пульту после сбора контекста. Предыдущий PR103/head98559017 содержал auth/read-only UI и исправления читаемости16Oct; после аудитов менялся контекст PR105, а не код пульта. Этот follow-up переносит приоритеты аудита в реализацию. Новый независимый audit result или установка на server1 не приписываются.

Основа: [fable §7](../context/reviews/20261017/submitted/fable.md), [review §7](../context/reviews/20261017/submitted/USTAR_REVIEW_20261016.md), [проверка спорных выводов](../context/reviews/20261017/README.md), [требования владельца](../context/runtime/control_panel_telegram_20261016.md), [наблюдения17Oct](../context/reviews/20261017/gaps_reconciliation.md). Ветка пульта включает проверенный контекст PR105/02fd394f; main и production этим не переключаются.

## Реализовано в этом изменении

| Требование | Реализация и граница |
|---|---|
| Четыре главных показателя перед графиками | Обзор: последний наблюдавшийся успех cron, возраст последней completed копии, SSD, код/задержка локального HTTP. У каждой карточки текстовый статус; неизвестное не заменяется нулём. Графики, контраст, шесть разделов и размеры текста16px/оси14px сохранены |
| Cron freshness | Чтение существующего v3 `/var/lib/ustar-cron/last-run.json` без Docker exec, Moodle bootstrap, SQL или запуска задач. Подтверждённый успех требует worker exit0, отсутствия новых task failures/USTAR error lines и полного вывода |
| Ошибка не должна стирать прежний успех | Приватный root-owned StateDirectory0700 и файл0600 сохраняют последний наблюдавшийся успех атомарно с fsync. Web identity не читает/меняет этот state. История начинается с первого наблюдения пульта; до него успех не выдумывается |
| Исторические задачи не новые инциденты | `scheduled_failed`/`scheduled_overdue_10m` показаны отдельно от `new_task_failures`. 18 задач не объявлены авариями по одним DB-флагам; effective-enabled overdue остаётся неизвестным до runtime-проверки настроек |
| SSD/HDD capacity и inode | Statvfs даёт available bytes, занято/зарезервировано %, total/free inode. Для HDD сохраняется точный UUID/rw mount gate. Warning: free<20% либо root<8GiB, inode free<10%; critical: free<10% либо root<4GiB, inode free<5%. Отсутствующий inode denominator — NoData |
| Свежий collector не делает старый источник свежим | Время каждой проверки фиксируется при завершении probe. Cron/backup содержат отдельную дату исходного отчёта; API вычисляет его возраст. Check старше45s становится NoData даже при свежем общем snapshot. Дубли check ID отвергаются; REQUIRED дополняет только отсутствующие проверки |
| Видимое время | Вверху — время snapshot в МСК и возраст; карточки возрастов обновляются локально. После stale45s главные числа не подтверждают исправность; исторический контекст и дата сохранены |
| HTTP failure / disabled / unknown | Опциональный фиксированный HEAD остаётся выключенным до проверки session/log side effects. HTTP503 сохраняет реальный код и latency без чтения тела. Отказ связи, отключённая проверка и неполученные данные различаются. Наличие ряда duration не выдумывает текущий HTTP200 |
| Внутренняя корпоративная сеть | Изоляция — уточнение владельца14:39MSK, не независимая perimeter-приёмка. Пульт остаётся loopback8098 за доверенным LAN/VPN HTTPS. Caddy2019 не используется как порт пульта. Independent observer нужен на отдельном доступном LAN/VPN узле; публичная публикация Академии не предполагается |
| Обновляемый прототип и комплект | Offline HTML пересобран из того же UI с явно synthetic adapter. CI проверяет актуальность прототипа и отсутствие сетевых запросов в browser fixture. Чистая сборка wheel исключает assets из старого setuptools build/lib |

Пороги cron: age>180s warning, >600s critical; worker failure critical, новые task errors warning. Это текущая оценка по receipt, а не уже развёрнутые Telegram alerts или правила с непрерывным окном. Проверенный maintenance window пока отсутствует; backup marker не подавляет тревоги автоматически. Полные правила длительности, dedup/recovery и bounded maintenance будут реализованы вместе с incident/outbox worker.

У snapshot остаётся TTL45s. Предложенные в review5мин не принимаются как улучшение. Пригодность backup по SHA/readback receipt и metadata не означает новое чтение гигабайт или успешный restore. Один HEAD latency не является p95 и не доказывает все пользовательские сценарии. Три PromQL history queries не означали отсутствия disk/backup facts: они уже были; здесь добавлены inode/capacity evaluation и первый экран.

## Контракт API и доступ

`GET /api/overview` по действующей серверной сессии читает только root-owned sanitized snapshot. В `cron` — даты последней попытки/последнего наблюдавшегося успеха, worker exit, счётчики ошибок и очереди, исходный status/reason. `checks` содержит одну запись `cron`, проверки `ssd_space/ssd_inodes/hdd_space/hdd_inodes`, отдельные observed/source timestamps. Сырые failure messages, имена задач из failures, stdout, конфиги и ключи не выдаются. Browser GET не запускает collector, cron, backup или Moodle. `/healthz` подтверждает только web service.

Изменяющие операции пока не добавлены. Plan/target/archive/actor/lock/revision/idempotency и подтверждение с явным scope остаются обязательным контрактом будущих jobs; общей кнопкой нельзя останавливать shared mail/DNS/ISPConfig. Текущие owner/viewer роли читают тот же набор показаний; TOTP/управление пользователями не объявлены реализованными.

## Открыто после этого изменения

- Read-only DB monitor с отдельной ролью/timeouts; запросы приложения через cluster superuser не добавлены. Наличие `pg_stat_statements` не предполагается.
- Реальная HTTP request statistics/p95/5xx с выборкой; независимый HTTP probe и host-loss observer, разрешённый сетевой путь и отдельная доставка.
- Правила инцидентов/ограниченного maintenance, durable outbox, numeric user+chat allowlists, Telegram retries/dedup/failure/recovery/NoData и подтверждённая доставка.
- Авторизованный download последнего completed/hash-verified зашифрованного архива, SFTP settings и ограниченные jobs. Key/transfer kit с ключом не выдаются ботом; новый backup по `/backup latest` не запускается.
- Runtime SMART/NTP/log adapters, sustained resource rules/retention и измерение overhead. Датированные owner archives не подставляются как live-метрики.
- Native LAN/VPN HTTPS/auth/denial/source matching/failure/recovery acceptance на server1. Результат clean-host restore/RTO сисадмина ожидается отдельно; Moodle5.2/PG17/Privacy deferrals сохранены.

## Проверка

Backend regression tests используют реальную опубликованную v3 receipt shape и проверяют failure после успеха, отсутствие истории, возраст, новые ошибки при exit0, malformed/future/truncated input, redaction, disk/inode полярность, реальные HTTP error codes и отдельную stale-проверку. Browser tests проверяют главные карточки, исторические counters, stale/source failure, disabled HTTP при наличии history, читаемость всех разделов/диалогов на1440/1024/768/390/320 и offline prototype без сетевых запросов. Systemd acceptance проверяет приватность нового StateDirectory на одноразовом Ubuntu CI runner.

Точные результаты локальных проверок и CI — в PR103 на опубликованном SHA. Synthetic fixtures/CI не являются показаниями server1. Агент не подключался к server1, не менял production, не создавал копий/restore и не отправлял Telegram messages.
