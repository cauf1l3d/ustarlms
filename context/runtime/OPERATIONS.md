# Эксплуатация и выпуск USTAR

Основание: предыдущие серверные отчёты и `scripts/release/deploy_*.sh`. Актуальность документа 05.10.2026; фактическое окружение перепроверяется перед записью.

## Пути и компоненты

| Объект | Значение | Уверенность |
|---|---|---|
| Ubuntu host | 24.04.4 LTS / kernel 6.8.0-139; общий сервер | Owner evidence 03–04.10, размещение не подтверждено |
| Moodle container | `ustar_moodle` | Предыдущие отчёты / installers |
| PostgreSQL container | `ustar_postgres`, PostgreSQL 16 | Предыдущие отчёты / installers |
| Bind mount кода | `/opt/ustar/data/moodle/public` → `/var/www/html` | G00 |
| Публичный root Moodle 5.1 | host `/opt/ustar/data/moodle/public/public`, container `/var/www/html/public` | G00 / текущие installers |
| Core CLI | `/var/www/html/admin/cli` | Текущие installers; не добавлять сюда второй public |
| Moodledata | `/opt/ustar/data/moodle/moodledata` → `/var/www/moodledata` | Предыдущие отчёты |
| Reverse proxy | Apache хоста → loopback `127.0.0.1:8082` → Moodle | Owner evidence; Caddy — заглушка, не TLS-прокси Академии |
| Адрес приложения | получить из фактического `$CFG->wwwroot` на сервере | Не публиковать внутренние сетевые адреса в новых отчётах |

Source в Git: `moodle/local/ustar` → `<public-root>/local/ustar`; `moodle/theme/ustar` → `<public-root>/theme/ustar`. Git не содержит весь core и config.php.

## Read-only сверка OPS-01

На сервере в свежем checkout репозитория, сначала получить нужный exact SHA. Manifest владельца 03.10 подтвердил 526 matching plugin/theme PR76; перед следующим релизом нужна новая сверка. Если в server checkout нет origin/main, использовать exact существующий commit согласно §9Б аудита. Значение ниже задаёт reference SHA, не гарантирует свежую эквивалентность:

```bash
git fetch origin
USTAR_EXPECTED=e1d57f5bc28f6964afd20aeff7620345b34a80fe
USTAR_REPORT="$HOME/ustar-readonly-$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -m 700 "$USTAR_REPORT"
# Результаты этих команд оставить у оператора; публиковать только обезличенную сводку.
sudo docker ps --format '{{.Names}} {{.Image}} {{.Ports}}'
sudo docker exec -i -u www-data ustar_moodle php <<'PHP'
<?php
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';
echo json_encode([
    'dirroot' => $CFG->dirroot,
    'wwwroot' => $CFG->wwwroot,
    'maintenance' => (bool)($CFG->maintenance_enabled ?? false),
    'local_ustar' => get_config('local_ustar', 'version'),
    'theme_ustar' => get_config('theme_ustar', 'version'),
    'configured_theme' => $CFG->theme ?? null,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
PHP
sudo python3 scripts/production_manifest.py   --repo "$PWD"   --moodle-root /opt/ustar/data/moodle/public/public   --commit "$USTAR_EXPECTED"   --require-match   --output-dir "$USTAR_REPORT/source"
```

Версионная пара определяет кандидатов, manifest устанавливает побайтное соответствие. При exit 2/3 прочитать comparison.md: не выполнять rsync для «исправления» отличий без разбора. Проверить complete=true и отсутствие changed/production_only/git_only/uncompared. Сравнение не атомарно и не покрывает DB, Moodle core и moodledata.

Для дополнительного наблюдения mount/core/PostgreSQL использовать `sudo python3 scripts/collect_runtime.py --repo "$PWD" --output-dir "$USTAR_REPORT/runtime"`. Проверить errors и дату. Этот collector не покрывает сам по себе theme version и всю DB schema. Не публиковать config.php, env, дампы или персональные строки. Отчёты остаются на сервере до просмотра и обезличивания.

## Проверки кандидата

- `python3 scripts/ci/source_checks.py` — полный локальный source check при наличии PHP/Node; `--skip-php` допускается только как явно неполный локальный шаг.
- `python3 -m unittest discover -s tests/r16 -v` — harness/source contracts.
- `npm ci --prefix tests/release_20260912` и `npm test --prefix tests/release_20260912` — DOM contracts; имя каталога историческое, тесты актуализировались.
- `tests/mobile` — Chromium на реальных templates со synthetic data; это не live screenshots.
- `frontend` — собственные test/build/audit/http checks; они не доказывают production deployment этого frontend.
- Full RC: Actions **USTAR review gate**, workflow_dispatch `full_rc=true` на exact branch/commit либо событие добавления label `full-rc` к PR. Нужны все шесть success jobs. Обычный opened/synchronize запускает только source/frontend; наличие уже поставленной метки на новом SHA не запускает автоматически полный gate.

## Выпуск и откат

1. Фиксировать source SHA, версионную пару, baseline и релевантный installer. Все текущие `deploy_*` ограничены своими базами и могут клонировать историческую ветку; это причина сохранять refs, не обходить проверки.
2. До maintenance проверить roots, свободное место, baseline file hashes, требуемые extensions и права файлов.
3. Сделать согласованный backup DB + code + moodledata, проверить читаемость/целостность. При записи пользователей после backup учитывать потерю этих изменений при восстановлении.
4. Штатный installer включает maintenance, копирует проверенные plugin/theme, запускает core upgrade, purge/build CSS и сверяет post-deploy manifest. Использовать его целиком, а не переносить вручную отдельные строки из старого runbook.
5. После успешного скрипта проверить login и **авторизованные** сценарии сотрудника, руководителя, HR/HRD; отдельно cron/уведомления/медиа. HTTP 303 без входа — только access smoke.
6. Зафиксировать новый deployment evidence с SHA, versions, manifest totals, backup/report references, smoke results и ограничениями. Обновить STATE/ledger; synthetic stage fixtures менять только как отдельное осознанное изменение тестовой базы.
7. После DB upgrade не откатывать только PHP/version.php. Восстанавливать согласованный набор, проверенный на isolated restore. Если installer оставил maintenance при частичной записи — сначала разобрать report/backup, не выключать его вслепую.

## Cron, сеть и наблюдаемость

Фактические расписания в `db/tasks.php`; основная зависимость — работа штатного Moodle cron. Проверять последние успехи/ошибки и задержку scheduled tasks, обработку work tasks, rewards/competitions, reporting, enrolments, grade requests и RSS. Наличие записи в db/tasks.php само по себе не доказывает запуск cron; установка v3 и результаты приведены ниже с датой. Не запускать все задачи вручную на prod как диагностику.

Перед мобильным этапом проверить доступ телефона через корпоративную сеть/VPN, DNS и доверенное HTTPS, воспроизвести login/upload/download/SCORM. Изменение Apache/wwwroot не входит в наведение порядка в Git. Performance baseline и clean-server restore остаются отдельными задачами; цифры CI не характеризуют нагрузку реальной компании.


## Установленный cron v3 и текущее продолжение 05.10

Источник — [fresh dated evidence](evidence_20261005_baseline.yaml). `/usr/local/sbin/USTAR_CRON_SETUP_20261005.py` v3; `/etc/cron.d/ustar_moodle` каждую минуту `--tick`, один worker `www-data`, `--keep-alive=0`, cron/backup locks. Last-run: `/var/lib/ustar-cron/last-run.json`; private rotated log: `/var/lib/ustar-cron/logs/cron.log`.

Последний owner result 05.10 02:13 UTC — 1.070 с, exit 0, schedule/container match true, adhoc/USTAR errors 0, new_failures пуст. Не запускать повторный installer, второй cron/systemd timer или direct cron.php для обычного наблюдения. Статус читает:

```bash
sudo python3 /usr/local/sbin/USTAR_CRON_SETUP_20261005.py --status
sudo docker top ustar_moodle -eo pid,etime,stat,args
```

При stale/failed выяснить конкретную задачу, effective-enabled/timezone, lock/backup pause, журналы и process внутри контейнера: Ctrl+C SSH wrapper мог оставить PHP child. Не повторять ручной запуск до проверки. 17 COMPONENT_DISABLED overdue и два disabled H5P/registration residual faildelay отличаются от новых business failures. Историю не обнулять. После container recreation штатно перепроверить binding ожидаемых IDs; обход защиты не допускается. Hash установленного script подтверждён в 02:32:33 UTC: `66fd51d364beb59a8874a63c695c122dca5452a720e61b86d070beb0b1939852`; source upload 09:48:49 UTC полностью проверен и совпал. [CLI и locks](recovery_contract_20261005.md): `--status` без PHP bootstrap/tasks, `--install` выполняет real production pass, отдельного `--rebind` нет. При обычной диагностике не переустанавливать.

Read-only PHP bootstrap не гарантирует отсутствие всех side effects. Для адресной SQL-диагностики предпочтителен psql BEGIN READ ONLY с bounded statement/lock timeout; в конкретном Moodle DB connection режим включается и проверяется после bootstrap. PGOPTIONS драйвер переопределял; invoking sync/reconcile/execute не является просмотром.

Открытые DB/file/transport вопросы — [SECURITY_BASELINE](SECURITY_BASELINE.md), пульт — [MONITORING](MONITORING.md), согласованные копии — [BACKUP_RESTORE](BACKUP_RESTORE.md). Root 76% подтверждён 05.10 02:13; directory inventory есть, отдельный большой полный стенд не подтверждён. Required mail/DNS/ISPConfig сохраняются.

Владелец разрешил порядок 1 → 7 → 6; остальные пункты [полного плана](../roadmap/USTAR_AUDIT_REMEDIATION_PLAN_20261005_RU.md) после них. [Baseline и exact core preflight](baseline_20261005.md) — текущий шаг. Затем свежая разовая согласованная recovery copy, isolated upgrade/rollback, Moodle/CI/OS и HTTPS. GitHub-публикация не является deployment; продолжать concrete operator commands. Регулярность позже на healthy HDD, не SERVEREXPRESS и не проваливший SMART диск.

Текущий конкретный шаг — [fresh manual backup со встроенным preflight](recovery_contract_20261005.md#следующий-операторский-шаг) и проверка resume/cron status; Moodle останавливается на время snapshot. После password prompt потеряна вставка длинного heredoc, поэтому новые SSH-команды выдавать одной физической строкой, `sudo -v` отдельно с ожиданием нового prompt, затем `sudo -n`. Backup `--recover` означает resume по marker, не rollback DB/files; сохранность archive/ключа и isolated restore проверяются отдельно.
