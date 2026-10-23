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
https://pkg.go.dev/text/template#hdr-Functions (`index`), native результат ожидается.

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
