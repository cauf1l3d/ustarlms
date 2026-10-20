# Согласованный переход1905→2000

Продолжение принятого [private chat-link плана](USTAR_CONNECT_PRIVATE_DELIVERY_PROGRESS_20261020.md).
[Owner cron-only](../runtime/connect_cron_contract_owner_20261021.json) закрывает
единственный отказ последнего preflight. Исходный552/99/HTTPS snapshot20Oct
не собирается заново ради документации; installer обязан проверять входы под locks.

## Результат и границы

Установленный payload830/local1905 → фиксированный1e8a8819/local2000.
Те же552файла и99таблиц; меняются шесть ранее проверенных addon-файлов.
Core/PHP/PostgreSQL/образы/сеть/политики доступа не обновляются.
Существующая task prune_connect_identity и все её disabled/customised flags
сохраняются; старое условие «задачи ещё нет» не переносится из первого installer.

Cron v3 c5eabdce → ae5fa1b0: только VERSIONS1905→2000, с точным hash обеих
версий. Одновременно сохраняется installation.json с прежними container bindings
и metadata. Расписание/last-run/история ошибок не сбрасываются; tick не запускается.
Внешний HTTPS/Mini App, private bridge, credentials/receiver update и notify
остаются следующими gates. Эта поставка не активирует бота.

## Транзакция

Используются проверенные pure copy/Host/paired-recovery примитивы старого
connect_academy_installer; его исходник и immutable installer02 не меняются.
Новый controller имеет отдельные namespace/marker и fixed payload.

| Порядок | Условие |
|---|---|
| Transaction EX, HTTPS EX | Запрет параллельной поставки и renewal/rollback на время операции |
| Проверка source/SQL/helper/cron/HTTPS | Точные текущие версии и552hashes; read-only SQL; ни PHP bootstrap, ни tick |
| Fresh HDD | Принятый SHA-pinned backup producer; подтверждённый readback; private logs |
| Cron EX, backup EX | В том же порядке, что штатный cron; bounded wait, без удаления locks |
| Web stop, zero DB clients, PG clean stop | Те же IDs/images/binds; restart guard=no |
| Cold code/config/data/PG pair + cron before/after | Полные verified before-images, metadata/ownership и durable marker до изменения |
| Activate + official upgrade twice | CLI maintenance; registry остальных addon,99schema/indexes и tasks строго сохраняются |
| Cron pair update | Пара source/installation с точным2000contract до public release |
| Release + HTTPS login | Доверенный CA/leaf и Secure cookie, без HTTP200 ожидания от прежнего redirect |

HTTPS config/vhosts/include/health/tool/renewal units/state сохраняются побайтно.
Поэтому владение renewal не передаётся в этом source-only этапе. Private bridge
позже потребует явного ownership handoff; добавлять директивы в vhost сейчас нельзя.

До public release ошибка восстанавливает соответствующие application+cron
before-images под теми же locks. Drift administrator/повреждённая pair запрещают
перезапись и оставляют незавершённую пару остановленной для явного recovery.
После durable public release нет автоматической потери новых записей;
поздний restore требует --discard-writes-since-snapshot.

## Проверка

Real disposable filesystem tests: update/repeat, task preservation, partial cron
write recovery, HDD/stop/client failures, permissions/locks, administrator drift,
paired restore и late-write consent. Root UID33 fixture обязателен в CI.
Дополнительный native Moodle5.1.8/PG16 этап: сначала реально устанавливается830/1905,
сохраняется custom task1/1, затем2000/upgrade/repeat и восстановление code/config/data/DB.
Native Apache fixture вызывает HTTPS login probe нового controller.

Local PHP/Docker отсутствуют; их результаты до CI не заявляются.
Self-review;0subagents. Native server1 upgrade/cron2000 acceptance ещё ожидаются.

## Обязательная HTTPS-совместимость backup перед fresh HDD

Проверка exact75ca974f producer установила: http_ready() ждёт plainHTTP200
на127.0.0.1,но принятый canonical HTTPS возвращает302. Поэтому сначала ставится
пара producer+scheduler с изменением только health readiness и pinned producer SHA.
Новый producer проверяет trusted CA/SNI/leaf/Secure cookie и canonical redirects;
при существующем HTTPS state нет fallback на HTTP. Начальный HTTP-режим доTLS
сохранён для исходных независимых копий. Шифрование/engine/UUID/quota/stop-resume/
calendar/unit/report contracts не меняются; login_http200 остаётся совместимым.

`--check-backup-https` и `--prepare-backup-https` выполняют только проверку/
согласованную запись двух root-owned scripts под существующими HTTPS+backup locks,
при inactive backup/recover services. Private before-images,metadata и journal
обеспечивают восстановление half-written pair. Timer/units/архивы/контейнеры не
изменяются и задания не запускаются. Historical helper manifests и immutable
installer02 не переписываются; новый updater имеет отдельный явный hash-profile.

Переход1905→2000 отказывает до fresh backup,если эта пара не принята.
Производственная установка compatibility/backup/upgrade ещё не выполнена.

## Итог source gate

Exact6aa проверен Context39500936977/Review39500936916,attempt1 SUCCESS.
См.[CI receipt](../runtime/connect_chat_link_update_ci_20261021.json). Native1905→2000/
repeat/paired restore/custom task1/1 и actualTLS helper readiness PASS в disposable CI.
Production preparation/application update/real scheduled2000 acceptance остаются owner gates.
