# Первый запуск в корпоративной сети

Владелец17Oct17:09MSK выбрал внутреннюю сеть. Комплект ставит отдельный пульт на **HTTPS:8443 выбранного LAN IPv4 server1**, без отдельного поддомена. Адрес Академии `http://ustar.local`, её контейнеры, Apache/Caddy, firewall/NAT и расписание бэкапа установщик не меняет. [Owner receipt23:36MSK](../context/runtime/console_native_install_20261017.json) подтверждает установку567e90d5 и limited native gate; HDD/SMART и другие native gates открыты. Owner23:59MSK сообщил открытие браузером. [Telegram и доступ из дома](REMOTE_ACCESS.md): на установленном567e90d5 сначала нужен startup hotfix. Команды ниже запускает владелец в SSH; прямого SSH-доступа у агента нет.

## Получение и установка

Откройте успешный запуск **USTAR console** на SHA из PR103. Скачайте artifact `ustar-console-<SHA>` и распакуйте ZIP на компьютере. Передайте **оба** файла `ustar-console.tar.gz` и `ustar-console.tar.gz.sha256` через WinSCP в `/home/aduk/`. GitHub token на server1 не нужен. Не используйте artifact другого SHA или незавершённого CI.

В существующем SSH-терминале aduk@server1:

```bash
cd /home/aduk
sha256sum --check --strict ustar-console.tar.gz.sha256
sudo install -d -m 0755 -o root -g root /opt/ustar-console-source
sudo tar --no-same-owner --no-same-permissions -xzf ustar-console.tar.gz -C /opt/ustar-console-source
cat /opt/ustar-console-source/ustar-console/SOURCE_COMMIT
sudo bash /opt/ustar-console-source/ustar-console/deploy/install.sh
sudo /opt/ustar-console/venv/bin/ustar-console-setup
```

Каждую команду выполнять после успеха предыдущей. SOURCE_COMMIT должен совпасть с выбранным CI. SHA256 из того же artifact проверяет передачу; происхождение — выбранный GitHub Actions на проверенном commit. Требования: Ubuntu24.04/Python3.12+venv, OpenSSL, Docker CLI, доступ к package index. Node, новый Docker и новая БД серверу не нужны.

Installer проверяет root ownership/отсутствие writable/symlink source, ставит wheel и hash-pinned зависимости. Создаёт отдельные identity `ustar-console` и `ustar-notify`, ограниченные units; пока ничего не запускает. Не добавлять web identity в docker/sudo/adm/journal или другие группы.

Локальный setup:

1. Показывает назначенные серверу IPv4; выбранный адрес обязан принадлежать серверу. Default использует известный LAN адрес только если он действительно назначен. Порт8443 должен быть свободен. Wildcard/public bind и LAN без TLS запрещены.
2. Однократно создаёт отдельные PostgreSQL роли/схему/function. Административное подключение `moodle` используется **только при настройке**. Обычный probe — `ustar_console_monitor`, read-only/timeouts, максимум2 соединения, только фиксированная aggregate function. `pg_stat_statements` и restart PG не нужны.
3. Создаёт отдельную локальную CA и сертификат с IP SAN, проверяет цепочку/IP. CA key root-private, web видит только leaf key. Если настройки прерваны после выпуска сертификатов, повторный setup использует существующую проверенную CA; частичный набор требует локальной проверки, автоматически не перезаписывается.
4. Предлагает логин/пароль и TOTP владельца. Пароль и bot token вводятся скрыто; не присылайте их в чат. Код после TOTP enrollment нельзя использовать второй раз: дождитесь следующего30s кода для входа.
5. Для отдельного Telegram-бота принимает token и numeric user IDs. Первый запуск поддерживает только явно перечисленные личные чаты с совпадающим user/chat ID. Existing webhook отклоняется; не удаляется. Enter вместо token откладывает Telegram. Последующая настройка: `sudo /opt/ustar-console/venv/bin/ustar-console-setup --telegram-only`.
6. Запускает пульт, fixed-operation broker, collector15s, SMART15min и, если настроен, notifier. Не запускает backup/restore/restart/lab в процессе установки.

HEAD к `/login/index.php` включён в LAN setup каждые15s с фиксированным Host `ustar.local`; может создавать обычные access-log/session записи. Не является p95 пользовательских запросов. Сборщик читает только агрегаты БД и существующий cron receipt; Moodle CLI/cron не запускается. При необходимости `local_http:false` в root-owned config отключает HEAD; после изменения перезапустить только console/collector.

## Доверие HTTPS на компьютере

Пульт покажет свой адрес и SHA256 fingerprint CA. Перенести на компьютер нужно **только публичный** `ustar-console-ca.crt`:

```bash
sudo cat /etc/ustar-console/ustar-console-ca.crt > /home/aduk/ustar-console-ca.crt
```

Скачайте его через WinSCP, сверив SHA256 fingerprint с выводом setup. В Windows откройте сертификат → «Установить сертификат» → текущий пользователь → «Доверенные корневые центры сертификации». Либо в обычном PowerShell своего пользователя:

```powershell
Import-Certificate -FilePath "$HOME\Downloads\ustar-console-ca.crt" -CertStoreLocation Cert:\CurrentUser\Root
```

Путь заменить фактическим расположением файла. Затем откройте HTTPS-адрес, показанный setup, например `https://192.168.1.60:8443`. Не переходите через предупреждение сертификата; проверьте IP/SAN/доверие. На телефоне нужна отдельная установка и явное доверие этой публичной CA. Leaf действует365дней: продление делать с сохранением CA и проверкой SAN, до истечения; automated renewal пока не реализован.

Если другой компьютер LAN не соединяется, проверьте `Test-NetConnection <LAN_IP> -Port 8443`, host firewall и маршрут. Установщик не открывает NAT и не перезаписывает firewall. Не публикуйте listener в интернет ради этого теста.

## Приёмка

Для установленного LAN пульта новый artifact содержит [guarded update](STORAGE_FIX_20261018.md): после root staging/checksum/SOURCE_COMMIT выполнить `sudo bash /root-owned-source/ustar-console/deploy/update.sh`. Этот путь заменяет только wheel, сохраняет настройки/CA/пользователей и прежние active states, отказывается при queued/running jobs и откатывает venv при ошибке. Полный setup повторно не запускать.

```bash
sudo /opt/ustar-console/venv/bin/ustar-console-accept
sudo systemctl status ustar-console.service ustar-console-ops.service ustar-console-collect.timer ustar-console-smart.timer --no-pager
```

Native receipt проверяет CA/TLS, fresh snapshot, обязательные службы и запрет чтения Docker/backup/CA key/private jobs веб-пользователем. `native_accepted` — ограниченный установочный gate; **не** приёмка всех источников, доставки, операций, производительности или восстановления. Отправлять агенту можно этот обезличенный вывод; secrets/config/raw journal не отправлять.

В браузере проверить вход/TOTP, телефон/desktop, новые timestamps, CPU/RAM/диски против CLI; выключенный источник не зелёный. Viewer должен получать403 на jobs/download. Учётную запись viewer создать локально:

```bash
sudo -u ustar-console /opt/ustar-console/venv/bin/ustar-console-user viewer --role viewer
```

Та же CLI сбрасывает пароль/роль и отзывает прежние сессии. `--totp enroll` подключает TOTP; `--totp disable` — локальное восстановление доступа через SSH. Web user administration пока не добавлено.

Для Telegram отправить своему боту `/start`, `/status`, `/metrics`, `/academy`, `/labs`, `/backup latest` с разрешённого numeric ID. Убедиться, что ответ пришёл именно владельцу, а посторонний аккаунт/группа игнорируются. В Events видны queue/backlog/transport; API ACK не доказывает прочтение человеком. Outbox хранится на диске, retries/429/recovery/NoData сохраняются после restart. Гарантия at-least-once: после обрыва между отправкой и записью ACK возможен повтор. Никакие host jobs ботом не выполняются.

Скачивание в разделе «Бэкапы»: текущий пароль/TOTP, session-bound grant120s, только последний completed .age, manifest SHA/readback + exact mount/metadata. Ключ и transfer kit не выдаются. После скачивания сравнить `Get-FileHash -Algorithm SHA256` с UI; получение файла не доказывает restore. Single range поддержан; новая ссылка нужна после expiry/logout/смены архива. Архив не перечитывается целиком каждые15s и не подписывается как newly rehashed.

Jobs сначала показывают target/outage/archive/expiry. Нужны typed action, текущий пароль/TOTP, CSRF, operator role, unchanged revision и idempotency. Нативную проверку начала/остановки lab или production outage выполнять только в подходящем рабочем окне; не нажимать backup/restart просто для проверки установки. Возможны отказы из-за cron/backup lock, helper hash mismatch и существующих данных lab. Не обходить их удалением lock/data или ослаблением прав.

## Независимая точка LAN

`observer.pyz` из комплекта перенести на **другой постоянно включённый компьютер корпоративной сети** с Python3.12. Нужны доступ к Академии/пульту и исходящий HTTPS к Telegram, публичная CA и **отдельный бот** с numeric IDs; тот же token нельзя использовать для двух pollers.

```text
python3.12 observer.pyz --configure
python3.12 observer.pyz
```

На Windows команда может называться `py -3.12`. Configure сохраняет token только локально; защищайте каталог ACL своего пользователя. Консольный observer хранит свои receipt/outbox, проверяет CA/IP и отдельно Academy HTTP;3 отказа или3 успеха с интервалом30s подтверждают переход. Для автозапуска используйте Task Scheduler/systemd на выбранном втором узле. Без фактического второго узла **host-loss не принят**. Обратный heartbeat в UI и контроль потери самого observer остаются открыты; dashboard не выдумывает его подключение.

## Границы и откат

[Текущий объём и открытые пункты](LAN_ROLLOUT_20261017.md). На первом LAN выпуске реальная история CPU/RAM/local HEAD хранится15дней в private collector SQLite и экспортируется как bounded sanitized series. Prometheus остаётся опциональным backend; Prometheus/Alertmanager не устанавливаются. Request p95/5xx, sanitized host logs, forecast/PSI, гибкая смена TTL и production restore — открыты, не заменяются синтетикой.

Откат доступа останавливает только пульт:

```bash
sudo systemctl disable --now ustar-console.service ustar-console-collect.timer ustar-console-smart.timer ustar-console-notify.service ustar-console-ops.service
```

Это не отменяет уже подтверждённые jobs. Проверьте отдельные `ustar-console-job-*` и lab unit; не убивайте running backup автоматически. Установка не удаляет auth/outbox/history/CA и архивы. Откат grant/schema PostgreSQL и сертификата доверия — отдельные явные действия; не удалять роли с зависимостями по случайной команде. CA/leaf key никогда не копировать в Git/Telegram.
