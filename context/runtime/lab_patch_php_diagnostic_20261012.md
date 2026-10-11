# Readiness отказ и PHP диагностика — 12.10.2026 Moscow

**Next:** только `--diagnose-php` нового [helper](../../scripts/infra/lab_patch_readiness.py).
[Owner refusal receipt](lab_patch_readiness_refusal_20261012.json): old helper2496d11/
SHA a45ddc установлен/checksum OK, `--check` отказал с `Offline stage PHP memory/email
settings differ`. Получено12Oct00:50:10MSK/11Oct21:50:10UTC; execution timestamps
не печатались. Это owner evidence, не независимый live query агента.

Два expected параметра были проверены одним условием: CLI memory_limit512M и
sendmail_path/bin/false. Actual values не были напечатаны; причина **не установлена**.
Возможно различие CLI и loaded lab.ini или process overrides в pinned lab_exec;
это гипотезы, не результат. По reviewed path CLI built-ins выполнены, до SQL и
final container recheck helper не дошёл. Full readiness не принят; stage/core DB
upgrade/activation/paired rollback не выполнены.

Candidate0v8ncwgu/manifest07dfa485, frozen checkpoint77672fd0 и19 addons/526 USTAR
сохраняются. Storage/download/prepare-stage/prepare-candidate не повторять.
[Candidate code-only owner PASS](lab_patch_candidate_result_20261012.json) не отменён;
runtime compatibility этим отказом не подтверждена и не объявлена нарушенной.

## Новый contract

New helper SHA256: `a432e80c82ad24dc640cf765fd90b3570a1e0872a55ec104ff52fa97b6388098`.
Устанавливается отдельным файлом `/usr/local/sbin/USTAR_LAB_PHP_DIAGNOSTIC_20261012.py`
root:root0600, прежний installed readiness SHA a45ddc остаётся. Git source file один;
old implementation2496d11 остаётся immutable для provenance. New implementation
публикуется в draft PR99, immutable bytes/SHA проверяются перед командой оператору.

Default PLAN_ONLY; `--check` и `--diagnose-php` взаимоисключающие. Existing SH/nonblock
coordination lock с readonly FD и прежняя SHA-pinned chain builder4a6810/cold/preflight/
resume/private engine/profile. Source/stage/production inspect before/after сохраняет
owned IDs/images/start times/offline network. Accepted candidate manifest/report,
frozen manifest и baseline offline config проверяются, полный code tree в diagnostic
не перечитывается. Это не повтор сборки и не full readiness acceptance.

Stage lab.ini предварительно byte-compared с SHA-pinned frozen entry и reviewed
memory512M/sendmail/bin/false/displayOff/logOn contents; при изменённом файле PHP
не выполняется. Stage-owned web mount metadata даёт число exact ini binds, readonly
flag и совпадение container destination со scanned INI list. Raw host/container
paths не выводятся. PHP only literal built-ins via retained stage adapter33:33,
auto_prepend/auto_append отключены аргументами; Moodle/config/plugin/unknown PHP,
Composer/HTTP/SQL не выполняются. PHP settings не меняются, overrides memory/mail
для сокрытия исходного различия не добавляются.

Observed memory_limit/max_input_vars/version/SAPI/int_size безопасно выводятся.
Sendmail печатается только для exact benign known values (/bin/false и стандартные
sendmail варианты); nonstandard command/path/value редактируется. INI list показывает
лишь проверенные basenames. Settings compared with expected512M/binfalse separate
booleans. `lab_exec` функция installed private engine читается по exact SHA как AST:
выводятся только literal memory_limit directives и sendmail_path=/bin/false внутри
функции. Другие строки/исходник/секреты не выводятся. Это **не resolved final argv и
не proof of override precedence**; итог устанавливается по actual diagnostic.

`LAB_PATCH_PHP_DIAGNOSTIC=COMPLETE; NOT_ACTIVATED; NO_SQL; NO_CORE_UPGRADE`
показывает завершение диагностики даже при settings mismatch, **не readiness PASS**;
`readiness_accepted=false`. Existing `--check` retains exact memory512M/binfalse
refusal, но теперь перед ошибкой печатает sanitized LAB_PATCH_PHP_OBSERVED. Checks
версии/extensions/SQL/space/inodes/namespace/provenance не ослаблены. После actual
values решается cause-specific correction, затем readiness, затем guarded stage
upgrade/repeat/paired code/config/Moodledata/physicalPG restore/browser acceptance.

## Проверки

[Validation](lab_patch_php_diagnostic_validation_20261012.json):35 dedicated tests
PASS, full r16 suite232 OK/10 skipped (private HDD backup engine source отсутствует).
9 new guard tests cover mismatch-without-SQL-or-PASS, no full candidate scan/filesystem
writes, frozen INI drift, engine pin, container drift, exclusive lock, diagnostics
stdout and private value redaction. Adapter fixture memory256M — synthetic example,
**не наблюдение на сервере**. Tests mock private chain/runtime; no real Docker/PHP/SQL.
Generated map526files/93tables/32tasks unchanged; context/index/diff checked before
publication. Historical implementation2496d11 Context52/sourcePHP82_83 success;
frontend npm audit failure/downstream skipped/full gate not PASS. New exact CI is
recorded separately after publication. PR99 remains draft/unmerged; application,
frontend, cron, scheduler, private engine and runtime settings unchanged.

## Оператор

В том же SSH окне после отдельного sudo -v: скачать new immutable helper в
/home/aduk/USTAR_LAB_PHP_DIAGNOSTIC_20261012.py, checksum/install separate root0600
path, затем только `--diagnose-php`. Каждая команда одна физическая строка.

```bash
sudo -n bash -c 'set -euo pipefail; umask 077; printf "%s  %s\n" a432e80c82ad24dc640cf765fd90b3570a1e0872a55ec104ff52fa97b6388098 /usr/local/sbin/USTAR_LAB_PHP_DIAGNOSTIC_20261012.py | sha256sum --check --strict -; python3 -I -B -u /usr/local/sbin/USTAR_LAB_PHP_DIAGNOSTIC_20261012.py --diagnose-php'
```

Нужен полный sanitized report. При error не менять ini, не запускать upgrade,
не retry/cleanup; сохранить output. Readiness прошлого script не повторять до
разбора actual memory/mail/INI/adapter observations.
