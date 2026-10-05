# Реестр кода, проверок и deployment evidence

Сводка Git/CI от 02.10.2026; дополнено owner runtime evidence 05.10.2026. Application source в main консолидирован на PR76 `e1d57f5bc28f6964afd20aeff7620345b34a80fe`. История main сохранена: прежний main `99248772a91526c87c1a31f425d1a07906727152` является предком этого SHA. Полный CI #456 проверен через GitHub API: **source, frontend, rollback, prepare-rc, moodle-db, gate — success**. [Run](https://github.com/cauf1l3d/ustarlms/actions/runs/37023122306). Счётчики из описания PR76: 210 Moodle tests / 891 assertions, 60 Chromium scenarios; это CI на synthetic данных.

## Последние версии

| Поставка | Exact SHA | local / theme | Уровень подтверждения |
|---|---|---|---|
| PR16 / G00 | `0e1eba2ca08b11f929730ab09df7bc91732f0e62` | 2026092601 / 2026092601 | Пользовательский manifest 27.09: 424 совпадения, нулевой drift; [запись](20260927_prod_g00.md) |
| PR48 | `7cc5587d300c799fc43e3f95c2ebc01cf3e50988` | 2026092709 / 2026092702 | В доступной истории владельца DEPLOY_OK и LOGIN_HTTP=200, 28.09 |
| PR50 | `ccae2ca209bf93ca0d8ca7273cb4d366f8c6b2a8` | 2026092710 / 2026092703 | В доступной истории владельца DEPLOY_OK, LOGIN=200, FEED=303; redirect не проверяет авторизованный UI |
| PR64 | `06b0b75496b9e3fe613b1001fa7ae599cf7ced80` | 2026093001 / 2026092703 | [Isolated gate #390](20260930_task_workspace_rc.md); отдельное точное подтверждение установки здесь отсутствует |
| PR67 RC2 | `0b369f626a0bdfc03446a305ba4ce902444f4ef1` | 2026093002 / 2026092703 | Пользовательский серверный отчёт в истории 30.09: успешный deploy; последующие изменения ниже |
| PR70 | `301cc7229246e0f212733221acc4659b05418cc8` | 2026093004 / 2026093002 | Пользовательский отчёт 30.09: DEPLOY_SUCCESS=YES, 493 совпадения, backup/CSS/maintenance checks |
| PR71, установленная ранняя ревизия | `4cbb8ac37ef99051b9432e1e5c7c462f1472ad36` | 2026093006 / 2026093004 | Исторический exact source manifest: 505 совпадений, no drift, DEPLOY_SUCCESS=YES. [Сохранённое свидетельство](../../tests/stage/production.json). После deploy выявлен signed-in layout defect; эта версия не является рекомендацией отката |
| PR71, последующий hotfix | `c77ba822423855ea822be461efc91793e3189afe` | 2026100101 / 2026100101 | Код исправления; отдельный manifest установки в доступных материалах не найден |
| PR72 mobile/messages | `971be902c4554539650aafc39e81fbc61354de7b` | 2026100102 / 2026100102 | Принятие владельцем записано в ADR0013; это документированное сообщение, не свежий полный manifest |
| PR73 season/team | `fb4036f53928749f42cbaffbfc2f925ada8a7551` | 2026100201 / 2026100102 | Source/CI release; отдельное exact deployment evidence отсутствует |
| PR74 avatars/login | `dd6e06efc0d4b2a9cab68cce4745393dfc572b21` | 2026100202 / 2026100201 | Full CI #441 по PR; exact deployment manifest отсутствует |
| PR75 app icon | `79be8529f949cc86164d287c5089f7d4271a9ca5` | 2026100203 / 2026100202 | Full CI #443 по PR; после иконки пользователь сообщил «хорошо вроде работает». Это подтверждение поведения, не точный manifest |
| PR76 adaptation reset | `e1d57f5bc28f6964afd20aeff7620345b34a80fe` | 2026100204 / 2026100202 | Full CI #456 проверен; owner manifest 03.10: 526 plugin/theme matching, versions 2026100204 / 2026100202; core и reset/reassign acceptance отдельно не подтверждены |

Промежуточные ветки и PR сохранены в [инвентаре](../archive/handoff_20261002/pr_inventory.json). Номер PR не идентифицирует immutable code: PR71 имел несколько существенно разных SHA.

## Историческое наблюдение владельца 02.10

02.10.2026 в запросе на консолидацию репозитория: академия работает более-менее стабильно, в ходе работы багов пока не выявлено. Это актуальная оценка эксплуатации. Она не устанавливает точный installed SHA, DB schema, состояние cron, нагрузочные показатели или результат восстановления.

История чата использована как свидетельство владельца; raw журналы этой сессией с сервера не получены. Git/CI проверены непосредственно. При handoff 02.10 current_source_commit/current_versions были null; новые датированные свидетельства 03–04.10 отражены ниже и в STATE. `tests/stage/production.json` и `runtime.json` остаются историческим fixture/preflight input и не переписаны ради более нового номера версии.

## Дополнение 05.10 — что подтверждено после консолидации

Owner-supplied evidence из [аудита](../audits/USTAR_INFRASTRUCTURE_AUDIT_20261005_RU.md) и рабочего диалога; прямого подключения этой сессии к production нет. [Машинный паспорт](evidence_20261005.yaml) и [сверка](../audits/AUDIT_RECONCILIATION_20261005_RU.md).

| Контроль | Свидетельство / граница |
|---|---|
| Application source | 03.10 02:56:02 UTC: PR76, 526 matching plugin/theme, changed/missing/extra/errors/skipped пусты; не полный core |
| Production versions | Moodle 5.1.1+ Build 20251219, PHP 8.3.33, PostgreSQL 16.15, local/theme 2026100204 / 2026100202, maintenance 0 |
| Follow-up manifest | Не состоялся из-за отсутствия origin/main; не drift и не новая successful сверка |
| Cron | v3, every minute, one worker; 04.10 23:57: exit 0 / 1.374 с / new failures и USTAR errors 0; H5P/registration disabled |
| Manual recovery | Snapshot 04.10, checksum/external key/isolated restore подтверждены в своей области; full shared-host restore не проверен |
| Открытые риски | DB superuser, writable code/config, HTTP, no unified monitoring, old core patch, root 76%, failed HDD; регулярных копий нет |

GitHub-поставка DOC-02 не меняла application code и production. Исходный аудит сохранён без изменения байтов, runtime/harness/roadmap pointers и полный план опубликованы PR78. [Проверка поставки](context_update_20261005.md). Исходное поручение требовало остановиться после публикации; следующая команда владельца 1 → 7 → 6 приведена ниже.

## Продолжение 05.10 — выбранные пункты 1 → 7 → 6

Владелец разрешил baseline → Moodle/CI/ОС → HTTPS; остальные пункты после них. INF-01 в работе. [Новый evidence](evidence_20261005_baseline.yaml), [baseline и tool scope](baseline_20261005.md), [component inventory](plugin_inventory_20261005.json).

| Контроль UTC | Результат / граница |
|---|---|
| Manifest 02:32:35.997389 | 526 files PR76, changed/missing/extra/errors/skipped/uncompared 0; independently checked vs actual Git blobs; non-atomic plugin/theme only |
| Inventory 02:43:33 | 468 disk/readonly DB component versions identical; USTAR 2 roots and 17 external roots / 55 components; no core Git HEAD |
| Core metadata | 3 SHA-256 match official weekly commit `1cd17816c56a7df7ee796892efccaf1ed5347340`; runtime core comparison still pending |
| Runtime images | PHP CLI 8.3.33 Debian13/Trixie, PG16.15; exact IDs/RepoDigests in new evidence; moving PHP FROM requires target pin decision |
| Cron 02:13 | v3 exit 0 / 1.070 s / schedule and container match; exceptions retained; installed wrapper hash confirmed |
| AppArmor/packages | snapd rc + stale profile include; Docker still uses docker-default; no repair/update/reboot performed |

Read-only preflight helper and context are repository work; application source, DB/data, historical fixtures and host wrappers unchanged. New full CI on the chosen runtime and isolated/production upgrade are not yet run. Official 5.1.7 candidate is identified, not deployed. Old manual recovery copy precedes latest cron writes; fresh copy still required before mutation. Exact implementation/CI of this repository delivery are recorded in its PR/Git history.

## Core comparison 05.10 08:28 UTC

Владелец выполнил immutable helper PR79 и передал архив private preflight directory. 24 678 matching / 1 changed (`public/index.php`) / 0 missing / 23 extra / errors и skipped 0. Coverage и expected changed OID проверены по exact official reference tree; PHP/application source из изменённого файла в архиве отсутствует. [Сводка и следующий шаг](core_review_20261005.md). Это complete comparison указанной области с обнаруженным drift, не full equivalence всего deployment. Точный SHA server helper совпал; самостоятельно server files не читались. Production writes/repair/cleanup не выполнялись, INF-01 остаётся in_progress.

## Перед следующим релизом

Получить свежие versions/scoped manifest и отдельно core/plugins/images, Apache/HTTPS/cron identity. При неизменном PR76 повторная установка не требуется. При другом baseline или drift — разобраться, не обходить preflight. Старые installers ограничены своими исходными версиями; docs main не разрешает слепо запускать historical deploy.

OPS-01 сохраняет проверку HRD reset/manager reassign как отдельную business acceptance. tests/stage/production.json и runtime.json остаются историческими fixtures; не переписаны для совпадения с текущей сводкой.

## Source review 05.10 — index и existing wrappers

Отдельный index upload: 93 bytes, SHA/OID verified; config bootstrap и redirect на `/local/ustar/home.php`. Matching/drift counts core snapshot 08:28 не меняются. Source uploads backup/cron получены 09:48:49 UTC: полное статическое чтение, hashes совпали; [контракт](recovery_contract_20261005.md). Default preflight/backup outage/resume-only recover, local export, lock coordination и real-pass installer различены. Локальные self-test/offline gates PASS; свежая production копия, archive restore и candidate stage ещё не выполнены. Application source и исторические fixtures сохранены; INF-01 остаётся in_progress.

## Свежий backup/cron и retained lab 05.10

Владелец выполнил fresh encrypted snapshot 05Oct; local wrapper/independent hash совпали, Moodle running YES, stop-to-running24.097s. Cron scheduled 10:24 UTC exit0/1.25s/new failures0; Apache root303. Старый restore/manual PASS подтверждён, authenticated admin feed screenshot учтён в его области; новая external/decryption verification отдельно открыта. Docker -a 10:59:56 UTC подтвердил удалённые lab containers; retained DB/code/data/state/report есть. [Конкретные evidence и resume helper](lab_resume_20261005.md). Новый helper source/offline failures проверены; на сервере ещё не запускался. Application/core/OS/HTTPS без изменений, INF-01 in_progress.
