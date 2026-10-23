# INF-01: первый read-only блок23Oct2026

Owner одобрил начало [плана возврата](infra_normalization_resume_20261023.md).
Первый конкретный результат — проверяемые команды
[`readonly_baseline_20261023.sh`](../../scripts/infra/readonly_baseline_20261023.sh).
Оператор сначала выполняет `sudo -v` и дожидается нового prompt, затем вставляет
содержимое блока. Нет скачивания зависимостей, архива, Moodle bootstrap, записей в
business DB, maintenance, service start/stop/restart, chmod/chown/ALTER ROLE,
backup capture, prune или сетевых обращений к провайдерам.

## Получаемые сведения

- UTC время/kernel/место, версия PostgreSQL и connection role, role attributes/
  memberships, DB/public/object/extension/default privilege owners, агрегат сессий.
- HBA **содержимое файла**, только при superuser probe; без auth options и текста
  ошибки, которые могут содержать чувствительные значения. Это не доказательство
  последних загруженных правил; pg_conf_load_time выведено отдельно.
- Версии local_ustar/theme_ustar из двух config rows, без пользовательских записей.
  Prefix `mdl_` — ранее принятый server1 baseline, а не autodiscovery другой БД.
- Host parent uid/gid/mode/symlink и только dbuser/dbname/prefix как ограниченные
  статические literals config.php. Config не исполняется и не печатается; literals
  не доказывают effective runtime после includes/environment overrides.
- Выбранные code/config/data paths внутри container, uid/groups `www-data`, stat и
  test -w без создания файлов. Это sample, не рекурсивная проверка всего core и не
  доказательство полного отсутствия ACL/symlink/replace рисков.
- Exact image IDs/mounts, state/restarts/OOM/health и ограниченные logging properties.
  Full inspect/Env, логи, пароли, keys и персональные DB rows не выводятся.
- Read-only status ранее установленных cron и HDD schedule helpers; capture/recover
  и повторная установка не запускаются. Helper status читается из текущей accepted
  root-managed установки; наличие успешного старого backup не становится свежим PASS.

## Интерпретация и пределы

Команды ограничены timeout; ошибка не скрывается и не запускает исправление.
Блок намеренно продолжает независимые probes после ошибки: shell exit последней
команды не является общим PASS. Любой отказ/timeout/unknown сохраняется как пробел
baseline. Разные probes не атомарны; не выполнять одновременно с изменением runtime.

`probe_role` — роль этого psql подключения, не автоматически Moodle application role.
Admin-looking role в pg_roles не доказывает независимый проверенный admin login.
Не снимать SUPERUSER и не менять permissions по одному наличию role/file.
Старые paired omissions UNIQUE indexes не ремонтируются этой проверкой.
WWW_DATA_WRITABLE=yes для code/config/родителя требует разбора; yes для data/cache/
sessions может быть необходимым. Missing_or_inaccessible — неизвестность, а не PASS.
Запись файла может быть запрещена при возможности заменить его через writable parent.

Парольные поля pg_roles/pg_authid, HBA options, текущие SQL texts, application/user
rows и contents логов/ключей не выбираются. Root всё же читает config **в памяти**
для трёх identifiers; это не запуск PHP. Access/auth logs и file atime могут
обновляться обычными механизмами ОС при чтении/подключении.

Первый блок не закрывает INF-01 целиком: full source/core manifest, complete ACL/
data/dumps/keys/SMART/independent admin verification и backup restore остаются дальше.
После owner output составить конкретный P0 change preview и native test/rollback,
сохраняя Академию/cron/backup/Connect/Apache/AutoReport.

## Проверка команд и происхождение

Локально доступны Bash и Python, нет PHP/PostgreSQL/Docker. Проверяются bash -n,
вложенный sh -n и компиляция встроенного Python без исполнения host probes.
SQL/реальные permissions и helper status должны быть выполнены владельцем на server1;
native baseline ещё не измерен. Это не source/SQL/production PASS.

PostgreSQL16 primary references, проверены23Oct:
https://www.postgresql.org/docs/16/view-pg-roles.html
https://www.postgresql.org/docs/16/view-pg-hba-file-rules.html
Модель — metadata only, BEGIN READ ONLY/ROLLBACK,12s statement/2s lock timeouts.
0subagents/author review, нет agent SSH/provider sends/main merge.

Exact handoff file: readonly_baseline_20261023.sh,5765bytes, SHA256 `4382ed9e1c2e08223b61b4dc9c4bdfd47d5b4c7d21e41939031428eb7cdf5ad0`.
После скачивания в /home/aduk оператор копирует root:root0600 в /root,
сверяет SHA и запускает /bin/bash; diagnostic helper не устанавливает cron/backup.
