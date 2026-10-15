# Восстановление последнего бэкапа Moodle на один час

**15Oct: запуск отложен владельцем до сборки [пульта](control_panel_scope_20261015.md). Эксперимент ещё не выполнен. Команды ниже сохранены для последующего этапа.**

Запрос владельца 14.10.2026 19:05 MSK. Подготовка опубликована в PR102; агент не подключался к server1. Реальное восстановление нового архива, браузерная приёмка и автоостановка пока pending. Первый запуск ниже выполняется на server1; проверка на другом чистом сервере описана отдельно и этим запуском не заменяется. Восстанавливается версия Moodle из бэкапа, без обновления до 5.3.

## Что создаёт архив

| Назначение | Установленный файл |
|---|---|
| Расписание ежедневно 03:30 Europe/Moscow | `/usr/local/sbin/USTAR_HDD_SCHEDULE_20261006.py`, `ustar-hdd-backup.timer` |
| Запись на HDD, проверка mount/UUID и readback | `/usr/local/sbin/USTAR_HDD_BACKUP_20261006.py` |
| Согласованный snapshot и шифрование age | `/usr/local/sbin/USTAR_BACKUP_SFTP_20261004.py` |
| Проверка зашифрованного комплекта | `/usr/local/lib/ustar-recovery/USTAR_RESTORE_PREFLIGHT_20261004.py` |
| Распаковка, новая БД, файлы и контейнеры | `/usr/local/sbin/USTAR_RESTORE_LAB_20261004.py` |
| Выбор нового архива, изоляция и час работы | `/usr/local/sbin/USTAR_BACKUP_RESTORE_TEST_20261014.py` |

Готовые архивы: `/srv/ustar-storage/backups/managed/ustar-recovery-*.tar.gz.age`, рядом `<имя-архива>.json`. Последний успешный выбирается по `/var/lib/ustar-backup/last-hdd-success.json`, а не по времени изменения случайного файла. Точное выбранное имя и SHA попадут в приватный отчёт стенда. Имя сегодняшнего архива здесь не выдумывается.

Внешний age-архив содержит `database.dump` (логическая БД Moodle), `postgres-globals-private.sql`, `site-files.tar.gz` (полные public и moodledata), `images.tar` (два точных Docker-образа web/PG), private container metadata, runtime/application versions, snapshot/checksums. Deployment compose/env/Apache входят при их наличии. В public находятся config.php, Moodle core, плагины и тема; в БД — учётки, роли, прогресс и история; в moodledata — загруженные материалы. Snapshot временно останавливает production web, оставляет PostgreSQL работающим, проверяет отсутствие других DB клиентов и затем возобновляет web. Новый restore использует готовый архив, нового snapshot не делает.

Ключ `/etc/ustar-backup/recovery.key`, установленные host backup/restore/cron скрипты, ОС, почта, DNS и ISPConfig в этот комплект не входят. Название SFTP историческое: само по себе не доказывает внешнюю передачу. `--recover` у backup-helper возобновляет сервис, а не восстанавливает архив.

Отдельный последний подтверждённый SSD archive: `/srv/ustar-storage/host-recovery-20261013T234210Z-874c1983/root.tar`, 43704842240 bytes, SHA256 `8de2742f3ffa303b8f4df3e53fef77fb9047768fdc50eebe696b61be96477730`. Он создан ДО обновления пакетов хоста, не является sector image и не используется этим экспериментом. Полное boot-восстановление не проверено.

## Проверенные исходники

Source commit: `cdf69bab822b1ecbc352018cb56f542f992e0db5`.

| Файл | SHA256 |
|---|---|
| Новый `USTAR_BACKUP_RESTORE_TEST_20261014.py` | `e9e206a841f7fe93e9e6551e17fe0d6569a93eb17f7c7eb80473a312fb272abf` |
| `ustar-restore-test-20261014.service` | `55ebea17f691ac1489cad1d9a2bca50ab42a8870818ef079b8760ba9f5be3c07` |
| Прежний private `USTAR_RESTORE_LAB_20261004.py` | `71f6a1c45c25bfe97fe0be7d0a362bb5a72e742b77c167f3783f526c85950dac` |
| Прежний private `USTAR_RESTORE_PREFLIGHT_20261004.py` | `e00577aefcbcdd8ae3ebc1f30f98c899c3efd3b2b4e593b6ce0d26d09e2756da` |

[Выбранный CI](https://github.com/cauf1l3d/ustarlms/actions/runs/38601259566): 33 root filesystem/lock/state tests, Docker/lifetime modeled, systemd parser PASS. Подлинные private verifier/extractor проверены на synthetic archive; повреждение отвергнуто. Это не native restore БД/архива владельца. Общий review gate отдельно failed на npm audit неизменённого frontend; source PHP8.2/8.3 и context прошли. Полный RC green не заявляется. [Validation](backup_restore_test_validation_20261014.json).

## Запуск на server1

Сначала отдельно `sudo -v`. Затем вставить только содержимое блока, без приглашения shell и прошлого вывода:

```bash
bash <<'BASH'
set -euo pipefail
ustar_source=https://raw.githubusercontent.com/cauf1l3d/ustarlms/cdf69bab822b1ecbc352018cb56f542f992e0db5/scripts/infra
curl -fsSL --connect-timeout 10 --max-time 60 "$ustar_source/backup_restore_test.py" -o /home/aduk/USTAR_BACKUP_RESTORE_TEST_20261014.py
curl -fsSL --connect-timeout 10 --max-time 60 "$ustar_source/ustar-restore-test-20261014.service" -o /home/aduk/ustar-restore-test-20261014.service
printf '%s  %s\n' e9e206a841f7fe93e9e6551e17fe0d6569a93eb17f7c7eb80473a312fb272abf /home/aduk/USTAR_BACKUP_RESTORE_TEST_20261014.py 55ebea17f691ac1489cad1d9a2bca50ab42a8870818ef079b8760ba9f5be3c07 /home/aduk/ustar-restore-test-20261014.service | sha256sum --check --strict -
sudo -n bash <<'ROOT'
set -euo pipefail
printf '%s  %s\n' 71f6a1c45c25bfe97fe0be7d0a362bb5a72e742b77c167f3783f526c85950dac /usr/local/sbin/USTAR_RESTORE_LAB_20261004.py e00577aefcbcdd8ae3ebc1f30f98c899c3efd3b2b4e593b6ce0d26d09e2756da /usr/local/lib/ustar-recovery/USTAR_RESTORE_PREFLIGHT_20261004.py | sha256sum --check --strict -
test -f /etc/ustar-backup/recovery.key
if systemctl is-active --quiet ustar-restore-test-20261014.service; then
    printf 'RESTORE_ALREADY_RUNNING; USE_STATUS\n'
    exit 1
fi
test ! -e /var/lib/ustar-restore-test/20261014
install -m 600 -o root -g root /home/aduk/USTAR_BACKUP_RESTORE_TEST_20261014.py /usr/local/sbin/USTAR_BACKUP_RESTORE_TEST_20261014.py
install -m 644 -o root -g root /home/aduk/ustar-restore-test-20261014.service /etc/systemd/system/ustar-restore-test-20261014.service
systemctl daemon-reload
systemctl start ustar-restore-test-20261014.service
ROOT
BASH
```

Служба работает независимо от SSH-сессии. Она не включается в автозагрузку. Старые стенды не запускаются. Production-контейнеры только inspect, без SQL/exec/stop. Проверяются SHA архива, расшифровка, внутренние checksums, образ/платформа, размер/память, свободный порт и отсутствие ранее созданного стенда. Требуется минимум 5 GiB доступной RAM; SSD-бюджет рассчитывается по самому архиву и включает 4 GiB резерва.

```bash
sudo -n python3 -I -B /usr/local/sbin/USTAR_BACKUP_RESTORE_TEST_20261014.py --status
sudo -n systemctl show ustar-restore-test-20261014.service -p ActiveState -p SubState -p Result -p ExecMainStatus -p MainPID --no-pager
sudo -n journalctl -u ustar-restore-test-20261014.service -n 50 --no-pager -o cat | awk '/^RESTORE_TEST_PHASE=|^RESTORE_TEST_READY=|^RESTORE_TEST_ERROR=|^RESTORE_TEST_ONE_HOUR=|^RESTORE_TEST_STOP=/{print}'
```

`preflight_running` означает начальную проверку без созданных контейнеров; не запускать второй экземпляр. Готовность: `RESTORE_TEST_READY=PASS; ACTIVE_FOR_SECONDS=3600`, status `ready` и `expires_at` в UTC. Час начинается с готовности login, не с запуска команды. Общий watchdog службы 2 часа включает подготовку. При раннем выходе статус/журнал сохраняют причину; не повторять capture вслепую.

В Windows PowerShell открыть SSH-туннель и оставить окно открытым:

```powershell
ssh -N -L 18086:127.0.0.1:18086 aduk@192.168.1.60
```

В браузере: `http://127.0.0.1:18086/login/index.php`. Учётки и пароли соответствуют моменту бэкапа. Проверить вход учащегося/администратора, роли, материалы, SCORM, прогресс. Сетевые интеграции намеренно недоступны. В копии отключены почта/cron/scheduled tasks, очищены sessions, изменены siteidentifier, cookie, DB credentials и URL. Исходный config сохраняется внутри приватного payload. База восстанавливается в новый PG cluster/app role, `postgres-globals-private.sql` сохраняется, но не исполняется. Внешних сетевых интерфейсов у контейнеров нет, доступ через loopback relay и SSH.

Контейнеры: `ustar_restore_test_20261014_pg`, `ustar_restore_test_20261014_web`. Файлы и отчёт: `/var/lib/ustar-restore-test/20261014/`, `report.json`. После часа удаляются только принадлежащие этому запуску контейнеры, relay останавливается, данные/отчёт/исходный backup остаются. Проверить `one_hour_completed: true`. Досрочная остановка:

```bash
sudo -n systemctl stop ustar-restore-test-20261014.service
```

Не вызывать отдельный `--stop-lab` одновременно с живым worker: он защищён lock. ExecStopPost выполнит cleanup после выхода worker. Повторный эксперимент с тем же каталогом намеренно отклоняется, без перезаписи данных.

## Перенос на отдельный чистый сервер

Это самостоятельная проверка. Нужны Linux amd64/Ubuntu24.04 LTS, systemd, Python3, Docker, age, util-linux (nsenter/findmnt), память/место по бюджетам выше. Runtime не требует Docker Hub: образы есть в бэкапе. Если образы отсутствуют, Docker должен быть полностью пустым (включая остановленные контейнеры). Установку Docker и SSH на новом хосте проверяют по его реальной конфигурации; IP второго хоста в сессии ещё не указан.

Через защищённую передачу подготовить на новом хосте приватный каталог `/home/aduk/ustar-dr-transfer` (0700):

- ровно один выбранный `ustar-recovery-*.tar.gz.age` и его `.json` из HDD managed; имя/SHA должны совпадать с отчётом первого теста, а после передачи — с `sha256sum`;
- четыре файла из таблицы SHA выше; private engine/checker копировать с server1, не искать их в публичном Git;
- независимую сохранённую копию `recovery.key` доставить отдельно; создание нового ключа старый архив не расшифрует. Не публиковать ключ/архив/приватный отчёт в чат или Git.

Существующая read-only WinSCP-сессия позволяет скачать; для загрузки на новый хост нужна обычная SFTP-сессия в его домашний каталог. Пример ниже предполагает пользователя aduk и свежую машину без существующих USTAR-каталогов/службы.

После `sudo -v` на НОВОМ сервере:

```bash
sudo -n bash <<'ROOT'
set -euo pipefail
umask 077
cd /home/aduk/ustar-dr-transfer
printf '%s  %s\n' e9e206a841f7fe93e9e6551e17fe0d6569a93eb17f7c7eb80473a312fb272abf USTAR_BACKUP_RESTORE_TEST_20261014.py 55ebea17f691ac1489cad1d9a2bca50ab42a8870818ef079b8760ba9f5be3c07 ustar-restore-test-20261014.service 71f6a1c45c25bfe97fe0be7d0a362bb5a72e742b77c167f3783f526c85950dac USTAR_RESTORE_LAB_20261004.py e00577aefcbcdd8ae3ebc1f30f98c899c3efd3b2b4e593b6ce0d26d09e2756da USTAR_RESTORE_PREFLIGHT_20261004.py | sha256sum --check --strict -
test ! -e /var/lib/ustar-dr-input
test ! -e /var/lib/ustar-restore-test/20261014
test ! -e /etc/ustar-backup
test ! -e /etc/systemd/system/ustar-restore-test-20261014.service
test ! -e /etc/systemd/system/ustar-restore-test-20261014.service.d
test -z "$(docker ps -aq)"
install -d -m 700 /var/lib/ustar-dr-input /etc/ustar-backup /usr/local/lib/ustar-recovery
shopt -s nullglob
ustar_archives=(ustar-recovery-*.tar.gz.age)
ustar_sidecars=(ustar-recovery-*.tar.gz.age.json)
test "${#ustar_archives[@]}" -eq 1
test "${#ustar_sidecars[@]}" -eq 1
test "${ustar_sidecars[0]}" = "${ustar_archives[0]}.json"
install -m 600 -o root -g root "${ustar_archives[0]}" "${ustar_sidecars[0]}" /var/lib/ustar-dr-input/
install -m 600 -o root -g root recovery.key /etc/ustar-backup/recovery.key
install -m 600 -o root -g root USTAR_RESTORE_LAB_20261004.py USTAR_BACKUP_RESTORE_TEST_20261014.py /usr/local/sbin/
install -m 600 -o root -g root USTAR_RESTORE_PREFLIGHT_20261004.py /usr/local/lib/ustar-recovery/
install -m 644 -o root -g root ustar-restore-test-20261014.service /etc/systemd/system/
install -d -m 755 /etc/systemd/system/ustar-restore-test-20261014.service.d
cat > /etc/systemd/system/ustar-restore-test-20261014.service.d/input.conf <<'UNIT'
[Service]
ExecStart=
ExecStart=/usr/bin/python3 -I -B -u /usr/local/sbin/USTAR_BACKUP_RESTORE_TEST_20261014.py --restore-lab --input-directory /var/lib/ustar-dr-input --load-images
UNIT
chmod 644 /etc/systemd/system/ustar-restore-test-20261014.service.d/input.conf
systemctl daemon-reload
systemctl start ustar-restore-test-20261014.service
ROOT
```

Итоговый запуск использует `--restore-lab --input-directory /var/lib/ustar-dr-input --load-images`; UUID HDD server1 на новой машине не требуется. Проверки/час/остановка те же. В SSH-туннеле заменить адрес на реальный IP нового сервера; браузерный URL оставить localhost:18086. Если локальный порт занят другим стендом, закрыть предыдущий туннель и остановить предыдущий эксперимент перед новым подключением.

После реального off-host прогона сохранить: выбранное имя/SHA, `image_archive_loaded: true` на пустом Docker, DB/file checks, bootstrap/login, ручные роли/SCORM и `one_hour_completed`. До этого off-host acceptance остаётся PENDING. Это восстановление приложения с безопасными test-настройками, не автоматический ввод нового production с DNS/TLS/SMTP/cron, не восстановление оригинальных PostgreSQL superuser roles и не boot/почта/DNS/ISPConfig recovery.

## Текущий приоритет и принятые ранее результаты

По выводу владельца Moodle5.1.8/core2025100608.00 уже обновлён, repeat/браузер/SCORM/разные роли приняты. Хост после обновления загрузился с kernel6.8.0-147, Docker29.9.0; login200, HDD mount, AppArmor и production policies/IDs подтверждены. Полный SSD archive/hash и scheduled14Oct backup успешны. Эти операции не повторять. Это свидетельства владельца, не SSH-измерения агента.

Moodle5.3/PG17 assets уже загружены владельцем; подготовка PR101 сохранена и продолжится после текущего эксперимента. Major migration не выполнена. Ошибка кнопки результата/маршрута отложена владельцем. Датированные документы main от06Oct описывают старые checkpoints и не отменяют эту текущую очередь.
