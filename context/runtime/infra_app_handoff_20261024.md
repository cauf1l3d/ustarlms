# INF02: отдельное DB-подключение Академии,24Oct2026

**Код7e35795f квалифицирован; следующий шаг — owner --check готового файла.**
Review40107134974/Context40107134982 SUCCESS: обе PHP1108/155skips/browser/runtime,
actual UID33 permissions1/1, прежние14privilege и9новых handoff native checks PASS.
Artifact12565387807 скачан и проверен по SHA256. [CI-квитанция](infra_app_handoff_ci_20261024.json).
Полный production coordinator не запускался в CI; server1 config/ownership не переключены.
Владелец24Oct явно разрешил публикацию; старый approval block снят. Уже принятые
роли/login/OID/metadata и прежние owner stands повторять не нужно.

## Принятый состав

Owner `--check` pinned Telegram3d1938ae: application_ready/local2300/ACKon,
host_changes=false/provider_called=false. Все3479 объектов public — mdl_ и moodle:
657таблиц,656последовательностей,2166индексов. Единственная custom функция
ustar_console.sample() — SECURITY DEFINER, её владелец ustar_console_stats не superuser,
app USAGE=false и EXECUTE=false; фиксированный search_path pg_catalog,pg_temp.
Схему мониторинга, функцию и её разрешения не менять.

Код: `scripts/infra/postgres_app_handoff.py`. Зависимость — уже установленный обычный
`/root/ustar-telegram-update-3d1938ae.py`, SHA256
`6f7729459e1ef25271fd86324e441c823a6d39763c7fec84baf7cf640a41eb31`.
Импортируется только закреплённый embedded envelope; старый CLI main не запускается.

## Переход

1. Повторно проверить guards внутри автоматической операции, получить известный
   пароль ustar_app скрытым tty-вводом. Проверить SCRAM и отказ входа в bootstrap
   с этим паролем. Пароль не передаётся в argv и не выводится.
2. Сохранить private before/after configuration/HTTPS/DB-metadata images; получить
   свежий HDD backup прежним неизменённым административным helper.
3. Под существующими упорядоченными неблокирующими locks приостановить действующие
   workers и веб-контейнер; PostgreSQL продолжает работать.
4. Одной SQL-транзакцией передать moodle DB и только проверенные public mdl_ tables/
   sequences/indexes роли ustar_app. Сверить OID/owners/ACL inventory до и после COMMIT.
   Не использовать REASSIGN OWNED; bootstrap/system catalogs/monitoring не передаются.
5. Атомарно заменить только dbuser/dbpass в config.php; файл root:33/0640, read/no-write
   для UID33. Writable/ACL parents или непроверенный named ACL вызывают отказ заранее.
6. Изменить только текущий `files.config.after` в canonical HTTPS state. Его исходные
   before-image metadata и backup сохраняются. Старые Connect journals не переписываются.
7. Запустить веб, проверить effective PHP session/current ustar_app и отсутствие пяти
   cluster flags, DB ownership, права config и HTTPS login. Вернуть прежние worker states
   и restart policies. Зафиксировать отдельную owner deployment receipt после вывода.

Обычный `--check` не исполняет PHP bootstrap и не вызывает Telegram provider.
Без аргументов — offline PLAN_ONLY без host/network access. `--status` читает только
private journal и прямо помечает это как journal_only, не как свежую health-проверку.

## Восстановление и границы

`--recover` возвращает незавершённый переход к исходным DB-владельцам/config/HTTPS;
`--undo` делает тот же metadata-only возврат после успешного переключения. Обе операции
требуют `--acknowledge-outage`. Бизнес-строки, файлы обучения, source release2300 и роли
не удаляются и не восстанавливаются из старого дампа. Если появился неизвестный объект,
ACL/config/HTTPS drift, инструмент отказывает до остановки рабочих сервисов.

После cutover используйте coordinated `--enable-presentation`/`--disable-presentation`;
они проверяют точный real HTTPS before/after snapshot до compatibility view для старых
runtime comparisons. Внутренний runtime journal по-прежнему обслуживает его исходный
controller; во время самого cutover оба старых journal остаются побайтно прежними.

Перед **старым application/physical restore** сначала требуется metadata undo. Старый
restore содержит protected-config inventory с прежним dbuser/password/uid/mode и не
является способом отката этой операции. После последующей законной смены сертификата,
config, source schema или grants для undo может потребоваться отдельная проверенная
актуализация; произвольное rebaseline инструмент не выполняет.

Private state `/var/lib/ustar-postgres-app-handoff` содержит before/after config с
секретами и журнал команд: это локальные root-only файлы, не содержимое отчёта/артефакта.
Административный POSTGRES_USER=moodle и backup/helper pins сохраняются. Полный INF03
code-tree hardening, HBA, Docker logs/healthchecks остаются отдельными задачами.

## Доказательства

- Local23tests:22PASS/1UID33 namespace skip. Проверены реальные file/journal операции,
  unknown drift, неверный пароль до side effects, recover после7checkpoint и после
  SQL COMMIT/config rename/HTTPS rename до следующего checkpoint. Добавлен guarded
  restart только PG при pending recovery после остановки restart=no containers; это
  modeled coverage, не новая приёмка reboot сервера. PG/systemd граница
  в этих тестах doubled, это не production proof.
- Existing CI дополнен `tests/stage/db_app_handoff_native.py`: generated SQL roundtrip,
  definer/member/stale-inventory refusals, schema и selected business data retention,
  реальная PHP-проверка password escaping. Это отдельный targeted delta после прежних
  14native privilege checks, а не новый полный серверный стенд.
- Strict actual UID33 read/no-write/no-unlink проверяется отдельно в existing runtime CI.
- Qualified code7e35795f, Review40107134974/Context40107134982 SUCCESS; artifact12565387807,
  771320B/SHA256 63a89d626615c9d5c01be86d785c042b4820064d7176b8c2c0939e1dd4125225 verified.
  New9checks и prior14checks PASS. OID native:string / cast bigint:number подтверждены.
- Owner deployment/effective PHP role acceptance pending; это не production PASS.

## Готовый файл и owner --check

Файл `ustar-db-app-handoff-7e35795f.py` — точная копия qualified
`scripts/infra/postgres_app_handoff.py`, blob8906e3738135f33e45e067f89ad0f43d730eb70b,
SHA256 `fe7c7d9bbaf6a33578533bcea114506c2736c39b57616f5e1c05b469ef969da8`.
Сохранить файл на server1 в `/home/aduk/ustar-db-app-handoff-7e35795f.py`.
Сначала `sudo -v`, затем:

```bash
sudo -n install -o root -g root -m 600   /home/aduk/ustar-db-app-handoff-7e35795f.py   /root/ustar-db-app-handoff-7e35795f.py

printf '%s  %s\n'   fe7c7d9bbaf6a33578533bcea114506c2736c39b57616f5e1c05b469ef969da8   /root/ustar-db-app-handoff-7e35795f.py |
sudo -n sha256sum --check --strict - &&
sudo -n timeout --kill-after=3s 90s /usr/bin/python3 -I -B   /root/ustar-db-app-handoff-7e35795f.py --check
```

Ожидаемый phase: `ready_for_app_handoff`, host_changes=false/provider_called=false,
objects3479/657tables/656sequences/2166indexes. Новый пароль не создаётся и для --check
не требуется. Не выполнять apply после refusal или recovery_required; сначала разобрать
код отказа. Это не повтор прежних ручных OID/login probes: готовый инструмент сам
проверяет точные ACL/schema/objects/control parents и текущие authoritative snapshots.

После принятого --check операторская операция в выбранное окно недоступности:

```bash
sudo /usr/bin/python3 -I -B /root/ustar-db-app-handoff-7e35795f.py   --apply --acknowledge-outage
```

Пароль ранее созданной ustar_app вводится скрыто в tty. Финал — app_role_active либо
baseline_restored/apply_succeeded=false; recovery_required требует явного восстановления.
При прерывании: тот же файл `--recover --acknowledge-outage`; явный metadata-only возврат
после успеха: `--undo --acknowledge-outage`. История/учебные данные не откатываются.
`--status` отражает journal_only; свежий guarded --check проверяет текущие controls.
