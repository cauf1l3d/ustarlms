# Перенос рабочей Академии на чистый сервер

Это постоянное восстановление production из финального бэкапа: пользователи, курсы, файлы, прогресс, код Moodle и плагины, настройки главной и темы. Новая Академия получает рабочий адрес, автозапуск после reboot и cron. **Это не стенд с таймером.** Версия Moodle и PostgreSQL берётся из бэкапа, обновление до5.3 не выполняется.

Подготовлен код `99ab241e1771b850bfedb3a5cab03ad4a2122917`, PR102. Проверки кода не заменяют первый реальный перенос: native приёмка на чистом сервере, reboot, вход/SCORM и доставка SMTP пока не выполнены. [Точные результаты](production_transfer_validation_20261015.json). [CI92/92 и systemd parser](https://github.com/cauf1l3d/ustarlms/actions/runs/38769562812) прошли на Ubuntu24.04 без skips; Docker/PG и reboot в этих тестах моделируются.

До начала: подготовьте SSH/sudo и WinSCP к обоим серверам, новый IP/адрес и окно недоступности. Новый сервер: Ubuntu24.04 amd64, Python3, systemd, **пустой Docker**, не менее5GiB доступной RAM. Скрипт проверит место под распаковку, образы, новую БД и резерв4GiB; `/var/lib` и `/opt` должны находиться на одной файловой системе. Недостающие Docker/age/util-linux устанавливаются из подписанных Ubuntu-репозиториев. Интернет для скачивания этих пакетов нужен, образы Moodle/PG входят в комплект.

**Этап1 начинает окно недоступности.** Старая Академия останется остановленной до завершения переезда или явной отмены. Администраторам тоже нельзя продолжать работу после начала финального снимка. Почта/DNS/ISPConfig и ОС старого хоста не переносятся и не останавливаются. Старому общему хосту нельзя забирать IP целиком ради Академии: он продолжает обслуживать другие системы.

## 1. На server1 собрать финальный комплект под sudo

Сначала отдельно:

```bash
sudo -v
```

Затем один блок. Он устанавливает проверенные скрипты и отправляет сборку в systemd, чтобы закрытие SSH не прервало переезд.

```bash
sudo -n bash <<'ROOT'
set -euo pipefail
umask 077
for ustar_unit in ustar-backup-recovery-20261015.service \
                  ustar-restore-test-20261014.service; do
    test "$(systemctl show "$ustar_unit" -p MainPID --value)" = 0
done
ustar_source=https://raw.githubusercontent.com/cauf1l3d/ustarlms
ustar_source=$ustar_source/99ab241e1771b850bfedb3a5cab03ad4a2122917/scripts/infra
ustar_tools=$(mktemp -d /root/ustar-production-tools.XXXXXXXX)
cd "$ustar_tools"
curl -fsSL --connect-timeout 10 --max-time 60 \
    "$ustar_source/backup_restore_test.py" -o controller.py
curl -fsSL --connect-timeout 10 --max-time 60 \
    "$ustar_source/backup_transfer.py" -o transfer.py
curl -fsSL --connect-timeout 10 --max-time 60 \
    "$ustar_source/production_restore.py" -o production.py
cat <<'SHA' | sha256sum --check --strict -
4804c27aada3d5f431ccf19cfb174dd8deb1bc6f991d3d9793d6a89b7be9b022  controller.py
1a075a5bd6f995ef7db2dd255470103175cde28d23a253a9cd72fb89d12cdcd8  transfer.py
3edb4de42a84e855302045d307390fb13156e7b9acabcf688fb720c743f42854  production.py
SHA
install -o root -g root -m 600 controller.py \
    /usr/local/sbin/USTAR_BACKUP_RESTORE_TEST_20261014.py
install -o root -g root -m 600 transfer.py \
    /usr/local/sbin/USTAR_BACKUP_TRANSFER_20261015.py
install -o root -g root -m 600 production.py \
    /usr/local/sbin/USTAR_PRODUCTION_RESTORE_20261015.py
ustar_export_unit=ustar-production-export-$(date -u +%Y%m%dT%H%M%SZ)
systemd-run --unit="$ustar_export_unit" --service-type=exec \
    --property=RemainAfterExit=yes --property=UMask=0077 \
    --property=TimeoutStopSec=10min \
    /usr/bin/python3 -I -B -u \
    /usr/local/sbin/USTAR_BACKUP_TRANSFER_20261015.py \
    --pack-production --acknowledge-outage
printf 'EXPORT_UNIT=%s.service\n' "$ustar_export_unit"
ROOT
```

Просмотр результата:

```bash
sudo journalctl -u 'ustar-production-export-*' -n 60 --no-pager -o cat
```

Нужны **обе** строки `PRODUCTION_SOURCE=FROZEN` и `TRANSFER_PACK=PASS`. Затем JSON покажет `directory` — точную папку `/srv/ustar-storage/transfers/<дата-суффикс>` — и SHA256 комплекта. Если показано только `Running as unit`, сборка ещё не принята как успешная.

Скрипт берёт эксклюзивную блокировку штатного cron v3, включает обслуживание и проверяет его действие, вызывает проверенный HDD writer для свежего снимка, отключает автозапуск старого web-контейнера и останавливает его. PostgreSQL и другие службы старого сервера не останавливаются. При занятом cron отказ происходит до остановки; завершения текущего cron нужно дождаться. Если создание бэкапа сорвалось, вызывается штатный recovery marker helper и возвращается работа старой Академии. Если источник уже заморожен, упаковка повторяется только для того же финального бэкапа.

После успешной заморозки ошибка упаковки **оставляет старую Академию остановленной**: её можно явно вернуть по команде отмены ниже. Не запускайте старую копию параллельно новой рабочей Академии.

## 2. Через WinSCP передать комплект

На server1 откройте через **Ctrl+O** точный `directory` из успешного вывода. Скачайте два файла:

- `ustar-transfer.tar`
- `ustar-transfer.tar.sha256`

В отдельной SFTP-сессии нового сервера загрузите оба прямо в домашний каталог SSH-пользователя. На server1 продолжает использоваться уже настроенный доступ `sudo -n /usr/lib/openssh/sftp-server -R -d /srv/ustar-storage`; эту настройку не копируйте в сессию нового сервера.

Внутри tar — зашифрованный бэкап, **ключ его расшифровки**, точные restore helpers, production worker и запускатель. Образы web/PG находятся внутри бэкапа. Внешний tar не зашифрован: храните комплект приватно, передавайте через SFTP, не публикуйте в чате/Git. Старые комплекты не содержат production worker; нужен новый формат v2 из этого этапа.

## 3. На новом сервере проверить и распаковать

В SSH нового сервера сначала отдельно выполните `sudo -v`, затем:

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

Сверьте показанный SHA256 с успешным выводом server1. Прежде чем переходить дальше, нужен `OK`. Каталог `/opt/ustar-transfer` должен отсутствовать: существующий комплект не перезаписывается.

## 4. На новом сервере поднять рабочую Академию

Для доверенной LAN/VPN замените пример `192.168.1.61` на **реальный адрес нового сервера** и выполните одну команду:

```bash
sudo python3 -I -B -u /opt/ustar-transfer/restore.py \
    --start-production --url http://192.168.1.61 \
    --source-stopped --install-dependencies
```

`--source-stopped` — ваше подтверждение, что старая Академия остановлена и её не будут запускать параллельно. Это не удалённая проверка исходного сервера. При аварийном восстановлении из ранее сохранённого комплекта подтверждайте его только когда старый узел действительно выключен/изолирован. Тогда вернётся состояние на дату того бэкапа; более поздние изменения в нём отсутствуют.

Команда принимает задание на восстановление: `PRODUCTION_START=SUBMITTED` ещё не означает готовность. Установка зависимостей и загрузка образов допускаются только на пустом Docker-хосте. Существующие `/opt/ustar` и `/var/lib/ustar-production-restore` защищены от перезаписи.

Статус:

```bash
sudo python3 -I -B /usr/local/sbin/USTAR_PRODUCTION_RESTORE_20261015.py \
    --production-status
sudo journalctl -u ustar-production-import-20261015.service \
    -n 40 --no-pager -o cat
```

Ждём `PRODUCTION_RESTORE=PASS; PERMANENT; BOOT_ENABLED; CRON_ENABLED` и `phase: ready`. Затем откройте в обычном браузере `http://192.168.1.61/login/index.php` — **SSH-туннель не нужен**. Учётные записи и пароли из бэкапа сохраняются, сессии сбрасываются. Срок работы не ограничен; после reboot поднимется та же БД, повторного импорта не будет.

Если адрес изменился, штатный Moodle search/replace обновляет старый wwwroot в БД. Тема, главная, salts/peppers и параметры интеграций сохраняются. Новая БД/роль приложения получает свои случайные credentials; старые PostgreSQL globals не исполняются. Индивидуальные флаги scheduled tasks и очередь сохраняются; назначается один cron каждую минуту. Mail больше не блокируется режимом стенда и использует восстановленные SMTP-настройки.

Для Интернета сначала нужен действующий TLS-прокси с сертификатом и DNS. Если он уже настроен на новом сервере и ведёт на `127.0.0.1:8082`, вариант этапа4:

```bash
sudo python3 -I -B -u /opt/ustar-transfer/restore.py \
    --start-production --url https://academy.example.com \
    --bind 127.0.0.1 --http-port 8082 --behind-proxy \
    --source-stopped --install-dependencies
```

`academy.example.com` — пример. Сертификаты, DNS и прокси эта команда не создаёт. HTTP-вариант выше предназначен для доверенной LAN/VPN, не для публикации паролей в Интернет. Внешние SMTP/LDAP/S3/репозитории должны быть доступны с нового сервера; перенос настроек не переносит сами эти сервисы.

## Приёмка перед завершением окна

Проверьте вход администратора и сотрудника, главную USTAR, роли, курс, файлы и SCORM с ранее сохранённым прогрессом. Отправьте одно тестовое письмо через штатную настройку Moodle и проверьте получение: SMTP-доставка автоматически не доказана. В статусе после ближайшей минуты должна появиться `last_cron_success_utc`; это не означает, что все ранее неуспешные бизнес-задачи исправились.

```bash
sudo systemctl is-enabled ustar-academy.service ustar-academy-cron.timer
sudo systemctl show ustar-academy.service -p ActiveState -p Result
sudo systemctl list-timers --all --no-pager ustar-academy-cron.timer
```

В согласованное окно выполните reboot нового сервера и повторите проверку входа, контейнеров и cron. До этого фактическое восстановление после reboot не принято. Старая Академия остаётся остановленной. Не удаляйте исходные данные/финальный бэкап до завершения приёмки.

Этот комплект переносит **Академию**, а не весь Ubuntu/shared host. Почтовый сервер, DNS/ISPConfig, сертификаты и старые host cron/systemd jobs в него не входят. Автобэкапы нового хоста тоже нужно настроить на его собственный диск/внешнее хранилище: старый HDD scheduler привязан к UUID и server1, его нельзя просто включить на новой машине.

## Управление постоянной Академией

```bash
sudo systemctl stop ustar-academy.service
sudo systemctl start ustar-academy.service
```

Остановка основной службы останавливает её cron/timer и контейнеры, **сохраняет данные**. Повторный start и reboot используют те же записанные container IDs, пути и образы. Посторонние контейнеры не принимаются за свои. База закрыта от внешних портов; код web монтируется read-only, moodledata/PG — для записи.

При сбое импорта данные и private logs сохраняются; скрипт не удаляет чужие/частично восстановленные каталоги и не запускает обновление Moodle. Если статус `cleanup_required`, сначала проверить/остановить принадлежащие этой операции контейнеры, не освобождать путь удалением данных.

## Как отменить переезд и вернуть старую Академию

Сначала на **новом** сервере остановите `ustar-academy.service` и активный импорт, если он ещё идёт; убедитесь, что новая web-копия не работает. Если нового сервера не существует, этот пункт не нужен. На server1:

```bash
sudo python3 -I -B -u /usr/local/sbin/USTAR_BACKUP_TRANSFER_20261015.py \
    --resume-source --target-stopped
```

Команда проверит исходный container ID/image/mounts, вернёт прежнюю restart policy, поднимет старый web и отключит обслуживание. После появления новых пользовательских данных на новом сервере такое возвращение — уже **не откат без потерь**: сначала нужен отдельный план переноса этих данных назад.

## Отдельно временный стенд

Старые `--start` и `--start-lab` остаются **изолированными тестовыми копиями** с отключёнными mail/cron. Для проверки без остановки production используйте `--pack` и/или:

```bash
sudo python3 -I -B -u /usr/local/sbin/USTAR_BACKUP_TRANSFER_20261015.py \
    --start-lab --duration 2h
```

Допустимы `30m`, `2h`, `1d`, `0` без таймера. Этот режим не превращается в production от `--duration 0`. Восстановление production выполняется только через `--start-production`.

## Проверенные исходники

| Файл | SHA256 |
|---|---|
| `backup_transfer.py` | `1a075a5bd6f995ef7db2dd255470103175cde28d23a253a9cd72fb89d12cdcd8` |
| `production_restore.py` | `3edb4de42a84e855302045d307390fb13156e7b9acabcf688fb720c743f42854` |
| `backup_restore_test.py` | `4804c27aada3d5f431ccf19cfb174dd8deb1bc6f991d3d9793d6a89b7be9b022` |

Private helpers engine71f6a1c4/checkere00577ae и HDD adapter75ca974f не менялись и в Git не публикуются. Target использует проверенные helper bytes из комплекта. Миграция/перенос URL: [Moodle migration](https://docs.moodle.org/501/en/Moodle_migration), [штатный search/replace](https://docs.moodle.org/500/en/admin/tool/replace). Нативное развёртывание на чистом сервере и браузерная приёмка остаются отдельным фактическим результатом владельца.
