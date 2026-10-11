# Принятый candidate и read-only проверка — 12.10.2026 Moscow

**Current owner01:27MSK:** diagnostic completed, memory512M correct, native sender
/usr/sbin/sendmail -t -i. Next [explicit per-process CLI readiness](lab_patch_cli_isolation_20261012.md).
[Actual result](lab_patch_php_diagnostic_result_20261012.json); below is previous dated contract.


**Owner12Oct00:50MSK:** old helper2496d11/a45ddc installed/checksum OK,
`--check` refused memory/mail before SQL; actual values не были выведены.
[Refusal](lab_patch_readiness_refusal_20261012.json) / [next diagnostic](lab_patch_php_diagnostic_20261012.md).
Ниже сохранён прежний published contract и scoped CI; readiness не принят.


**Next:** один `--check` [readiness helper](../../scripts/infra/lab_patch_readiness.py).
[Owner receipt](lab_patch_candidate_result_20261012.json): corrected builder4a681023,
SHA bb71ddd01566341d506a560628453bdcec045de89af7f4a80774a205f2f059c2 установлен;
`LAB_PATCH_CANDIDATE_CODE=PASS; NOT_ACTIVATED; NO_CORE_UPGRADE` получен.
Не повторять SSD/HDD работы, download пакета, cold checkpoint, prepare-stage или
prepare-candidate. Результат принят по owner stdout, прямого server access нет.
Время начала/окончания сборки не печаталось, поля timestamps остаются null.

## Принятые артефакты

Candidate: `/var/lib/ustar-restore-lab/20261004T123406Z-741e3f72/patch-candidate-518-20261012-0v8ncwgu`.
Manifest SHA: `07dfa485c3118d4b72d6f47f1de4c7bbb421b22a02bb5a3f25c1f8634f7f459a`.
44174 entries,29409 stock core files unchanged,19 addon roots/57 metadata/526 USTAR
files; full readback and independent overlay inodes PASS. All23 extras сохранены:
22 in code, legacy githash.php в private preserved-extra-files, readback PASS.
В original frozen checkpoint root vendor **отсутствует**, в candidate не копировался.
Указанные70,914,838,528 free bytes измерены до копирования; это не текущий free.
Новый core2025100608.00 ещё не подключён к stage, DB2025100601.03 не обновлялась.

## Контракт следующей проверки

Helper SHA256: `a45ddc705e353e0013b2b9e4b8a34fd2c94c3c58831f15a37ae30badb2a706dc`.
Implementation: `2496d11fac6af3fd8f0265355ad143016423d001`. Published immutable helper bytes
совпали с tested local content; Git blob25851ef077675c7370f31754fe3155ac5e75fe39.
[PR99](https://github.com/cauf1l3d/ustarlms/pull/99) draft/unmerged; owner receipt
и helper в этой ветке, main unchanged.

Default PLAN_ONLY. Явный `--check` берёт SH/nonblock существующий coordination lock
через read-only FD, без его создания. Pinned corrected builder и цепочка cold/
preflight/resume/private engine/profile проверяются по прежним SHA. При отказе
нет автоматического retry, recovery, переключения или очистки.

Проверяется ровно принятый candidate directory/manifest SHA, code-only report,
provenance, root/control permissions и все44174 entries (type/owners/mode/single
link/SHA/filesystem), а также separate legacy file. Новый config сравнивается со
static offline template/homepage insertion; frozen overlay metadata/hash/independent
inodes сверяются повторно. Код/config/manifest/пакет не перезаписываются.

Source/stage/production Docker inspect выполняется до/после: owned IDs/images/
start times/mounts/offline network сохраняются. Production PHP/SQL не выполняются.
В existing stage web выполняется только literal `php -r` с PHP constants,
get_loaded_extensions и ini_get; auto_prepend/append отключены аргументами процесса.
Никакие config.php, Moodle bootstrap, unknown PHP helpers, Composer или candidate
files не исполняются. Ожидается selected PHP8.3.33/CLI/64bit,512M,/bin/false,
обязательные расширения из stock composer.json/environment.xml плюс pgsql.
Официальный5.1 XML задаёт минимум PHP8.2/PG15; tested target остаётся PG16.15.
Apache PHP settings этим CLI чтением отдельно не подтверждаются.

В stage PG выполняются только bounded BEGIN READ ONLY/statement_timeout15s/
lock_timeout3s queries: original core version,57 addon versions, disabled scheduled
tasks и server_version_num160015. Не запускаются upgrade, cron, email или HTTP.
Loopback relay TTL7200s может истечь; это не мешает проверке работающих container
и не является причиной снова создать baseline workspace.

Top-level composer.json у19 preserved addon roots читаются как JSON. Вывод —
число requirements/php/ext/packages, наличие локального vendor/composer.lock;
имена/значения private packages и содержимое файлов не печатаются. Это inventory,
**не Composer dependency resolution**, не полный анализ nested dependencies или
проверка API совместимости дополнений. Никакой автоматический composer install.
Fresh available bytes/inodes выводятся с reserve4GiB; DB-growth/paired restore
capacity в этой команде не принимаются, нужны actual activation budget.

`LAB_PATCH_READINESS=PASS; NOT_ACTIVATED; NO_CORE_UPGRADE` означает целостность
принятого candidate и перечисленные platform prechecks. Flags full addon/runtime
dependencies/homepage/upgrade/paired rollback остаются false. Guarded activation
helper перед своей операцией повторит input checks и обеспечит coordinated restore;
readiness PASS сам по себе не разрешает blind upgrade или rollback одной БД.

## Проверки

[Validation JSON](lab_patch_readiness_validation_20261012.json):26 локальных тестов: real candidate builder + synthetic private file fixtures,
полный неизменный filesystem snapshot, tampered stock/sidecar/manifest, missing/
extra/link/permissions/root-mode/provenance, wrong PHP/32bit/extensions/mail/PG,
container drift, space reserve, no default reads, exclusive/replaced lock,
dependency pin и private error redaction. Docker/PHP/SQL adapters mocked; server
readiness ещё не выполнен. Full r16/context suite223 OK/10 skipped (private HDD backup engine не предоставлен);
context/code-map/diff checks PASS. Парсер требований отдельно выполнен на настоящем
SHA-pinned5.1.8 archive: PHP8.2/PG15/21 required extensions включая pgsql, PASS.
Локальный source_checks без PHP проверил24JS/93tables/56templates; полный PHP lint
в этом окружении blocked (binary отсутствует), это не замена exact-commit GitHub CI.
После публикации exact implementation2496d11:
[Context52](https://github.com/cauf1l3d/ustarlms/actions/runs/38177469124) SUCCESS;
[Review488](https://github.com/cauf1l3d/ustarlms/actions/runs/38177469099)
source PHP8.2/8.3 SUCCESS включая новый r16 suite, frontend npm audit FAILURE,
rollback/moodle-db/prepare-rc/gate SKIPPED. Full gate не PASS, merge не выполнен. Application/frontend/workflows
не менялись. PR99 previous exact HEAD808fc0: Context51 success, sourcePHP8.2/8.3
success; frontend npm audit failure, rollback/moodle-db/prepare-rc/gate skipped.
Это не полный CI gate PASS и не основание merge.

## Команды оператору

В том же SSH `aduk@server1:~$`: отдельный `sudo -v`, затем download immutable helper,
SHA check и install root:root0600. Каждая команда — одна физическая строка.

```bash
sudo -n bash -c 'set -euo pipefail; umask 077; printf "%s  %s\n" a45ddc705e353e0013b2b9e4b8a34fd2c94c3c58831f15a37ae30badb2a706dc /usr/local/sbin/USTAR_LAB_PATCH_READINESS_20261012.py | sha256sum --check --strict -; python3 -I -B -u /usr/local/sbin/USTAR_LAB_PATCH_READINESS_20261012.py --check'
```

Без короткого timeout: проверяется весь accepted tree. После report готовится
full-addon stage activation/upgrade/repeat и paired code/config/Moodledata/physicalPG
restore, затем browser acceptance. Прежний source lab/checkpoint и production
сохраняются. При error сохранить полный stdout/error; автоматического retry нет.
