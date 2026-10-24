# INF02: отдельное DB-подключение Академии,24Oct2026

**Статус: source реализован локально; публикация отклонена автоматической проверкой.**
Нужно явное разрешение владельца на запись в существующую ветку PR106; обходить отказ
через другой API нельзя. Новый SQL/UID33 delta CI не запущен. [Локальная квитанция](infra_app_handoff_local_checks_20261024.json).
Команду production apply до подтверждённого результата не выдавать. Это не квитанция
о переключении server1. Owner уже создал обе роли и принял текущий preflight;
повторные ручные login/OID/inventory и новый серверный стенд не нужны.

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
- Новый CI result/immutable commit/run/artifact и production acceptance пока pending.
