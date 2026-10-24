# CON-02: обновление приложения и отдельное включение ACK

Поставка завершена23Oct2026 (МСК); план принят22Oct. Все9задач/45шагов закрыты как source/handoff.

Инструмент: `4394b99475f469b061010a1ea4aa77b9d984c40f`. Приложение и worker: `534764821c4f65437ab50b3c7f25578d538e9504`.
Один обычный файл `ustar-con02-update-4394b994.py`, SHA256 `331c5c54eb605e142c401d6b84cbda02a1ba468be40b2ffc1da768b9429d322b`; архив и ручная распаковка не требуются.
Код инструмента проверен отдельно от последующего коммита документации.

На server1 владелец23Oct завершил --upgrade (app_ready/local2200), затем --enable-ack
(runtime_active). Receiver/notify active+enabled, DB доступна. Application/worker534,
557файлов/100таблиц по fixed payload; агент SSH не выполнял. UID130 подтвердил два
различных события179/180, по1delivery attempt; прежний revoked checkpoint исторический.
[Owner receipt](connect_con02_server1_owner_20261023.json), [результат и границы](connect_con02_server1_20261023.md).
Команды ниже — сохранённый runbook, повторять deploy/pilot ради нового checkpoint не нужно.

## Исторические индексы

Предыдущий b971 --check отказал schema_indexes. Owner read-only differential подтвердил
отсутствие только UNIQUE coin_accounts(userid) и test_results(attemptid) при сохранении
обычных индексов и остальных97таблиц. Новый controller принимает либо полный XMLDB,
либо точно эту пару на baseline2000. Никакие индексы/legacy записи на сервере не
исправляются; исходное отсутствие UNIQUE остаётся документированной исторической
разницей. Все остальные проверки и сохранение old99 при upgrade/recovery обязательны.
[Наблюдение и границы исправления](connect_con02_index_compat_20261023.md).

## Проверка и обновление

Сохранить прикреплённый `.py` в `/home/aduk`, затем:

```bash
sudo -v
sudo -n install -o root -g root -m 600 /home/aduk/ustar-con02-update-4394b994.py /root/ustar-con02-update-4394b994.py &&
printf '%s  %s\n' 331c5c54eb605e142c401d6b84cbda02a1ba468be40b2ffc1da768b9429d322b /root/ustar-con02-update-4394b994.py |
sudo -n sha256sum --check --strict - &&
sudo -n /usr/bin/python3 -I -B /root/ustar-con02-update-4394b994.py --check
```

Ожидается `baseline_ready` и `baseline_index_profile: server1_legacy_indexes_20261023`
для показанной владельцем схемы. Строгая fresh схема показывает `xml_install_indexes`. Проверка читает текущие файлы/SQL, не создаёт state/locks,
не запускает Moodle bootstrap и не вызывает Telegram/bridge nonce.

Обновление создаёт свежий HDD backup и matching cold pair, с паузой Академии:

```bash
sudo /usr/bin/python3 -I -B /root/ustar-con02-update-4394b994.py --upgrade --acknowledge-outage
```

Ожидается `app_ready`,local2026102200,ACK выключен. Копирование может занимать несколько минут;
команда рассчитана на один запуск без90-секундного timeout. Статус:

```bash
sudo /usr/bin/python3 -I -B /root/ustar-con02-update-4394b994.py --status
```

При `baseline_restored` предыдущая попытка восстановлена; новая установка ещё не началась.
Следующий `--upgrade --acknowledge-outage` создаст новую попытку со свежей парой.
После `app_ready` обычный повтор upgrade выполняет только проверки.

## ACK и восстановление

После `app_ready` ACK включается отдельно:

```bash
sudo /usr/bin/python3 -I -B /root/ustar-con02-update-4394b994.py --enable-ack
```

Ожидается `runtime_active`. Новый sender v2 и отдельный ACK-only credential не расширяют
принятый состав получателей. ACK cutoff/ключи сохраняются на всю generation; прежний
notify cutoff остаётся. Revoked сотрудник не становится связанным от root-команды.

Для отдельного возврата к прежнему runtime с сохранением приложения2200:

```bash
sudo /usr/bin/python3 -I -B /root/ustar-con02-update-4394b994.py --disable-ack
```

Для восстановления прерванной операции:

```bash
sudo /usr/bin/python3 -I -B /root/ustar-con02-update-4394b994.py --recover
```

Эти действия выбираются по ситуации:

| Действие | Результат |
|---|---|
| `--disable-ack` | Возвращает прежний v1/a86 runtime на приложении2200, прежние состояния сервисов; не откатывает DB/cursor. |
| Повтор `--enable-ack` после прерывания | Продолжает ту же generation/cutoff/ключи. После законченного disable создаёт новую generation. |
| `--recover` во время app upgrade до public release | Восстанавливает matching code/config/moodledata/PG и cron2000. |
| `--recover` после app public release | Проверяет и завершает2200; новые DB записи не откатывает. |
| `--recover` при app_ready и существующей runtime generation | Возвращает её сохранённый v1 runtime; SQLite schema1/cursor/uncertainty сохраняются. |

Поздний откат приложения — отдельное решение с простоем и потерей записей после snapshot.
Сначала требуется `--disable-ack`. Только для явно выбранного позднего restore:

```bash
sudo /usr/bin/python3 -I -B /root/ustar-con02-update-4394b994.py --restore --acknowledge-outage --discard-writes-since-snapshot
```

После прерывания такого restore повторяется именно эта команда с обоими флагами;
обычный recover не продолжает поздний разрушительный restore неявно. Старые installer/journals/locks
не сбрасывать вручную. При отказе сохранить JSON; private log root0600 не публиковать целиком.

HTTPS owner/config/vhosts/health/observability/tool/units/state/CA/leaf сохраняются во время операции.
Проверенная штатная смена leaf между операциями принимается. Cron меняет только version/hash pair;
расписание, custom/disabled flags и история не сбрасываются.

## Проверки и границы

Точная квитанция: [connect_con02_index_compat_ci_20261023.json](connect_con02_index_compat_ci_20261023.json).
Tool `4394b994`: Context [39947880072](https://github.com/cauf1l3d/ustarlms/actions/runs/39947880072)
и Review [39947880106](https://github.com/cauf1l3d/ustarlms/actions/runs/39947880106) SUCCESS.
PHP8.2 job120394897070 и PHP8.3 job120394896737: каждый1048tests/153environment skips PASS,
включая browser gates. Runtime120394896695 и native120399836924 SUCCESS.
Строгий root/GID33 state11/11 без skips; actual systemd и HTTPS-owner PASS.
Native154tests/800assertions, direct2000→2200/repeat/restored2000,9 fresh ACK indexes,
4 SQL mutations denied, custom task[1,1]/history/preferences/revoke PASS.
Скачанные JSON и ZIP digests проверены; обычный.py совпадает с независимой exact-Git
сборкой и всеми557 payload/worker/frozen-legacy файлами. Новый native gate проверяет и strict fresh, и исторический профиль
с отсутствием ровно двух UNIQUE; synthetic duplicate legacy rows сохранены при upgrade/repeat/restore.
Локально74focused/1UID33namespace skip; прежний whole r16 имел6environment errors и
не заявлен PASS. Полный regression подтверждён указанными CI jobs.

Native restore проверен как logical PostgreSQL плюс реальные source/config/moodledata в disposable CI.
Физическое копирование и crash recovery проверяются отдельно на реальных файловых деревьях.
Systemd/UID33/LoadCredential/private TLS реальные; Telegram и bridge store синтетические.
В HTTPS-сценарии реальные Apache/OpenSSL/PHP, SQL и состояния worker-сервисов моделируются.
Это не подтверждение работы на production, реальной отправки провайдеру или live group acceptance.
FullRC jobs пропущены; не passed. Main не merged.

Авторское ревью,0subagents; независимого reviewer не было. Исправления подтверждены RED→GREEN:
полная baseline XMLDB/default/index сверка; prefixed PG/9 ACK indexes; cold/ready container IDs;
protected config при restore; оставшийся copy budget; сохранение inode неизменных файлов;
ACL/xattrs при записи и activation; повтор временного symlink; private failure diagnostics;
post-public process loss и7 runtime durable boundaries без rewind generation/cursor.

Отложенное небольшое замечание: readonly check при исключении старого frozen guard может выводить
обобщённый `controller_operation_failed`. Отказ остаётся без изменений; расширение диагностики
в этот выпуск не включено.

Решения выполнения:

- Cloud helper scripts/adjunct resource skill были недоступны: применены эквивалентные private briefs,
  ledger и основной TDD workflow. Цена ошибки — дополнительная ручная сверка полноты evidence.
- Foundations Tasks1/2 опубликованы одним checkpoint, поскольку check/host controls используют Journal.
  Цена ошибки — этот foundation приходится откатывать совместно; product/legacy код не менялся.

Следом — [проект B.1 routing/preferences](../proposals/USTAR_CONNECT_CON02B_ROUTING_DESIGN_20261023.md),
затем отдельный implementation plan/source/CI/поставка. CON-02C Bitrix и live группа остаются отдельными задачами.
