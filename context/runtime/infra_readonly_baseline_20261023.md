# INF-01: первый read-only блок23Oct2026

**Текущее состояние23Oct: отдельный PostgreSQL admin уже создан и проверен.**
Owner corrected retry: ustar_pg_admin, две обязательные SCRAM-сессии9976/9995,
login_role/effective_role совпали, rolcanlogin=t/rolsuper=t, оба READ ONLY/ROLLBACK.
INF02 step2 login acceptance принята; **createuser больше не выполнять**.
Ниже сохранена датированная история команд и исправления. moodle privileges/HBA/
config/services не менялись; copy rehearsal/backup/recovery и полная INF02 ещё pending.
[Та же квитанция](infra_readonly_owner_20261023.json), поле admin_creation_success.

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
SQL/реальные permissions и helper status выполнены владельцем на server1; частичный
результат и два пробела ниже. Локальной native Docker проверки исправления ещё нет.

PostgreSQL16 primary references, проверены23Oct:
https://www.postgresql.org/docs/16/view-pg-roles.html
https://www.postgresql.org/docs/16/view-pg-hba-file-rules.html
Модель — metadata only, BEGIN READ ONLY/ROLLBACK,12s statement/2s lock timeouts.
0subagents/author review, нет agent SSH/provider sends/main merge.

Первоначально выполненный owner файл: readonly_baseline_20261023.sh,5765bytes,
SHA256 `4382ed9e1c2e08223b61b4dc9c4bdfd47d5b4c7d21e41939031428eb7cdf5ad0`.
После скачивания в /home/aduk оператор копирует root:root0600 в /root,
сверяет SHA и запускает /bin/bash; diagnostic helper не устанавливает cron/backup.

## Получен owner baseline23Oct21:08МСК

[Квитанция](infra_readonly_owner_20261023.json), measured18:08:18UTC. Подтверждены:

- PostgreSQL16.15: `moodle` — единственный найденный superuser/login admin, с
  CREATEROLE/CREATEDB/REPLICATION/BYPASSRLS. Все657public tables и656sequences его.
  Независимый admin login не проверен. Это роль psql probe; роль приложения пока не
  установлена: первый static probe прочитал `public/public/config.php`, где нет
  трёх поддержанных literals. Установщики используют `public/config.php` уровнем выше.
- www-data33 может записывать `local/ustar` и его version.php. Проверенные code
  parents/core/theme samples не writable. Это P0 INF03, не полный recursive/ACL audit.
- `/`55%,48G available; scheduled cron23Oct18:08UTC exit0 за1.222s, без новых ошибок.
  Счётчики19overdue/2failed требуют task-level разбора; error20Oct — исторический.
- Автоматическая HDD copy23Oct03:30МСК complete/success/exit0, timer active/enabled,
  marker отсутствует, следующий24Oct03:30МСК. Поздний success16:18UTC не объявляется
  ещё одним scheduled run. Decryption/clean-host restore этим status не проверены.
- Три bind mounts получены. Inspect metadata для обоих containers потеряны: наш
  шаблон обращался к отсутствующему `.State.Health`. Исправление использует
  `{{with index .State "Health"}}`; отсутствие healthcheck не объявляется healthy.

Исходник исправлен в двух местах: optional Health lookup и основной config path
(включая www-data sample). Текущий5780B SHA256
`6ac4552ae3a2cc39df0dd65dcc32bb0fd275457cff92d98df13ffd64f8a0afc4`.
Это новый source artifact, не подмена SHA уже выполненного owner файла.
Повторять весь baseline не нужно: только команды ниже.

## Узкий follow-up без изменения сервера

Идентификаторы config читаются статически, содержимое и пароли не печатаются.
Нет PHP bootstrap, повторного SQL/cron/backup запуска или изменения services/прав.
Docker Go-template correction локально проверена по первичной документации
https://pkg.go.dev/text/template#hdr-Functions (`index`). Owner выполнил команды ниже:
native Docker correction и основной config metadata/literals получены; повтор не нужен.

```bash
sudo -v

sudo -n timeout --kill-after=3s 10s docker inspect --format \
  '{{.Name}} id={{.Id}} image={{.Image}} state={{.State.Status}} restarts={{.RestartCount}} oom={{.State.OOMKilled}} health={{with index .State "Health"}}{{.Status}}{{else}}not_configured{{end}} log_driver={{.HostConfig.LogConfig.Type}} max_size={{index .HostConfig.LogConfig.Config "max-size"}} max_file={{index .HostConfig.LogConfig.Config "max-file"}} readonly_rootfs={{.HostConfig.ReadonlyRootfs}}' \
  ustar_moodle ustar_postgres

sudo -n timeout --kill-after=3s 10s docker exec -u www-data ustar_moodle sh -c '
p=/var/www/html/config.php
stat -Lc "CONFIG=%n uid=%u gid=%g mode=%a" "$p" || exit
if [ -r "$p" ]; then echo WWW_DATA_READABLE=yes; else echo WWW_DATA_READABLE=no; fi
if [ -w "$p" ]; then echo WWW_DATA_WRITABLE=yes; else echo WWW_DATA_WRITABLE=no; fi
'

sudo -n timeout --kill-after=3s 10s /usr/bin/python3 -I -B - <<'PY'
import json, re, stat
from pathlib import Path
p = Path('/opt/ustar/data/moodle/public/config.php')
s = p.lstat()
print(json.dumps({'config': str(p), 'uid': s.st_uid, 'gid': s.st_gid,
                  'mode': oct(stat.S_IMODE(s.st_mode)), 'symlink': p.is_symlink()}))
if not stat.S_ISREG(s.st_mode) or s.st_size > 262144:
    raise SystemExit('config_type_or_size_refused')
with p.open('rb') as f:
    raw = f.read(262145)
if len(raw) > 262144:
    raise SystemExit('config_size_refused')
raw = raw.decode('utf-8')
values = {}
for key in ('dbuser', 'dbname', 'prefix'):
    found = re.findall(r'(?m)^[ \t]*\$CFG->' + key +
                       r'\s*=\s*([\'\"])([A-Za-z0-9_.-]{1,63})\1\s*;', raw)
    values[key] = found[0][1] if len(found) == 1 else 'not_one_supported_literal'
print(json.dumps({'static_literals_not_effective_runtime_proof': values}))
PY
```

Следующее изменяющее действие готовится после этого результата: отдельный admin
PostgreSQL с проверенным login/recovery и разбором upgrade/backup consumers, затем
минимизация elevated flags и code write access. Нельзя демотировать единственный
admin сейчас, менять HBA целиком или рекурсивно chmod/chown data/core по sample.

## Follow-up получен: переход к INF-02, шаг2

Owner output23Oct: оба контейнера running/restarts0/OOMfalse, exact images совпадают
с accepted HDD adapter. Healthcheck отсутствует; json-file без max-size/max-file;
это не healthy verdict и не измерение текущего размера logs. Реальные container IDs
сохранены в `owner_followup` [той же квитанции](infra_readonly_owner_20261023.json).
Основной config.php —33:33/0755, regular/no symlink, readable+writable под www-data.
Статические dbuser/dbname/prefix=`moodle/moodle/mdl_`; PHP не исполнялся, includes/
environment overrides этим не исключены. Начальный18:08UTC receipt сохранён отдельно
от follow-up без придуманного measurement timestamp.

Первый адресный write — **добавить** `ustar_pg_admin` с private tty password и проверить
его в двух отдельных новых DB sessions. Никакой demotion/HBA/restart/config write в
этом блоке. Пароль выбирает owner и сохраняет в своём password manager; агент его не
получает, plaintext/verifier не печатается. Используются существующие createuser/psql
в production PostgreSQL image, без установки новых packages.

Сначала отдельный `sudo -v`, дождаться prompt. Затем Bash block:

```bash
(
  set -euo pipefail
  ustar_pg_ip=$(sudo -n docker inspect --format \
    '{{range .NetworkSettings.Networks}}{{println .IPAddress}}{{end}}' \
    ustar_postgres | awk 'NF {print; exit}')
  if [[ ! "$ustar_pg_ip" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ || \
        "$ustar_pg_ip" == 127.* || "$ustar_pg_ip" == 0.0.0.0 ]]; then
    printf '%s\n' 'POSTGRES_INTERNAL_IP_NOT_FOUND' >&2
    exit 1
  fi

  sudo -n docker exec -it ustar_postgres env \
    PGCONNECT_TIMEOUT=5 \
    PGOPTIONS='-c password_encryption=scram-sha-256 -c search_path=pg_catalog -c statement_timeout=15s -c lock_timeout=3s' \
    createuser --username=moodle \
    --superuser --pwprompt ustar_pg_admin

  for ustar_admin_session in 1 2; do
    printf 'ADMIN_SESSION=%s\n' "$ustar_admin_session"
    sudo -n docker exec -it ustar_postgres psql -X -W \
      -P pager=off -v ON_ERROR_STOP=1 \
      "host=$ustar_pg_ip dbname=moodle user=ustar_pg_admin connect_timeout=5 require_auth=scram-sha-256 passfile=/dev/null" \
      -c "BEGIN READ ONLY; SET LOCAL statement_timeout = '15s'; SET LOCAL lock_timeout = '3s'; SET LOCAL search_path = pg_catalog; SELECT session_user AS login_role, current_user AS effective_role, pg_backend_pid() AS backend_pid, rolcanlogin, rolsuper FROM pg_catalog.pg_roles WHERE rolname = current_user; ROLLBACK;"
  done
)
```

Creation prompts twice for the new password; the two login probes each prompt once
more. Expect `ustar_pg_admin`/`ustar_pg_admin`, distinct backend PIDs, rolcanlogin=t
and rolsuper=t in both sessions. Required SCRAM prevents a local-trust login from
being mistaken for password verification. Container IP is discovered locally before
creation; no external socket/port/router binding added. Connection5s, SQL15s/lock3s;
interactive typing is deliberately not killed by a whole-command timeout.

Existing name → createuser refuses and the subshell stops, no silent password reset
or reuse. Failed verification → leave `moodle` privileges untouched, collect error
and repair only the new admin path. No DROP/reassignment/CASCADE occurs automatically.
If cancelling this additive step before any ownership/use of the new role, owner may
run `sudo docker exec -it ustar_postgres dropuser --username=moodle ustar_pg_admin`;
ordinary drop refuses dependencies. Old backups retain the previous admin path;
backup coverage of new credentials/roles is not claimed by this creation step.

## Следующие writes и совместимость

INF-02 steps3–5 require copy rehearsal: domain writes/File API/cron, XMLDB upgrade/
repeat, dump/restore and cluster-operation denials under target flags. Source
`production_restore.py` already separates `ustar_restore_admin` and `ustar_app` in
its isolated restore, but that is not this live role's acceptance. Old release
scripts and readiness SQL use POSTGRES_USER; the installed backup engine is a
separate hash-pinned delegate. All consumers must be checked before demoting moodle.

INF-03 target candidate for primary config is root:33/0640 with unchanged bytes,
root-owned non-writable parents and effective www-data read/no-write/no-replace.
Before applying: full ACL/xattrs/links sample and copy test, private recovery image,
and coordinated metadata handoff in HTTPS owner state. `academy_https.Manager.capture`
saves uid/gid/mode/xattrs, `replace` reapplies them; readiness checks accepted metadata.
Plain chmod/chown alone would leave a stale captured owner/mode and a future restore
could reinstate the old permissions. Current Connect2300 successor snapshots include
HTTPS controls, so full handoff/rollback must also preserve their contracts. Source
local_ustar must be root-managed as a release tree; do not recursively change moodledata.

INF-05: later explicit max-size/max-file and healthchecks need exact container recreate
preview and preservation of mounts/IDs in consumers; changing daemon defaults alone
does not retrofit running containers. No log truncate/prune or Docker restart now.

Primary references checked23Oct2026:
https://www.postgresql.org/docs/16/app-createuser.html
https://www.postgresql.org/docs/16/libpq-connect.html (`require_auth`)
https://www.postgresql.org/docs/16/auth-trust.html
https://docs.docker.com/engine/logging/drivers/json-file/

Local Bash syntax/author review can qualify these commands, not prove actual role
creation or successful SCRAM sessions. Both owner outputs remain required.

## Исправление CLI23Oct: unsupported --maintenance-db

Owner выполнил первую версию блока; PostgreSQL16 createuser отказал на неизвестном
`--maintenance-db=moodle`. Это ошибка наших команд: Bash syntax проверялся, но перечень
опций createuser был проверен недостаточно. По owner output и upstream argument parsing
отказ произошёл до password prompt/DB connection/CREATE ROLE; оба SCRAM-входа не запускались.
Новую роль этим запуском не создали, права moodle и конфигурацию не меняли.

Минимальное исправление — удалить неподдержанный параметр из createuser и dropuser
rollback выше. Не подменять его на `-d` (это CREATEDB) или PGDATABASE: createuser16
задаёт cparams.dbname=NULL, а connectMaintenanceDatabase выбирает postgres/fallback
template1. Роль кластерная; подключение к бизнес-БД moodle для создания не требуется.
Остальная команда/парольный поток и два required-SCRAM подключения к moodle сохранены.

Проверены полная PG16 документация createuser/dropuser и REL_16_STABLE source:
https://github.com/postgres/postgres/blob/REL_16_STABLE/src/bin/scripts/createuser.c
https://github.com/postgres/postgres/blob/REL_16_STABLE/src/fe_utils/connect_utils.c
https://www.postgresql.org/docs/16/app-dropuser.html
Одноразовая static option check против независимого upstream long_options сначала
отклоняет старый --maintenance-db; исправленный набор принимается. Это source/CLI
compatibility check, не native выполнение createuser; owner retry ещё требуется.
