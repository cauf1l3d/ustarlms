# CLI mail isolation и повтор readiness — 12.10.2026 Moscow

**Next:** новый `--check` [helper](../../scripts/infra/lab_patch_readiness.py), SHA256
`2942e58967980d1d8adcc63c04d996d8798d642724bfead2c66380aee32bc9f9`. Установить отдельным файлом
`/usr/local/sbin/USTAR_LAB_PATCH_READINESS_CLI_20261012.py`; old readiness/diagnostic
сохраняются. Immutable implementation/remote bytes фиксируются после публикации.

[Owner diagnostic22:27:09UTC](lab_patch_php_diagnostic_result_20261012.json) принят:
PHP8.3.33/CLI/64bit/memory512M/max_input_vars5000, native sendmail_path=/usr/sbin/sendmail
-t -i. Файл lab.ini совпадает с frozen entry; один readonly bind, destination не в
scanned list. Empty adapter literal list не исключает nonliteral/delegated overrides.
Причина readiness отказа — sender mismatch; **почему INI policy не применена CLI,
не установлено** (permissions/path/overrides не проверены). Main INI/readability
предыдущий sanitized report не показывал. Это не доказательство Apache settings.

Memory правильная; sender native configuration менять не требуется для безопасного
CLI прогона. Переходим к explicit process policy, проверяем действующие значения;
original defaults остаются отдельным наблюдением, не объявляются исправленными.
Root/private INI mode600, frozen copy, web settings и production mail/DNS/ISPConfig
не меняются. Сборка candidate0v8ncwgu/manifest07dfa485 code-only PASS сохраняется.
Storage/download/prepare-stage/prepare-candidate/diagnostic не повторять.

## Контракт

Default PLAN_ONLY, existing SH/nonblock readonly lock FD, прежняя pinned chain.
`--check` как прежде re-hashes весь accepted candidate/sidecar/config/frozen overlay,
inspects owned source/stage/production namespace before/after, проверяет пространство
и21 required PHP extensions/versions/platform. Дополнительно сверяет frozen+stage
lab.ini с exact accepted policy, требует один readonly bind, canonical destination.
Destination передаётся PHP как отдельный argv после `--`, не вставляется в PHP code.

Первое literal PHP built-in чтение показывает **native CLI**. Второе запускается с
`-d memory_limit=512M -d sendmail_path=/bin/false -d display_errors=Off -d log_errors=On`.
Оба отключают auto_prepend/auto_append. No Moodle/config/plugin bootstrap/custom helper/
HTTP/Composer; no settings files/container changes. Это configuration только текущего
PHP CLI process. Не выполняется mail()/sendmail/false binary; is_executable('/bin/false')
проверяет наличие blocker. is_readable(bound_ini) и main/scanned flags — наблюдения
файловой доступности; содержимое INI читается только host-side с проверенными SHA.

Native/effective platform должны совпадать; effective PHP8.3.33/CLI/64bit,
required extensions, memory512M/senderbinfalse и executable blocker обязательно
проверяются. Если flags ignored или значения неверные — отказ до SQL. Native mail
mismatch отдельно сохраняется (`native_cli_mail_disabled=false`), persistent PHP
configuration changed=false. Apache mail configuration/readability не названы PASS.
После effective policy PASS идут только bounded stage BEGIN READ ONLY SQL: original
core2025100601.03/57 addon versions/disabled tasks/PG160015. Production SQL/PHP не
выполняются. All manifests/INI/config/container identities проверяются в конце.

Report runtime содержит native_php_cli и php_cli (effective), явный CLI policy,
readable/main/scanned INI flags и process isolation verified=true. Readiness PASS
относится к candidate integrity и isolated CLI platform/readonly DB scope, не к
persistent mail settings, full addon/runtime/home/upgrade/paired restore acceptance.
Все последние flags остаются false. Future candidate CLI bootstrap/upgrade/repeat
обязаны повторять этот explicit policy; bare PHP нельзя считать изолированным по
файлу lab.ini. Persistent web isolation принимается в отдельном stage runtime step.

`--diagnose-php` продолжает показывать native defaults без process overrides и
не выполняет SQL/readiness acceptance. Old refusal/diagnostic contracts сохранены
как dated evidence; прежнее правило «никаких memory/mail overrides» заменяется
этим cause-specific process policy после actual owner sender observation.

## Проверки

[Validation](lab_patch_cli_isolation_validation_20261012.json):40 dedicated tests
OK/1 skipped (local PHP binary отсутствует), full r16237 OK/11 skipped (10 private engine и1 local PHP), context/index/diff PASS.
Fixture имитирует actual native sender и применяет argv policy; полный filesystem
snapshot unchanged. Checked ignored overrides fail before SQL, отсутствующий blocker,
INI corruption/readwrite bind, прежние tamper/lock/redaction/platform guards.
Новый **real PHP CLI test** вызывает actual interpreter с временным baseline INI
(sendmail default), затем с policy flags; проверяет native/effective values и неизменные
bytes/inode/mtime, без mail()/Moodle/SQL. Он запускается в PHP8.2/8.3 GitHub CI,
локально skipped; CI acceptance pending at publication. Private engine/Docker/SQL
в локальных тестах mocked. Code map526files/93tables/32tasks, app/frontend/workflows
unchanged. Exact implementation CI/readback и server readiness фиксируются отдельно;
PR99 draft/unmerged, frontend audit не обходится.

## Оператор

Same SSH shell, отдельный sudo -v, download immutable helper, checksum/install
root:root0600 в new CLI-readiness path, затем один `--check`. Physical one-line cmds.

```bash
sudo -n bash -c 'set -euo pipefail; umask 077; printf "%s  %s\n" 2942e58967980d1d8adcc63c04d996d8798d642724bfead2c66380aee32bc9f9 /usr/local/sbin/USTAR_LAB_PATCH_READINESS_CLI_20261012.py | sha256sum --check --strict -; python3 -I -B -u /usr/local/sbin/USTAR_LAB_PATCH_READINESS_CLI_20261012.py --check'
```

Нужен полный report; candidate не подключается, core upgrade не запускается.
После PASS — guarded stage activation/upgrade/repeat/paired code/config/Moodledata/
physicalPG restore и browser acceptance; эти runtime действия текущий helper не делает.
