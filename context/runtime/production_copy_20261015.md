# Полная копия Академии без остановки прода

**Статус16Oct:** архив получен/инструкция передана; результат restore ожидается. Следующий этап — пульт/Telegram, Moodle5.2/PG17 отложены. Текущие статусы и остаток — [сверка](../audits/AUDIT_RECONCILIATION_20261016_RU.md); прежние next-step/порядок ниже относятся к своей дате.

Задача сисадмина — поднять на отдельном чистом сервере постоянную копию рабочей Академии из последнего завершённого бэкапа. Действующая Академия на server1 продолжает работать. Пользователи и пароли, курсы, файлы, прогресс, плагины, тема и главная USTAR берутся из бэкапа. У копии свой адрес, постоянные данные и автозапуск после перезагрузки; ограничения по времени нет.

**Данные копии соответствуют времени выбранного бэкапа, а не моменту передачи.** В выводе сборки и статусе копии есть `snapshot_completed_utc`. Изменения на проде после этого времени не попадут в копию и не синхронизируются автоматически. Новый бэкап с остановкой в этом сценарии не создаётся.

Почта Moodle, встроенный PHP sendmail и cron копии выключены, чтобы копия не дублировала рассылки и фоновые действия оригинала. Флаги задач и их очередь в восстановленной БД сохраняются. Прямые вызовы внешних интеграций из интерфейса этим режимом не блокируются; если они используются, для их проверки нужны тестовые подключения. Основной прод остаётся источником рабочих данных.

## Что передаём сисадмину

После этапа 1 передайте **два файла** `ustar-transfer.tar` и `ustar-transfer.tar.sha256`, эту инструкцию и SHA256, показанный при сборке. Внутри tar находятся зашифрованный архив Академии, его JSON, ключ расшифровки, точные образы web/PostgreSQL и необходимые скрипты. Отдельно скачивать образы и знать пароль старой БД не требуется.

**Внешний tar не зашифрован и содержит ключ и рабочие данные.** Передавайте через SFTP/WinSCP и храните приватно. В чат/Git комплект не загружайте. Резервная копия всего SSD, почта/DNS/ISPConfig, ОС и внешние сервисы в этот комплект не входят. Moodle и PostgreSQL используются в версиях из бэкапа; обновление до 5.3 не выполняется.

Новый сервер: Ubuntu 24.04 amd64, Python3, systemd, SSH и sudo, пустой Docker. Нужно не менее 5 GiB доступной RAM; место под распаковку, образы, БД и резерв 4 GiB проверит скрипт. `/var/lib` и `/opt` должны находиться на одной файловой системе. Недостающие Docker/age/util-linux можно установить командой этапа 4 из подписанных Ubuntu-репозиториев. Для этого нужен Интернет.

Новый IP/домен должен отличаться от адреса работающей Академии. Ни IP, ни DNS действующего прода не переключайте. Пример `192.168.1.61` замените адресом нового сервера. Прямой HTTP используйте в доверенной LAN/VPN; HTTPS-вариант ниже требует заранее настроенного TLS-прокси. Не запускайте этапы 3–4 на server1.

## Этап 1 На server1 собрать комплект

Владелец или администратор server1 сначала отдельно вводит пароль:

```bash
sudo -v
```

Затем вставляет весь блок. Он скачивает проверенный подготовительный скрипт, проверяет SHA256 и отправляет упаковку в systemd. Закрытие SSH не прервёт сборку.

```bash
bash <<'BASH'
set -euo pipefail
ustar_script="$HOME/USTAR_PREPARE_COPY_20261015.sh"
ustar_source=https://raw.githubusercontent.com/cauf1l3d/ustarlms
ustar_source=$ustar_source/38485343d87e91cdc8627a5aceb8c477997a068c/scripts/infra
curl -fsSL --connect-timeout 10 --max-time 60 \
    "$ustar_source/prepare_production_copy.sh" -o "$ustar_script"
printf '%s  %s\n' \
    781a73d96cff555a443dfb8fbc887871aa2f088ea0ed6d31fd8b026236c1d518 \
    "$ustar_script" | sha256sum --check --strict -
sudo -n bash "$ustar_script"
BASH
```

Скрипт устанавливает три проверенных restore helper и запускает **только `--pack`**. Выбирается последний успешный архив с HDD, проверяются SHA256, ключ и внутреннее содержимое. Затем создаётся отдельный комплект в `/srv/ustar-storage/transfers/<дата-суффикс>`. Нового backup, обслуживания, остановки контейнеров, вызова Moodle на проде и изменения его БД нет. Существующие архивы не перезаписываются. Nice/IO priority уменьшают приоритет чтения; упаковка всё равно использует диск и CPU.

Просмотр результата:

```bash
sudo journalctl -u 'ustar-copy-export-*' -n 60 --no-pager -o cat
```

Дождитесь `TRANSFER_PACK=PASS`. `Running as unit` означает только начало работы. В JSON запишите `directory`, `sha256`, `source_archive` и `snapshot_completed_utc`. Поле `production_stopped: false` означает, что экспорт не запрашивал остановку. Если бэкап слишком старый, не называйте комплект актуальным снимком сегодняшнего состояния: его дата указана явно.

## Этап 2 Передать через WinSCP

На server1 в WinSCP нажмите **Ctrl+O** и откройте точный `directory` из успешного вывода. Доступ к HDD через sudo SFTP уже настроен. Скачайте `ustar-transfer.tar` и `ustar-transfer.tar.sha256`. Передайте сисадмину оба файла и эту инструкцию, отдельно сообщив ожидаемый SHA256 и время бэкапа.

Сисадмин загружает оба файла в домашнюю папку SSH-пользователя **нового сервера**. Для чтения папки на HDD не меняйте рекурсивно права и владельца архивов. Старый комплект `od28adph` не содержит этот режим; нужен новый комплект по этапу 1.

## Этап 3 На новом сервере проверить и распаковать

Сначала отдельно выполните `sudo -v`, затем вставьте:

```bash
bash <<'BASH'
set -euo pipefail
cd "$HOME"
sha256sum --check --strict ustar-transfer.tar.sha256
sha256sum ustar-transfer.tar
sudo -n bash -s -- "$HOME/ustar-transfer.tar" <<'ROOT'
set -euo pipefail
umask 077
test ! -e /opt/ustar-transfer
test ! -L /opt/ustar-transfer
install -d -o root -g root -m 700 /opt/ustar-transfer
tar --no-same-owner --no-same-permissions -xf "$1" -C /opt/ustar-transfer
ROOT
BASH
```

Нужен результат `OK` и совпадение SHA256 с переданным владельцем. Существующий `/opt/ustar-transfer` не перезаписывается. Скрипт запуска дополнительно проверит каждый файл комплекта и внутренний архив до восстановления.

## Этап 4 На новом сервере поднять постоянную копию

Замените пример IP своим адресом. Эта команда предназначена **для нового сервера**:

```bash
sudo python3 -I -B -u /opt/ustar-transfer/restore.py \
    --start-production-copy --url http://192.168.1.61 \
    --install-dependencies
```

`PRODUCTION_COPY_START=SUBMITTED` означает начало импорта через systemd. Дождитесь завершения:

```bash
sudo python3 -I -B /usr/local/sbin/USTAR_PRODUCTION_RESTORE_20261015.py \
    --production-status
sudo journalctl -u ustar-production-import-20261015.service \
    -n 40 --no-pager -o cat
```

Нужны `PRODUCTION_COPY=PASS; PERMANENT; BOOT_ENABLED; MAIL_OFF; CRON_OFF` и `phase: ready`. Статус покажет `parallel_copy: true`, `mail_enabled: false`, `cron_enabled: false` и время бэкапа. После этого откройте **http://192.168.1.61/login/index.php** в обычном браузере. Используйте учётные записи из бэкапа; прежние веб-сессии сбрасываются.

У копии своя БД и новые локальные credentials. При смене адреса штатный Moodle search/replace обновляет прежний wwwroot в восстановленной БД. Контейнер PostgreSQL наружу не публикуется. Код web смонтирован read-only, пользовательские данные хранятся постоянно. Копия не истекает через час; после reboot используются те же данные и контейнеры без повторного импорта.

Для HTTPS при **уже настроенных** DNS, сертификате и TLS-прокси на `127.0.0.1:8082` используйте вместо предыдущей команды свой домен:

```bash
sudo python3 -I -B -u /opt/ustar-transfer/restore.py \
    --start-production-copy --url https://academy-copy.example.com \
    --bind 127.0.0.1 --http-port 8082 --behind-proxy \
    --install-dependencies
```

Команда не создаёт DNS, сертификат или прокси. Домен в примере нужно заменить.

## Что проверить сисадмину

1. Войти администратором и сотрудником, проверить главную USTAR и права ролей.
2. Открыть курс, файлы и SCORM; сверить прогресс на дату бэкапа.
3. Проверить `parallel_copy: true`, `mail_enabled: false`, `cron_enabled: false` в статусе и выключенный `ustar-academy-cron.timer`.
4. Перезагрузить только новый сервер в согласованное время, повторить вход и проверку контейнеров/статуса.

```bash
sudo systemctl is-enabled ustar-academy.service
sudo systemctl is-enabled ustar-academy-cron.timer || true
sudo docker ps --format 'NAME={{.Names}} STATUS={{.Status}}'
```

Для первой команды ожидается `enabled`, для таймера `disabled`. На новом сервере постоянная служба называется `ustar-academy.service`. Управление только копией на этом сервере:

```bash
sudo systemctl stop ustar-academy.service
sudo systemctl start ustar-academy.service
```

Данные при остановке не удаляются. При ошибке импорта скрипт останавливает только свои новые контейнеры и сохраняет файлы/закрытые журналы. Повторный старт поверх существующих данных запрещён; не удаляйте их для обхода ошибки без разбора причины.

Новые автоматические бэкапы на новом сервере настраиваются отдельно. Старый HDD scheduler привязан к server1 и UUID его диска. Этот сценарий не переключает пользователей на новую копию и не догоняет изменения рабочего прода. План реального переезда с финальным снимком описан отдельно в [production_transfer_20261015.md](production_transfer_20261015.md); его команды с остановкой **для текущей задачи не используются**.

## Версии и границы проверки

Исходный код: `a1a0c62bfcbb04df96d8797537599db11e692f4f`; подготовительный shell: `38485343d87e91cdc8627a5aceb8c477997a068c`, PR102. SHA256 controller `4804c27aada3d5f431ccf19cfb174dd8deb1bc6f991d3d9793d6a89b7be9b022`, launcher `369628cc4d24e4d44ffa56373cece25b38f69b30783f43770374d3bf77d4610f`, worker `b59e1e0e974138af6a9d5e3acca5c856d03ac4719c2c5a336985bb239c5c66eb`.

[CI 101/101](https://github.com/cauf1l3d/ustarlms/actions/runs/38773052825) на Ubuntu24.04 PASS без skips:45 controller +32 transfer +24 production, реальный PHP config/loopback HTTP и parser systemd для обоих постоянных режимов. Docker/PG/Moodle import и reboot моделируются. Первый реальный сбор нового комплекта, clean-host import, браузер/SCORM и reboot копии ещё не выполнены. [Validation](production_copy_validation_20261015.json). Общий review CI отдельно может падать на существующем npm-аудите frontend; успешный restore workflow не означает полностью зелёный release gate.
