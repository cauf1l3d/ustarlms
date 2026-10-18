# Журнал выполнения roadmap

## 2026-10-18 — accepted bot buttons, bounded long polling and Mini App check

Owner04:40MSK reports update commands/buttons working; exact native SHA/numerical latency not supplied. Continued11:49MSK. Source4bb34f0b removes successful10s sleep with5s long polling/.2s yield and10s error backoff. Pending incidents leave before wait; telemetry and maintenance refresh afterward. Review found maintenance-start-during-wait false alert; regression RED→GREEN, full230 PASS, no Critical/Minor. All3 source4bb34f0b CI SUCCESS,230backend/45Chromium/native fixtures PASS; artifact12131452167 ZIP/tar/source/all wheel Python/observer verified. Pinned operator command and Mini App steps in TELEGRAM_LATENCY_20261018.md. Reuse existing complete wheel updater, not an extra root patch installer. Mini App testing requires device route/private HTTPS trust and separate Telegram WebView/login/role check. No native update, home VPN or network/auth/CA change by agent; owner installation/timing/Mini App acceptance remain pending.

## 2026-10-18 — Telegram owner response and presentation increment

Owner reports bot now responds; raw/partial presentation is the new task. New dated receipt preserves this limited receiver evidence and the lack of native module hash/stdout. Prior failure receipts are unchanged. Implemented Russian cards/buttons, aggregate values/freshness, explanatory alerts, four-command bounded burst, same-cycle first delivery, opt-in Mini App with existing login. Private actor/chat filters, durable outbox, token/proxy/CA and network boundaries retained. Sourceb275f899 all3CI SUCCESS,223backend/45Chromium/actual systemd/rollback PASS; artifact12120404826 hashes/SOURCE_COMMIT/wheel/observer verified. Owner command pinned in TELEGRAM_OPERATOR_20261018.md. First source attempt failed only two rootless test fixtures, then corrected; review retry fixed Chromium screenshot transient without app edits. No native installation or phone Mini App acceptance claimed. Independent observer remains required for loss of server1 itself.

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

## 2026-10-05 — Moodledata planning policy и post-write SMART

База main `a7b5a23eae1efa94a00164bd18c4f4685b14b252` (PR85); ветка `codex/ustar-lab-mutable-data-policy-20261005`. Owner message17:40:58UTC подтверждает PR85 helper checksum/install/check: scope=moodledata_tree_entry, permissions, uid33/gid33/mode0666/regular/links1. Exact name/origin не получены; штатным lock/cache не объявлены. Strict immutable policy ошибочно применялась к mutable metadata-only tree. Правка задаёт exact private0700/33:33 Moodledata boundary, per-entry ownership/type/no-hardlinks, nonwritable directories и non-executable files; regular write modes учитываются без открытия content, chmod/chown отсутствуют. Code/control/PostgreSQL guards и pins сохраняются. Report содержит только count таких files, root перепроверяется после live walk.

25 helper tests/77 full r16 tests PASS, включая six новых boundary/read-only tests; source JS24/XMLDB93/templates56 PASS с явным skip-PHP. Первый запуск нового fixture выявил missing autospec при portable lstat ownership mock, исправлено; final tests зелёные. Real local Docker/PHP/SQL не запускались. Fresh SMART17:36:49UTC pending0/reallocated0/offlineUNC1; новый long start successful, expected20:11:04UTC/23:11:04MSK, result pending и HDDtrusted=false.

STATE/ACTIVE/BACKLOG/runtime evidence/entry pointers и generated context index обновляются. Exact implementation/remote CI — в Git/PR. Server policy helper ещё не выполнен; next operator immutable update/check/report. Candidate full-addon upgrade/paired rollback и выбранные1→7→6 продолжаются после report; production/application/audit/historical fixtures неизменны.

## 2026-10-05 — planning PASS, newest SMART PASS и cold checkpoint/workspace

База maina826a73c052a72c94ad5c6367b9cb9bcc1e115f0 (PR86), branchcodex/ustar-lab-cold-rollback-20261005. Owner output получен20:46:23UTC: PR86 checksum/install/check20:44:34UTC PASS,526 USTAR/57 addon metadata, free10,51GiB/first budget8,15GiB. [Sanitized report](../runtime/lab_planning_result_20261005.json) сохранён. SMART20:44:52UTC newest extended Completed without error at16385h, pending/reallocated0/residual198=1; old read failure retained/outdated. Surface+long test-scope PASS, no target partition/mount/schedule configured.

[Новый helper](../runtime/lab_cold_checkpoint_20261005.md) делает paired cold code/config/Moodledata/physicalPG checkpoint при остановке только lab и always same source IDs restart, затем separate mutable workspace/new IDs/loopback18085. Frozen checkpoint never mounted; hashes/metadata/full inventory/fsync checked, source untouched, productioninspect before/after only. Second-copy budget9,80GiB/reserve4GiB, no overwrite/deletion of files, failure preserves copies and removes only owned stage containers. Core upgrade/repeat/paired restore after upgrade not executed.

18 filesystem/failure tests/full95 r16 PASS; real pinned uploaded engine adapter checked with Docker/ownership/relay calls mocked. Local source JS24/XMLDB93/templates56 PASS with explicit skip-PHP, real local Docker/PHP/SQL not run. Exact implementation/source-context CI recorded in this delivery PR/Git. STATE/ACTIVE/BACKLOG/evidence/entrypoints/index updated; application PR76/original audit/frontend/release/stage/workflows unchanged. Next operator: immutable SHA download/check/root600 install and --prepare-stage, full sanitized result, then candidate5.1.8 with preserved addons/extras and upgrade/repeat/paired rollback. Owner order1→7→6 remains active.

## 2026-10-06 — владелец перенёс storage/map/автобэкапы перед стендом

Owner00:45:07MSK/05Oct21:45:07UTC явно включил оптимизацию хранения, актуальную карту сервера и HDD automatic backups в проход до нового стенда. PR87 implementation40e7d13e197cd59321e5e89e3f477539eb265b19 merged37f9f676ee434498f011d26be0db5fae7a83195c; source8.2/8.3/frontend37375193949 и context95tests37375193850 SUCCESS, server --prepare-stage теперь paused, deployment evidence отсутствует.

Свежие df/lsblk/du: root49G/36Gused/11Gavailable/78%, SSD111.8GiB/root partition50GiB; около61.8GiB вне показанных размеров partitions, free extent ещё не подтверждён. Home15GiB включает около13.1GiB snapshot candidates; backup-export1.9G, restore-lab2.3G, cron-lab2.2G, opt/backups1.8G. Parent/child размеры не суммируются повторно. HDD465.8GiB без показанных partitions/FSTYPE/mount. [Карта и следующий read-only шаг](../runtime/server_map_20261006.md).

Existing manual backup source повторно проверен: SSD staging/export,6GiB quota, no rotation/timer/mount identity guard. Новый backup contract предусматривает HDD payloads/encrypted archives, persistent mount guard, recovery marker на SSD, cron coordination, trial/verification/status/retention до schedule. Schedule03:30MSK/7daily4weekly3monthly пока proposal, не установлен. Никаких partition writes, moves, deletions или schedule changes пока не выполнено. Текущие STATE/ACTIVE/BACKLOG/project/entrypoints обновлены; старое backup-last правило superseded, прочие hardening/product задачи не расширены.

## 2026-10-06 — свежая карта и подготовка HDD helper

Владелец прислал свежий `fdisk/docker/timedatectl/systemd timers/ss` inventory. SSD: GPT disk234441648 sectors; `sda1` BIOS boot2048–4095, root `sda2`4096–104855551; PMBR/GPT backup boundary stale (`104857599 != 234441647`), free tail129586062 sectors/61.79GiB подтверждён, но записи не было. Четыре containers running: production Moodle/PG и retained lab web/PG; timezoneEtc/UTC, NTP yes, 18 timers, no managed USTAR backup timer. Карта дополнена без credentials/full inspect.

Добавлен offline-tested `scripts/infra/hdd_initialize.py`: exact WWN/serial/capacity guard, no mounted/holder/namespace/swap/open-user/signature acceptance, edge-zero verification, GPT+single ext4 only after revalidation, UUID readback, no mount/fstab/SSD/container/scheduler changes. 16 focused tests PASS; full 111-test invocation was not accepted in the base interpreter because optional `mcp` dependency is absent (existing harness errors), while context and relevant helper tests pass. Operator execution is pending; no HDD partition/filesystem/mount/timer changes have occurred.

## 2026-10-06 — HDD initialized and temporarily mounted by owner

База main `89fc5d49f1ac89c29e99d0095f8482f17f22f60d` (PR89), ветка
`codex/ustar-hdd-mounted-context-20261006`. Owner confirmed initializer SHA
`ef3a5699f54384428d40e1c9b96b2707e117ad188b1f36628c95fb823be49aa2`,
`EMPTY_HDD_CHECK_PASS` и `FILESYSTEM_READY`. GPT/sdb1/ext4 labelustar-hdd,
UUID `359a2bae-4e79-461a-ab72-1597f605d801` созданы. Mount output получен
06Oct03:07:20MSK/00:07:20UTC; execution timestamp не напечатан. Отдельная
owner команда смонтировала UUID на `/srv/ustar-storage` с
`rw,nosuid,nodev,noexec,relatime`, root:root0700; df458G/28Kused/453Gavailable/1%.
Повторный initialize не требуется. Residual SMART198=1 сохраняется;
полная hardware/recovery qualification не заявляется.

Подготовлены one-line operator commands: guarded candidate fstab/private backup
и copy/flush/readback SHA существующего encrypted05Oct archive. Bash syntax и12
offline failure/success cases PASS с mocked mount/device/systemctl/sync и реальным
fixture copy/hash. Это подготовка; fstab, archive copy, fresh HDD backup trial,
producer/timer и SSD repair/growth ещё без operator execution evidence.
Исходники/архивы/контейнеры не удалены и не перемещены агентом; прямого SSH нет.
STATE/ACTIVE/BACKLOG/server map/backup entrypoint и generated index актуализированы.
Application source, workflows/helpers/harness code и байты оригинала аудита
не меняются; проверка контекста и точный implementation SHA/CI — в delivery PR/Git.

## 2026-10-06 — HDD fstab/copy PASS и новый backup producer

База main `4d1378ab01a6afd9526917681f5afcd54d3127df` (PR90), ветка
`codex/ustar-hdd-backup-producer-20261006`. Owner output received03:33:41MSK/
00:33:41UTC подтверждает FSTAB_CONFIGURED=PASS, backup `/etc/fstab.before-ustar-hdd-c2kb8T`,
HDD_ARCHIVE_COPY=PASS и matching SHA прежнего05Oct archive; df458G/913Mused/452Gfree/1%.
Source archive остаётся на SSD; reboot/mount-after-boot и fresh capture не выполнены.

Actual private backup source13,689bytes повторно получен/полностью прочитан, dependency
SHA0d31e694… совпал. Новый `scripts/infra/hdd_backup.py` — SHA
`75ca974f1d763f6fc84281501a99936894f1b487c1cc4d0c1d71697e67c79d75` — использует
его reviewed capture pipeline без публикации/изменения установленного engine.
Anchored HDD staging/export100GiB quota, private paths, exact disk/mount/live guards,
existing EX lock, SSD marker и отдельные HDD status/manifests; legacy last-local-export
не переписывается. Stop reply/durable completion под blocked catchable signals;
resume работает без HDD, uncertain inflight marker не снимается ради обхода.
Final readback/fsync + local Apache login200; failed staging/partial сохраняются.

29 local tests PASS:19 boundary/kernel/filesystem и10 actual pinned-engine fixture cases
с real tar/files/hash, mocked Docker/age/mount/fixture ownership; настоящий SIGINT
во время fixture stop отложен и resume выполнен. Это не real Docker/PG/encryption/HDD
power-loss acceptance. В обычном CI10 private-source cases явно skipped; remote
context/source checks и exact implementation SHA фиксируются delivery PR/Git.
Application source, original audit bytes, historical runtime/fixtures и cron v3
не меняются. STATE/ACTIVE/BACKLOG/server map/entrypoints/index обновлены.
Server producer install/check/fresh trial, timer/retention, source deletion и SSD
repair/growth pending. Next operator: pinned install → --check → chosen-window
--backup --acknowledge-outage/report, then policy/timer and SSD recovery steps.

## 2026-10-06 — Owner HDD trial PASS и начальный ежедневный timer prepared

База main `d884e402948af91b5c9f2645e07fdaf1b1838e03` (PR91), ветка
`codex/ustar-hdd-backup-schedule-20261006`. Owner upload received04:16:21MSK/
01:16:21UTC подтверждает pinned producer installation/preflight и fresh
HDD_BACKUP=PASS06Oct01:10:22..01:12:02UTC. Archive957829597bytes, SHA256
`d9352e3ce5e237a6ac0ab14c72b9da2648d6906fe7e95cd5c88bea726c965af3`,
pause24.111s,readback/login200,staging removed, archive deletionfalse.
Sanitized report `context/runtime/hdd_backup_trial_20261006.json` добавлен;
это manual trial, не scheduled run/restore/external copy/agent server access.

`scripts/infra/hdd_backup_schedule.py`, SHA256
`54533f6a629699e031a877b4a57f68401477a61cf20cab39512c79a2e0477b7d`,
готовит только три systemd units: daily03:30Moscow/window03:30..03:40,
no catch-up/retry/archive deletion, existing100GiB quota, main45min,
post-stop/boot resume по SSD marker с точным producer/container guard,
journal и отдельный scheduled report. Trial<=48h/readback/preflight и
unit conflict/override/active-worker/verify guards до activation;
operator installation/first scheduled run/boot recovery ещё pending.
Предлагаемый initial policy станет действующим после owner install команды;
retention7daily/4weekly/3monthly и Telegram delivery ещё не приняты/не настроены.

24 local tests PASS, включая настоящий systemd255 calendar и unit parsing;
реальные files/modes/inodes и producer uncertain-marker guard;
systemctl/mount/Docker/fixture ownership подменены. Не заявлены production
negative/power-loss/recovery acceptance. SHA/CI фиксируются в delivery PR/Git.
STATE/ACTIVE/BACKLOG/server map/backup entrypoints/index обновлены; исходный
аудит, application source, workflow/harness, private engine и cron v3 прежние.
Source deletion, SSD repair/growth, fresh archive independent copy/restore pending.

CI correction within this delivery: the first context/source164-test run found
one fixture calling root-only producer main under a non-root runner. The test
now mocks effective UID explicitly; production root guard/source stays unchanged.
24 focused tests also PASS with simulated non-root caller. Frontend audit reported
GHSA-68fv-2mgg-jv7q/source-map-js1.2.1; npm regenerated only that lock entry to1.2.2
(version/resolved/integrity), verified against registry/upstream release.
Production frontend is unused; Moodle/plugin/theme/runtime code is unchanged.
Clean npm ci and npm audit --omit=dev --audit-level=moderate PASS (zero runtime
vulnerabilities); frontend4 unit tests PASS. Build/new exact-head CI recorded in PR.
No audit threshold or required check disabled.

## 2026-10-06 — Owner установил HDD timer; следующий шаг SSD/consumer preflight

База main `040c671236cb4d7f4a225700bd288ced7e6f14f3` (PR92), ветка
`codex/ustar-hdd-timer-evidence-20261006`. Owner terminal output received
11:02:24MSK/08:02:24UTC: scheduler source/install PASS, timer loaded/active/enabled,
backup service inactive/static, recovery service inactive/enabled. Next event
07Oct00:30UTC/03:30MSK совпадает в status/list-timers; last scheduled null,
recovery marker false, last successful backup remains manual06Oct01:12:02UTC.
Execution timestamps команд не напечатаны; это owner evidence, не live access.
Sanitized `hdd_backup_schedule_install_20261006.json` сохраняет область/пределы.

Начальный daily03:30Moscow/no catch-up/no deletion/100GiB quota активирован
оператором; повторный install/manual capture не требуется. First scheduled run,
boot recovery/mount, fresh independent copy/restore и alerts ещё pending.
INF-04/INF-14 остаются in_progress, закрытие всех acceptance не приписывается.
PR92 exact implementation7501440b476bf720658f5bf039efc3e4f1237d9c CI context
37400747178 и review37400747176 SUCCESS; source8.2/8.3/frontend PASS,
r16 164tests/10private-engine skips, scheduler24PASS; ordinary PR DB/rollback/
prepare-rc/gate skipped. Production application/cron/private engine прежние.

Обновлены текущие STATE/ACTIVE/BACKLOG/entrypoints/server map/backup contract;
старые SESSION_LOG/RELEASE_LEDGER и audit не переписываются. Следующий документ
`ssd_storage_preflight_20261006.md`: bounded metadata/layout/SMART, all-container
mounts, named copy sizes и control-path filenames. Нет partition writes, package
install, service stop, backup invocation, relocation или удаления данных.
Перед GPT/root growth нужны свежая identity/layout, проверенные partition-table
copies и host/boot recovery/console; Academy archive не заменяет shared-host rescue.

## 2026-10-06 — SSD/consumer read-only вывод получен; rescue доступ уточняется

База main dc1e8cfee35ed479a9a314dd86c9e46c8c8bf9fe (PR93). Owner attachment
received08:19:59UTC, first inventory08:18:26UTC, subsequent command times absent.
Root78%/11G available; HDD post-trial1,8Gused/451Gavailable; root filesystem UUID,
quota mount options и GPT UUID/starts/sizes зафиксированы. PMBR mismatch и stale
backup GPT ещё присутствуют; dump не является записью или binary recovery copy.
SMART overallPASSED/raw1,5,196,198=0; packed vendor raw не интерпретирован как
fail counts/wear. lsblk model/serial columns усечены, zero WWN не identity pin.
Four containers running, retained lab has active binds. Named du snapshots сохранены;
reference grep fallback matches six wrappers but emits missing-link errors, no full
consumer clearance/active shared-service impact claim. Sanitized result JSON добавлен.
STATE/ACTIVE/BACKLOG/server map актуализированы; console/rescue explained in plain
language, unknown; next full JSON identity/ext4 header query read-only/syntax checked.
Никаких source moves/deletions, SSD GPT/root writes, service stops/reboots или backup
relaunch не выполнено. Existing timer first run07Oct03:30MSK всё ещё pending.


## 2026-10-06 — Full SSD identity/ext4 follow-up; table-copy command prepared

База main7fbf17c34e1b7a3abe58d5888e8c4ae684696083 (PR94). Owner terminal
output received08:36:58UTC, command timestamp not printed. lsblk JSON resolves
full model WALRAM120GB/serial2203JPDG120GB6000933,120034123776bytes; WWN zero
not used as unique pin. Partition UUIDs unchanged. Mounted ext4 header reports
13106432×4096 =53683945472bytes exactly equal sda2; resize_inode/64bit/etc recorded,
not offline fsck/SMART/GPT repeat. Header last-write is not query timestamp.
New sanitized evidence preserves this scope without rewriting previous dated JSON.

One-line guarded backup command prepared: verify SSD serial/capacity/root UUID/raw
GPT boundary/partition starts and GUIDs, exact HDD UUID/WWN/CWD filesystem0700,
fresh private directory, raw first2MiB/old-tail1MiB/physical-tail1MiB, native
sgdisk --pretend backup and metadata, source cmp then HDD sync/hash readback.
Raw copies avoid relying only on utility in-memory GPT interpretation. No block
device output, GPT repair/grow/resize, stop services, source cleanup or backup rerun
in this step. Bash/Python syntax, geometry/source-copy checks and context validation
use regular sparse-file fixture (three ranges4MiB/root excluded); native sgdisk
not executed locally. Production execution and console/rescue remain pending. Timer first
scheduled event07Oct00:30UTC unchanged. Current state/queue/server map/index updated;
application/private helpers/scheduler/cron untouched, original audit preserved.


## 2026-10-06 — GPT/boot copy PASS; first guarded relocation prepared

База main1c37276937750857055bbfe56be065457dad222c (PR95). Owner09:03:36UTC
подтвердил доступ сисадмина к консоли; exact method/rescue medium/boot test отдельно
не названы. Owner output received09:06:01UTC: GPT_COPY_READBACK=PASS, nine payload
hash checks OK, raw source cmp/sync завершены guarded command. Private directory
/srv/ustar-storage/ssd-layout-20261006-06NYpg; capture timestamp хранится в private
file, не напечатан. Raw PMBR/primary+old/physical-end GPT/BIOS boot и metadata/native
backup сохранены на HDD. Existing GPT warnings остаются, SSD не записывался. Hash
values не даны, binary contents не публикуются; previous dated evidence не переписано.

Следующая one-line команда подготовлена: recheck identities/mount/hash copy, raw
header/table CRC и layout before write, sgdisk --move-second-header, independent
raw post CRC/pointers/exact entry array/GUID/kernel sizes/MBR boot code+BIOS boot
preservation, readonly utility verify/dump/df. Only GPT/PMBR relocation, no root
partition growth/ext4 resize/stop services. Bash/Python syntax PASS и17 local guard
scenarios on sparse regular file PASS, including corruption/mismatch failures;
native sgdisk locally unavailable/not executed. Expected post usable_end234441614
не является server result. No automatic retry/rollback after write-stage failure.

STATE/ACTIVE/BACKLOG/entrypoints/server map и generated context index обновлены;
original audit, application526files/93tables/32tasks, frontend/private backup engine/
cron/scheduler unchanged. Publication CI/deployment отделены от owner server evidence.
Timer first scheduled event07Oct00:30UTC unchanged; root growth/source cleanup/stage
и full host boot restore пока не выполнены. Следующий результат нужен от оператора.


## 2026-10-06 — полная актуальная редакция аудита и статус работ

По запросу владельца подготовлен USTAR_INFRASTRUCTURE_AUDIT_20261006_RU.md
с сохранением разделов исходного audit05Oct. Current snapshot12:39UTC: HDD
mount/fstab, fresh manual trial, timer active и raw GPT-copy подтверждены; GPT
write/root partition/ext4 growth, first scheduled07Oct03:30MSK, full-addon stage,
production patch/OS/HTTPS и full DR остаются pending. Это сверка уже полученных
свидетельств; новых операций на production агент не выполнял. Старое backup-last
исправлено по owner06Oct order, root78%, theme boost, core exception, CI8.3
учтены. Official Moodle5.3 released05Oct/minimumPG17+ проверены; future PLAT-01
дополнен отдельной PG major migration/paired rollback, выбранный patch5.1.8
и очередность владельца сохранены. Без новых app/frontend/infra/workflow changes.

STATE/document/entrypoints/ACTIVE и PLAT-01 acceptance актуализированы; original
audit05Oct hash и история не переписаны. Полный остаток, scope каждого PASS и
свидетельства включены в новую редакцию; публикация документа не закрывает риски.
Local Markdown/link/JSON/YAML/context checks и exports проверяются для этого
docs-only выпуска; реальные CI runs и exact implementation SHA фиксируются в PR.


<!-- Imported PR98 855264f4e38de4a7423b035cfd7ec746b2fce82d -->

## 2026-10-11 — стратегии импортированы; owner GPT relocation PASS

Изучен shared dialogue до final PR96 и текущий main с PR97. Владелец прислал
результат операции started14:40:15UTC: nine copy checks OK, raw pre/post GPT
CRC/entry array/boot/kernel geometry PASS, native verify без проблем,
GPT_RELOCATED=PASS; ROOT_PARTITION_SIZE_UNCHANGED=PASS. Root df49G/37used/11free/78%,
sda2 start4096/size104851456 sectors. Finish timestamp не напечатан.
[Evidence](../runtime/ssd_gpt_relocate_result_20261011.json),
[продолжение](../runtime/storage_continuation_20261011.md).

Четыре owner strategy attachments сохранены без изменения bytes; receipt hashes
и reconciliation показывают дату2025/источники2026, противоречие storage-first,
непроверенные коммерческие/правовые/конкурентные выводы. Вложения не принимают
новый ADR/очередь. STATE/ACTIVE/BACKLOG/entrypoints/index обновлены; audit05Oct
и dated JSON сохранены. Full audit06Oct дополнен только ссылкой на новый execution
result. Source приложения, frontend, infra helpers, workflow/harness не изменены.

Следующий operator step: pinned scheduler --status и growpart -N, без partition
write/backup capture/install/recovery. Server dry-run, scheduled acceptance, root
growth, full-addon stage/paired rollback/production patch/OS/HTTPS ещё впереди.
Новых серверных операций агент не выполнял. Проверки и точный implementation SHA
для этого docs-only выпуска фиксируются в GitHub PR; CI не является server acceptance.


## 2026-10-11 — последний scheduled backup и growth dry-run PASS; partition-only команда

Owner прислал scheduler --status: последний11Oct00:30:00.847420..00:31:44.442026UTC
complete/success/exited0, last backup success00:31:43.444339UTC, marker=false;
timer active/enabled/next12Oct00:30UTC. Это один latest run, не история07–10Oct
и не fresh restore. Actual growpart -N: start4096, old size104851456/end104855551,
new size234437519/end234441614; GUID/sda1/root start/type/PARTUUID прежние.
[Evidence](../runtime/ssd_growth_preflight_result_20261011.json),
[операторская команда](../runtime/storage_continuation_20261011.md).

Подготовлен только growpart с identity/raw pre/post/boot/CRC/kernel/unchanged ext4
guards; текущая relocated таблица сначала сохраняется и читается обратно на HDD.
Native growpart disk lock не вложен во внешний disk flock; отдельный operator
lockfile держится на весь шаг. Exact syntax/quoting и15 sparse-file guard scenarios
PASS, native growpart локально не выполнялся. Server partition/ext4 growth pending.
Новых production операций агент не делал. Application/frontend/helpers/workflows
не изменены; PR98 остаётся открытым, известный frontend npm audit failure предыдущего
head3a3a13c13e9042f8bdc48bec04de78acb0d5ef73 не обходится и не исправляется этим docs шагом.


## 2026-10-11 — actual partition growth PASS; online ext4 команда подготовлена

Owner operation started15:21:56UTC; end timestamp не напечатан. Original9 иnew6
copy checks OK; новый private snapshot `/srv/ustar-storage/ssd-grow-20261011-AiJI89`
предшествует growth. Actual growpart CHANGED: start4096,size234437519,end234441614.
Raw pre/post CRC, entry-array only root end, boot bytes и kernel size PASS. Ext4
остаётся13106432x4096bytes, df49G/37used/11free/78%; native No problems found,
2014 free sectors. Caution2048-sector end относится к некоторым encryption tools;
current mounted root — direct ext4, не crypto mapper. [Evidence](../runtime/ssd_partition_grow_result_20261011.json).

Следующий [guarded online ext4 resize](../runtime/storage_continuation_20261011.md)
сохраняет grown GPT на HDD, old FS/kernel/identity проверяет до записи, syncfs и
exact expected29304689 blocks/UUID/mount/raw GPT после. Kernel messages отдельно,
marker не является full service smoke. Старые меньшие GPT snapshots после FS
growth unsafe для table rollback. Native online resize не запускался агентом;
Bash/Python/exact quoting и11 regular-file guard tests PASS. STATE/ACTIVE/BACKLOG/
entrypoints/index обновлены, source/helper/workflow/frontend unchanged. PR98 open;
CI нового SHA отделён от local checks и owner server evidence.


## 2026-10-11 — online ext4 resize PASS; post-service smoke впереди

Owner start/finish stamps15:35:13UTC, exact elapsed отдельно не измерен. Native
resize2fs1.47.0 online:13106432→29304689(4k)blocks, descriptors7→14. Seven checksum
checks нового grown GPT snapshot `/srv/ustar-storage/ssd-ext4-20261011-MCyX9n` OK,
raw source cmp/GPT unchanged/UUID/mount/quota checks PASS. Root df110G/37used/
69available/35%; exact df size117815881728/used38711189504/avail73749991424bytes.
Kernel supplied window содержит только resizing/resized, no errors in that window,
не full historic/later host health. [Exact evidence](../runtime/ssd_ext4_resize_result_20261011.json).

Подготовлены readonly host/unit/container/listener/kernel inventory, pinned cron
v3 --status и два known HTTP login probes. Browser authenticated file/SCORM и
mail/DNS/ISPConfig acceptance отдельно впереди; затем fresh PR87 stage budget/check.
Без service stops/reboot/cleanup/source changes. Все меньшие старые partition
snapshots unsafe для table rollback выросшей FS; grown GPT snapshot не full host
backup. STATE/ACTIVE/BACKLOG/entrypoints/index актуализированы. PR98 open; remote
CI нового SHA отделяется от local checks и owner execution. Application/frontend/
helpers/workflows unchanged. Короткий smoke не повторяет полный baseline QA.


## 2026-10-11 — post-resize cron/HTTP/visible SCORM; выбор версии Moodle

Owner upload: inventory15:44:55UTC, root110G/69G available, HDD458G/6.5G used/447G
available, root inodes7%. Four production/lab containers running; required loaded
service instances active. Kernel warning query since15:21:56UTC: No entries.
Proxy/backend login200; pinned cron v3 SHA OK, scheduled15:44:02..15:44:03UTC
exit0/1.135s, no new failures, USTAR counters0. Прежние17overdue/2scheduled failed
сохранены. Screenshot показывает authenticated SCORM экран3/15; fresh login,
completion write и полный role/SCORM QA не доказаны. Functional mail/DNS/ISPConfig
не следует из units/listeners. AppArmor unit failed: prior audit records stale
snap-confine include, но current failure timestamp/cause отдельно не измерены.
[Sanitized receipt](../runtime/post_resize_checks_20261011.json); raw log/image публично не размещались.

Проверены официальные5.3 latest/requirements и lifecycle:5.3 LTS released05Oct,
PHP8.3/PG17+, supported upgrade source4.4+. 5.1.8 — prepared ближайший patch,
не обязательная ступень перед5.3;5.1 security support ends19Apr2027,5.3 —01Oct2029.
[Обоснование](../runtime/moodle_target_rationale_20261011.md). Scope1→7→6 сохранён;
production core не обновлялся. Next fresh PR87 read-only identity/budget.

Remote predecessor95666345bd04104e28462743ec32b104099a8733: Context#48
38152494478 SUCCESS; Review gate#484 38152494499 FAILURE, sourcePHP8.2/8.3 SUCCESS,
frontend failed npm audit; downstream rollback/moodle-db/prepare/gate skipped.
Это не CI нового docs commit. PR98 остаётся open, audit threshold unchanged;
application/frontend/helpers/workflows/release не менялись.


## 2026-10-11 — existing lab подтверждён; fresh cold preflight PASS

Owner message received18:46:12UTC/21:46:12MSK. Reviewed helper downloaded/installed,
both SHA checks OK; --check marker LAB_COLD_CHECKPOINT_PREFLIGHT=PASS. Operation
time не напечатано; login banner18:35:55UTC отдельно. Budget free73738452992/
required10526347264bytes, inodes6838433/225294, reserve4294967296bytes уже включён,
additional workspace1773512704bytes. [Receipt](../runtime/lab_cold_preflight_result_20261011.json).

User уточнил, что recovery lab уже установлен. Это существующий source
ustar_recovery_lab_20261004_web/pg, не новая patch workspace. Exact helper PASS
также означает новые checkpoint/workspace/names/relay absent и loopback18085/RAM
guards PASS на момент check. Установка инструмента и check не создавали новую
копию и не обновляли core. Download/install/check не повторять.

[Next SHA-pinned --prepare-stage](../runtime/lab_stage_check_handoff_20261011.md) prepared,
Bash syntax PASS; server execution pending. Создаёт отдельный frozen checkpoint
и mutable workspace; временно pauses только source lab, resumes те же IDs.
Production inspected/continues running. Fresh guards повторяются перед записью;
старый lab/checkpoint не переустанавливаются, partial paths не overwrite/delete.
Current queues/entrypoints/STATE/ACTIVE/BACKLOG актуализированы, application/helper/
frontend/workflow/release trees unchanged. Это baseline old core, не upgrade5.1.8.

Docs predecessorc3251acc0cbfee48b65b9bd4944406fbae7e4f74: API returned startup_failure
run38164755773 with no jobs. Source/context checks на этом SHA не объявлены PASS;
причина startup failure отдельно не установлена. Historical helperPR87 source
CI37375193949/context37375193850 SUCCESS остаются отдельным evidence. GitHub PR
metadata update ранее403 из-за unverified email; files/ref publication состоялись.

## 2026-10-11 — cold checkpoint и отдельная baseline workspace реально запущены

Owner exact PR87 installed SHA OK; --prepare-stage18:55:53..19:17:02UTC PASS.
Wrapper1269s (21min9s), helper1267.054s. Frozen checkpoint63431entries, полный
inventory/file hashes PASS, manifest77672fd0b5b7bc5a27d1bd71d536f2bf47449455623589b53e0605005584b4c0.
Отдельная mutable workspace restore bootstrap/login200,526 USTAR file hashes,
57 addon metadata,4910 Moodle contenthashes checked. Source lab resumed,
production containers unchanged. CoreDB2025100601.03 исходный, upgrade=false,
cron=false/email disabled/loopback only. Manual login/roles/SCORM NOT_TESTED,
full-addon compatibility/patch upgrade and paired rollback false.

Storage plan free73738285056/required10526347264bytes относится к pre-copy
планированию; fresh post-copy free этим stdout не измерено. Relay7200s, exact
start/expiry не напечатаны. Установка/check/prepare завершены и не повторяются.
[Owner receipt](../runtime/lab_stage_prepare_result_20261011.json) и [Windows SSH tunnel/browser handoff](../runtime/lab_stage_browser_handoff_20261011.md)
опубликованы; next login/home/one SCORM smoke нового стенда. PR83 synthetic CI
не является командой real full-addon upgrade: pinned candidate/vendor/addons/
USTAR home contract и workspace-only upgrade/repeat/paired restore helper ещё
предстоит подготовить. Старые lab и frozen checkpoint сохраняются. Scope1→7→6,
OS/AppArmor/functional shared services/HTTPS и fresh archive restore остаются
в измеренных границах. Application/frontend/helpers/workflows не менялись;
PR98 open, публикация docs не означает server candidate acceptance или CI PASS.

## 2026-10-11 — новый stage authenticated SCORM и официальный пакет5.1.8

Owner screenshot received19:53:07UTC, sha256f1dd998554ba43206fdde1facc0a24e92a4814728aac7213b71fe29ca89b71e1:
127.0.0.1:18085/mod/scorm/player.php, administrator session, «Товароведение»,
section6/20/30percent/text/images rendered. SSH tunnel/new stage browser access
и authenticated SCORM rendering PASS в видимой области. Fresh login/home redirect,
completion/progress persistence/all roles/separate attachment download не доказаны.
Raw image не опубликован; [receipt](../runtime/lab_stage_browser_result_20261011.json). Original prepare report NOT_TESTED
сохранён как исторический stdout; поздняя manual evidence отдельна.

Agent downloaded exact official5.1.8 tgz locally:75466974bytes,
SHA0e8e2cdeecf24512b75e24158fa224ed08a20c608b4e1065cb2068832467665c matches primary
Moodle checksum,39376entries/29409files/9967dirs,313711622logical file bytes.
Four metadata Git blobs match exact upstreamcc0bf17da8b5fbd290faf95b2b14a03d64331923;
not full-core equality. No root vendor in archive; lock0prod/68dev packages, runtime
addon/vendor compatibility still unverified. Stock index/get_default_home_page_url
support local path defaulthomepage; runtime USTAR home acceptance remains open.
[Package receipt](../runtime/moodle_518_package_verification_20261011.json).

[Next operator handoff](../runtime/lab_patch_package_handoff_20261011.md): existing shared lab lock read-only fd8/EX-NB,
private parent/checkpoint manifest identity guards/reserve4GiB+128MiB, create new
private directory, bounded HTTPS download and pinned SHA/fsync only. Bash syntax
PASS; server download not run, no tar extraction/PHP/SQL/container operations.
Full-addon candidate assembly/upgrade/repeat/paired restore helper not delivered
by this docs step; current workspace/source lab/frozen checkpoint preserved.
Exact source/context/application trees unchanged; queues1→7→6 maintained.

## 2026-10-11 — owner official package download PASS

Owner output received20:14:42UTC; download started20:09:23UTC, finish timestamp
not printed. New private package directory suffixnnixp4se; official5.1.8 tgz
pinned SHA0e8e2cdeecf24512b75e24158fa224ed08a20c608b4e1065cb2068832467665c OK, fsync
and MOODLE_518_PACKAGE=PASS/NO_CORE_UPGRADE=YES. Exact parent/checkpoint/lock/reserve
guards passed; no extraction, addon copy, PHP/SQL, container stop/create or upgrade.
[Receipt](../runtime/moodle_518_package_download_result_20261011.json). Download command
completed, do not repeat; next separate candidate assembly/hash verification.


<!-- Imported PR99 ee0b07b09b9a0dfadc70f3bfb2a80b0f4cb8be46 -->

## 2026-10-11 — INF-08 separate full-addon candidate code

Owner/aduk; branch `codex/lab-patch-candidate-20261011`, fresh main `c53723c8d669a1013c518f34abf392143d211d9d`. Accepted exact official tgz download and prior owner SSD/HDD/baseline stage/SCORM observations. New helper builds a fresh code directory only: stock core, frozen addons/extras hashes and independent inodes, static offline config/home insertion. No PHP/SQL/container mutations/live code/data/PG writes.

30 filesystem/archive/lock/failure tests PASS; real stock archive extraction/full readback PASS. Full context suite194 tests OK/10 skipped. Regenerated inventories recorded in [contract](../runtime/lab_patch_candidate_20261011.md). Docker/private inputs mocked; server execution pending. Stage upgrade/repeat/paired restore/runtime addon acceptance pending. Application/frontend unchanged; full continuation/strategy originals in PR98.

## 2026-10-12 Moscow — INF-08 package githash collision fix

Owner00:06MSK installed helper9fe10fc/SHA4850e1 OK; precheck refused before candidate directory/copy. Actual official archive generates root githash.php (cc0bf17), also listed among legacy23 extras in Git comparison. Previous local stock-only integration missed the combined inventory. Fix preserves stock githash and copies legacy file to private preserved-extra-files with independent inode/hash/full readback and separate manifest section; other collisions still refused. Full builder now tested with actual package+synthetic overlay, namespace checked for all19 actual roots/23 extra names.33 local candidate tests/197 full context tests OK,10 private-engine integrations skipped; real private addons/runtime not accepted. GitHub PR98 metadata update now works after owner email verification. Corrected server install/execute and paired runtime upgrade/restore pending. [Contract](../runtime/lab_patch_candidate_fix_20261012.md).


## 2026-10-12 Moscow — actual candidate PASS; readonly platform check prepared

Owner corrected helper4a681023/SHA bb71ddd installed/checksum OK, prepare-candidate
PASS. Exact directory0v8ncwgu/manifest07dfa485,44174 entries/29409 stock files/19 addon
roots/57 metadata/526 USTAR, independent inode/hash checks PASS.23 extras preserved,
legacy githash separately; original root vendor absent. No execution timestamps printed;
space70,914,838,528bytes is pre-copy budget, not post-copy measurement. No candidate
activation, PHP/SQL/core upgrade or paired rollback. [Receipt](../runtime/lab_patch_candidate_result_20261012.json).

Next helper verifies exact accepted manifest/full tree/config/frozen overlay and
retained namespace, then only stage PHP built-ins and bounded READ ONLY SQL.
No Moodle/config/plugin bootstrap, Composer, HTTP, new files or container mutations.
Default plan-only, shared existing nonblock lock, installed builder SHA pin/error
redaction. Optional addon top-level Composer declaration counts, not resolution.
Fresh reserve output is not activation/paired restore capacity.26 filesystem/runtime/
lock/redaction fixture tests; full r16 suite and context/code-map checked separately.
Source/stage private engine/Docker/PHP/SQL mocked locally; actual server readiness pending.
Guarded stage upgrade/repeat/paired code/config/Moodledata/physicalPG restore remains
next runtime implementation, then browser acceptance. Existing source/checkpoint/prod
preserved; storage/download/baseline/candidate creation not repeated. Previous exact
HEAD808fc0 Context51/sourcePHP8.2/8.3 success; frontend audit failure/downstream skipped,
no full gate PASS/merge. Current pointers/index updated, application/frontend/workflows
unchanged. [Contract](../runtime/lab_patch_readiness_20261012.md).

Publication: readonly helper implementation2496d11fac6af3fd8f0265355ad143016423d001,
remote immutable bytes match SHA a45ddc705e353e0013b2b9e4b8a34fd2c94c3c58831f15a37ae30badb2a706dc.
Exact Context52/38177469124 SUCCESS; Review488/38177469099 sourcePHP82_83 SUCCESS,
frontend npm audit FAILURE, downstream SKIPPED. PR99 remains draft/unmerged;
PR98 next-step metadata updated to accepted candidate/readiness. Server check pending.


## 2026-10-12 00:50:10 Moscow received — readonly PHP settings refusal

Owner checker2496d11/SHA a45ddc installed and both checksums OK. Readiness refused
Offline stage PHP memory/email settings differ. No actual PHP settings or execution
start/finish timestamps printed. Reviewed path reached PHP built-ins after candidate
readback, but before SQL/final container recheck; readiness not accepted, no activation
or upgrade. Root cause unknown; CLI/INI/adapter override differences are hypotheses.
[Receipt](../runtime/lab_patch_readiness_refusal_20261012.json).

New --diagnose-php in shared readiness source: guarded accepted controls/frozen INI,
owned inspect before/after, literal PHP built-ins and AST-only memory/mail literals
from exact pinned private engine. No full tree rehash, SQL, Moodle bootstrap, settings
write or acceptance PASS. Diagnostic report sanitized; existing --check strict512M/
binfalse refusal retained but observed values now printed safely. New separate installed
path USTAR_LAB_PHP_DIAGNOSTIC_20261012.py preserves old readiness helper.35 dedicated
and232 r16 tests OK/10 private engine skips; adapter fixture256M is synthetic, not server
fact. Application526/tables93/tasks32/generated code map unchanged. Current pointers/
index updated; frozen candidate/checkpoint/source lab/production not mutated. Exact
new publication/CI/server diagnostic remain separate evidence. [Contract](../runtime/lab_patch_php_diagnostic_20261012.md).

Published diagnostic implementationb90fd0d836a2f5b07af3818eccf8836c06a5992d,
SHA a432e80c82ad24dc640cf765fd90b3570a1e0872a55ec104ff52fa97b6388098,
immutable remote readback matches Git blob36ea5e9d234c5c815f8f2a2e3a990cb6ddd5ad51.
Context54/38178676723 success; Review490/38178676706 both source_checks and r16
steps success, source jobs in progress on Chromium install; frontend audit failure,
rollback skipped, later downstream statuses not yet observed. No full gate PASS,
PR99 remains draft/unmerged; real server diagnostic pending.


## 2026-10-12 01:27:46 Moscow received — native CLI sender confirmed

Owner diagnosticb90fd0/a432e80 installed/checksums OK, COMPLETE22:27:09.438042UTC,
no SQL/bootstrap/activation/upgrade, production containers unchanged. Native PHP8.3.33/
CLI/64bit/memory512M/max_input5000, sender/usr/sbin/sendmail -t -i. Frozen INI/one
readonly bind correct, destination absent from scanned list; empty adapter literals
not proof of no overrides. Native sender explains refusal; underlying INI loading/
access/main-or-later override cause not established. [Receipt](../runtime/lab_patch_php_diagnostic_result_20261012.json).

New CLI policy memory512M/binfalse/displayOff/logOn for invoked PHP process only.
Native and isolated observations separate; strict effective values/platform/executable
blocker verified before stage READ ONLY SQL. Frozen INI/readonly bind, complete accepted
candidate hashes/config/overlay, source/stage/prod before/after and reserve checks
retained. Added bound INI readable/main/scanned booleans, no private paths printed.
No settings files/container/service change, no repair claim about Apache or native
INI loading. Future candidate CLI uses same policy. Dedicated40 tests OK/1 skipped
for absent local PHP; real PHP CLI test designed for both PHP82/83 CI, validates
flags against actual temporary INI without mail()/Moodle or file changes. Full r16/
context/code-map recorded after validation; original candidate/frozen copies remain.
Exact publication/CI and real server readiness separate; stage upgrade/repeat/paired
restore and browser acceptance still pending. [Contract](../runtime/lab_patch_cli_isolation_20261012.md).

Published CLI policy implementation587971b65b15b9dd07ae3a03b2a73d129c3e08b9,
SHA2942e58967980d1d8adcc63c04d996d8798d642724bfead2c66380aee32bc9f9,
immutable remote bytes match Git blob53e7ffd65e0842b47aea32bd3eec91a2315d2a42.
Exact Context56/38181448987 success; Review492/38181449065 both full source PHP82/83
success including new real CLI flag/temporary INI test; frontend audit failure,
rollback/moodle-db/prepare-rc/gate skipped. Full gate not PASS, PR99 draft/unmerged;
real server CLI-policy readiness not yet executed, production not upgraded.


## 2026-10-12 01:56:35 Moscow — owner corrected readiness PASS

Owner installed implementation587971/readinessSHA2942e589, both checksums OK.
Readonly accepted candidate/platform preflight PASS:44,174 entries/manifest07dfa485,
57 addon metadata/526 USTAR, PHP8.3.33/PG16.15/required extensions, original DB/addon
versions and stage tasks0. Native sender still sendmail; bound private INI not
readable to CLI user33; effective process policy512M/binfalse PASS. No bootstrap,
activation/core upgrade or persistent configuration change. Optional exif/soap
missing; Composer/vendor declarations do not prove dependency resolution.
[Exact owner receipt](../runtime/lab_patch_readiness_result_20261012.json).

Private stage engine retrieved from owner file, fully read40187bytes/SHA71f6a1
matched; actual ownership/mount/exec/SQL/relay APIs reviewed and AST signatures
verified. Input remains private outside Git. New stage transaction helper pins
accepted readiness and its transitive dependencies, retains bind root inodes/IDs,
saves a fresh cold stage pair before first rename/SQL, runs official package
admin/cli/upgrade.php twice and uses explicit PHP policy throughout. Core stock
plugin changes allowed; frozen57 addon versions checked. Bounded readonly DB/file
reference checks, stage-only task disable, paired failure recovery, durable marker,
no deletion and --restore-baseline for rollback acceptance.23 dedicated filesystem/
failure injection tests PASS; full r16 260 OK/11 skips. Real Docker/PHP/Moodle/PG
upgrade/restore not executed locally or on server. Exact implementation/publication/
CI recorded separately after publication; PR99 remains draft/unmerged.
[Contract](../runtime/lab_patch_upgrade_20261012.md).


Published upgrade implementation59b88e1ae061bfa9ca4070ec74c4ffbf1629df44,
helper SHA452d8fd682bc2878fee66aaa9c121528e707d944b943d90264c2d220a48133f4,
immutable GitHub blob3f42c59 byte-identical PASS; local/published tree51c561a matched.
Exact Context58/run38184389152 SUCCESS and Review494/run38184389139 complete
source PHP8.2/8.3 SUCCESS. Frontend npm audit FAILURE; rollback/prepare-rc/moodle-db/
gate SKIPPED; no full gate PASS, merge or server deployment claim. PR98/99 metadata
updated to owner readiness PASS and concrete stage upgrade/recovery handoff.
Operator immutable curl/checksum/install/upgrade and recovery commands documented.


## 2026-10-12 11:29:51 Moscow received — real stage upgrade/repeat PASS

Owner downloaded/installed exact implementation59b88e1/helperSHA452d8fd6, both
checksums OK. Finished00:04:42.290128UTC/03:04:42MSK, operation start timestamp not
printed. New transactionrunfylkhzxl, fresh current-stage paired cold baseline
manifest156e468887a09992b6e6b69bc05f4733385e648546f17f3c102c98d5f50abce9
PASS before SQL. Official CLI first/repeat PASS; stage candidate active/coreDB
2025100608.00, login200/4910 contenthashes, tasks0/CLI mail blocker/loopback-only,
same stage container IDs/bind-root inodes and source/production identities/start
times. [Exact receipt](../runtime/lab_patch_upgrade_result_20261012.json).

This is owner server evidence, not independent agent SSH or all-feature QA.
Production core unchanged; paired rollback/home/roles/SCORM/full-addon/Apache
acceptance not reported. No fresh12Oct HDD timer/host reboot/independent archive
restore evidence inferred. Existing source CI implementation59b88e1 Context58 and
both full PHP8.2/8.3 source jobs PASS; frontend audit failure/full gate not PASS.
Helper/application/frontend/workflows/private scripts unchanged in this delivery.
Next same installed --restore-baseline: real three-tree rollback to original
core2025100601.03, preserving upgraded files and IDs/root inodes; accept owner
output before re-upgrade/browser QA. No repeat download/install/preparation.
[Reviewed handoff](../runtime/lab_patch_rollback_handoff_20261012.md).


## 2026-10-12 13:02:37 Moscow received — actual paired stage rollback PASS

Owner pre-restore curl proxy/backend loginHTTP200 with Hostustar.local; no timestamps,
body or authenticated session, not a post-restore probe. Same installed helper59b88e1/
SHA452d8fd6 verified. Markers08:51:07..09:01:36UTC/11:51:07..12:01:36MSK,629s.
LAB_PATCH_PAIRED_RESTORE=PASS, paired_trees_restored=true/baseline_restored=true,
original coreDB2025100601.03, login200/4910 contenthashes. [Exact owner receipt](../runtime/lab_patch_restore_result_20261012.json).
Prior actual5.1.8 upgrade/repeat plus this full code/config/Moodledata/physicalPG
restore establish isolated stage cycle acceptance on this helper. Private source/
production guard passed by exact helper control flow before PASS; its raw fields
were not printed and are not fabricated in the receipt. Production core unchanged;
production rollback/fresh HDD restore/host reboot/full-addon/browser/Apache not
inferred. Current stage original baseline, not candidate5.1.8. Prior runfylkhzxl/
manifest156e4688 and upgraded files retained.

Next same helper --upgrade-stage archives the restored marker, runs readonly
accepted candidate/platform/budget checks and creates a new run/current-stage pair
for5.1.8/browser QA. No repeat download/install/preparation or old rollback trial.
New report per-transaction rollback pending does not invalidate old accepted proof;
no infinite restore loop absent code changes/failure. [Operator command and scoped native QA matrix](../runtime/lab_patch_reupgrade_handoff_20261012.md).
Helper/application/frontend/workflows/tests unchanged; documentation-only context/
JSON/operator-shell/source-equivalence checks apply, broad tests not rerun. Existing
source CI exact59b88e1 Context58/PHP8.2/8.3 SUCCESS; frontend audit failure/full gate
not PASS, PR99 draft/unmerged. No production deployment or merge.


## 2026-10-12 13:40:55 Moscow received — active5.1.8 re-upgrade/repeat PASS

Owner same installed helper59b88e1/SHA452d8fd6 checksum OK. Markers10:21:45..
10:40:14UTC/13:21:45..13:40:14MSK,1109s; internal finished_at10:40:14.161729UTC.
New runxdgki_yo/current paired baseline manifestd15a50027186ae6da5ae2a7a83ac74579d88399c36dcb8605ee4c0799fcff342
PASS before SQL. Core2025100608.00/candidate active, official first/repeat PASS,
login200/4910 hashes/tasks0/CLI mail blocker/loopback, same stage IDs/bind-root
inodes/source/production identities. [Verbatim receipt](../runtime/lab_patch_reupgrade_result_20261012.json).
Latest report rollback=false belongs to new transaction; previous actual same-helper
restore156e4688/runfylkhzxl remains accepted, no infinite rollback cycle. Current
stage5.1.8, not original baseline. No authenticated/full-addon/home/Apache result.

Reviewed SHA-pinned official package0e8e2cde again: stock moodlelib supports relative
URL defaulthomepage/get_default_home_page_url/HOMEPAGE_URL, stock index redirects.
Candidate/local/ustar/home.php config is statically supported without modified
index; actual authenticated redirect remains pending. PuTTY official manual local
source/destination/Local/Add settings checked for18085 access. [First native QA handoff](../runtime/lab_patch_browser_qa_20261012.md):
fresh private-window admin login/root redirect/home, SCORM media/progress/relogin,
then existing real roles/preserved addons. Relay policy7200s implies approximate
access until15:40MSK, actual client tunnel/current unit state unobserved. Source/
helper/application/frontend/workflows unchanged; existing exactsourceCI59b88e1
success/full gate failure retained, documentation-only checks apply. No repeated
core operations for browser access, no production deployment/merge.


## 2026-10-12 14:10:26 Moscow received — partial stage browser acceptance

Owner reports SCORM opens, academy access, My Team opens and admin version5.18.
Two attachments read directly from local paths: SCORM screenshot SHAa7390f9b,
admin screenshot SHAd2c12550. Browser URLs use loopback18085/private window; current
stage attributed by owner report and prior re-upgrade receipt, not agent live SSH.
Visible authenticated SCORM player screen16/20, USTAR menu/styles/text/images and
admin footer Moodle5.1.8(Build20261005) accepted in visible scope. My Team accepted
as owner report, page not pictured. The phrase «регается» does not establish fresh
login or self-registration. Screenshots do not show home/root redirect, SCORM saved
progress/relogin/completion, real roles or full addon/Apache settings. Raw images
not published; sanitized observations/full attachment hashes in
[receipt](../runtime/lab_patch_browser_result_20261012.json).

Admin cron age warning expected with intentionally disabled clone scheduled tasks;
no cron enablement. Next same-attempt SCORM section change/reload/relogin and actual
root→USTAR home, then existing real employee/manager/HR scopes and preserved addons.
Package-specific resume position differs from persisted completion; compare both
before calling a failure. Prior same-helper stage rollback proof retained; no new
upgrade/restore loop, production operation or merge. Helper/application/frontend/
workflows/tests unchanged; doc-only context/index/provenance checks apply. Existing
exact59b88e1 source CI PASS/frontend audit FAILURE/full gate not PASS retained.


## 2026-10-12 14:41:24 Moscow received — basic admin browser smoke accepted

Owner exact follow-up: «Переход между экранами работает, прогресс тоже, вход выход
и переход на главную». This answers the requested same-attempt SCORM persistence/
logout-login/root-home check. Accept stage admin browser SCORM navigation/progress,
fresh login after logout and home transition in owner-reported scope. Exact final
URL/new screenshot/independent browser or DB logs not supplied; no fabricated
redirect trace/completion/grade/self-registration/all-role proof. Prior screenshot
observations/hashes and original report preserved in
[updated receipt](../runtime/lab_patch_browser_result_20261012.json).

Next existing real employee/manager accounts: own learning/tasks, direct HR page
capability denial for non-HR employee, manager team/learning with actual current
assignments; then HR/relevant preserved addons/completion/grades/Apache isolation.
Source read confirms team.php/use + scoped presenters and hr.php/system HR guard;
ordinary hierarchy visibility is not management permission. View-as not real actor.
No production role changes, password/personnel publication, repeated admin/core
upgrade/restore/preparation. Prior same-helper paired rollback proof retained,
current stage518/runxdgki_yo and clone cron disabled. Helper/application/frontend/
workflows/tests unchanged; doc-only checks apply; full gate/frontend failure and
production deployment acceptance remain open, PR99 draft/unmerged.


## 2026-10-12 15:25:03 Moscow received — employee/manager requested smoke PASS

Owner exact «все работает» replies to preceding two-account checks: employee own
learning/tasks and HR-page denial without HR capability; manager correct roster/
learning. Accept this requested stage smoke in owner-reported scope. Account IDs,
new screenshots/logs and independent browser/DB queries absent; do not invent HR,
all write/cross-scope capabilities, completion/grades or full addon acceptance.
Original14:10 screenshots and14:41 basic admin browser result retained; latest
[receipt](../runtime/lab_patch_browser_result_20261012.json) records the follow-up.

Next batch existing real HR people/card, materials library/detail/document/file,
existing grading queue/attempt if actual capability present. No role expansion or
new grades inferred from viewing; empty queue separate from attempt view. Then
bounded Apache/PHP isolation, relevant addon runtime and frontend/full release CI.
Pinned profile19 roots/57 metadata includes offline external integrations; don't
mislabel provider isolation as a data/permission regression or enable external
network/mail/cron to force a result. Same helper/application/frontend/workflows/
tests unchanged; prior paired restore proof retained/current stage518/runxdgki_yo.
No repeated passed admin/employee/manager smoke, core operations, production deploy
or merge. Doc-only context/index/provenance checks apply. Relay7200s deadline≈15:40
Moscow is inferred, not agent current service observation.


## 12Oct HR owner acceptance and Apache inspection helper

Owner12Oct15:50MSK accepted preceding HR people/card/material/document/assessment read smoke; writes/full addons not inferred. Access ended by owner report. Next bounded Apache/PHP inspection without relay restart or core upgrade, then CI. Production unchanged.
New builtins-only temporary HTTP probe checks existing Apache handler; bounded namespace request, guarded cleanup, readonly DB/version checks; no relay/start/upgrade/production operations. Local tests cover interrupted cleanup, replacement retention and settings denial. Existing frontend dependency edits remain uncommitted and excluded from this delivery; npm audit/full CI still pending.


## 12Oct16:34MSK Apache runtime acceptance and frontend audit fix

Owner Apache/PHP check12Oct13:33:40UTC PASS:512M/mail blocked/required extensions present/tasks0/code and identities unchanged. HR read smoke accepted. Next CI dependency fix/full RC and relevant addon acceptance before production; do not repeat passed stage core operations.
Receipt:context/runtime/lab_patch_apache_result_20261012.json. Audit job114826833000/run38253886782 identifies Next cache poisoning advisories GHSA-4jqv-mc3x-m676/GHSA-mcj8-r9mp-w47p and Sharp GHSA-wq5f-xc86-pv6w. Prepared existing dependency edits reviewed:Next15.5.27,Sharp0.35.5,lockfile parity; no threshold suppression. Production native Moodle code and all pinned stage helpers unchanged. Publication/full RC pending.

Local frontend audit production dependencies0 findings,4 unit tests,clean install(ignore scripts),build and built HTTP fixture PASS. CI uses normal npm ci. Receipt:context/runtime/lab_patch_frontend_audit_fix_20261012.json.


## 12Oct16:58MSK full RC and fresh production inventory

Full RC exacta42672e/run38257457655 SUCCESS(all8 jobs);Apache and requested browser read smoke accepted. Production Docker/HDD/free-space/service result received12Oct16:58MSK:root60.77GiB,HDD445.34GiB,HDD rw,backup service0/success12Oct00:31:39UTC. Next pinned scheduler --status for archive identity/readback/recovery marker,then production preparation/relevant addons;no repeated stage core or inventory operations.
Receipts:context/runtime/lab_patch_full_rc_result_20261012.json and production_patch_inventory_20261012.json. Scheduler status read scope reviewed from source;uses pinned producer and reads saved reports/systemctl only,no backup,start/stop,PHP/Moodle bootstrap or SQL. Free-space figures alone are not a production transaction capacity guarantee.


## 12Oct17:28MSK scheduler status and production preflight

Owner12Oct17:28MSK scheduler status accepted:complete/success,marker absent,no recovery errors,next13Oct03:30MSK. Correction:status has no archive identity/hash. Next production_patch_readiness --check:read-only pinned profile/code+DB/addon versions,HDD archive SHA/sidecar,aggregate usage and sizes;no Moodle bootstrap or activation. Passed stage/full RC/inventory checks not repeated.
Helper static read-impact reviewed;11 guard tests and prior broad274/11skips PASS. Credentials/config never printed;SQL queries bounded read-only. Source/real server evidence pending. Native application/frontend bytes unchanged;full RC a42672e retained within its scope.


## 12Oct17:44MSK production preflight accepted; private candidate helper

Owner production preflight12Oct14:43:42UTC PASS:526 USTAR/57 versions,old core2025100601.03,tasks129 unchanged,latest1.019GB archive4e18019e rehashed. Next separate production candidate from official5.1.8/live overlays with candidate-only home config;no activation,core/data/DB or service mutations. Stock wrapper reviewed separately from deliberately rewritten lab loader. Passed preflight/stage/full RC not repeated without drift.
The lab rewrites root and public config;public loader separately reviewed against original upstream and5.1.8 archive hashes before allowlisting. Profile code unchanged;original root config only candidate-home insertion. Test9/full284(11skips) PASS before final loader guard;dedicated10 PASS afterward. Source CI/server candidate pending.

## 12Oct production candidate configuration refusal and metadata-only diagnostic

Owner installed source57583559dd3451073620783c5e72bab3a5ec6597 helper7ef2216b,
checksum OK;--prepare-candidate refused at configuration before SQL,live inventory,
own namespace/lock or copy. No candidate START. Accepted production readiness and
prior stage/full RC retained. [Refusal receipt](../runtime/production_patch_candidate_refusal_20261012.json).

Guard demands exact final setup text and no homepage token anywhere. Official stock
config-dist comments after setup demonstrate a possible limitation;actual failed
condition is unknown. Added --diagnose-config:only pinned readiness module and
bounded protected config read,fixed raw-text labels/counts,never source values.
No PHP parse/bootstrap,SQL,Docker,archive/profile scan,namespace/config/lifecycle writes.
Insertion guard unchanged. Dedicated16 tests PASS(secret sentinels,misleading raw
lookalikes,UTF8/comments/closing tag/code,CLI isolation,original10 copy/drift guards).
New source CI/diagnostic server execution pending. No production activation/upgrade.

## 12Oct23:06MSK owner diagnostic accepted; candidate config comment-tail fix

Owner c092d3b6/SHA6ea279b4 checksum OK,metadata-only diagnostic COMPLETE:one exact
setup,no raw homepage token,only old exact-final-text condition failed. Raw suffix
comments_and_whitespace. No config text,PHP/SQL,namespace/copy or lifecycle writes.
[Receipt](../runtime/production_patch_config_diagnostic_result_20261012.json).

Fixed candidate-only insertion accepts complete comments/ASCII whitespace after
setup while retaining all original bytes. Conservative lexical prefix check rejects
setup lookalikes in strings/comments,nested or controlled if/loop setup,unsupported
mixed PHP tags/heredoc/complex interpolation/attributes. Tail code,tags,unfinished
comments,CR-hidden statements and non-ASCII whitespace refuse. No PHP executes in
the builder. Source config is retained and compared;only the isolated candidate gets HOME.
Local23 tests:22 PASS/1 synthetic PHP-dependent skip in Python-only environment;
PHP source CI checks fake local setup receives same credential hash/prefix and new
homepage. Real temporary candidate with trailing comments retained/source unchanged PASS.
Exact fixed source CI/server preparation pending. Prior readiness/stage/full RC retained;
no production activation/upgrade or new all-addon acceptance inferred.

## 12Oct23:31MSK production candidate accepted; switch/paired recovery preflight

Owner sourcef1b68694/SHA7b8ee79a candidate518-wnd475x8 PASS:44174 entries,
manifesteb6d025d/sourcee7318ecc,19 roots/57 versions/526 USTAR,four originals,
production readback/identities unchanged. [Receipt](../runtime/production_patch_candidate_result_20261012.json).
Creation closed. Owner23:21 noted circular progress;report concrete closed scopes and
remaining steps. Do not repeat candidate prepare or passed stage core/role read checks.
Exact fixed source context38303047195/review38303047204 SUCCESS(source8.2/8.3/frontend),
Python298/11skips,synthetic PHP setup and real comment-tail candidate tests PASS.
Ordinary DB/rollback/gate skipped;prior full native-application RC retained in scope.

New readonly switch preflight reads missing live root ownership/modes/inodes and
physical PG/data metadata/budget,other Docker bind consumers/restart policies,exact
installed backup/cron hashes and existing shared lock. Accepted manifests/config
pinned;no code-wide candidate rehash until actual switch,no PHP/SQL or any filesystem
outputs/lifecycle/backup/activation. Candidate private root mode must not replace
live bind-root metadata. Local15 real temp read/secret/tamper/metadata/lock guard tests
PASS. New source CI/server preflight pending. Fresh physical paired copy and automatic
production restore transaction not implemented by this collector. Relevant addon
runtime,production activation/upgrade and fresh paired recovery still pending.


## 12Oct23:52MSK accepted switch conditions; unified production transaction

Owner attachment switch preflight20:51:43UTC PASS on eadee288/fd65eda0:code33:33/0755,
data33:33/0770,PG999:1000/0700,children999:999,mixed root-owned data,no bind consumers,
unless-stopped,12,706,045,952bytes needed/64,707,956,736free. No PHP/SQL/outage/writes.
[Receipt](../runtime/production_patch_switch_preflight_result_20261012.json).
Candidate/preflight closed;no repeating collectors or stage cycle.

Unified transaction captures fresh encrypted HDD copy,uses EX backup/cron lock,
clean web/PG stop,fresh verified physical pair,preserved live-root inode/UID/GID/mode,
official CLI upgrade/repeat and all-three-tree restore on pre-release failure.
Imports only3 pure stage journal/copy/move functions and cold copier;no stage ops.
Restart no guards partial copies;owned maintenance file blocks web before DB
bootstrap. After durable public release,old-data restore requires explicit discard
acknowledgement. Custom/addon task flags preserved;core defaults may evolve.
Custom SQL read-only,role/route/history resets absent. Raw command details private.

Local24tests:23 PASS/1 exact owner fixture skip(local root namespace cannot chown33/
999). Real file copy/recovery/retry/journals/kernel locks;Docker/SQL/HDD fake.
PHP8.3 source CI now mandates privileged temporary33/999/GID1000 owner fixture.
Exact new CI/publication/production execution pending. Real production restoration
not claimed;prior actual stage rollback/package/native full RC retained. Post-switch
role/learning/cron and full addon compatibility remain distinct acceptance.


## 13Oct01:48MSK production attempt stopped before cold pair; original resumed

Owner d3e1bfed/24a51add checksum/install and explicit upgrade executed. Fresh HDD
PASS,then PRODUCTION_BASELINE_RECOVERY=PASS:old2025100601.03/login200/5151 references,
paired_trees_restored=false. Read-only marker:baseline_restored,switch_started=true,
live_trees_modified=false/public_released=false;12Oct21:52:04..21:53:56UTC. All three
baseline directories/complete manifest absent. Docker event read succeeded but
returned no exits. [Receipt](../runtime/production_patch_stop_result_20261013.json).
Specific failing guard unknown;do not infer exit/client/task state or claim upgrade
or all-tree restoration. Stage/candidate/native full RC acceptance retained.

Old helper omitted failing reason/phase and recovery overwrote final phase. Fixed
safe failure_phase/code/scalar evidence survives recovery and is printed. Strict
clean-stop,zero-client,version/task conditions remain unchanged. New read-only
--diagnose-transaction requires restored unreleased baseline,existing SH lock;
current SQL comparisons/standard stop signals and exact known-ID receipt labels
only. No PHP/Moodle/stop/start/backup/activation/journal writes or raw logs/messages.
Current sample cannot prove the previous shutdown state. Local31 tests30 PASS/1
namespace owner skip,including nonzero/OOM rejection,failure evidence/privacy and
old-receipt read-only no-write checks. New CI/server diagnostic pending. Next this
mode without outage,not a blind upgrade repeat. Actual production upgrade/QA pending.

Exact diagnostic source a8caec553996b8a1eebb85f265f9dcbdba147ffc/tree27db3165,
helper6f87a780,remote raw byte readback PASS. [CI receipt](../runtime/production_patch_stop_ci_20261013.json):
context38319610463/review38319610453 SUCCESS including source8.2/8.3/frontend;
Python344/12skips PASS,mandatory privileged33/999/GID1000 fixture1/OK(no skip).
Local full Python344/13skips PASS with existing MCP dependencies;local PHP absent.
Ordinary DB/rollback/full gate skipped;prior unchanged native full RC remains
separate. Source/helper/guard behavior unchanged by this evidence-only follow-up.
Server read-only diagnostic and production upgrade still pending.


## 13Oct02:50MSK restored transaction diagnostic completed; same installed upgrade next

Owner a8caec55/6f87a780 checksum verified;--diagnose-transaction COMPLETE/read-only
at12Oct23:49:48UTC. Current old core/57 addons/all task flags match,other PG clients0;
SIGWINCH web/SIGINT PG,10 ID-only control receipts. [Receipt](../runtime/production_patch_transaction_diagnostic_result_20261013.json).
Sequence consistent with web stop then recovery before normal PG stop;inference
only,not old exit/client/task values. Old exact failing guard remains UNRECORDED.
Read-only collector closed. No new code/install/stage cycle. Next operator runs same
already installed tested6f87a780 helper --upgrade-production --acknowledge-outage;
it captures fresh recovery and now records exact failure phase/code if it recurs.
Strict conditions retained. Upgrade/actual paired production restore/post-switch
business/cron acceptance remain pending. No repeat permission requested:outage and
transaction already authorized. Source/helper/tests unchanged from exact passed CI.


## 13Oct03:47MSK production518 upgrade/repeat technical PASS

Owner same installed a8caec55/6f87a780 SHA verified;fresh HDD and cold paired
baseline PASS,accepted candidate activated,official CLI first/repeat PASS.
[Receipt](../runtime/production_patch_upgrade_result_20261013.json):finished00:27:29UTC,
core2025100608.00,57 addons,5151 file references,HTTP200,132 tasks enabled,
custom/addon flags and root/container identities/restart policies preserved.
Fresh HDD1,049,765,934bytes/c857f79b;paired manifest5822b456/run-o4w0cytg retained.
Production restoration=false;no full-addon runtime claim. Previous unknown guard
not reproduced;do not invent its exact cause. Upgrade scope closed. Next existing
read-only cron --status,scheduled0330MSK HDD result(after0327MSK upgrade finish),
brief real production login/material/progress/tasks/team/HR read smoke;no repeat
install/upgrade/stage cycle. Then selected OS/HTTPS plan. Source/helper unchanged
from exact source/context/frontend CI proof;this follow-up is evidence-only.


## 13Oct04:08MSK post-switch scheduled cron and HDD status accepted

Owner uploaded terminal text read from supplied scratch copy;raw file not altered
or published. [Selected receipt](../runtime/production_patch_post_switch_status_result_20261013.json):
cron v3 SHA66fd51d3 verified,schedule/binding match,scheduled01:07:01..01:07:02UTC
(04:07MSK) after core upgrade,exit0/1.175s/new_failures empty/USTAR errors0.
Residual2 scheduled_failed/17 overdue counts same as prior owner baseline and
before/after pass;no new error or resetting/disabled-task cleanup inferred.
HDD scheduled00:30:00..00:31:43UTC complete/success/exit0,no recovery marker,timer
active/enabled,next14Oct03:30MSK. Scheduler status has no new archive identity/hash
or decryption proof. Production518 technical upgrade/repeat/cron/HDD acceptance
closed. No browser statement/screenshots supplied;only brief real authenticated
production UI confirmation remains. No repeat server command/status/install/upgrade/
stage operation. Next selected OS/HTTPS work after this brief confirmation.
Source/helper/application/frontend unchanged;context evidence-only follow-up.


## 13Oct04:14MSK production browser accepted; patch closed,OS read-only handoff next

Owner explicit browser acceptance OK,SCORM opens,different accounts work;answers
preceding short login/home/existing content/progress/tasks/team/HR checks. [Receipt](../runtime/production_patch_browser_result_20261013.json)
records owner scope,not exact named roles/full write/all-addon/restore coverage.
Moodle518 acceptance closed with prior production upgrade/repeat/cron/HDD proof.
INF-08/INF-09 accepted in the delivered patch/native matrix scopes. No repeat
upgrade/status/stage/browser cycle. INF-10-OS starts with read-only metadata and
cached-index APT simulation,not installation or reboot. [Handoff](../runtime/host_os_preflight_20261013.md).
Commands syntax/dpkg placeholder quoting verified locally;actual server output
pending. Ubuntu primary docs confirm simulation semantics and24.04 service restart
behavior;no unsupported blanket no-restart promise for later installation. Shared
recovery/restart plan prepared only after package/service observations. Console
access previously confirmed,no repeat permission question. Application/helper source
unchanged;context follow-up only. Then selected HTTPS;broader security tasks deferred.


## 13Oct04:49MSK OS output received; targeted AppArmor check and Moodle5.3 goal

Owner terminal upload04:37MSK read locally without modification/publication.
[Sanitized receipt](../runtime/host_os_preflight_result_20261013.json):Ubuntu24.04.4,
running139,installed linux-generic146/reboot flag,13 unit observations,AppArmor
only failed unit;production containers retain accepted image IDs/docker-default
labels. Kernel profile enforcement/next boot selection/shared functional acceptance
not inferred from these labels/unit states. Cached APT simulation32 upgraded/18 new
firmware/no removals;index stamp12Oct10:47UTC,no catalog refresh/install. Docker/
containerd,netplan/open-iscsi,AppArmor are among changes,so actual shared-service
recovery/restart plan is necessary before installation. Previous05Oct stale snap
include is a candidate,current root cause not established by failed unit metadata.
[Next one-line handoff](../runtime/host_os_apparmor_handoff_20261013.md) uses parser
Q/K/one job/timeouts on that exact profile and reads Snap consumers/selected loaded
profiles/SSH socket. It neither changes profiles nor starts Snap or host services.
Command bash syntax and primary Ubuntu flag semantics checked locally;server
profile/consumer result pending. Targeted reversible quarantine and shared package
plan will use that result;no global AppArmor disable/Snap purge/empty include or
blind APT/reboot command. Existing console access reused,not requested again.

Owner asked whether we would upgrade to the latest stable Moodle;clarified that
accepted5.1.8 was the completed branch patch and current stable5.3 LTS requires
PG17+ (production16.15),PHP8.3 minimum met. Official latest/release requirements
checked13Oct;direct upgrade from supported4.4+ needs no intermediate5.2. Owner
04:49MSK accepted complete OS then prepare5.3/PG/addons/isolated rehearsal and
production window. PLAT-01 goal now explicit in STATE/ACTIVE/BACKLOG;no major DB
or Moodle migration is executed and prior518 acceptance remains closed. HTTPS
remains selected;broader unrelated hardening remains deferred. Source/application/
frontend/tests/workflows/helpers unchanged;this PR99 continuation is receipts and
operator handoff only. Actual OS executable source will use a separate updated-main
branch rather than stacking a new feature on this prior patch branch.


<!-- Imported PR100 d1a3b6e1f62aa00a550016fdb55a55a4c47344a3 -->

## 13Oct13:18MSK Snap include failure confirmed; retain LXD and prepare exact repair

Owner current compile exit1/missing absolute include line11 confirmed exact
a308e931/root0:0/0644/dev2050/inode3151055 profile. Snap core18/LXD/snapd mounts
active,enabled listening LXD socket and inactive daemon remain;Snap executable
process count0 does not establish unused consumers. Kernel docker-default enforce
observed,SSH socket loaded/active/enabled metadata only. [Selected receipt](../runtime/host_apparmor_observation_20261013.json).
Quarantine would not satisfy the observed consumer scope. Retain profile and add
only if-exists to its one literal include;conditional syntax retains future include
semantics. New code branch from fresh current main c53723c,not stacked on PR99.
Helper preserves verified byte/basic-metadata backup,compiles Q/K,checks baseline
again,atomically replaces one file,starts/reloads only AppArmor and verifies old
kernel labels,Docker identities/restart counts/enforcement and selected Snap units.
Compile failure leaves live bytes unchanged;post-load failure retains fixed file
plus exact original/private phase instead of restoring known broken rules or
unloading policies. Repeat after success does not reload. Local helper and full
Python suite PASS,actual Ubuntu parser fixture mandatory in dedicated CI. Server
repair still pending;no packages/reboot or Snap/LXD activation/removal.

Selected prior upgrade/cron/browser/OS receipts copied byte-for-byte from accepted
PR99 source ee0b07b09b9a0dfadc70f3bfb2a80b0f4cb8be46;application/frontend remain
main baseline. Accepted518/stage/storage work not repeated because main's older
entrypoints describe06Oct history. INF08/09 closed in delivered patch/matrix scope;
INF10OS current,major53/PG17+ follows OS as accepted by owner04:49MSK. This is not
full addon/Snap/LXD/shared-host restore acceptance. CI run/implementation SHA and
remote byte checks follow publication before the pinned operator command.


## 13Oct exact host helper parser/root CI accepted; operator apply ready

Implementation3a3ef0f3c5df8355822af76feb6fa62b2553fdfc/570986e4 raw byte readback
and tree equivalence PASS. [Validation](../runtime/host_apparmor_validation_20261013.json):
Ubuntu fixture38386147380/job11525831959713 tests/no skips,real parser and
root temporary file transactions PASS;context38386146947 PASS. Review38386147199
PHP8.2/8.3 source PASS,unchanged main frontend audit failure on Next/sharp;build/
HTTP skipped,full PR green not claimed. No unrelated dependency modification
in this host-helper branch. Earlier PR99 dependency correction remains distinct.
Native/app trees unchanged,current-main base c53723c maintained. Dedicated test
uses Q/K and simulated service/Docker operations;it does not load server policies
or prove Snap/LXD business functionality. The root-pinned --repair command is now
ready;actual server repair/package/reboot evidence remains pending.


## 13Oct14:24MSK actual AppArmor repair PASS; refreshed package/boot/recovery plan next

Owner verified download and installed helper570986e4 from exact3a3ef0f3,then
--repair PASS/complete. [Receipt](../runtime/host_apparmor_result_20261013.json):
one optional include,originala308e931/root0:0/0644/dev2050/inode3151055/mtime retained
privately,corrected93e1813e,AppArmor start/active/success and Snap parent/namespace
helper enforce;prior kernel labels,Docker enforcement/IDs/images/restart counts
and selected Snap unit states preserved. No package/Moodle/DB/Snap/Docker mutation
in this repair;full Snap/LXD runtime not claimed. Targeted AppArmor acceptance
closed,no repeated helper/status/browser/core/stage cycle.

[Next handoff](../runtime/host_packages_handoff_20261013.md):refresh catalog with
error-on-any then simulate;read boot kernel/package/GRUB selection/image paths/
free space/selected shared recovery logical sizes and capped session-read-only
MariaDB system/aggregate engine metadata. Read only selected Dovecot storage
settings,Docker daemon live-restore and container name/status metadata. No package
install/remove,reboot,boot changes,service restart or DB business data query.
Catalog update writes APT metadata/executes existing update hooks;not falsely
labelled filesystem-read-only. Commands bash-n and primary API/flag semantics
checked;actual output pending. Conventional dirs/global mail templates do not
prove all configured roots;no raw live MariaDB copy offered as coherent recovery.
Next concrete version set/private coherent shared recovery/service window comes
from output. Console authorization already confirmed;no repeated initial question.
Prior518 acceptance and latest53/PG17+ after OS goal preserved. Source/helper/tests/
workflow/native/frontend unchanged;context/evidence-only PR100 continuation.

### 13Oct15:04MSK — refreshed APT/boot/shared metadata; cold root recovery handoff

Owner upload reviewed locally and kept private. APT catalog completed then38
upgrades/25new/no removals/no held;147 kernel candidate,139 running and146
installed/default. SSD62,750,203,904B/HDD475,042,938,880B free,DPKG audit silent.
Six containers/live restore false;MariaDB root/no-password1045 gives no engine
proof. Mail global location confirmed;ISPConfig missing-or-link not disambiguated.
AppArmor accepted result in upload repeats prior evidence;no new repair requested.

Added no-upgrade root-private cold SSD filesystem file archive helper2d1b2502,
SHA bcbbd378eded04b2ff0103aa2cef5d38d54f6cbfef60df67f53067bcb7a6aadd.
HDD UUID/anchor/foreign fs/space and shared flock+POSIX APT guards;original
active timers/sockets/services and exact six containers gracefully stopped;
Docker policy snapshot restored,PG readiness then web/all services;failure
or SSD marker-write error still attempts resume. Source manifest stability,
real tar sparse/ACL/xattrs/hardlinks,readback checksum/protected hashes and
unchanged package baseline. Private/plain archive,logs/temp/swap/virtual mounts
excluded;not an atomic all-process disk image or tested bare-metal boot restore.
Systemd handoff survives SSH. Resume-services is runtime resumption,not file/DB
rollback. No unrelated cleanup or new app/role QA cycle.

17 local root tests PASS and exact Ubuntu24.04 root run38398856106/job115300978352
17/no skips PASS,log summary inspected. Context38398855981 PASS,review38398856037
source8.2/8.3 PASS;unchanged frontend audit failed/build+HTTP+DB+rollback+fullgate
skipped. No whole PR green. Source/public raw readback/tree matching PASS.
Capture/install/reboot server execution still pending. Current task INF-10-OS;
latest stable53/PG17+ isolated addon/recovery work follows OS.


## 2026-10-13 — host cold recovery configfs preflight correction

Owner first task exited1 at13:00:01UTC,marker `not_started`,error
`ADDITIONAL_PERSISTENT_FILESYSTEM_NOT_COVERED`. Diagnostic received16:42MSK
reports only `/sys/kernel/config`,configfs,UUID null. Exact source ordering puts
this refusal before any service stop,archive directory or transaction marker;
that no-pause conclusion is source inference,not a separate service audit.
No resume or AppArmor/Moodle replay is needed for this preflight refusal.

Implementation `cf78962183a98c49eb05241e5db5913cdda10471`,SHA `00b15bc193db34cd12e06f27be5d63c512c766428de9053c0186b374a6becb7b` adds only the observed kernel type/path
exception inside excluded `/sys`. Foreign mount types,locations and nested
persistent children still refuse. No mount or kernel object mutation. Original
17 tests plus five mount regressions pass locally as root and exact Ubuntu24.04
run38407989890/job115332541993:22/no skips;log summary inspected. Context38407990439
PASS;review38407988735 frontend audit failed on unchanged main dependencies,
source PHP8.2/8.3 succeeded;DB/rollback/full gate skipped,full PR green not claimed.
Public helper bytes/git blob/tree match local. New `-r2` transient systemd unit
avoids the retained failed first unit. Corrected server retry,accepted archive,
package installation and reboot remain pending owner execution. Prior AppArmor/
518/browser/cron/HDD acceptance closed;INF-10-OS remains active,53/PG17+ follows.


## 2026-10-13 — owner r2 service-stop refusal and successful runtime resume

Receipt received17:07:15MSK:installed00b15bc1 hash PASS,cf789621 helper,r2
invocation34da76b91415422b85110a89ee50854f. Marker failed_resumed,
SERVICE_UNCLEAN_STOP,services_resumed=true,14:05:16.445898..17.991866UTC;
unit exit1/failed/MainPID0. Source phase:disk guard passed,shared-service guard
failed before Docker policy/container stop or archive copy. Planned archive
path does not prove a copied payload. No packages/reboot executed. Failed
unit and its past stop properties unknown. Strict ExecMainStatus0 might
misclassify clean signal exit;this is an unverified hypothesis,not a waiver.
Next read-only PID1 journal metadata and current unit exit policy,without raw
messages/configuration/log text. No new download,capture,outage or runtime
resume. Current post-resume metadata must not be presented as past exit proof.
Helper/application bytes unchanged;no repeated tests needed. Prior22 exact
source fixture checks remain dated source evidence,not this server acceptance.
INF-10-OS remains active;AppArmor/Moodle518 acceptance closed,53/PG17+ after OS.


## 2026-10-13 — cron guard localization and native systemd shutdown coverage

Uploaded17:26:12MSK terminal metadata read fully. Stop jobs done and UNIT_SUCCESS
7ad2d189 for amavis/Apache/caddy/cron;source sequence localizes refusal to cron.
Past numeric exit/signal status absent;current14 units active/success and cron
simple/KillSignal15 are resume/policy evidence only. Raw upload remains private.
The previous guard demanded ExecMainStatus0 without ExecMainCode semantics.

Implementation `5d69566b19a0a85a597eafc79cda24b4851c2800`,SHA `944886527998f428a86c835817525cd1da92511348ccf276ebf0933ce1561c54`,local72047f0/tree661f7d80. Unit reads add
main exit code/PID/type/KillSignal;stop receipts admit exit0/no-main or configured
SIGTERM on daemon type only with inactive/dead/MainPID0/Resultsuccess. Hardkill,
core,timeout,OOM,nonzero exit,wrong state/main PID still refuse,including Docker/
containerd. Failures retain unit and selected metadata in SSD marker/public status;
finally service resume persists it. No raw environment/config/command emission.

29 local root tests PASS. Exact Ubuntu24.04 run38415153155/job115357515006 passes
29 file/model plus2 actual systemd sleep-only unit cases,no skips;both summaries
inspected. Real SIGTERM accepted;SIGKILL refused even with systemd success override.
No production services involved. Context38415153882 PASS;review38415153492 main
frontend audit failed/source PHP8.2/8.3 succeeded;DB/rollback/full gate skipped,full PR green not claimed.
Application/frontend/AppArmor helper bytes/maps unchanged. New-r3 handoff follows;
server r3/archive/install/reboot pending,accepted AppArmor/518 stay closed.


## 2026-10-14 — r3 resume-required incident and guarded runtime recovery fix

Owner01:09:16MSK installed94488652/5d69566b hash PASS,r3 invocationd13be599.
22:07:20.494589..25.039277UTC marker resume_required/CONTAINER_IDENTITY_DRIFT,
unit failed/exit1/PID0. Original runtime resumption not confirmed;copy phase not
reached by source. No package/reboot. Priority runtime recovery,not new capture.

Source `2766a1a9608d58ebf6f5ff28c5f2f06b90dbc389`,SHA `4bf199c14fcf69a39a2283ca93d87b304e42b881e22c16ccec1c0d5c710116b2` fixes a source bug in ordered mount-array
identity comparison. Complete records/duplicates and exact IDs/images remain
required;real differences block container operations. Failed recover now saves
only changed-field names in SSD marker/status. Actual incident cause unknown;
reordering is a supported hypothesis,not server evidence. Recovery starts only
original runtime/policies and performs no archive/file/DB restore or new stop.
34 local root fixtures and Ubuntu run38476723475/job115569100844 34 model/file
plus2 real systemd cases/no skips PASS;log summaries read. Context38476723506 and
source8.2/8.3 in38476723495 PASS;unchanged main frontend audit failure/full gate
skipped. Docker recovery simulated;native units are isolated sleep-only fixtures.
App/frontend/AppArmor bytes unchanged. Owner guarded resume execution pending;
backup/host install/reboot remain deferred until runtime recovered.


## 2026-10-14 02:17 MSK — cold manifest failure

**Current14Oct02:17MSK:** Owner r3 recovery completed at23:05:54UTC (services_resumed=true/unit exit0). Subsequent r4 reached selected_services_stopped, then FileNotFoundError before copy; finally restored services at23:08:40UTC. Live metadata-only scan completed;Docker root stopped at foreign-filesystem guard;actual cold missing path remains unknown. New source retains exact private failure path/operation/step and retries one complete initial manifest only on FileNotFoundError,never skips entries or retries post-copy verification.41 local tests PASS;exact Ubuntu CI and new server capture pending. No archive/package/reboot acceptance. See current host cold recovery contract/result.

Exact99b3e0dc/SHA962a75d0: local41/root Ubuntu41+2 no skips PASS(run38484018659/job115593190536);context38484018640 PASS. Current operator contract pins r5. Actual r4 path unknown;new server capture pending.


## 2026-10-14 02:32 MSK — excluded proc link resolution

**Current14Oct02:32MSK:** Owner r5 failed_resumed/FileNotFoundError at23:28:39..23:29:13UTC;services_resumed=true. Saved diagnosis identifies building_protected_manifest/resolve_link/errno2 under /proc,identical across both attempts. Old strict resolver follows proc magic links before exclusions;the same error class was reproduced locally with a real pipe FD. New component resolver stops at excluded trees before lookup,archives raw links,and retains persistent same-filesystem target checks.48 local tests PASS including real tar/link readback. Exact Ubuntu CI and server r6 pending. No host archive/install/reboot acceptance.

Exact22d8a4e1/SHA3f8f0c0e: Ubuntu48+2(no skips) run38485478610/job115597955732 and context38485478396 PASS. Updated operator r6 handoff parsed successfully;server capture pending.


## 2026-10-14 03:06 MSK — host cold archive accepted

**Current14Oct03:06MSK:** Owner r6 source22d8a4e1/SHA3f8f0c0e completed at00:04:07UTC:43,704,842,240B archive,readback SHA8de2742f verified,223777 protected members,services_resumed=true,package baseline unchanged. Readback with services running began23:54:39UTC;total21m56s. Cold capture acceptance CLOSED;do not repeat capture/resume. Packages/reboot remain unexecuted. Next native APT download-only/no-remove,review exact38+25 plan before installation;retain kernels139/146. Full host boot restore/external recovery unverified. AppArmor/Moodle518 acceptance remains closed;latest major follows OS.

Owner supplied status phase complete/readback true/223777 protected members,package_baseline_unchanged=true. Agent has no server connection and did not independently read archive. No full boot restore claim. Next operator native APT download-only uses cached signed catalog;download output is reviewed for63-package plan before a separate concrete installation handoff.


## 2026-10-14 03:14 MSK — package download mirror refusal

Owner native APT download-only/no-remove receipt:38+25/zero removals,no package name drift;49 visible versions match saved plan,14 cached versions not printed.6 Docker packages fetched,102MB in31s;ru.archive.ubuntu.com IPv6 network unreachable and IPv4 port80 timeout. No dpkg install/reboot. Next bounded central archive HTTPS probe and exact source-entry location,then guarded mirror change/retry. Cold archive r6 acceptance stays closed;do not repeat copy/resume or claim ForceIPv4 alone resolves observed IPv4 failure. Raw upload remains private.


## 2026-10-14 03:21 MSK — central mirror reachable

Owner IPv4 HTTPS archive.ubuntu.com/noble-updates/InRelease probe OK;matching source is ubuntu.sources line2. Prepared guarded exact regional-to-central URI replacement with unique root-private original backup;native signed/error-on-any catalog update precedes download-only/no-remove retry. ForceIPv4 is command-scoped. Bash syntax PASS;actual source edit/catalog/download/install/reboot pending. Cold archive acceptance unchanged and closed.


## 2026-10-14 03:32 MSK — cached package installation preparation

**Current14Oct03:32MSK:** Owner central mirror replacement and signed catalog/download completed:38 upgrades+25 new/zero removals;63 names match,43 visible retry versions match,6 Docker versions matched earlier,14 cached versions require native exact simulation. Backup source /root/ustar-apt-mirror-20261014.i9mk34/ubuntu.sources. Accepted r6 cold archive/services remain closed. New guarded cached package installer embeds63 versions,reuses hash-pinned accepted service control,and verifies cache/baseline/config/boot/runtime before reboot handoff.32 local root tests PASS;exact Ubuntu CI and server install/reboot pending. No repeated capture/resume or AppArmor/Moodle518 acceptance.

Root controller32 tests PASS including actual separate-process POSIX lock and sparse archive/private receipt fixtures;APT/shared service/boot commands are modeled. Source b6e1529d;dependency cold3f8f0c0e unchanged. Exact Ubuntu CI and operator delivery remain pending. No full host boot restore claim.


Installer review adds cleanup receipt-I/O failure handling:failed receipt write must not block resume or claim PASS.33 local root tests PASS;source c753cf2e exact Ubuntu CI pending. Previous438f3da2 root32(+48+2) and context CI passed;superseded before operator handoff.


## 2026-10-14 04:01 MSK — exact cached installer handoff

**Current14Oct04:01MSK:** Owner03:32 central mirror/catalog/download completed:38+25/no removals,63 names match. Cold archive r6 remains accepted. Exact cached installer d000e800/SHA c753cf2e is ready:33 local root and Ubuntu33+48+2/no skips PASS,all log summaries read;context CI PASS,published bytes/tree match. Existing accepted service controller3f8f0c0e is reused. Guards cover plan/cache/backup/baseline/config/boot,cleanup receipt-I/O and original runtime resume. Systemd launch/status handoff prepared. Actual installation and reboot pending;do not repeat capture or accepted AppArmor/Moodle518 work.

Ubuntu38493438210/job115623715462:33 installer+48 accepted-controller file/control+2 actual sleep-only systemd tests,zero skips,three summaries inspected. Context38493438435 PASS. Native package installation/maintainer scripts/Docker/shared service changes remain operator acceptance. Source byte readback matches;outer/inner shell syntax PASS. Root-private archive remains accepted;no full host boot recovery claim.


## 2026-10-14 04:18 MSK — host package installation accepted

**Current14Oct04:18MSK:** Owner exact d000e800/c753cf2e installation PASS:01:08:25..01:12:55UTC,63 versions verified,APT exit0,147 boot files verified,services resumed,container identities/policies and critical config bytes preserved. Unit success/exit0/PID0,active/exited is RemainAfterExit. Installation acceptance CLOSED;do not rerun installer/cold capture/resume. Next native reboot behind maintenance flock and selected kernel/service/HDD checks;actual reboot/boot acceptance pending. Existing console and139/146 fallback files retained;full host boot restore/external recovery unverified. Latest Moodle major follows host acceptance.

Owner invocation afcdde4bf53f460e810336e71a308e92,01:08:25.711288..01:12:55.066500UTC,duration269.355212s;status complete_reboot_pending and native unit success/0/PID0. No actual reboot yet. Prepared native flock/systemctl reboot and selected read-only boot/container/shared/HDD/cron metadata;bash syntax PASS. No new helper/configuration/policy write or repeat installer.


## 2026-10-14 04:41MSK — native host boot accepted;Moodle53 assets next

Owner terminal confirms Ubuntu24.04.5/kernel6.8.0-147 boot at01:33:42UTC,
Docker29.9.0,zero failed units,selected shared services healthy,AppArmor Docker
enforce,exact HDD mount/timer/no marker. Login303 without redirects then final200.
All six container IDs/policies match installation;four labs original restart=no,
exit0,no autostart expected. [Receipt](../runtime/host_reboot_result_20261014.json).
Installation/native boot acceptance closed in observed scope;no installer,capture,
resume or second reboot. Post-boot scheduled Moodle cron status remains to observe.
No authenticated browser/shared mail-DNS end-to-end/full host restore inferred.

Next PLAT-01 [nonactivating preparation](../runtime/moodle53_preparation_20261014.md):
official5.3.0 LTS/core2026100500.00/SHA7e5edf11 and official17.11-trixie/amd64
tag verified on14Oct;PHP8.3.33 meets minimum. Native status+download/pull only,
new private directory,archive hash/OS-architecture/image digest receipts before
isolated logical PG16->17 restore and full-addon upgrade/repeat/paired recovery.
Actual asset download/digest/5.3 integration and production migration pending.
Application baseline526 files/93 tables/32 tasks unchanged. Host docs PR100 only;
no merge,production activation or new shared pause. Current state/entrypoints
updated;older dated checkpoints retained as history.


## 2026-10-14 12:33MSK — passed route quiz still pending;focused diagnosis

Both supplied screenshots opened from scratch copies. Material6 complete;
assessment7 numeric23.5/25 over17.5,1/3 attempts,stillpending sync;8 locked and
noContinue,progress6/16. [Triage](../runtime/route_refresh_triage_20261014.md)
records observed UI separately from actual DB/POST cause. Current-main
route.php/model blobs match local source;POSTsync writer exists andGET remains
read-only. Server cause unknown;bounded nativePython/psql read-only diagnostic
prepared,without PHP bootstrap,credentials/personnel/log output or data mutation.
No speculative fix/test release claimed. Prior host boot accepted;prepared53
asset caching was not activated when this bug became current priority.


## 2026-10-14 13:29MSK — later DB sample confirms saved version3 pass/progress

[Aggregate receipt](../runtime/route_refresh_db_result_20261014.json) preserves
owner20-row read-only sample without employee/attempt identifiers:10 learners,
old version2 empty runtime/progress;version3 has8 passed/complete/Moodlecompletion2,
including both23.5 scores. One zero/null result each remains incomplete;no pass
is forced. Previous name-filtered query selected nothing;it is not a persistence
failure. Current effective version/expiry and fresh browser render remain open.
No speculative code/data fix;fresh reload then focused version/expiry inspection
only if symptom persists. Prior native host boot acceptance stays closed.


## 2026-10-14 — владелец вернул приоритет переходу Moodle

Владелец явно отложил BUG-ROUTE-REFRESH и поручил завершить третий пункт подготовки
перехода Moodle. PLAT-01 снова текущая задача. Приёмка пакетов, native boot ядра147,
Docker29.9.0 и наблюдённых служб остаётся закрытой. Повторная остановка/копия/установка
ОС/перезагрузка не требуется. Диагностика кнопки сохранена как deferred;исправление
и изменение оценок/прогресса не выполнялись.

Проверенный следующий шаг — native download-only:официальный Moodle5.3.0 LTS
с SHA2567e5edf11 и образ PostgreSQL17.11-trixie/linux-amd64 с записью фактического
RepoDigest;один read-only status существующего cronv3. Официальные требования,
checksum и image definition повторно сверены14Oct. Bash syntax и context checks
проверяются в этой поставке;серверная загрузка/стенд/миграция/совместимость/активация
ещё не выполнены. Команды и границы:context/runtime/moodle53_preparation_20261014.md.


<!-- Imported PR102 7f29c9367aed623d68ddc9bdb79c701f6dfba9f6 -->

## 2026-10-14 — OPS-02 one-hour restore experiment, PR102

Owner19:05MSK requested latest backup restore, hour runtime and portable clean-host instructions. Fresh main c53723c8 base, no application changes. Controller/source cdf69bab822b1ecbc352018cb56f542f992e0db5 reuses exact private engine/verifier, uses independent loopback-only containers and systemd cleanup, retains data. Root33 tests and selected CI38601259566 passed; private synthetic compatibility checked. General review gate failed unchanged frontend npm audit; not full RC. [Runbook](../runtime/backup_restore_experiment_20261014.md), [validation](../runtime/backup_restore_test_validation_20261014.json). Native owner archive/DB/browser/hour/off-host pending; no direct SSH. Host/5.1.8 owner acceptance retained, Moodle53 PR101 next, route defect deferred.


## 2026-10-15 05:47MSK — owner reprioritized INF-06

Owner explicitly says one-hour restore not executed; defer until unified server/Academy backend control panel. Metrics, alerts, backups, remote access and clear usable UI are priorities. [Scope](../runtime/control_panel_scope_20261015.md) defines views, collectors, operations/auth boundaries, independent observer and acceptance. Existing backup/cron schemas inspected; no live-server commands or messages sent. Separate host/domain not specified. OPS-02 deferred; Moodle53 preparation retained.


## 2026-10-15 — finish OPS-02 transfer handoff after interrupted agent

Owner13:58:19MSK explicitly deferred the panel and requested duration in the script plus four actions: sudo pack → WinSCP → unpack clean server → start without timer. Shared conversation6ad0c421-5554-83ed-b6d0-b36e78fef38a read; existing code50dfcb7/e68c887a inspected rather than recreated. Source e68c887a contains chosen controller/relay/watchdog lifetime, private self-contained tar+checksum with recovery key, clean Ubuntu dependency bootstrap and generated units.59 root tests repeated locally PASS/no skips; selected Ubuntu24.04 CI38726287776 logs show36+23 and native systemd parsing PASS; context38726287799 SUCCESS. General gate38726287787 failed unchanged Next/sharp npm audit; PHP8.2/8.3 passed, DB/rollback/full RC skipped.

[Four-stage runbook](../runtime/backup_transfer_20261015.md) and [validation](../runtime/backup_transfer_validation_20261015.json) finish the missing publication. Source/production/owner acceptance remain distinct; no archive/key/PII published. Current pointers now OPS-02 and panel deferred; earlier06Oct/05:47MSK entries are history. Implementation bytes are unchanged from e68c887a. Native server1 restore, copied owner archive clean-host restore/browser/lifetime acceptance remain pending; no direct SSH, merge, deployment or public network exposure. Moodle518/host update accepted earlier by owner; do not repeat; PR101 and route defect stay as previously scoped.


## 2026-10-15 — публикация исправления главной восстановленной копии PR102

Архив владельца `ustar-pr102-handoff.zip`/MANIFEST и bundle проверены; база GitHubd7d68f83 совпала. Оригинальные коммиты45e73688/274a2815 локально применены fast-forward;68 root tests без skips и context/source-pin/Bash checks PASS. Native Docker/DB/apt/время не запускались, production не затрагивался.

После отказа обычного git push из-за отсутствия credentials владелец явно разрешил новые SHA и обновление ссылок. Код/тесты из bundle опубликованы через GitHub API без изменения байтов: `1b8bfdd566eb0759f8c0b6faa8078eb27564fcb0`, SHA256контроллера4804c27a/launcher6633ba33. Все действующие source pointers и download URL заменены на опубликованный commit; прежний CI сохранён как prior evidence, новые результаты фиксируются отдельно. PR102 остаётся draft/open, main не меняется. Исправленный native стенд/браузер/off-host pending; пакетod28adph содержит прежний контроллер и для следующего переноса пересобирается.

Проверка опубликованного code SHA: Ubuntu24.04/root selected CI38764190030/job116522997784 SUCCESS,45+23, logs inspected; context38764190057 SUCCESS. Общий frontend npm audit38764190021 снова падает на прежних Next/sharp; полноту application RC не заявляем.


## 2026-10-15 — реальный production перенос вместо изолированного --start

Owner19:02MSK подтвердил, что требуется рабочая Академия на чистом сервере. PR102 дополнен отдельным permanent worker: последний финальный snapshot после cron-lock и maintenance, source web freeze/restart=no с явной отменой; комплектv2 включает pinned worker. Target --start-production требует URL/source-stopped, пустой Docker и новые каталоги, сохраняет данные/тему/главную/salts/flags/tasks queue, заменяет URL штатным CLI, не обновляет core. Восстановлены boot/unless-stopped и один minute cron; остановка main останавливает timer/cron. Снятие maintenance использует Moodle set_config для инвалидации config cache и оставляет последующее штатное управление maintenance. При ошибке source backup сначала выполняется SSD marker recovery; журналы закрыты, данные не удаляются. Source exporter в инструкции запускается через systemd, зависит только от sudo и установленных pinned helpers.

Код 99ab241e1771b850bfedb3a5cab03ad4a2122917. Локально73 backup/lab tests PASS и19 production tests (2 native checks skipped: local PHP/loopback unavailable); CI Ubuntu выполняет эти проверки реально. Начальный source CI38768367897 PASS без skips92, включая PHP config evaluation, loopback HTTP и systemd parser; итоговый SHA проверяется отдельно в validation. Docker/PG/Moodle dump/source freeze/reboot/SMTP остаются modeled/not native. Не заявлять production acceptance, не выполнять server1 commands от агента. [Инструкция в четыре этапа](../runtime/production_transfer_20261015.md) заменяет прежний смысл переноса, а не превращает --duration0 в production.

Итоговый source99ab241e CI38769562812/job116541513212 PASS:45+28+19=92,0skips; native PHP config, loopback HTTP и parser lab/import/permanent units; logs inspected. Context38769563102 PASS. Native Docker/PG/полный Moodle import/reboot/SMTP по-прежнему не выполнялись.


## 2026-10-15 — постоянная копия для сисадмина без остановки source

Owner19:48:48MSK requested full production copy handoff without stopping the working Academy. Existing --pack exports only the last completed authenticated HDD archive; no new capture, Moodle bootstrap, service or container operations. Added snapshot_completed_utc to package metadata/output. New --start-production-copy dispatches the permanent importer with --parallel-copy, a distinct URL, mail/PHP-sendmail/cron off, preserved database task flags and data, boot startup without cron dependency. Manual cron invocation refuses copy state before Docker commands. Normal production cutover still requires source-stopped; flags cannot be mixed. Timed lab remains separate.

Codea1a0c62b CI38773052825/job116553564286 PASS:45+32+24=101,0skips; native PHP config in both modes, loopback HTTP, parser production/copy/import units, logs inspected. Local101 PASS with PHP+loopback environment skips2. Preparation38485343 pins3 verified helpers and submits --pack via low-priority systemd job. No source host access or native new package/import/reboot was performed. The sysadmin guide replaces the current handoff; panel/Moodle53 remain deferred. Snapshot age is explicit; later production writes are not automatically copied.


## 2026-10-16 — repository reconciliation and next Telegram panel

Owner00:20:51MSK confirms archive obtained/instruction passed to sysadmin; native clean-host result awaited. Moodle5.2/PostgreSQL17 explicitly deferred, previous5.3 rehearsal retained as history. Next INF-06 is same-host Linear-inspired panel with auth, metrics/alerts, labs/SFTP, manual operations and Telegram latest encrypted backup/control.

Consolidation brings accepted PR98/99/100/102 sources and all dated receipts into canonical main without application526/schema93 changes. Legacy Next frontend removed from required workflow graph/manifest/archive; native PHP/JS/Mustache/browser checks and DB/rollback gate retained. CLI test umask isolated and optional package fixture made explicit; production helper guards unchanged. All former entrypoint snapshots and branch heads retained. Full audit residuals and current evidence are in AUDIT_RECONCILIATION_20261016_RU.md; Git/CI publication is not a native host operation or a restore acceptance.

Полный RC теперь определяется сохранёнными метками PR на каждом synchronize/reopened, а не только событием первоначального labeled: новый коммит не может незаметно пропустить DB/rollback после добавления full-rc. Pre-merge main зафиксирован как c53723c8; исторический application baseline e1d57f5b остаётся отдельным.


## 2026-10-17 — review attachments, safe server facts and canonical context

Owner requested fable first, all eight submitted files, script/path audit and fresh context before owner repeat review and continuation of repo/panel/Telegram. Read originals including PDF; source submissions and SHA256 preserved under reviews/20261017, unsafe shell/YAML proposals stored as non-executable text. Confirmed nested public/public, PG role/database and invalid GROUP BY assumptions, cookie/query/config/log disclosure risks, unbounded work, importer error deletion/redaction/checksum drift and guard false positives. Replaced with bounded stdlib collector, closed schemas, private export/handoff, immutable validated import, rollback/freshness checks and separate526 source comparison. No bootstrap/HTTP/raw SQL/log export or service operations.

Verified original full discovery545 with19 environment skips and real MCP stdio; CI failure inferred from an unrelated MCP2 sandbox is not reproduced. CI now uses isolated pinned venv and retains the full suite. New full suite572:553 passed/19 existing environment skips,27 new facts tests. Real import CLI into separate temporary checkout passed current/index/context/secret gates, preserved production receipts/tasks, and kept unavailable source comparison unknown. Synthetic snapshot was not added to working context. Validation details: reviews/20261017/validation.json.

START_HERE is the sole reading order; STATE/BACKLOG generate current/checkpoint. Old checkpoint preserved. Source-derived theme tokens/layer order/capability references, selected grades/routes/evidence/work/mobile scenarios and explicit coverage gaps added. PR103/head98559017 auth/read-only implementation and graph prototype acknowledged as open/not deployed; latest three CI runs successful. Fresh server1 report and sysadmin restore still awaited. Privacy and platform deferrals preserved; Jan15 planning recommendation is not an owner migration order. No server1/Telegram operations.


## 2026-10-17 — first owner collection partial; Docker/DPKG collector compatibility

Owner04:01MSK supplied successful script SHA check,31 terminal block statuses and REPORT path:17ok/13error/SMART not requested. No facts/errors/manifest archive supplied yet; recorded separate server_facts_terminal receipt, not a production snapshot. Containers/packages are independent failures;11 following probes depend on containers/code_layout. PHP/global PG command success does not establish app/backup/cron operational health.

Reproduced strict Docker inspect raw fallback failure on optional Health and null log Config with actual Go text/template. Replaced optional lookups with index/with; no Env/Health.Log/custom log output. Dedicated Debian package-version pattern accepts epoch/tilde, installed status is explicit, optional missing package exit1 tolerated, fatal2/empty installed set remain unavailable. Six new regressions; full578 suite559passed/19existing environment skips; focused33/0skips includes actual Go templates and JSON numeric semantics. Sources and validation in reviews/20261017/collector_compatibility*. Corrected pinned collector prepared for repeat run in a new private directory, original report untouched. Actual root causes in this owner run await archive; no server configuration/service or Telegram operations performed.


## 2026-10-17 — two actual server1 snapshots imported; repeat-audit context ready

Owner04:16MSK supplied both ZIP folders. Validated exact member paths/CRC/size/type, closed payload schemas/checksums and byte equality with nested report.tar.gz. Collector hashes match published cee34b13/1bdabba3. Imported both unchanged in timestamp order:01:00:21Z17ok/13error and01:12:27Z28ok/2error, SMART excluded. Latest526/526 plugin/theme files match e1d57f5b, no changed/missing/extra/skipped; new last_exact_source_manifest bound to immutable facts/comparison, dated05Oct evidence preserved.

Confirmed DB role moodle superuser/privileges and runtime write access to plugin/config0755, selected unbounded containers/no healthchecks/per-container log options, UFW inactive/global SSH settings and all-address2019 listener requiring process/external verification. No assumption that2019 is proven publicly reachable Caddy API or443/cert proves Academy TLS. Latest17Oct03:31MSK backup success/readback reported and archive stat matches; cron04:12MSK worker0/new errors0 but aggregate failed2/overdue18 retained, active/disabled detail unknown. Full DR/ACL/core/schema/performance scopes remain open.

pg_stat_statements absent from application DB extensions explains unavailable query stats; no installation/restart. Fixed numeric scheduled flag projection and timedatectl separate-property selection; old reports immutable. Isolated PGlite PostgreSQL18.6/WASM reproduced int2 cast42846 and verified new query for int2/int4; production16 column type/new SQL results not independently probed. Clock source showed unknown/null despite ok; empty response now rejected, actual new host clock still unknown. Full580 suite561passed/19environment skips, real MCP and Go templates; archive/import/source/context/secret gates passed. Runtime reconciliation/coverage/backlog/current handoff ready for owner repeat audit before repo/panel/Telegram continuation. No production mutations or agent SSH.


## 2026-10-17 — third owner report: scheduled/clock/SMART observations

Owner04:41MSK supplied report.tar.gz plus separate PG/NTP console lines. Bounded tar/gzip/exact members/schema/checksums passed; 36,240bytes archive SHAa33aabd5 and collector SHA4abad3b6 match pinned4c524a52. Imported original payload as01:39:24Z snapshot285497b0,30ok/1error; source526/526 still matches e1d57f5b. Old snapshots/errors and prior validation remain unchanged. Console supplement is explicitly outside archive checksums, not independently reexecuted.

Scheduled147:15 disabled/132 DB-flag enabled; both positive faildelay rows are disabled Moodle.org registration/H5P (60/960s), enabled faildelay0.18 overdue DB-flag-enabled rows all last_run0; upstream scheduler also checks component enablement/overrides, actual eligibility unknown. Cron01:39:03Z worker0/new failures0/USTAR errors0/adhoc0. Clock Etc/UTC,NTPfalse,NTPSynchronizedtrue; both supplemental client commands unavailable, no server/offset/ongoing-client conclusion. SMART sda/sdb healthtrue/exit0 at50/47°C; attributes/wear/self-test history/historical HDD198 residual not rechecked. Same03:31MSK backup receipt/stat, no new capture/rehash/restore.

Only archive error remains pg_query_stats. Owner console: extension available, not installed, empty shared_preload_libraries, compute_query_id auto, track null, pending_restart false. No extension/config/restart/task/NTP changes. STATE/ACTIVE/BACKLOG/coverage/current/index and runtime reconciliation updated for repeat owner audit; INF-06/PR103 and platform/Privacy deferrals preserved. Full r16 suite580/561pass/19existing skips, real MCP stdio and Go templates; context/source/schema/secret gates pass. Application/core/DB schema unchanged, no agent SSH or Telegram delivery.


## 2026-10-17 — targeted core/NTP/SMART/component follow-up

Owner13:05:42MSK supplied core/extra ZIP folders and terminal transcript. Validated safe bounded ZIP members/CRC, every checksum, core nested tar equality and exact original report bytes. Core collector hash898667dd matches bddd8b94 pinned source. Official Moodle5.1.8/cc0bf17d verified;24742 matching paths exactly cover eligible reference tree, changed/missing/errors/skipped0, extras23 and exit3 retained. Extra names equal05Oct set, including public config/index backups and legacy handlers/reports requiring access/origin review. Uploaded Git config/hooks/scripts not executed; only pack/idx objects read in a fresh trusted bare repo. Whole deployment and excluded plugin/config/vendor provenance remain separate.

Extra33 probe files checksum-verified; allowlisted derivative keeps NTP selected public peer, all27SSD/17HDD attributes, ATA errors/self-test history and DB flags. Serial/WWN/nonselected NTP peers/raw private outputs not published; originals unchanged. SMART09:59:14Z: healthtrue at50/49C, exit64 both is only bit6/error-log records. HDD5/196/197/198/199 raw0; failed long test16359h and newer successful16385h preserved, no new test. SSD model outside smartctl DB, vendor wear/TBW units unconfirmed. Ntpsec active/running/enabled, selected194.58.206.20 offset-0.2024ms/jitter0.1690ms, reach377octal; timedated NTPfalse/syncedtrue coexists, no long-term SLO or host-stratum inference. ACL probes failed because getfacl absent, no ACL conclusion. Empty PG preload reconfirmed; no module activation/restart.

Later DB flags and30 official source files whose blob hashes match reference tree explain all18 earlier overdue records as expected component skips if effective settings match DB. Every class directly inherits scheduled_task/no run-if-disabled override; effective runtime config/cache/actual skip and full cron/SMTP acceptance not executed. Observation times remain separate/non-atomic. Previous three31-block snapshots/526 plugin manifest, backup/restore receipts and dated audit immutable; STATE/BACKLOG/coverage/current/index and entrypoints updated for owner repeat audit before repo/panel/Telegram. Native SSH/TLS/ACL/DR/RTO and existing infrastructure tasks remain open; platform/Privacy deferrals preserved.

Verification: archive/source/byte-equality/changed-doc links,15 historical payloads, generated current/index, source526/schema93/harness33, secret and whitespace gates pass. Full local r16 discovery580 cases;577 ran successfully in4.915s;19 existing test environment skips plus one Go class setup skip (three cases not run locally because Go absent), real MCP stdio passed in pinned mcp1.30.0/PyYAML6.0.3 venv. CI requires native Go fixture; CI result is recorded separately in PR. No application/schema changes, production mutations, agent SSH or Telegram delivery. [Detailed review](../reviews/20261017/core_extra_reconciliation.md), [validation](../reviews/20261017/core_extra_validation.json).


## 2026-10-17 — owner ACL/SSH/Caddy/extras follow-up

Owner14:03:05MSK supplied gaps ZIP and apt/collection terminal receipt. Safe bounded ZIP26 members/284182bytes/SHA86133045, CRC and all24 hashes validated; six probes exit0/empty stderr, explicit timestamps10:59:16Z at1s resolution. No uploaded code executed. Inline source has no archived self-hash; no pinned-collector identity assertion. Raw6.4MB ACL remains private, structured derivative with original file hashes/provenance published; owner original unchanged.

ACL44175 unique code objects plus seven parent/data anchors; named/default/mask entries0, owners39449root:root/4726uid33:gid33; all4726 owner-writable, group-write439/world-write0. Outer code root/config/plugin/parent writable by33 implies POSIX directory-entry replacement risk, subject to mounts/LSM/immutable/actual actor; no negative write/rename tests or hardening performed. Nested root-owned theme/public does not prove parent replacement protection.23 prior extra names all hashed/metadata collected, every permission/owner agrees with ACL; config backup/source/helpers/PDFs still need content origin and HTTP access review.

Current aduk SSH tuple settings observed: password/pubkey yes, kbdinteractive no, TCP/agent/X11 forwarding yes, UseDNSno, root without-password. Input IP tuple intentionally not exported; loaded-daemon overrides/all-context admission/login not accepted. Both2019 sockets127.0.0.1 andall-address have comm caddy/PID862; executable/API identity/auth/family/external reachability not accepted. Four selected du allocated values captured, not total SSD/Docker-containerd inventory. Owner separately installed acl2.3.2-1build1.1 (new1/upgraded0/removed0); needrestart deferred list not a restart instruction. Package installation is a host change, collector reads only; agent performed no host operations.

Updated STATE/BACKLOG/coverage/entrypoints/current/index preserve24 earlier dated payloads and plugin526/core24742/NTP/SMART/backup observation times. Repeat owner audit precedes repo/panel/Telegram; full ACL hardening, external2019/TLS, broader SSH/runtime, query stats, full storage/vendor provenance and restore/RTO remain open. Local580 discovered/577run successful5.819s;19 existing individual skips plus one Go class setup skip, real MCP stdio passed; Go fixture required in CI. Source526/schema93/harness33, generated views/index, checksum/shape/permission crosschecks, links/secrets/whitespace pass. [Review](../reviews/20261017/gaps_reconciliation.md), [validation](../reviews/20261017/gaps_validation.json).


## 2026-10-17 — external network terminal follow-up14:24MSK

[Separate redacted owner receipt](../runtime/external_network_result_20261017.json): server1 ipify IPv4 egress equals home Windows TCP2019 target; TcpTestSucceeded=False and separate ICMP timeout. Exact probe times not supplied. One home source/path only; inbound NAT mapping, other sources/IPv6, API identity/auth and listener hardening not verified. Owner LAN HTTP access is recorded as reported, not TLS/cookie acceptance. Network coordinates remain in private dialogue. No archive checksum/authentication claim for pasted output; earlier dated payloads unchanged. STATE/INF-10/coverage/entrypoints/current/index updated. This is a context-only follow-up; application/schema/source baseline and production configuration unchanged. Validation/CI results recorded in PR105.


## 2026-10-17 — console audit follow-up after owner16:30MSK authorization

PR103 previous head98559017 had readable UI/charts but no post-audit implementation changes. Integrated current main/reviewed PR105 context02fd394f locally, preserved application526/schema93 and immutable runtime payloads. Added existing cron v3 receipt reader with durable private last observed success; worker exit/task failures separate, historical faildelay/overdue counts not new failures. Current SSD/HDD capacity/inode checks, per-check/source receipt timestamps, no duplicate REQUIRED checks, true HTTP error codes, first-screen cron/backup/disk/HTTP cards and rebuilt offline prototype. Corporate-LAN isolation is owner-reported; independent observer needs a separate reachable LAN/VPN node, not assumed public publication. [Traceability and acceptance limits](../../server-console/AUDIT_FOLLOWUP_20261017.md). Validation/CI recorded in PR103; no agent host changes, backup/restore or Telegram delivery.


## 2026-10-17 — first corporate-LAN console rollout preparation

Owner17:03MSK authorized finish/install;17:09 selected internal corporate LAN, later continue preserves task. Added native explicit-IP HTTPS/local CA/setup, fixed read-only PG aggregate monitor, private bounded15d history, separate SMART15min/NTP, RFC6238 TOTP/replay protection, durable incident/outbox/Telegram numeric private user+chat allowlists/retry/offset, operator credential+session-bound encrypted archive FD streaming, fixed root broker jobs with pinned helpers/plans/revision/locks/idempotency/systemd persistence and standalone observer bundle for a different LAN node. Academy/core/plugin/theme,102 previous runtime payloads and production services unchanged by agent. No agent SSH, delivery/backup/restore/restart/install was performed. Native receipts and remaining p95/logs/SFTP/restore/TTL/admin UI gates explicit in [LAN rollout](../../server-console/LAN_ROLLOUT_20261017.md); local/CI verification on published SHA recorded in PR103.

## 2026-10-17 — owner installed console; limited gate and source failures received23:36MSK

Owner installed verified CI artifact567e90d54d4e50acf43088a8127f80ae5c89740f (tar SHA c5a1c5de0e1709a1216b781517390ab08ac65d9b0d34c9475d437dbe1ce98631). After password retry and malformed Cyrillic input in setup, ASCII `no` skipped TOTP; Telegram token was also skipped. [Sanitized terminal receipt](../runtime/console_native_install_20261017.json) preserves exact20 checks,4 unit states and source_stale=false/native_accepted=true. TLS with local CA, web/broker/collector timer and identity isolation passed the limited installer gate. HDD critical/probe_failed and SMART no_data/probe_failed remain unresolved; ProtectSystem=strict versus same-namespace mount rw check is a source-based hypothesis, not a measured host fault. SMART timer/receipt and host mount need read diagnostics. Notifier inactive is consistent with skipped Telegram; rules/resource_rules remain not configured without worker. Browser trust/login/role denial, download hash, receiver ACK, independent observer, jobs/concurrency/overhead and restore/RTO are not accepted. Exact probe time absent; receipt time is message receipt, not fabricated measurement. No raw secrets/network coordinates exported, no agent SSH/host action, no modification of earlier evidence or installed code. STATE/ACTIVE/BACKLOG/project/entrypoints/current/index updated; INF-06 remains in_progress.

## 2026-10-18 — Telegram startup fix and private home-access runbook

Owner17Oct23:59MSK reports console opens in browser and requests Telegram/home access without inbound port publication; [receipt](../runtime/console_browser_open_20261017.json) does not infer CA trust/login/role acceptance. During connector preparation found notifier main called Telegram.getWebhookInfo without mandatory data argument. Added worker-entrypoint regression: old code TypeError reproduced; fixed explicit empty payload, full first iteration/status publication succeeds.111 backend tests PASS8.57s; no production Telegram API calls or server changes by agent. Prepared exact-module SHA94903be5412a0e48d21c8154afcda9cf8880b5bd670c0e83400b2df95d8ecd39 for owner hotfix; installed base567e90d5 is unchanged until owner applies it. [Remote access runbook](../../server-console/REMOTE_ACCESS.md) uses dedicated BotFather token/numeric private ID/polling; Tailscale single-host route with specific8443 grant is a proposal, no network installation claimed. Official Telegram/Tailscale docs checked; outbound reachability remains required. TOTP remains skipped; HDD/SMART diagnostics and receiver/observer/restore gates open. Existing dated runtime payloads unchanged; CI recorded separately in PR103.

## 2026-10-18 — native HDD namespace evidence, SMART partial telemetry and guarded update

Owner00:14MSK supplied [separate terminal receipt](../runtime/console_storage_probe_20261018.json): same HDD UUID/host rw/service ro, successful SMART oneshot0/active waiting timer; SSD PASSED50°C with197/199 absent, HDD PASSED48°C/selected counters0. Timestamp SMART20:50:22UTC preserved; other probes lack time and are not ordered against that reading. Earlier install/browser receipts immutable. Fixed mount validation via PID1 read-only mountinfo plus local UUID/device agreement, retaining service read-only protection and real host-ro refusal. SMART missing/stale/future/invalid reasons separate; partial attributes warning retains known readings, no fake zero/vendor wear. UI per-disk freshness preserves healthy device data when another is unavailable; prototype rebuilt. New regressions reproduced old failures;125 backend PASS, UI build/prototype/bash/compile checked. Native CI extended with disposable loop ext4 and actual restricted service, plus updater active-job refusal/rollback/local-patch/auth/CA/state preservation. New wheel-only updater copies old venv, leaves production app/config/units untouched and restores previous active console states on failure. New artifact/native source receipt and Telegram/VPN/role/download/observer/restore remain pending. No agent SSH or production mutations. Exact CI/artifact evidence recorded in PR103.

Owner00:27MSK steered active work with [Telegram ID helper traceback](../runtime/console_telegram_transport_20261018.json): local token format accepted, getUpdates transport_unavailable; ID/persistent configuration/delivery not confirmed. No token/nonce exported. Requested owner date/DNS/direct curl IPv4+IPv6 without credentials; cause (connectivity/TLS/proxy/response) remains unresolved. Storage implementation continues while waiting; no implied cancellation of Telegram/VPN objective.

## 2026-10-18 — verified storage release and direct Telegram TCP timeout

[Release3305ecc9 evidence](../runtime/console_storage_release_20261018.json): console39071433982/context39071433983/review39071434081 all PASS,125 backend3.58s/45 Chromium1.5m, real PG16 denials/native host-rw-local-ro/write denial/host-ro refusal and updater queued/running refusal/rollback/local-patch/auth/CA/config/notifier preservation PASS. Artifact12114394512 downloaded, ZIP SHA1ff935e2c59f4dbb2dd8defeaa7fdb1a4e7dfca8f386c5c6a5af5fb56fd1a497 and inner tar176987bytes SHA46b88e0e272b071357dfc62a34094f2ca51b79e63f0d4c4ece52d178612b4dc5 match; CRC/member paths/no links/SOURCE_COMMIT/wheel/updater checked. Delivered archive/checksum; owner update/fresh server1 receipt not received. Subsequent context-only heads are not executable release artifacts.

Owner01:26MSK supplied [tokenless date/DNS/direct curl](../runtime/console_telegram_network_20261018.json), printed date17Oct22:26:10UTC: IPv4 resolution returned a public Telegram endpoint, direct TCP443 connect timed out after5002ms (curl28/HTTP000); TLS/API authentication was not reached, printed TLS0 is not verification proof. Curl6 exit6 obtained no usable address, not proof of authoritative AAAA absence or global IPv6 failure. Individual command timestamps absent; exact firewall/perimeter/provider/DNS/proxy cause not established. Next compare general HTTPS and same endpoint from corporate Windows. Token/ID/persistent configuration/receiver ACK and home VPN remain unaccepted. No credentials/server coordinates/login/password transcript exported; no agent SSH/network changes. Existing dated receipts unchanged; context-only update.


## 2026-10-18 — Telegram comparison and matching public DNS answers

[Owner01:37/01:41MSK receipt](../runtime/console_telegram_egress_20261018.json): server direct HTTPS ipify/GitHub200, Telegram curl28/8002ms HTTP000; selected route exists but gateway delivery is not proven. Uploaded Windows screenshot independently viewed: same public endpoint TCP443False; account/raw image not exported, hash retained. Google/Cloudflare HTTPS A answers both149.154.166.110, TTL206/30, Status0/ADfalse; match local DNS, no other RR types or global outage proof. Prior pasted date22:26:10UTC belongs to the earlier probe and is not a timestamp for these later tests. Need available permitted outbound access/corporate proxy or owner-controlled remote endpoint; none supplied yet. Agent did not change server/network/TLS, authenticate token/ID or accept delivery/home VPN. Prior dated receipts unchanged; release3305ecc9 remains the delivered verified executable, no new owner deployment receipt. Context-only consolidation.


## 2026-10-18 — safe local Happ/NOVAVPS format discovery

Owner selected existing paid VPN in Happ and01:48MSK supplied only masked HTTPS novavps.app/s/ shape. [Receipt](../runtime/console_vpn_subscription_20261018.json) does not infer a raw protocol/provider export contract; full URL/config absent. Public root/search did not establish provider documentation, no placeholder or third-party subscription accessed. Added stdlib standalone probe/tools and22 focused privacy/origin/redirect/error/format/limits cases; full147 backend PASS5.81s, compile/whitespace checked. Hidden URL input only on owner server, bounded same-origin HTTPS GET with CA verification, fixed enum/count metadata without node/query values or raw errors; no subscription save, root/runtime/network changes. Unknown/HTML/encrypted response explicitly not compatibility/connection acceptance. [Runbook](../../server-console/VPN_SUBSCRIPTION_PROBE.md), exact published command/hash in PR103 and dialogue. Owner probe result needed before compatible client/loopback Bot API proxy. Telegram ID/config/receiver and private home route still open; verified executable package3305ecc9 remains pending owner update. Prior receipts unchanged.


## 2026-10-18 — INF-06: исправлен интерактивный запуск VPN probe

Владелец получил checksum OK без запроса ссылки/JSON. В локальном controlling PTY прежний
обычный timeout из bash heredoc не показал prompt и завершился124; прямой Python и контрольный
timeout --foreground показали prompt, приняли скрытый искусственный invalid input и вернули
санитизированный invalid_subscription_URL. Сети/реальных секретов тест не использовал.
[Receipt](../runtime/console_vpn_probe_tty_20261018.json) сохраняет неизвестные server elapsed/exit.
Runbook/owner команда исправлены: без внешнего timeout, PROBE_EXIT печатается и при ошибке;
скрипт/hash не меняются, внутренний30s начинается после ввода. Исходный4b70c936 прошёл все3 CI.
Owner retry/формат подписки/proxy/Telegram/home access пока не приняты. Никаких host operations.


## 2026-10-18 — INF-06: формат подписки получен, Telegram-only Xray подготовлен

Owner02:07MSK: checksum OK, hidden input работает, exit0; JSON35 outbounds/15VLESS/10gRPC5TCP/14Reality1TLS/insecure0.
[Receipt](../runtime/console_vpn_format_20261018.json) сохраняет точные counts; URL/node values не получены,
configuration_installed=false и node_values_validated=false. Формат совместим с выбранным клиентом, соединение не принято.
Подготовлены pinned Xrayv26.3.27 SHA ZIP/binary, строгий client-only importer, public IPv4 pin, DynamicUser/LoadCredential,
loopback18081/default blackhole/разрешение только Telegram443; временная проверка и cleanup при неуспехе.
Бот получил явный CONNECT transport с проверкой CA/hostname без env proxy/bypass/redirect; proxy-aware wizard
перезапускает notifier для reload credentials, private-ID challenge вводит token только локально. Tools включены в artifact.
Локально175 backend PASS6.70s (28 новых focused), реальные4 TCP/gRPC/Reality/TLS configs проходят официальный Xray,
реальный local CONNECT принимает trusted Telegram cert и отвергает unknown CA/wrong hostname при NO_PROXY=*.
Native systemd/Xray routing fixture добавлен в CI; здесь systemd недоступен. Build/current CI/artifact проверяются отдельно.
Owner update/proxy install/ID/receiver ACK и домашний маршрут ожидаются; server1 не менялся агентом.

CI83b2e829 выявил отличие fixture UID: unprivileged runner владеет tmp config directory, mock geteuid0 не менял её owner. Исправлен только test stat fixture; production root ownership guard сохранён.Запуск от nobody локально недоступен (container cannot set groups); unprivileged fixture повторяется в CI.

CId398d131:175 backend/45 Chromium/PG16/console native update/sandbox PASS; Xray fixture отказал на ancestor ownership исходного /opt. Fixture binary перенесён в отдельное root-owned /var/lib дерево, default production path/ownership guard не меняются; следующий CI печатает только публичные uid/mode /opt для диагностики.

CIf22c19e8 подтвердил /opt root0777 на hosted runner; trusted fixture binary hash и4 actual configs PASS. Sandbox listener не поднялся в2s. Добавлены только synthetic fixture journal diagnostics; production logs остаются отключены. Startup grace расширен до5s внутри прежнего RuntimeMaxSec25s; причина startup failure проверяется следующим CI.

Причина startup воспроизведена локально: credential называется xray без extension, Xray без -format завершает23 (cannot get format), тогда как config.json проходит. Постоянная/transient команды теперь используют общий xray_command с явным -format json; реальные4 configs с extensionless credential после fix PASS. Native fixture проверяет именно этот путь.


## 2026-10-18 — INF-06: проверенный Xray/Telegram выпуск передан, домашняя сеть — отдельный этап

Изучен shared dialogue6ad40728 и актуальный PR103; старый незавершённый local console worktree сохранён отдельно.
На exact1f605e4d все3 CI SUCCESS:console39079252673/context39079252684/review39079252801.
Job117532674964 logs inspected:175 backend/45 Chromium,4 actual configs, DynamicUser/LoadCredential/Telegram-only default-deny,
PG monitor/HDD namespace/update queued-running refusal/rollback/auth-CA-config-local-patch-notifier preservation PASS.
Artifact12118052115 скачан: ZIP191381B SHA86fc2a81, inner tar190969B SHA377eb564,
SOURCE_COMMIT1f605e4d/22 regular-directory members/tools verified. [Receipt](../runtime/console_telegram_release_20261018.json).
[Handoff](../../server-console/TELEGRAM_RELEASE_20261018.md) даёт root copy-before-hash stage, update без full setup,
отдельный interactive Xray install, private challenge ID и proxy-aware Telegram-only setup.
Installed567e90d5 receipt не обновляется: owner update/provider connectivity/config/receiver ACK ещё не получены.
Новый запрос владельца запрещает риск потери Internet/LAN на server1/домашнем ПК. Старый subnet route отменён как текущий план;
[userspace/SSH candidate](../../server-console/HOME_ACCESS_PLAN_20261018.md) требует isolated Windows pilot,
no TUN/default route/DNS/netfilter и узкие grants; нет обещания, что обычный Windows GUI не меняет сеть.
Xray outgoing provider subscription не даёт обратный доступ; reverse требует своего внешнего endpoint.
Ни VPN, ни update, ни production/network changes агентом не выполнялись; прямого SSH нет.

Fresh-context final review:Critical/Important0. Исправлено описание prepared/installed Xray;
ограничение ID helper первыми100 pending updates описано в handoff, код очереди не менялся.
Deferred minor: автоматическая пагинация ID challenge не реализована; текущий путь — отдельный свежий бот.
Решение: старый subnet route не реализовывать — противоречит последнему требованию; цена ошибки userspace feasibility —
отложенный доступ, не молчаливая смена default route/DNS. Реальные server/provider/receiver/Windows gates открыты.

Handoff verification:5 Bash blocks pass bash -n; artifact updater/tools and wheel transport/alerts/setup bytes match source.
Context/index/current/secret guards PASS,526 source/93 tables/33 tasks unchanged.
Local system Python initially lacked mcp; pinned harness venv then ran577 tests OK (20 environment skips).
No runtime source changed; approved executable artifact remains exact1f605e4d.


## 2026-10-18 — INF-06: native update/Xray приняты, credential guard блокирует notifier

Owner03:46MSK update1f605 PASS/checksum/PIP/localCA/fresh snapshot: HDD/capacity/backup/cron/PG OK, SMART warning partial.
Xray12 secure/first candidate TLS302/active, private challenge ID и proxy wizard завершены; bot no reply.
Owner03:49 sanitized diagnostic:notify9 restarts/auto-restart/exit1, ValueError credential_guard alerts225/262;
Xray active0 restarts, proxytrue/allowlist1/matching. [Receipt](../runtime/console_telegram_native_20261018.json).
Actual service inode mode/ACL не присланы; не приписывать их owner observation.
Official systemd issue29435 объясняет root0440 ACL named-user с group::---/maskr--/other---.
Локальный реальный POSIX ACL воспроизвёл прежний отказ; positive и hardlink regression RED, затем GREEN.
Reader проверяет один O_NOFOLLOW/NONBLOCK FD, regular/singlelink/root-or-self/max8192; owner0400/0600 либо
только narrow read-only ACL root/currentUID с нулевыми group/other, без широкого разрешения0440. Source0600 сохраняется.
17 new credential cases/77 focused/192 backend PASS6.55s; native first-cycle installedwheel/static notifier UID/LoadCredential
fixture добавлен, actual CI pending. Fresh reviewer Critical/Important0; independently17 ACL tests PASS.
Deferred minor: malformed/duplicate synthetic ACL metadata и52-byte/two-reader case не имеют отдельных tests;
kernel-validated ACL и проверенный parser fail closed; реальный типовой ACL покрыт.
Новый source fix требует owner применения; receiver ACK не получен. Units/token/auth/CA/network/production не менялись агентом.

Exact sourcefd46c383 all3 CI SUCCESS:console39084647257/job117548560984,context39084647251,review39084647259.
Logs inspected:192 backend5.33s/45 Chromium1.7m; CONSOLE_NOTIFIER_LOADCREDENTIAL PASS static user/first cycle/mode0o440.
HDD namespace/update rollback remain PASS. [Fix evidence](../runtime/console_notifier_credential_fix_20261018.json).
Published raw alerts.py readback matches SHA33c706c0/local source. [Owner command](../../server-console/NOTIFIER_CREDENTIAL_FIX_20261018.md)
shell+inner syntax and actual isolated file/control fixtures success/start-failure rollback/concurrent-change preservation/
unexpected module/bad download refusal PASS. Source token/permissions and units unchanged.
Owner application and real receiver ACK remain pending; no production operation executed by agent.
