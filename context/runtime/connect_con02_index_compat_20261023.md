# CON-02: совместимость с историческими индексами server1

23Oct2026, Europe/Moscow. [Owner receipt](connect_con02_index_owner_20261023.json).
Статус: исправление source проверено в required CI; новый обычный.py готов для owner --check.
Tool `4394b99475f469b061010a1ea4aa77b9d984c40f`, SHA256 `331c5c54eb605e142c401d6b84cbda02a1ba468be40b2ffc1da768b9429d322b`.
[Команды](connect_con02_deployment_20261023.md), [точная CI-квитанция](connect_con02_index_compat_ci_20261023.json).

## Наблюдение и причина отказа

Владелец подтвердил SHA обычного b971.py; readonly --check отказал schema_indexes.
Read-only differential проверил99таблиц и показал ровно2отличия: отсутствуют
UNIQUE local_ustar_coin_accounts(userid) и UNIQUE local_ustar_test_results(attemptid).
Обычные индексы этих полей присутствуют, остальные сигнатуры совпадают.
Upgrade/ACK не запускались, конфигурация/данные не изменены. Это owner terminal evidence,
не независимый SSH. Причина первоначального отсутствия индексов не установлена.

Controller ошибочно требовал fresh-install XMLDB indexes для всей исторической базы.
install.xml содержит оба UNIQUE, но текущий upgrade.php не добавляет их существующим
legacy таблицам; комментарий около2026082705 явно сохраняет старую coin_accounts.
Активные runtime writers этих двух legacy таблиц в текущем продукте отсутствуют
(route_tester содержит только очистку). Fresh CI baseline не воспроизводил это отличие.
Это несовместимость preflight, а не доказательство повреждения данных.

## Ограниченное исправление

При frozen baseline local2026102000/theme2026100202 принимается либо полный XMLDB
index profile, либо ТОЧНО показанная пара отсутствующих UNIQUE. Частичная пара,
любые другие missing/extra/unique изменения, иной baseline и все column/default
отличия по-прежнему запрещены. Новый ACK table проверяется строго по fresh XMLDB.
Readonly JSON дополнительно показывает baseline_index_profile.

После принятия baseline все старые99таблиц/колонки/полные определения индексов
сравниваются с сохранённым снимком при upgrade/repeat/recovery. Автоматического
CREATE/DROP INDEX, исправления legacy данных или расширения схемы вне новой ACK
таблицы нет. Отсутствующие ограничения остаются явно документированной legacy-разницей,
а не считаются восстановленными этим выпуском. App/worker534 и accepted a86/HTTPS
contracts неизменны; новая поставка — отдельный ordinary.py с новым SHA после CI.

## Проверки

Три положительных historical cases воспроизвели schema_indexes до исправления.
После исправления74focused tests PASS/1local UID33 namespace skip. Проверены полная
пара, fresh profile, отказ частичной паре/неизвестным индексам/колонкам/defaults,
отказ изменению старых индексов во время upgrade и strict new ACK table.

Native runner дополнен вторым настоящим2000→2200/repeat/paired restore lifecycle
на disposable PostgreSQL: удаляются только два указанных unique indexes, добавляются
синтетические duplicate legacy keys, проверяется сохранение их строк и индексов.
Первый strict fresh lifecycle остаётся. Оба сравниваются с fresh ACK table и
получают4native SQL mutations (column/index/task/extra). Оба actual native lifecycle PASS; их JSON и ZIP digest скачаны и проверены.
Context39947880072/Review39947880106 SUCCESS; PHP8.2/8.3 каждый1048tests/153skips,
strictroot11/11 без skips, actual runtime/HTTPS PASS. Native154tests/800assertions
и оба controller lifecycle сохраняют97прочих таблиц/оба legacy набора/новую ACK table,
custom task[1,1], preferences/revoke/history и synthetic duplicate legacy rows.
Новая ACK table совпадает с fresh по9indexes,4SQLmutations отказаны в каждом профиле.
CI .py = независимая exact-Git сборка,557payload/7worker/5legacy/16modules сверены.

0subagents/author review; нет agent SSH/provider sends/main merge. Production2000+
policy overlay/a86 сохранён; UID130 last revoked, принятый pilot не повторять.
