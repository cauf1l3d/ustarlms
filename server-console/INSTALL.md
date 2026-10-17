# Установка первого рабочего среза пульта

Этот выпуск показывает состояние и не управляет Академией. Он устанавливается отдельно от Moodle, не меняет её контейнеры, cron, БД, бэкапы и прокси. Реальная установка на server1 и её приёмка пока не выполнены. Следующие этапы: правила/Telegram/внешний наблюдатель, защищённое скачивание и операции.

## 1. Получить проверенный комплект

В GitHub Actions откройте успешный **USTAR console → console-readonly**, скачайте artifact `ustar-console-<SHA>` именно нужного коммита. В нём два файла: `ustar-console.tar.gz` и `ustar-console.tar.gz.sha256`. Комплект содержит только пульт и его инструкцию; архивы Академии и ключи в него не входят.

Передайте оба файла на server1 через WinSCP. В терминале из папки с файлами:

```bash
sha256sum --check --strict ustar-console.tar.gz.sha256
sudo install -d -m 0755 -o root -g root /opt/ustar-console-source
sudo tar --no-same-owner --no-same-permissions -xzf ustar-console.tar.gz -C /opt/ustar-console-source
```

Проверьте `SOURCE_COMMIT` внутри комплекта с SHA успешного CI. Контрольная сумма обнаруживает повреждение передачи; происхождение подтверждается получением из своего GitHub Actions на выбранном SHA.

Требуется Ubuntu24.04, Python3.12 с `venv`, доступ к Python package index для установки фиксированных зависимостей и существующий Docker CLI. При отсутствии venv установите `python3-venv` через обычный утверждённый процесс обновления хоста. Новый Docker, Node и отдельная БД на server1 не нужны.

```bash
sudo bash /opt/ustar-console-source/ustar-console/deploy/install.sh
```

Установщик создаёт пользователя `ustar-console`, отдельный venv и три systemd unit. Он **не запускает службы**. Пользователь пульта не должен состоять в `docker`, `sudo`, `adm`, `systemd-journal` или других дополнительных группах. Не добавляйте эти группы, если источник метрик недоступен.

## 2. Настроить закрытый HTTPS-доступ

До запуска снять фактические listeners командой `sudo ss -ltnp`, определить действующий proxy и VPN. Пульт слушает **127.0.0.1:8098**, существующий адрес Академии **127.0.0.1:8082** не меняется. Порт8098 не публикуется в интернет.

Сисадмин выделяет HTTPS listener на **выбранном адресе корпоративной сети или VPN server1**, например порт8443, с сертификатом, которому доверяют компьютер и телефон владельца. Отдельный поддомен не требуется. Для доступа из корпоративной сети нужен доверенный сертификат; для удалённого доступа — настроенный VPN и сертификат; скрипт их не создаёт и firewall не меняет. Нельзя использовать открытый HTTP для входа.

В `/etc/ustar-console/config.json` задайте точный внешний origin без завершающего `/`, например `https://VPN_IP:8443`. `VPN_IP` в примере обязательно заменить фактическим адресом. Сохранить root:ustar-console и права0640.

Gateway проксирует **весь корень выделенного listener** на `http://127.0.0.1:8098`. Должен сохранять внешний `Host` и задавать `X-Forwarded-Proto: https`; `X-Forwarded-For` перезаписывается реальным адресом клиента. Приложение доверяет proxy-заголовкам только от127.0.0.1 и проверяет Origin у всех POST. Путь `/console/` первым выпуском не поддерживается. Существующий vhost Академии и конфигурацию других служб не заменять.

Пароли, user/session state: `/var/lib/ustar-console/auth.sqlite3`; права каталога0700, БД0600. Создайте свою учётную запись локально, пароль вводится скрыто и не попадает в аргументы команды:

```bash
sudo -u ustar-console /opt/ustar-console/venv/bin/ustar-console-user owner --role owner
```

Эта же команда сбрасывает пароль и отзывает прежние сессии. Публичной регистрации и стандартного пароля нет. Все три роли в этом выпуске читают одни данные и могут менять только собственный пароль/сессию; host-операций и web-управления пользователями ещё нет. TOTP остаётся следующим hardening-этапом; текущий доступ ограничен VPN.

## 3. Запустить и сверить источники

```bash
sudo systemctl enable --now ustar-console-collect.timer ustar-console.service
sudo systemctl start ustar-console-collect.service
sudo systemctl status ustar-console.service ustar-console-collect.timer --no-pager
```

Сборщик запускается отдельно раз в15секунд. Root необходим ему для чтения закрытых отчётов и двух фиксированных Docker inspect; полный Docker socket не доступен веб-пользователю. Systemd ограничивает сборщик по времени, памяти и записи. Если источник недоступен, настраивайте его отдельно: не расширяйте права веб-службы.

Подключённые источники:

| Данные | Источник и граница |
|---|---|
| CPU/RAM/load/SSD | `/proc` и statvfs; RAM=MemAvailable, CPU — короткое моментальное измерение. SSD/HDD: available bytes, занято/зарезервировано %, total/free inode; текущие пороги не заменяют правила устойчивой нагрузки |
| Cron | Только чтение `/var/lib/ustar-cron/last-run.json` v3. Worker exit, новые failures и USTAR error lines раздельны; старые faildelay/overdue не новые инциденты. Последний наблюдавшийся success — приватный `/var/lib/ustar-console-collector/cron-success.json` (root0700/0600), без запуска cron/Moodle/SQL |
| Контейнеры | Только ustar_moodle и ustar_postgres; status/running/OOM/restarts/policy/start time; Env и полный inspect не читаются |
| HDD | Точный mount `/srv/ustar-storage`, UUID359a2bae-4e79-461a-ab72-1597f605d801 и rw |
| Бэкап | Последний success/attempt и recovery marker; whitelist manifest, размер и metadata архива. SHA — из отчёта о readback при создании, **не новая проверка гигабайт** |
| Стенд | Ограниченные поля state контроллера14Oct, без token/key/config. Реальный container/probe стенда ещё не подключён |
| События | Только ограниченный журнал безопасности самого пульта, до100 строк на ответ. Сырые журналы сервера не выдаются |

У каждой проверки есть время получения и отдельно дата исходного cron/backup отчёта. Возраст бизнес-данных не обнуляется свежим collector snapshot; REQUIRED placeholders не дублируют подключённые проверки. Измерение старше45секунд становится «нет данных»; исторические значения остаются с датой. При пропаже collector и при потере соединения UI не подтверждает текущую исправность. `/healthz` подтверждает только работу веб-службы, не здоровье Академии.

Локальный HTTP-проверщик по умолчанию выключен: HEAD `/login/index.php` может создавать Moodle-session/access-log записи. После проверки этого эффекта можно **явно** включить его drop-in:

```bash
sudo systemctl edit ustar-console-collect.service
```

```ini
[Service]
ExecStart=
ExecStart=/opt/ustar-console/venv/bin/ustar-console-collect --local-http
```

```bash
sudo systemctl daemon-reload
sudo systemctl start ustar-console-collect.service
```

Он выполняет только фиксированный loopback HEAD, без redirect/cookies/тела; локальный ответ не подтверждает доступность снаружи.

История подключается опционально к существующему Prometheus: задайте `"prometheus": "http://127.0.0.1:9090"` и перезапустите только `ustar-console.service`. Нужны ровно один node_exporter target с job `ustar-node` и, отдельно, HTTP probe job `ustar-http-external` из независимой разрешённой точки корпоративной сети/VPN. Имя job не означает публикацию Академии в интернете. API разрешает только CPU/RAM/external response, периоды1h/24h/7d/15d, не более721точки, таймаут2секунды. При отсутствующих/неоднозначных series — «нет данных». Сырые PromQL и внешний URL клиент передать не может. Установку и правила Prometheus/Alertmanager этот комплект пока не выполняет.

БД-monitor role, probe/observer из независимой точки корпоративной сети/VPN, alert evaluation/delivery и важные host logs пока остаются **неподключёнными**. Поэтому суммарный health честно показывает неизвестность этих областей. Зелёное измерение ресурсов означает получение показаний; проверку устойчивой нагрузки подтверждают отдельные правила, которые пока не подключены.

## 4. Приёмка и отключение

Войти с компьютера и телефона через согласованный VPN/HTTPS. Проверить шесть разделов, выход, ошибочный пароль и смену пароля. Сравнить RAM/CPU/load/df, Docker состояния и дату/SHA бэкапа с независимыми CLI; проверить метаданные и реальное наличие HDD. Убедиться, что у пульта недоступны ключи/config/данные Академии, а API не имеет host-операций. На первом реальном запуске измерить overhead и дисковый рост; systemd лимиты не являются доказательством фактического потребления.

Для проверки stale остановить **только таймер сборщика пульта**, подождать больше45секунд, убедиться в «нет данных», затем включить таймер обратно. Академию не останавливать ради этой проверки. Проверку после reboot выполнить в согласованное окно, не инициировать reboot только ради установки пульта.

Диагностика:

```bash
sudo journalctl -u ustar-console.service -u ustar-console-collect.service -n 60 --no-pager
sudo systemctl show ustar-console.service -p User -p Group -p MemoryCurrent
```

Для отключения остановить только созданные units:

```bash
sudo systemctl disable --now ustar-console.service ustar-console-collect.timer
sudo systemctl stop ustar-console-collect.service
```

Пользователь, состояние и установленный код сохраняются. Контейнеры, production cron и backup timer не затрагиваются. Пульт не заменяет SSH/rescue и внешний мониторинг.

## Локальная сборка и проверки

Python3.12, Node24; из `server-console/`:

```bash
python3 -m venv /tmp/ustar-console-build
/tmp/ustar-console-build/bin/pip install --require-hashes -r requirements-dev.lock
/tmp/ustar-console-build/bin/pip install --no-deps --no-build-isolation -e backend
PATH="/tmp/ustar-console-build/bin:$PATH" bash build-release.sh
/tmp/ustar-console-build/bin/pytest -q tests
cd ui
npx playwright install --with-deps chromium
CONSOLE_TEST_PYTHON=/tmp/ustar-console-build/bin/python npm test
```

Browser tests используют изолированные fixtures и настоящий auth/API; синтетические ресурсы в тестах не являются показаниями server1. Desktop/mobile/tablet screenshots сохраняются в CI artifacts. Native server acceptance, Telegram и восстановление сисадмина этим CI не подтверждаются.
