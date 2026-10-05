# Журнал выполнения roadmap

## 2026-09-20 — первый этап рефакторинга, R01 review

Владелец: Codex. Код: `e657cda7dc29750511535b94eee85b22ca7fdb86`,
[PR #2](https://github.com/cauf1l3d/ustarlms/pull/2) к `integration/ustar-20260919`.
Приняты шесть последовательных этапов, описанных в `context/architecture/refactor_20260920.md`.

Реализованы текущий PR CI и отдельные DB/rollback стенды, генераторы данных, preview/reset/reward guards,
корректный статус пустого стандарта, защита frontend и manifest/preflight. Исправлен реальный дефект
чистой установки capabilities. В rollback устранены отсутствие Moodle bootstrap при чтении версии,
подстановка ERR-переменных Compose и несовместимый pg_dump.

[Run 35527954308](https://github.com/cauf1l3d/ustarlms/actions/runs/35527954308):
source, frontend, moodle-db, rollback и gate — success.
Тестируемый merge SHA `e3ae87c5a9df1071f80d4aeff730b572965f002d`.
10 DB tests / 36 assertions (1 notice), 21 Python tests, 49 isolated PHP assertions,
88 DOM assertions, 4 frontend security tests и HTTP smoke собранного Next.
Полный синтетический restore DB/moodledata/source/config — PASS.
Подробности и артефакты: `context/runtime/20260920_stage1_ci.md`.

Production не менялся. Схема metadata нормализована без изменения эффективной структуры;
rollback восстанавливает весь согласованный комплект, а не только PHP-файлы.
R01 остаётся review: branch protection enforcement не подтверждён. R00 production browser/DR acceptance открыт.
Следующее действие: принять PR и required gate; дальнейшие изменения — модель сотрудника/организации второго этапа.

## 2026-09-07 — опубликован исходный roadmap

**Тип поставки:** документация и статический аудит. Реализация R00–R20 ещё не выполнена этой поставкой.

- Прочитаны четыре отчёта Fable и продуктовая стратегия владельца.
- Проверены контекст/ADR и выбранная база `e71bca2856bc0f80e39cb9ed1cee6098934e232a`; установлен разрыв с исходным main `5443bf5…`.
- Прослежены route completion, studio, scope, economy, game mastery/competition, achievements/dashboard, evidence, organization, adaptation, migration и contextd paths.
- Для contextd выполнено воспроизведение нарушения границы чтения на искусственном файле; реальные чувствительные файлы не читались.
- Подготовлены 21 ticket, условия приёмки студии/наград, раздельные code/validation/deployment statuses и вход из main.
- Отмечены подготовленные ранее UI-пакеты, их неподтверждённый deployment не назван завершённым.
- PHP/Moodle DB/browser/prod acceptance не запускались. Application code, DB, Docker, branch protection и default branch не менялись.

**Следующее действие:** R00; независимые ready-задачи R01/R04/R16. После публикации идентификатор документационного commit доступен в Git history этого файла.

## Шаблон следующей записи

Дата; ticket ID; owner; branch/code SHA; что изменено; какие acceptance проверены и где; not-run и причина; migration/rollback; deployment evidence или «не установлен»; следующий ticket/blocker. Не включать персональные данные/секреты и не заменять результаты тестов общим словом PASS.

## 2026-09-12 — R00 source consolidation and GitHub synchronization

Code: `89ba18b2130040fbdf7f0ca02bef4999d5ef1fdb`. Published verified patch payloads on integration/ustar-20260912; updated canonical context and harness separately on main. Application code on main is unchanged. See release publication scope for four source-only exclusions. Validation and separate deployment evidence: [release](../../release/20260912/README.md), [runtime](../runtime/20260912_release.md). R00 is review, not done: full production manifest and broader smoke remain outstanding. No deployment, schema mutation or history rewrite during repository synchronization.

## 2026-09-12 — verified source and snapshot publication

Source `48326c6a011f58b985d251cb0462835bc1d37a16`. User approved public publication of R16, tours, four production differences and reviewed dated context. 367 source files verified including separate theme config. R16 server installation and 21 tests supplied by user; daemon not started. R00 review; schema and live tour acceptance outstanding. No backups or private diffs published. No production files changed by Git publication.


## 2026-09-20 — второй этап, пакет организации и доступа

[PR #4](https://github.com/cauf1l3d/ustarlms/pull/4), head `2502281af5e687a7bcb094b2bad8593d480304d7`,
stacked на PR #2. Единое разрешение должности/подчинения, временные назначения,
конфликты, границы команды, сохранение ручных reporting decisions и read-only dry-run.
Native CI run `35544164856`: все пять jobs SUCCESS; Moodle 24 tests / 89 assertions /
3 notices, install/upgrade/repeat/schema parity и полный synthetic rollback проходят.
R10/R12 остаются in progress; employment state, explicit role migration и полный
consumer/writer переход ещё впереди. Production не менялся.
Доказательства: `context/runtime/20260920_stage2_org.md`.

## 2026-09-24 — PR #14, текущая сборка исходников

GitHub code SHA `b48f24ad0c3e18313bb16caf93c18a97451d4d2d`, source/frontend
workflow 193 — success. Публикация стандарта должности, версионная оценка
уровня навыка и отзыв, честная неизвестная квалификация, пакетное чтение
evidence, поиск и пагинация HR, изоляция USCOIN и единый доступ к каталогу;
открытый опросник закреплён за версией. Точные изменения и ещё открытые
критерии перечислены в `RC_AUDIT_ACCEPTANCE_20260924.md`.

Moodle DB на этом коде, rollback, браузер, production latency и восстановление
не выполнялись. Снятие блокировки bug #4 требует проверки владельца;
серверный временный backup-файл остаётся на завершающий этап по указанию
владельца. PR #14 не является принятым или готовым к production релизом.

## 2026-09-24 — PR #14, замечания владельца и организация

GitHub source SHA `4c44f32d04b3bab56fbc36e46c6810b4a714f247`; source/frontend
gate 201 прошёл на `08db2902249290dccd527839960cc77bf00724fd`, gate 202 прошёл
на `1efea68ed15de525f5df0e3d5ea10c513074c8ab`. Проверка текущего
документационного изменения остаётся за следующей проверкой ветки.
13 замечаний из PDF перечислены без утраты порядка в `RC_13_BUG_REPORT_20260924.md`;
исправлены в исходниках редактор каталога, задачи, кнопка руководителя для грейдов,
создание/переименование блоков, управленческий список кадровых заявок, стиль Студии
материалов и симметрия страниц входа. Исходный эталон входа содержит иллюстрацию
слева и форму справа; историческая текстовая просьба о левом расположении формы
противоречит макету, и выбор отмечен как открытый.

PHP/source/frontend проверяются PR workflow. Moodle DB, browser, свежая установка,
upgrade, production performance и clean restore на этом SHA не выполнялись.
Пункт 4 PDF — на проверке владельца; production, DB, moodledata и secrets не менялись.

## 2026-10-02 — repository consolidation and mobile handoff

Owner requested current main and complete engineering context while production is stable. Reviewed all PR heads and exact ancestry; PR76 e1d57f5bc28f6964afd20aeff7620345b34a80fe contains prior main and 62 open PR heads. Full CI #456 verified via GitHub API. Preserved unique parallel docs and historical evidence; refreshed entrypoints, actual architecture/schema/API maps, operations, release ledger and current backlog. Application and deployment status remain separate: fresh production manifest unknown. Next: MOB-01, full Android/iOS client preparation. No production operations or application behavior change in handoff.

## 2026-10-05 — DOC-02: аудит, контекст, harness и полный план

Владелец поручил завершить остановленную публикацию из [shared-диалога](https://chatgpt.com/share/6ac2efa7-5800-83eb-8f5e-20ae3549ecab), затем остановиться. База main до правки: `1dfa124ef9a3585b0d36133ee576bce9f7e9692a`; application PR76 без изменений. Ветка `codex/ustar-infrastructure-context-20261005`, точный documentation SHA/PR — в Git history этой записи.

Опубликован исходный аудит с SHA-256 и отдельной сверкой boost/test-copy hypothesis/HDD target. Добавлен полный поэтапный план с rollback/acceptance/coverage текущего цикла и future Privacy/mobile/B2B/LTS/refactor. Исправлены stale VM/Caddy/manifest/mobile-priority поля; runtime evidence датировано. Cron v3 и ручной restore признаны в измеренной области, открытые риски не закрыты. Harness читает current handoff и audit bundle через прежнюю безопасную boundary; обновлён integrity gate и индекс.

Проверки и not-run — [delivery report](../runtime/context_update_20261005.md). Production, host wrappers, роли, SSH/Apache/Docker, данные и snapshots не менялись. Регулярный backup последним этапом на healthy HDD, не SERVEREXPRESS; текущий HDD провалил long SMART. Следующее состояние: awaiting_owner_instruction, INF-01 намечен, выполнение не начинается.

## 2026-10-05 — новая команда 1 → 7 → 6, INF-01 в работе

DOC-02 merged PR78: implementation `b265a7aabd6205065b4fc6458d7fa40a99974d3d`, main `5ced5dee85734a52ee31397596589e81458a3320`. Затем владелец выбрал пункты 1 → 7 → 6, остальные после них. Новая ветка от этого main: `codex/ustar-core-preflight-20261005`; точный implementation SHA/CI — в PR и Git history. Application PR76 не меняется.

Получены свежие owner baseline/manifest/inventory. 526 manifest records дополнительно сверены с реальными Git blobs, 468 disk/readonly DB component versions совпадают. Три core metadata SHA-256 совпали с official weekly commit `1cd17816c56a7df7ee796892efccaf1ed5347340`, но полная core identity не доказана. Зафиксированы PHP8.3.33/Trixie, PG16.15 images, extra plugin roots, wrapper hashes, AppArmor residual profile, storage inventory и cron success. Исходный аудит и предыдущий passport сохранены.

Добавлен read-only operator core preflight: immutable upstream tree, streaming hash, explicit exclusions, symlink/error detection, private external report и отсутствие repair/PHP/bootstrap/DB execution. 8 synthetic tests; полные checks — [baseline](../runtime/baseline_20261005.md). Обновлены текущие pointers/статусы/harness docs; широкая OS/security задача разделена, чтобы выполнить OS часть выбранного пункта 7 и оставить SSH/MFA/firewall позже. Прямого server access нет; production writes не выполнялись. Следующий шаг — verified script на сервере, затем свежая recovery copy и isolated upgrade/rollback, Moodle/CI/ОС, HTTPS.

## 2026-10-05 — core report 08:28 UTC, review index.php следующим шагом

PR79 merged main `888c22832d4225f752926a346fae0230c75410b6`; implementation `b69561c3f4d14ccb284905f60550ec1c47c47b14`, context/source/frontend CI PASS, DB/rollback/full gate skipped. Владелец запустил collector и передал `ustar-core-rHoI7j.zip`. Collector SHA и весь JSON checked; exact reference tree coverage 24 679, matching 24 678, changed один public/index.php, missing/errors/skipped 0, extra 23. Expected changed OID совпадает с upstream tree. Содержимого production index.php в архиве нет; причины правки и active consumers дополнительных файлов пока неизвестны.

Новая ветка от свежего main: `codex/ustar-core-evidence-20261005`. Публикуется только обезличенная сводка/контекст, не raw archive/config/tokens/PDF/source private helpers. Следующие inputs: private copy index.php и static CLI names/hash existing wrappers, затем fresh recovery/stage readiness. Порядок 1 → 7 → 6 и in_progress INF-01 сохранён. Application code/original audit/historical fixtures/production без изменений; проверки exact documentation delivery и SHA — в PR/Git history.

## 2026-10-05 — index и backup/cron contracts проверены

База свежего main PR80: `9e2941fb82b8173a93cd5dfc762adb657886b4b6`; новая ветка `codex/ustar-recovery-contract-20261005`. Index upload 93 bytes совпал с observed blob OID и изучен полностью: config bootstrap, redirect на главную USTAR. Поведение сохранить при official patch; core difference не объявлена исправленной.

Владелец передал оба existing wrappers в 09:48:49 UTC. SHA совпали, полный static review выполнен. Default backup — preflight; backup требует outage acknowledgement, пишет только local encrypted export, не выполняет network transfer. `--recover` только resume Moodle по marker, не restore archive. Cron shared/exclusive locks согласованы; `--status` без bootstrap/tasks, `--install` делает real pass, rebind flag отсутствует. Локальные cron self-test и offline backup mode gates PASS; production execution/backup/restore не выполнялись. [Проверенный контракт](../runtime/recovery_contract_20261005.md).

Обновлены текущие STATE/ACTIVE/BACKLOG/evidence/index pointers; raw wrappers, audit/application/historical fixtures не меняются. Следующий operator checkpoint — fresh manual backup со встроенным preflight, resume/fresh cron status, затем decryption/isolated upgrade/rollback. INF-01 in_progress и порядок 1 → 7 → 6 сохранены. SSH paste lesson: отдельный `sudo -v` с ожиданием prompt, дальнейшие команды одной физической строкой с `sudo -n`. Exact implementation/CI — в Git/PR истории поставки.

## 2026-10-05 — свежий backup/cron и retained lab resume

База PR81 main `fa1d26f8462c11b6d8174bbe2ec4a889d835ae71`; новая ветка `codex/ustar-lab-resume-20261005`. Владелец создал свежую local encrypted copy 05Oct, independent local SHA совпал; Moodle resume 24.097s. Scheduled cron 10:24 exit0/1.25s/new failures0, root HTTP303. Fresh external copy/decryption отдельно не доказаны. Старый restore/manual PASS учтён; повтор полной baseline-репетиции отменён как ненужный. Screenshot показывает authenticated admin feed, не полный role/SCORM proof.

Owner Docker -a 10:59:56 UTC: lab containers отсутствуют; retained physical DB/code/data/state/report/env есть. Три uploaded lab scripts полностью прочитаны; SHA chain совпал. Old stop удаляет owned containers, но не данные; old restore отвергает existing ROOT. Добавлен pinned-engine resume helper: same lock, exact config/image/namespace/resource guards, readonly clone SQL, lab-only bootstrap/HTTP/loopback relay, failure cleanup только owned containers. 10 offline tests и совместимость real provided generator с synthetic inputs (ownership calls mocked) PASS. Прямого Docker/server resume/upgrade не выполнялось.

[Детальный контракт](../runtime/lab_resume_20261005.md). Точный implementation SHA и CI — в Git/PR. Application PR76, original audit и historical fixtures сохраняются; context pointers/index обновлены. INF-01 in_progress; следующий server checkpoint — immutable helper resume, затем candidate Moodle/CI/OS и HTTPS в порядке 1 → 7 → 6. Размер retained lab не измерен, cleanup/regular backup не выполняются.

## 2026-10-05 — patch runtime CI, resumed lab и HDD write/read started

База PR82 main `7a6eefde8a3662373a62f5c455b499cfe6a51c80`; ветка `codex/ustar-patch-runtime-20261005`. Owner resume11:30:58UTC PASS/login200/production unchanged; повтор baseline archive restore не нужен. HDD long SMART read failure подтверждена, only Windows metadata; owner/sysadmin затем явно разрешили overwrite/format. Guarded full-capacity one-zero-pattern write/read test фактически начат12:28:57UTC, ещё не completion/health PASS.

Добавлен отдельный pinned candidate5.1.8/PHP8.3.33/Trixie/PG16.15 stage runtime, actual version/module/core/PG guard перед install, source PHP8.2+8.3 и обе DB cells в полном gate. Historical runtime/plugin rollback untouched, application PR76/original audit preserved. Full CI exact SHA/evidence фиксируются Actions/PR; local Docker/PHP недоступны, не объявлены local runtime PASS. [Контракт/пределы](../runtime/patch_runtime_20261005.md). Context/index pointers обновлены; свежие lab/export df/du и full-addon core upgrade/paired rollback впереди. Порядок1→7→6 сохранён; major/LTS/широкая cleanup/regular backup не исполнялись.

## 2026-10-05 — owner storage/HDD checkpoint и lab patch preflight

Свежая база main156110de2a5cc305a57fd9d94fc74753e7f0e5f0 (PR83); новая ветка codex/ustar-lab-patch-preflight-20261005. PR83 full RC PASS, verified actual patch metadata и PHPUnit210/891. Owner output13:11:41UTC: HDDactive/running, success/0 неcompletion; root11Gfree/78percent, lab2.3G/export~1.9G. Никакой cleanup/format/reboot не выполнялся.

Добавлены pinned read-only running-lab preflight и reference526USTAR hashes/57 addon versions/3 core metadata. Existing SH lock без создания, protected links/config/images/mounts/network, separate code/data/PG non-sparse copy budget плюс4GiBreserve, bounded clone READ ONLY SQL и pure CLI settings, productioninspect before/after. Не создаёт rollback и не повторяет archive restore.15 offline safety tests; real provided config generator/owned-SQL interfaces additionally checked on synthetic inputs with Docker/SQL mocked. [Контракт/следующий operator step](../runtime/lab_patch_preflight_20261005.md).

Application PR76/original audit/historical fixtures unchanged. Context source/index pointers updated; exact implementation/CI recorded in PR/Git. Server helper not executed; candidate full-addon core upgrade/paired rollback pending report. Порядок1→7→6 и healthy-HDD automation-last сохранены.
## 2026-10-05 — HDD surface completion и диагностика отказа planning helper

База main `d8292f96c6aa72eb0ccd759531ed2e3dfe97abb6` (PR84), ветка `codex/ustar-lab-preflight-diagnostics-20261005`. Owner message получено17:11:36UTC, timestamps самих terminal commands не даны. HDD unit inactive/dead/success0; log write/read completed/zero badblocks/0errors. Post-write long SMART/attributes ещё не получены, HDD trusted=false. Full du labels lab2,3G/export1,9G; предыдущий root free11G не переизмерен.

Owner SHA checks PR84 script/reference OK, root install и check выполнены; отказ Untrusted retained input, offending scope unknown. Обнаружен дефект диагностики общего trusted guard; допуски не ослаблены. Новая ошибка содержит только fixed scope/failed tests/UID/GID/mode/type/links, без leaf names/content/symlink targets. Четыре новых meaningful tests проверяют private data/name redaction и сохранение owner/permission/link отказов.19 helper tests PASS; full71 r16 tests PASS в isolated env с requirements mcp1.30.0/PyYAML6.0.3 (первый запуск default Python не имел mcp, его import errors устранены окружением). JS24/XMLDB93/templates56 source checks PASS с явным skip-PHP; Docker/PHP/SQL не исполнялись. Existing application/frontend/release/stage/workflows и original audit неизменны.

STATE/ACTIVE/BACKLOG/runtime evidence/entry pointers и generated content index актуализируются. Exact implementation/remote source-context CI — в PR/Git, новый full core upgrade RC не заявляется. Следующий operator checkpoint: post-write smartctl-a и long self-test, immutable diagnostic helper update/retry; по scope/metadata точечно разобрать guard, без массовых chmod/chown/cleanup. Candidate full-addon upgrade/paired rollback pending successful planning report. Порядок1→7→6 сохраняется.
