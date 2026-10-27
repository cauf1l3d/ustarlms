# Возобновление cron после принятого релиза Academy

**Закрыто owner20Oct20:38МСК.** Два scheduled success17:36:03Z/17:37:23Z,
exit0,без новых failures/USTAR errors;adhoc0,overdue18,2 старых failed.
Повторное применение и диагностика не нужны. Ошибка17:33Z историческая.
[Полная санитизированная квитанция](cron_release_update_20261020.json).
Дальше private bridge/chat-link,с согласованным cron-контрактом следующего релиза.
Ниже сохранены причина,проверенная процедура и recovery;не инструкция повторять установку.

## Причина подтверждена владельцем20Oct20:08МСК

`cron.service` active/enabled, schedule present, container binding matches,
10 запусков wrapper за10 минут. Последний настоящий проход завершился
`2026-10-20T01:20:05.495272Z` (04:20МСК), после него task log не вырос.
Wrapper error17:07UTC: `Production application versions differ from the reviewed snapshot`.
Текущая очередь:2 adhoc,107 overdue10m,2 прежних failed scheduled,0 USTAR failed.
Это отказ версионной защиты, а не остановка службы или stale-only ошибка пульта.

В точном исходнике cron v3/SHA66fd51d3 закреплён local_ustar2026100204;
owner-installed payload830 использует2026101905. Подготовленный инструмент
меняет только этот литерал и согласованные поля versions/script_sha256 в
installation.json. Проверка остаётся точной, future candidate2000 не разрешается.
Полный private source не публикуется. [Квитанция и проверки](cron_release_update_20261020.json).

## Применение на server1

Выполнять команды по одной физической строке. `sudo -v` сначала отдельно.
Источник инструмента: `caebe51a696700bf1a59e519c36b13f13ede9652`, SHA256
`c88d6c2878f0ae126fc88adaa26fc7ad676ef30eca2f412ba7efc12c54b7e005`.

```bash
sudo -v
curl -q -4 -fsSL --connect-timeout 10 --max-time 60 https://raw.githubusercontent.com/cauf1l3d/ustarlms/caebe51a696700bf1a59e519c36b13f13ede9652/scripts/infra/cron_release_update.py -o /home/aduk/USTAR_CRON_RELEASE_20261020.py
printf '%s  %s\n' c88d6c2878f0ae126fc88adaa26fc7ad676ef30eca2f412ba7efc12c54b7e005 /home/aduk/USTAR_CRON_RELEASE_20261020.py | sha256sum --check --strict - && sudo -n install -o root -g root -m 600 /home/aduk/USTAR_CRON_RELEASE_20261020.py /usr/local/sbin/USTAR_CRON_RELEASE_20261020.py
printf '%s  %s\n' c88d6c2878f0ae126fc88adaa26fc7ad676ef30eca2f412ba7efc12c54b7e005 /usr/local/sbin/USTAR_CRON_RELEASE_20261020.py | sudo -n sha256sum --check --strict - && sudo -n /usr/bin/python3 -I -B /usr/local/sbin/USTAR_CRON_RELEASE_20261020.py --check
```

Ожидается `ready_for_cron_release_update`. Проверяются точные old/new wrapper
SHA, root-private state, неизменное расписание232bytes, прежние identities,
receipt установленного payload830/upgrade_complete, эффективные версии,
core2025100608, отсутствие maintenance/needs_upgrade и работающий cron.
Проверка читает БД и делает прежний PHP CLI config bootstrap, который может
обращаться к Moodle-кешам; задания не запускаются.

Только при готовности:

```bash
sudo -n /usr/bin/python3 -I -B /usr/local/sbin/USTAR_CRON_RELEASE_20261020.py --apply
```

Ожидается `applied_waiting_for_scheduled_run`. Применение берёт прежний cron EX
и backup SH locks, сохраняет before-images с правами/xattrs в
`/var/lib/ustar-cron/release-20261020/`, затем заменяет только wrapper и
installation.json. Расписание, контейнеры, config.php, БД, очереди, faildelay и
журналы не изменяются инструментом. Прямого worker/cron.php/--install запуска нет.
Обычный cron начнёт обрабатывать накопившиеся задания на следующей минуте.
У этих заданий остаются штатные бизнес-эффекты, включая уведомления.

При `cron_or_backup_busy` операция не обходит lock; повторить после завершения
текущего процесса. Не удалять locks и не запускать второй worker. После потери
SSH/прерывания повторный `--apply` восстанавливает известную незавершённую пару
и повторяет проверки; неизвестные изменения администратора не затираются.

## Приёмка scheduled success

Через1–2минуты (обработка накопленного может занять дольше):

```bash
sudo -n timeout --kill-after=2s 40s /usr/bin/python3 -I -B -u /usr/local/sbin/USTAR_CRON_SETUP_20261005.py --status
```

Требуется новый `last_run.trigger=scheduled`, свежий finished_utc,
cron_exit_code0, отсутствие новых failures/USTAR errors и рост task log ID.
Пульт должен получить новую source date и подтверждённый успех. Счётчик overdue
не обязан стать нулём: исходный baseline уже содержит18 overdue и2 старых failed.
`last_wrapper_error` может сохранять прежнее событие17:07UTC как историю;
сравнивать его дату с новым успешным проходом, не удалять файл ради зелёного UI.
При свежих ошибках задач разбирать именно их, не менять критерий успеха.

Новый SHA действующего wrapper после применения:
`c5eabdceaa2f137eccd35087b03d473f61257aaeb77624f4855583ebaf353638`.
Повторный `--apply` возвращает `already_applied` без нового запуска задач.

## Откат только обновления контракта

```bash
sudo -n /usr/bin/python3 -I -B /usr/local/sbin/USTAR_CRON_RELEASE_20261020.py --rollback
```

Восстановит исходные wrapper/installation metadata при неизменности управляемой
пары и snapshots. Возврат старой версии снова остановит worker на version guard;
это не восстановление бизнес-данных и не отмена уже обработанных задач.
Не публиковать private before-images/state. После owner acceptance сохранить их.

## Совместимость последующих поставок

Исторические immutable helpers и manifests, ожидающие старый SHA66fd51d3,
остаются историческими. Новый будущий Connect installer/release preflight должен
быть собран с reviewed SHAc5eabdce и отдельным target version contract; не править
на сервере старый helper/manifest и не повторять installer02b0548c. Candidate2000
нельзя активировать, оставив cron guard1905 без согласованного обновления.
После нового scheduled success продолжить private bridge/scoped keys/link/notify.
