# CON-02: совместимость с историческими индексами server1

23Oct2026, Europe/Moscow. [Owner receipt](connect_con02_index_owner_20261023.json).
Статус: исправление source реализовано; новая required CI/native и artifact acceptance pending.

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
получают4native SQL mutations (column/index/task/extra). Actual gates ещё pending.

0subagents/author review; нет agent SSH/provider sends/main merge. Production2000+
policy overlay/a86 сохранён; UID130 last revoked, принятый pilot не повторять.
