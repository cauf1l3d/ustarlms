# Начальное ежедневное расписание HDD-копий — 06.10.2026

Статус: **prepared / server installation and first scheduled run pending**.
Владелец подтвердил [установку producer и свежую пробную копию](hdd_backup_trial_20261006.json)
06Oct01:10:22..01:12:02 UTC /04:10..04:12 MSK: archive957829597bytes,
SHA256 `d9352e3ce5e237a6ac0ab14c72b9da2648d6906fe7e95cd5c88bea726c965af3`,
pause24.111s, readback и Apache login200, staging removed. Это ручной trial,
не выполнение timer, не decryption/restore и не независимая копия.

## Начальный режим

| Настройка | Реализация |
|---|---|
| Расписание | Ежедневно03:30 `Europe/Moscow`,00:30 UTC при текущем timezone rule |
| Активация | Рекомендуемый начальный режим; операторская команда установки ещё не выполнена |
| Начало работы | Только03:30..03:40 MSK; проверка в wrapper до capture |
| Пропущенное событие | `Persistent=false`; без дневного catch-up после reboot; wake-from-suspend вне окна также отказывает |
| Snapshot сразу при установке | Не запускается; включается timer, а не backup service |
| Ближайшее событие | Установка отказывает, если до него не более15min; после03:40 06Oct ожидается07Oct03:30 MSK |
| Ёмкость/удаление | Existing managed quota100GiB, no archive deletion; достигнутая quota отказывает и сохраняет архивы |
| Нагрузка | Nice10, IO best-effort7, один systemd oneshot + existing EX backup / cron SH lock |
| Таймаут | Main45min; termination и post-stop recovery5min; Restart=no |
| Восстановление Moodle | ExecStopPost и boot recovery unit по private SSD marker; без требования HDD |
| Результат | Journal + `/var/lib/ustar-backup/last-hdd-scheduled-run.json`; success требует свежего producer PASS и login200 |

Окно ограничивает **начало** запуска, не гарантирует верхнюю границу паузы:
реальный trial дал24.111s, future размер/нагрузка могут изменить длительность.
Выключенный ночью сервер пропускает копию. Номинальный период24h не является
доказанным RPO; ошибка/выключение увеличивает возраст последней точки.

Retention7daily/4weekly/3monthly остаётся предложением; автоматического удаления
в этой поставке нет. Quota100GiB — предел накопления, не политика долгого хранения
и не гарантия запаса при росте. External/Telegram delivery, overall dashboard и
fresh scheduled restore остаются отдельными acceptance; journal не означает
внешнего оповещения.

## Source, units и защита установки

Source: `scripts/infra/hdd_backup_schedule.py`, SHA256
`54533f6a629699e031a877b4a57f68401477a61cf20cab39512c79a2e0477b7d`.
Installed root:root0750 `/usr/local/sbin/USTAR_HDD_SCHEDULE_20261006.py`.
Producer root-owned/type/links/parents и SHA
`75ca974f1d763f6fc84281501a99936894f1b487c1cc4d0c1d71697e67c79d75`
проверяются до исполнения. Existing private engine не меняется/не публикуется.

Только три root-owned unit definitions под `/etc/systemd/system`:

- `ustar-hdd-backup.service` — scheduled capture и post-stop recovery/report.
- `ustar-hdd-backup.timer` — календарь, no persistence/retry/random delay.
- `ustar-hdd-backup-recover.service` — resume, enabled на boot после Docker/Apache;
  также dependency перед scheduled capture; без marker ничего не меняет.

Установка требует успешный trial не старше48h, повторяет producer preflight и
archive readback/manifest comparison под existing lock; контейнеры не останавливает.
Все conflicts/overrides/active backup проверяются до первой unit write; matching
повторная установка сохраняет unit inodes. Candidate проверяется host
`systemd-analyze verify`; loaded fragment/drop-ins проверяются перед enable.
Чужие unit files/overrides не заменяются. Enable только этих units; `--now` только
для timer. Shared services и cron v3 не переустанавливаются. При failure после unit
writes файлы могут остаться неактивными для проверки; PASS требует timer active.

`--recover-if-needed` возобновляет только marker `ustar-hdd-backup-v1` через точный
container-ID guard producer и проверяет login readiness. Foreign marker сохраняется.
Uncertain inflight stop при still-running Moodle сохраняется для ручной проверки;
не обходить guard legacy helper. SIGKILL/power-loss/reboot не объявлены прошедшей
recovery rehearsal. Dependency failure до ExecStart может не вызвать ExecStopPost;
журнал/systemd job status и возраст last success остаются обязательными наблюдениями.

## Команды оператора

Установить immutable source по проверенной SHA командой из delivery/PR, без SCP.
Authenticate отдельно `sudo -v`, затем:

```bash
sudo -n python3 -u /usr/local/sbin/USTAR_HDD_SCHEDULE_20261006.py --install
```

Ожидаем `HDD_SCHEDULE_INSTALLED=PASS`, timer active/enabled, next timestamp и
`recovery_marker_present=false`. Backup service до first run inactive допустим;
`Result=success` без last scheduled report не доказывает запуск копии.

```bash
sudo -n python3 -u /usr/local/sbin/USTAR_HDD_SCHEDULE_20261006.py --status
sudo -n systemctl list-timers --all --no-pager ustar-hdd-backup.timer
sudo -n journalctl -u ustar-hdd-backup.service -u ustar-hdd-backup-recover.service --since today --no-pager -n 100
```

Status читает SSD reports/unit properties; не создаёт директории/lock, не требует
HDD и не запускает engine/backup. После first run ожидаем producer `HDD_BACKUP=PASS`
и `HDD_SCHEDULE_RESULT=PASS`; свежий report/hash/date сравнить с pre-run state.
Не запускать backup service днём ради smoke: window gate откажет. Ручная копия
остаётся отдельным chosen-window CLI producer.

После изменения production images пересмотреть и поставить проверенные pins до
следующего backup; неизвестные image identities автоматически не принимаются.
Для остановки будущих запусков:

```bash
sudo -n systemctl disable --now ustar-hdd-backup.timer
```

Это отключает timer, не прерывает running capture и не удаляет archives.

## Проверки

24 local tests PASS: реальные systemd255 calendar/unit parsing, UTC/Moscow boundaries
и отсутствие ordering cycle; wrong SHA before exec, no daytime capture, old success
не становится новым PASS, foreign/ambiguous marker retained, resume без HDD,
failed resume/report, damaged status не блокирует resume, stale/future/mismatched
trial, foreign unit/override/active worker/verify failure до unit writes,
idempotent install и timer-only activation. Файлы/modes/inodes/reporting реальные;
systemctl operations, mount/Docker и fixture ownership подменены. Это ещё не
installation/boot/first scheduled run на server1.

Контракт сверялся с upstream systemd v255
[timer](https://github.com/systemd/systemd/blob/v255/man/systemd.timer.xml) и
[service](https://github.com/systemd/systemd/blob/v255/man/systemd.service.xml).
CI/implementation SHA фиксируются delivery PR/Git; production evidence отдельно.
