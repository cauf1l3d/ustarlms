# Совместимость первого сбора — 17 октября 2026

Владелец передал terminal output первого запуска cee34b13, self SHA256 check OK. [Receipt](../../runtime/server_facts_terminal_20261017.json) сохраняет только видимые статусы:17 ok,13 error, SMART not_collected. Архив `/var/tmp/ustar-server-facts-20261017T010021Z-506c32/report.tar.gz` создан, но ещё не получен агентом. Поэтому содержимое измерений и точные `reason` не утверждаются; версии/production state не обновляются из одних статусов.

13 error — не13 независимых отказов сервера. `containers` и `packages` — два исходных сбоя. Остальные11 блоков зависят от containers → code_layout: manifest/access, четыре application-DB PG probes и четыре Moodle probes. PHP CLI и глобальные PG probes выполнились. В частности, `pg_query_stats: error` пока не доказывает отсутствия extension: без определённой DB запрос вообще не выполняется.

## Воспроизведённые ошибки сборщика

- Docker inspect raw fallback использует `missingkey=error`. При отсутствующем optional `Health` даже `if .State.Health` завершается ошибкой; индексирование null log Config также небезопасно в raw fallback. Новый шаблон использует `index`/`with` и сохраняет `not_configured`, не включая Health.Log, Env или arbitrary log options. Проверен настоящий Go text/template engine, включая старые неуспешные expressions; это не mocked JSON-only тест. [Первичный источник Docker CLI](https://github.com/docker/cli/blob/v29.0.0/cli/command/inspect/inspector.go).
- Предыдущий TOKEN regex отвергал `~`, разрешённый в Debian/Ubuntu package version. Например, synthetic version `5:29.9.0-1~ubuntu.24.04~noble` воспроизводит ошибку. Добавлен отдельный закрытый package-version pattern. [Debian Policy Version](https://www.debian.org/doc/debian-policy/ch-controlfields.html#version).
- `dpkg-query` теперь отдельно фильтрует installed state. Отсутствующий optional caddy не считается ошибкой всей выборки, а fatal exit2 и пустой установленный набор не выдаются за успешный inventory.

Эти дефекты согласуются с видимым результатом первого запуска, но окончательная привязка его причин требует `errors.json` из исходного архива. Данные первого отчёта не переписываются. Импорт сохраняет прежнюю закрытую форму и проверку checksum; reports со старой версией не редактируются для прохождения новой схемы.

Добавлены6 regression tests: Debian epoch/tilde, optional/uninstalled/fatal query, actual strict Go template evaluation с отсутствующим/null Health/log config и настроенными значениями. Защита отсутствия raw Env/Health.Log/custom log options проверена. Native Go helper использует только standard library и не подключается к Docker. На server1 остаётся stdlib Python3 без новых пакетов и служебных операций.

Следующий шаг: повторить corrected pinned collector, получить исходный и новый archives, проверить/import observations, затем продолжить повторный review владельца. Терминальный receipt не заменяет полный facts snapshot или functional acceptance.
