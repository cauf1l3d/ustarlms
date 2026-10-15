# USTAR: стенд на заданное время и перенос в четыре этапа

**Статус16Oct:** архив получен/инструкция передана; результат restore ожидается. Следующий этап — пульт/Telegram, Moodle5.2/PG17 отложены. Текущие статусы и остаток — [сверка](../audits/AUDIT_RECONCILIATION_20261016_RU.md); прежние next-step/порядок ниже относятся к своей дате.

**Owner15Oct19:48MSK: текущая задача — [постоянная копия без остановки прода](production_copy_20261015.md).** Для неё используются `--pack` и `--start-production-copy`; сценарий финального переезда с остановкой здесь сейчас не запускается.

**Исправление цели владельцем15Oct19:02MSK:** для переноса рабочей production Академии используйте [новую четырёхэтапную инструкцию](production_transfer_20261015.md). Ниже — прежняя изолированная тестовая копия; `--start` и `--duration 0` не делают её production. Для новых запусков также нужны обновлённые скрипты/комплектv2 из новой инструкции, прежние SHA ниже — история.

Актуально 15.10.2026. Последняя команда владельца: пульт отложить; закончить скрипт и простую инструкцию. Предыдущая очередь «сначала пульт» отменена. PR102, ветка `codex/restore-experiment-20261014`; исходный код `1b8bfdd566eb0759f8c0b6faa8078eb27564fcb0`.

[Сборщик/launcher](../../scripts/infra/backup_transfer.py), [контроллер](../../scripts/infra/backup_restore_test.py), [проверки](backup_transfer_validation_20261015.json). Исходники готовы; фактический запуск на server1, перенос архива владельца и браузерная приёмка нового восстановления ещё не подтверждены.

## Правка 15.10: главная USTAR в восстановленной копии

Владелец запустил `--start-lab --duration 2h` из пакета `20261015T124205Z-od28adph`; на `127.0.0.1:18086/` открылась штатная главная Moodle вместо USTAR.

Причина: после production-обновления 5.1.8 работает штатный `public/index.php`, а вход в USTAR задаётся только строкой `$CFG->defaulthomepage = '/local/ustar/home.php';` в production `config.php`. Pinned engine пересобирает `config.php` стенда из своего шаблона и эту строку теряет. У `theme_ustar` нет layout `frontpage`, поэтому `/` рендерит родительский Boost. Данные, тема и плагины в бэкапе целы.

Правка: контроллер читает `config.php` из распакованного бэкапа до перезаписи и переносит в стенд только `defaulthomepage` и `theme` — лишь если это ровно одно безусловное присваивание строкового литерала, значение в допустимом виде, а каталог темы существует в копии. Динамические, условные, закомментированные, внешние и многократные значения не переносятся и называются в отчёте. PHP не исполняется, credentials/wwwroot/mail/cron не читаются. После запуска Moodle проверяется, что перенесённые значения действительно применились; иначе `PRESENTATION_SETTINGS_NOT_EFFECTIVE` до старта relay, стенд останавливается.

В отчёт добавлены `presentation_settings_carried`, `presentation_settings_skipped`, `effective_theme`, `effective_defaulthomepage`; в `--status` — `presentation`. Engine и checker не менялись. Локально: 45 тестов контроллера + 23 переноса = 68 PASS под root.

Действия: текущий стенд пересоздавать не нужно — открыть `/local/ustar/home.php`; следующий стенд запускать уже с исправленными скриптами. Пакет `od28adph` содержит старый контроллер, для нового сервера комплект нужно собрать заново (`--pack`).

## Перенос в четыре этапа

Целевой сервер: Ubuntu 24.04 LTS **amd64**, SSH, Python3, systemd, минимум 5 GiB доступной RAM и место под архив, распаковку, образы и новую БД с резервом 4 GiB. Docker должен быть пустым, включая остановленные контейнеры. Недостающие Docker/age/util-linux установит этап 4 из репозиториев Ubuntu; для этого нужен доступ к ним. Образы Moodle/PostgreSQL входят в бэкап и не скачиваются из Docker Hub.

### 1. На старом сервере собрать комплект под sudo

В SSH на server1 сначала отдельно ввести пароль:

```bash
sudo -v
```

Затем вставить блок целиком. Он устанавливает два проверенных скрипта и собирает комплект из **последнего успешно завершённого HDD-бэкапа**. Нового snapshot/остановки production нет.

```bash
sudo -n bash <<'ROOT'
set -euo pipefail
umask 077
for ustar_unit in ustar-backup-recovery-20261015.service ustar-restore-test-20261014.service; do
    test "$(systemctl show "$ustar_unit" -p MainPID --value)" = 0
done
ustar_source=https://raw.githubusercontent.com/cauf1l3d/ustarlms/1b8bfdd566eb0759f8c0b6faa8078eb27564fcb0/scripts/infra
ustar_tools=$(mktemp -d /root/ustar-transfer-tools.XXXXXXXX)
cd "$ustar_tools"
curl -fsSL --connect-timeout 10 --max-time 60 "$ustar_source/backup_restore_test.py" -o controller.py
curl -fsSL --connect-timeout 10 --max-time 60 "$ustar_source/backup_transfer.py" -o transfer.py
printf '%s  %s\n' 4804c27aada3d5f431ccf19cfb174dd8deb1bc6f991d3d9793d6a89b7be9b022 controller.py 6633ba331a15cb08e37b9de8d092141fa56592a79d4959003be68a10870a0c64 transfer.py | sha256sum --check --strict -
test ! -L /usr/local/sbin/USTAR_BACKUP_RESTORE_TEST_20261014.py
test ! -L /usr/local/sbin/USTAR_BACKUP_TRANSFER_20261015.py
install -o root -g root -m 600 controller.py /usr/local/sbin/USTAR_BACKUP_RESTORE_TEST_20261014.py
install -o root -g root -m 600 transfer.py /usr/local/sbin/USTAR_BACKUP_TRANSFER_20261015.py
python3 -I -B -u /usr/local/sbin/USTAR_BACKUP_TRANSFER_20261015.py --pack
ROOT
```

Успех: `TRANSFER_PACK=PASS`. Поле `directory` покажет точный каталог `/srv/ustar-storage/transfers/<дата-суффикс>/`; `source_archive` — выбранный бэкап, `sha256` — сумму комплекта. Имя сегодняшнего архива заранее не подставляется. После ошибки зависимые этапы не выполнять.

### 2. Через WinSCP скачать и передать два файла

В существующей сессии WinSCP на server1 нажать **Ctrl+O**, открыть путь из `directory` и скачать `ustar-transfer.tar` и `ustar-transfer.tar.sha256`.

Уже настроенный SFTP-сервер `sudo -n /usr/lib/openssh/sftp-server -R -d /srv/ustar-storage` позволяет скачать файлы от root без изменения прав HDD. На **новом** сервере создать обычную SFTP-сессию и загрузить оба файла прямо в домашний каталог SSH-пользователя. Старую настройку sudo/read-only SFTP на новый сервер не копировать.

**Внутри tar есть ключ расшифровки и все данные бэкапа.** Передавать и хранить комплект как секрет; не прикладывать в чат или Git. Отдельно переносить ключ для этих четырёх этапов больше не требуется. Существующую независимую копию ключа сохранить.

### 3. На новом сервере проверить и распаковать

Войти по SSH пользователем, в чей домашний каталог загружены файлы. Отдельно выполнить `sudo -v`, затем:

```bash
bash <<'BASH'
set -euo pipefail
cd "$HOME"
sha256sum --check --strict ustar-transfer.tar.sha256
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

Ожидается `ustar-transfer.tar: OK`; сумма соответствует этапу 1. Каталог назначения должен быть новым. Извлекаются восемь обычных файлов с приватными правами, включая `restore.py` и `transfer.json`.

### 4. Одной командой поднять копию без ограничения времени

```bash
sudo python3 -I -B -u /opt/ustar-transfer/restore.py --start --install-dependencies
```

У `--start` по умолчанию `--duration 0`. После закрытия SSH восстановление продолжает работать в systemd. `RESTORE_START=SUBMITTED; NOT_YET_READY` — запуск принят, но Moodle ещё не готов. Проверка:

```bash
sudo python3 -I -B /usr/local/sbin/USTAR_BACKUP_RESTORE_TEST_20261014.py --status
```

Готовая копия: `phase: ready`, `unlimited: true`, `hold_seconds: 0`, `expires_at: null`. В журнале: `RESTORE_TEST_READY=PASS; ACTIVE_FOR_SECONDS=UNLIMITED`. Журнал установки зависимостей приватный: `/var/lib/ustar-recovery-bootstrap/`. Флаг установки нужен только на чистом целевом сервере.

## Открыть Академию

В Windows PowerShell оставить открытым SSH-туннель, заменив пользователя/IP на данные нового сервера:

```powershell
ssh -N -L 18086:127.0.0.1:18086 USER@NEW_SERVER_IP
```

В браузере: `http://127.0.0.1:18086/login/index.php`. Учётки соответствуют моменту бэкапа. Если локальный порт занят старым туннелем, закрыть тот туннель. Проверить вход администратора/сотрудника, роли, файлы, SCORM и прогресс.

## Стенд на server1 с нужной длительностью

После установки скриптов из этапа 1 запуск — одна команда:

```bash
sudo python3 -I -B -u /usr/local/sbin/USTAR_BACKUP_TRANSFER_20261015.py --start-lab --duration 2h
```

| Параметр | Время после готовности Moodle |
|---|---|
| `--duration 30m` | 30 минут |
| `--duration 2h` | 2 часа |
| `--duration 1d` | Сутки |
| `--duration 0` | Без автоматической остановки |
| Без параметра при `--start-lab` | 1 час |
| Без параметра при `--start` | Без таймера |

Конечный срок — максимум 30 суток. Контроллер, HTTP relay и systemd watchdog согласованы; прежний скрытый предел 2h в новом launcher снят. Подготовка отдельно ограничена одним часом, отсчёт выбранного времени начинается после готовности Moodle. Это параметр первоначального запуска, не изменение срока уже работающего стенда. Для server1 туннель: `ssh -N -L 18086:127.0.0.1:18086 aduk@192.168.1.60`.

Статус и досрочная остановка:

```bash
sudo python3 -I -B /usr/local/sbin/USTAR_BACKUP_RESTORE_TEST_20261014.py --status
sudo systemctl show ustar-backup-recovery-20261015.service -p ActiveState -p SubState -p Result -p ExecMainStatus --no-pager
sudo journalctl -u ustar-backup-recovery-20261015.service -n 40 --no-pager -o cat
```

```bash
sudo systemctl stop ustar-backup-recovery-20261015.service
```

Новый launcher использует **`ustar-backup-recovery-20261015.service`**. Старую `ustar-restore-test-20261014.service` с жёстким watchdog не запускать по прежней инструкции. Останавливаются/удаляются только контейнеры этого восстановления; данные, отчёты и исходный бэкап остаются. Каталог состояния/отчёта: `/var/lib/ustar-restore-test/20261014/`. При конечном сроке ожидается `duration_completed: true`; старое `one_hour_completed` истинно только для 1h. Повторный запуск при существующих данных отклоняется; не удалять их для обхода без отдельного решения.

## Состав, проверка и границы

Комплект включает выбранный `.tar.gz.age` и `.json`, `recovery.key`, `restore.py`, контроллер, private restore engine/verifier и `transfer.json` с размерами/SHA256. В зашифрованном бэкапе: логическая БД, полный public/config/core/plugins/theme, moodledata и два Docker-образа. Сборщик проверяет расшифровку и внутренние суммы без создания открытого восстановления; исходный архив сохраняется.

| Компонент | SHA256 |
|---|---|
| `backup_transfer.py` | `6633ba331a15cb08e37b9de8d092141fa56592a79d4959003be68a10870a0c64` |
| `backup_restore_test.py` | `4804c27aada3d5f431ccf19cfb174dd8deb1bc6f991d3d9793d6a89b7be9b022` |
| Private `USTAR_RESTORE_LAB_20261004.py` | `71f6a1c45c25bfe97fe0be7d0a362bb5a72e742b77c167f3783f526c85950dac` |
| Private `USTAR_RESTORE_PREFLIGHT_20261004.py` | `e00577aefcbcdd8ae3ebc1f30f98c899c3efd3b2b4e593b6ce0d26d09e2756da` |

Локально повторно прошли 36 controller + 23 transfer теста без skips; Docker/БД/apt/lifetime моделируются. На точном исходном SHA [Ubuntu24.04 root CI38726287776](https://github.com/cauf1l3d/ustarlms/actions/runs/38726287776) SUCCESS: 59 тестов и настоящий systemd parser для legacy/generated 0/2h units. [Context38726287799](https://github.com/cauf1l3d/ustarlms/actions/runs/38726287799) SUCCESS. [Общий review38726287787](https://github.com/cauf1l3d/ustarlms/actions/runs/38726287787) FAILED на npm audit неизменённых Next/sharp; source PHP8.2/8.3 PASS, DB/rollback/full RC не запускались.

Без таймера — работа до ручной остановки/отказа/перезагрузки. Автозагрузка после reboot ещё не реализована. У копии отключены почта/cron/внешние интеграции; сессии очищены, URL/cookie/siteidentifier и новая БД изолированы. Восстанавливается версия Moodle из бэкапа без обновления до 5.3. `postgres-globals-private.sql` сохраняется, но не исполняется; приложение получает новую БД/роль. Это автономная копия Академии для проверки. Публичный production-доступ с DNS/TLS и перенос ОС/почты/DNS/ISPConfig — отдельная работа. Реальный clean-server restore и браузерная приёмка остаются pending до вывода владельца.

По ранее принятому owner evidence Moodle5.1.8 и обновление хоста/kernel147/Docker29.9.0 уже выполнены; не повторять. PR101/Moodle5.3/PG17 preparation сохраняется, major migration не выполнена, ошибка маршрута отложена. SSD archive общего хоста этим скриптом не используется. [Прежний часовой сценарий](backup_restore_experiment_20261014.md) и [его validation](backup_restore_test_validation_20261014.json) — история первой редакции.
