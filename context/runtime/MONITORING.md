# Метрики и алерты Академии

Owner16Oct выбрал [пульт с Telegram](control_panel_telegram_20261016.md) следующей задачей. Общий backend/бот/проверенная доставка пока не внедрены. Прототип PR103 показывает UI; demo values не являются метриками.

| Контур | Что показывать |
|---|---|
| Host | CPU/load/RAM/swap, SSD/HDD bytes+inode/growth, SMART observation age, uptime/boot |
| Academy | HTTP readiness/latency, exact container state/identity, PHP/DB availability, selected error rate |
| Cron | last start/finish/exit/duration, fresh success, effective-enabled overdue, adhoc age/new failures |
| Backup | latest completed file/time/bytes/hash/mount, last run failure/resume, offsite/restore age separately |
| Labs | URL/port/state/purpose and expiry; source prod distinct |
| Shared services | Apache/mail/DNS/ISPConfig/MariaDB availability in measured scope |
| Observer | heartbeat/NoData and loss of host/panel from outside this host |

Наличие process exit0 не отменяет task-level failure. Исторические17/18 overdue и2 failed/disabled H5P/registration не превращать в новые incidents и не обнулять ради зелёного UI. Snapshot age, collector age и NoData всегда видимы.

Пороги/retention/resource budgets фиксируются при реальном collector baseline. Failure/recovery/NoData и сбой самой Telegram доставки нужно испытать с реальным разрешённым получателем. Same-host бот/пульт не является независимым монитором потери всего host.

Получатели Telegram и токен пока не настроены. Не отправлять PII, recovery key, config, полные DB/log output. `/backup latest` выбирает проверенный immutable encrypted archive; для крупного файла — авторизованная download-ссылка пульта, а не ложное обещание upload большого private kit через обычный Bot API.
