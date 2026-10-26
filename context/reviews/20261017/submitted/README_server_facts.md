# Read-only сбор фактов о сервере

## Назначение

Закрыть разрыв между тем, что репозиторий **знает**, и тем, что на сервере **есть**.
Каждое серверное утверждение в `context/` сейчас — квитанция владельца с пометкой
`independently_measured_on_server: false`. Этот скрипт даёт проверяемый снимок.

## Отношение к существующим скриптам

`scripts/collect_runtime.py` **уже собирает** и **не дублируется** здесь:

| Уже есть в `collect_runtime.py` | Файл |
|---|---|
| `docker inspect` контейнеров **без `Env`**: image, image_id, mounts, running, status, started_at, restart_count, health, uptime | `runtime.json` |
| Read-only probe версий: PHP, `moodle_db`/`disk`, `ustar_db`/`disk`, `postgres server_version` | `runtime.json` |
| Сверка `ustar_db == ustar_disk` | `runtime.json` |
| Git source: commit, branch, dirty | `runtime.json` |
| contextd units и pids | `runtime.json` |

`scripts/production_manifest.py` собирает сверку plugin/theme по SHA.
`scripts/refresh_context.py` объединяет всё это в снапшот.

**Этот скрипт добавляет то, чего нигде нет:** роли и права PostgreSQL, владение кодом
и config, политику SSH и firewall, TLS, доказательства копий и восстановления,
состояние cron и scheduled tasks, SMART, пакеты, логи и место.

Запускать **вместе**: сначала `collect_runtime.py`, затем `server_facts.sh`.

## Гарантии

1. **Только чтение.** Нет `apt`/`dpkg install`, нет `systemctl start/stop/restart`,
   нет SQL кроме `SELECT`/`SHOW`, нет `docker exec` с записью, нет создания файлов
   вне выходного каталога.
2. **Секреты не собираются.** `config.php` сокращён до ключей `SET`/`-` без значений.
   `docker inspect` идёт **без** поля `Env`. Ключи и сертификаты — только именами.
   `/etc/shadow` не читается.
3. **Сбойный блок не прерывает прогон.** Причина пишется в `_errors.txt`, остальное
   собирается. Скрипт возвращает `1`, если были сбои.
4. **Внутренние адреса вырезаются перед коммитом** (см. шаг «Публикация»).

## Запуск

```bash
sudo scripts/infra/server_facts.sh
# или с явным каталогом:
sudo scripts/infra/server_facts.sh /srv/ustar-storage/reports/server-facts-$(date -u +%Y%m%dT%H%M%SZ)
```

Root нужен только для `smartctl` и чтения unit-файлов. Без SMART достаточно
пользователя с доступом к `docker`.

Переменные переопределения (если имена отличаются):

```bash
USTAR_MOODLE_CT=ustar_moodle USTAR_PG_CT=ustar_postgres \
USTAR_DB_NAME=moodle USTAR_DB_USER=moodle \
USTAR_PUBLIC_DIR=/opt/ustar/data/moodle/public \
USTAR_HDD_DIR=/srv/ustar-storage \
  scripts/infra/server_facts.sh
```

## Что покрывает по задачам

| Задача | Файлы |
|---|---|
| **INF-02** роли PG | `D1_roles.txt`, `D5_table_owners.txt`, `D2_databases.txt` |
| **INF-03** код/config/секреты | `H3_ownership.txt`, `H4_acl_ustar.txt`, `H5_wwritable.txt`, `C6_config_keys.txt` |
| **INF-05** логи и лимиты | `B4_limits.txt`, `I1_journal.txt`, `I2_log_sizes.txt` |
| **INF-07** HTTPS | `E1_apache_vhosts.txt`, `E3_tls_inventory.txt`, `E6_http_cookie.txt` |
| **INF-10** SSH/firewall | `H1_sshd.txt`, `E4_firewall.txt`, `H2_users.txt`, `H6_apparmor.txt` |
| **INF-11** cron/exceptions | `G3_task_sched.txt`, `G4_adhoc_queue.txt`, `G5_cron_state.txt`, `G2_cron_log.txt` |
| **INF-14 / OPS-02** копии | `F1_backup_timer.txt`, `F2_backup_files.txt`, `F4_backup_evid.txt` |
| **PERF-01** производительность | `D3_settings.txt`, `D6_table_sizes.txt`, `D7_top_queries.txt`, `D8_wal.txt` |
| **INF-04** SMART | `A13_smart.txt` |
| **INF-01** базовое состояние | `A3`–`A15`, `B5`, `B6` |

## Публикация в репозиторий

```bash
# 1. Отфильтровать секреты и внутренние адреса
cd /srv/ustar-storage/reports/server-facts-<timestamp>
grep -rilE 'password|secret|token|apikey|BEGIN [A-Z ]*PRIVATE KEY' . > _review_secrets.txt || true
cat _review_secrets.txt        # прочитать, вырезать найденное
sed -i -E 's/\b(10|172\.(1[6-9]|2[0-9]|3[01])|192\.168)\.[0-9]+\.[0-9]+\b/<internal>/g' *.txt

# 2. Разложить в контекст
cd /path/to/ustarlms && git switch main && git pull --ff-only
D=context/runtime/server-facts/$(date -u +%Y%m%d)
mkdir -p "$D" && rsync -a --exclude '_review_secrets.txt' <report-dir>/ "$D"/
cp <report-dir>/../runtime.json "$D"/ 2>/dev/null || true

# 3. Индекс, проверка, ссылка в точке входа
python3 scripts/build_context_index.py
python3 scripts/check_context.py
scripts/infra/apply_server_facts.sh "$D"      # см. отдельный скрипт

# 4. Коммит
git add context/runtime/server-facts context/CONTEXT_INDEX.md \
        context/index/context_index.json context/current.yaml context/roadmap/STATE.yaml
git commit -m "context(runtime): read-only server facts snapshot

Closes evidence gap for INF-01/02/03/04/05/07/10/11/14 and PERF-01.
No secrets: config.php keys only as SET/-, docker Env excluded,
keys listed by name, internal addresses redacted."
git push -u origin HEAD
```

## Правила (для агентов)

1. Раз в месяц или перед каждой инфраструктурной операцией — новый каталог с датой.
2. Прогон **не заменяет** native acceptance. Скрипт показывает состояние, но не
   доказывает работу функций приложения.
3. `_review_secrets.txt` и `_errors.txt` не коммитятся.
4. Секреты и адреса — проверять **до** `git add`, не после.
