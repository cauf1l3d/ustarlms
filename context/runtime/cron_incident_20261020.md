# Cron: отчёт источника остановился на 04:20 МСК

**20:08МСК: причина подтверждена владельцем.** Cron active/schedule/binding match,
10invocations/10min;wrapper rejects old local0204 versus installed1905. Last DB
task log unchanged04:20,107overdue/2adhoc. Повторная диагностика ниже не нужна.
[Точное исправление и приёмка scheduled success](cron_release_update_20261020.md).
Ниже сохранён первоначальный разбор до получения native результата.

Owner20Oct19:59МСК после принятого HTTPS сообщил тревогу пульта:
«С последнего подтверждённого успеха прошло больше 10 минут», время проверки
19:59, дата отчёта источника04:20МСК. Это owner UI report, не измерение через SSH.
Причина пока не установлена; отключение cron.service не доказано.

## Проверенный путь данных

Console source PR103 `de4544d97f4f77036cfbb358ab79273361e6a6e5`:
`server-console/backend/ustar_console/cron.py` читает
`/var/lib/ustar-cron/last-run.json`, поле `finished_utc`.
`collector.py` передаёт его как `source_observed_at`; свежая проверка сборщика
не заменяет дату отчёта cron. Последний полностью успешный проход дополнительно
помнится в `/var/lib/ustar-console-collector/cron-success.json`.
`cron_success_overdue` выставляется после600сек без подтверждённого полного
успеха. Это не доказательство, что все Moodle tasks выключены.

Wrapper v3/source contract: [recovery_contract_20261005.md](recovery_contract_20261005.md).
`/usr/local/sbin/USTAR_CRON_SETUP_20261005.py`, SHA256
`66fd51d364beb59a8874a63c695c122dca5452a720e61b86d070beb0b1939852`.
Schedule `/etc/cron.d/ustar_moodle`, receipt `/var/lib/ustar-cron/last-run.json`.
Один worker и cron EX/backup SH locks обязательны. `--status` читает state,
Docker identities и bounded readonly SQL; не запускает Moodle PHP или tasks.
`--install` выполняет настоящий production pass, а `--rebind` отсутствует.

04:20 предшествует owner install receipt04:36 нового Academy payload830/1905.
Связь с установкой — гипотеза, не установленная причина. Installer держит
существующий backup EX lock на время транзакции; его finally освобождает lock,
расписание cron он не меняет. Проверить schedule/service, identity/version guard,
receipt и актуальный DB task-log timestamp до изменения расписания или запуска.
HTTPS cutover принят около19:56; отсутствие отчёта началось существенно раньше.

## Следующий native шаг: только чтение

```bash
sudo -v

sudo -n systemctl show cron.service ustar-console-collect.timer \
  -p Id -p LoadState -p ActiveState -p SubState -p UnitFileState --no-pager

printf '%s  %s\n' \
  66fd51d364beb59a8874a63c695c122dca5452a720e61b86d070beb0b1939852 \
  /usr/local/sbin/USTAR_CRON_SETUP_20261005.py | sudo -n sha256sum --check --strict - && \
sudo -n timeout --kill-after=2s 40s /usr/bin/python3 -I -B -u \
  /usr/local/sbin/USTAR_CRON_SETUP_20261005.py --status

sudo -n stat -c '%n | modified=%y | bytes=%s | mode=%a | owner=%U:%G' \
  /etc/cron.d/ustar_moodle /var/lib/ustar-cron/last-run.json

sudo -n journalctl -u cron.service --since '-10 minutes' --no-pager -o cat | \
  awk '/USTAR_CRON_SETUP_20261005[.]py/ {n++} END {print "managed_cron_invocations_10min=" n+0}'
```

Прислать вывод, включая ошибки/отсутствующие файлы. Нулевой journal count сам по
себе не доказывает отсутствие запусков: хранение journald/syslog может отличаться.
Raw config.php, private logs, dump и ключи не нужны. Пока не запускать второй
worker, `--install`, прямой `admin/cli/cron.php`, не удалять locks, не обнулять
faildelay/history и не менять порог тревоги. После подтверждения причины — одно
адресное исправление и приёмка нового scheduled success/свежего console receipt.

Private bridge/chat-link/notify остаются следующим Connect этапом после cron.

## Отдельный CI follow-up

Повторный context-only commit a916f478 не менял production/test code d1e61a3b.
Context39461413096 SUCCESS; Review39461413068: обе source jobs и connect-db SUCCESS,
runtime118761541814 FAILED в synthetic PHP HTTPS fixture с
`session_cookie_not_secure` при первом cutover. Ранее exact-code d1e61a3b native
PASS,owner server cutover/status active. Причина нового CI failure не подтверждена;
не считать это доказанным flake,не ослаблять Secure-cookie guard. Связь с cron
не установлена. После native cron triage отдельно воспроизвести и исправить этот
CI boundary;точная запись в [CI receipt](connect_academy_https_ci_20261020.json).
