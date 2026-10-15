# Сверка работ USTAR с06Oct по16Oct2026

## Результат

По поручению владельца16Oct выполнена сверка Git, CI и сохранённых owner receipts за06–16Oct. Канонический продукт — Moodle/local_ustar/theme_ustar; Next.js `frontend/` — legacy и исключается из active CI/release. Архив получен и инструкция передана сисадмину; native результат восстановления ожидается. Следующий этап — INF-06 пульт/Telegram. Moodle5.2 и PostgreSQL17 отложены.

Проверяющий не подключался к server1 и не запускал stop/update/reboot/restore. Установка и ручная приёмка ниже подтверждены владельцем в своих датах/областях. Synthetic CI, текущий Git source и native acceptance различаются. Original [audit05Oct](USTAR_INFRASTRUCTURE_AUDIT_20261005_RU.md) сохранён byte-for-byte; [audit06Oct](USTAR_INFRASTRUCTURE_AUDIT_20261006_RU.md) — исходная точка этой сверки.

## Что выполнено после исходной точки

| Когда | Подтверждено | Evidence и граница |
|---|---|---|
| 06Oct | HDD producer/timer установлены, raw GPT/boot copy verified | Не полный image/boot restore; поздние scheduled results ниже |
| 11Oct | GPT relocation, partition growth и online ext4 resize; filesystem120032006144B/df110G | [SSD receipt](../runtime/ssd_ext4_resize_result_20261011.json), UUID/quota/mount сохранены; [post checks](../runtime/post_resize_checks_20261011.json) |
| 11–12Oct | Отдельный full-addon candidate5.1.8,57 versions/19 addon roots/526 USTAR files; real lab upgrade/repeat/paired rollback/re-upgrade и scoped browser/Apache QA | [Upgrade](../runtime/lab_patch_upgrade_result_20261012.json), [restore](../runtime/lab_patch_restore_result_20261012.json), [Apache](../runtime/lab_patch_apache_result_20261012.json); не universal addon compatibility |
| 13Oct | Prod5.1.8/core2025100608.00, CLI upgrade twice,57 addon versions/5151 files, new HDD and cold pair | [Production](../runtime/production_patch_upgrade_result_20261013.json); production paired restore не выполнялся |
| 13Oct | Owner browser/SCORM across accounts и post-switch cron/HDD success | [Browser](../runtime/production_patch_browser_result_20261013.json), [cron/schedule](../runtime/production_patch_post_switch_status_result_20261013.json); не полная ACL/SMTP acceptance |
| 13Oct | One optional AppArmor include repaired, docker-default enforce | [AppArmor](../runtime/host_apparmor_result_20261013.json); без удаления Snap/LXD |
| 14Oct | r6 SSD root.tar43704842240B/readback SHA/223777 protected members, services resumed | [Cold receipt](../runtime/host_cold_recovery_result_20261013.json); plaintext file archive, не boot restore |
| 14Oct |63 approved cached packages installed, boot kernel147, Docker29.9.0; native reboot/mount/IDs/policies/HTTP and cron accepted | [Install](../runtime/host_packages_install_result_20261014.json), [boot](../runtime/host_reboot_result_20261014.json); не whole-host mail/DNS E2E |
| 14–15Oct | Timed labs, portable transfer kit, homepage carry, permanent/copy modes и read-only export prepared; selected101 tests/native PHP/HTTP/systemd checks | [Copy validation](../runtime/production_copy_validation_20261015.json); Docker/PG/import/reboot modeled |
| 16Oct | Owner confirmed archive obtained and instruction passed to sysadmin | [Handoff receipt](../runtime/sysadmin_handoff_result_20261016.json); новый filename/hash не сообщены, clean-host restore не принят |

## Источники Git и разрешение противоречий

`main` до сверки — c53723c8. PR98/storage, PR99/patch, PR100/host и PR102/restore были независимыми ветками от main: их результаты не попадали в канонический current context. Консолидация сохраняет их commits/parents, source helpers, tests и receipts. Исходники plugin/theme526 и XMLDB93 не меняются.

Шесть storage/stage JSON различались между PR98/99 только runbook URL/форматированием; сохранены исходные bytes PR98, поскольку весь original runbook теперь доступен. Содержательные результаты совпадают. SESSION_LOG объединён append-only; новые RELEASE_LEDGER sections сохранены. Старые entrypoints/STATE находятся в [архиве](../archive/reconciliation_20261016/README.md); исходные ветки/commits не удаляются.

Устранены текущие ложные next-step: «root growth не выполнен», «первый scheduled ещё впереди», «повторить patch/host reboot», «следом5.3 preparation», «пульт отложен». Это исторические положения05/06Oct, а не текущая очередь. Отдельный запуск07Oct в представленном evidence не восстановлен задним числом; более поздние scheduled12–14Oct действительно приняты.

## Frontend и CI

Legacy Next.js не используется на проде. Его npm audit/build/http-smoke исключены из required workflow, needs и full gate; файлы и lock не входят в release manifest/source archive. Исходники сохранены с пометкой legacy. Это не исправление уязвимостей старого клиента и не разрешение его публиковать.

Native PHP8.2/8.3, JS/AMD, Mustache, DOM/Playwright, XMLDB, immutable manifest, full RC database matrix и paired rollback сохранены. [CI scope](../runtime/CI_SCOPE_20261016.md). Тесты проверяют release boundary; исправлены межтестовая утечка umask и случайный выбор untracked optional package. Ни production guard, ни SHA проверки не ослаблены. Новое exact-head CI evidence публикуется в PR консолидации, не заимствуется у прежнего source SHA.

## Полная сверка остатка по аудиту

Все33 текущие задачи сопоставлены исходным разделам аудита; [машинная сверка](reconciliation_20261016.json) не имеет unmapped tasks. `done` означает закрытие указанной области, не всей системы. `waiting_external` не блокирует начало пульта.

| Задача | Аудит | Статус | Принято или остаток |
|---|---|---|---|
| DOC-01 | §10 | done | Канонический main; история сохранена. |
| DOC-02 | §10 | done | Original audit/hash и harness сохранены. |
| INF-01 | §4.1 | in_progress | Fresh полный manifest/расширенная baseline-приёмка, provenance исключений. |
| INF-02 | §4.6 | planned | Runtime DB superuser на проде не уменьшен; роль новой копии это не исправляет. |
| INF-03 | §4.6 | planned | Prod code/config writable; key/dump access и безопасный deploy ещё не приняты. |
| INF-04 | §4.7 | in_progress | Рост SSD закрыт; hardware reserve/power/offsite/SMART follow-up открыты. |
| INF-05 | §4.3/4.7 | planned | Измеренная ротация/healthcheck/recreation и disk budgets на проде. |
| INF-06 | §4.3 | ready | Следующий пульт/backend/бот; delivery/observer ещё не внедрены. |
| INF-07 | §4.5 | planned | Доверенный HTTPS и клиентские сценарии/renewal alert. |
| INF-09 | §5.1 | in_progress | Production8.3/518 CI есть; итоговый exact-head полный gate отдельно от native DR. |
| INF-08 | §5.1 | done | 5.1.8 upgrade/repeat/browser/cron приняты; этап не повторять. |
| INF-10-OS | §4.4/5.1 | done | AppArmor/63 packages/boot147/IDs/mount приняты; полный host restore отдельно. |
| INF-10 | §4.4/7 | planned | SSH/firewall/MFA/effective policies и emergency access. |
| INF-11 | §4.0 | planned | H5P/registration exceptions, SMTP delivery/full cycle, обоснованный re-enable. |
| OPS-01 | §4.1/7 | in_progress | Полные роли/workflows/PR76 reset отдельно; route refresh bug остаётся. |
| INF-12 | §7 | planned | ACL/IDOR/files/view-as/CSRF/concurrency negative matrix. |
| AUDIT-01 | §5.5/7 | planned | Legacy consumers/XMLDB и миграции без удаления истории. |
| PERF-01 | §6 | planned | Passive baseline и согласованные p50/p95/p99/concurrency, без выдуманных гарантий. |
| INF-13 | §5.3 | planned | Owners/security reporting/advisories и итоговая baseline-приёмка. |
| INF-14 | §4.2 | in_progress | Scheduled backups приняты; retention/RPO/RTO/offsite/key custody/alerts открыты. |
| OPS-02 | §4.2 | waiting_external | Архив передан; ждём clean-host restore/roles/SCORM/reboot. Full shared-host DR открыт. |
| PLAT-01 | §5.1 | deferred | Moodle5.2 + PG17 отложены;5.3 assets/PR101 только историческое исследование. |
| PRIVACY-01 | §5.2 | deferred | Policy/export/delete/retention/provider acceptance отдельным этапом. |
| MOB-01 | §5.4/6 | planned | API inventory и архитектурное решение. |
| MOB-02 | §5.4/6 | planned | Полное server API/ACL/files покрытие. |
| MOB-03 | §5.4/6 | planned | Android/iOS slice на реальных устройствах. |
| MOB-04 | §5.4/6 | planned | Полный scope/performance/offline/push. |
| MOB-05 | §5.4/6 | planned | Signed builds/pilot/support/update channel. |
| B2B-01 | §5.4 | planned | Выбрать isolation/hosting/delivery/support ADR. |
| B2B-02 | §5.4 | planned | Tenant-negative tests/per-client DR/data/license acceptance. |
| TECH-01 | §5.5 | planned | Измеренный refactoring с контрактами и миграциями. |
| INTEGRATIONS-01 | §4.0/5.5 | deferred | Новая Bitrix integration только по отдельным требованиям. |
| DOC-03 | §10 | done | Новая консолидация/CI/pointers; publication evidence в PR. |

Приоритетные незакрытые риски реального прода: DB cluster privileges, writable code/config, HTTP/client trust, storage/key/offsite/retention, SMTP/external tasks и отсутствие независимых alerts. Новая parallel copy с отдельным DB role/RO code не закрывает эти вопросы старого прода. Хостовые service active/HTTP200 не равны функциональному mail/DNS/ISPConfig acceptance.

## Очередь и ветки

1. После консолидации — [INF-06 пульт и Telegram](../runtime/control_panel_telegram_20261016.md), начальный protected read-only slice, затем jobs/download/delivery acceptance.
2. Параллельно ждать OPS-02 от сисадмина; подтверждение должно относиться к реальному clean-host archive import/roles/files/SCORM/reboot. Не повторять уже полученную упаковку без причины.
3. Дальнейшие hardening/HTTPS/retention/ACL/performance — по BACKLOG без выдуманного полного закрытия аудита.
4. PLAT-01 Moodle5.2/PG17 отложен. Исторические5.3 assets и PR101 сохраняются; перед возобновлением заново выбрать точный target и проверить требования/совместимость. Не переименовывать5.3 experiment в якобы выполненный5.2.

[Все122 remote branch heads и роли](../runtime/repository_state_20261016.json): main — canonical, четыре принятые ветки — provenance консолидации, PR101 — deferred, PR103 — next prototype с базой от обновлённого main. Старые integration/release/prod-sync/snapshot/codex heads не считаются current deployment и не удаляются без отдельной необходимости.

Пульт не установлен, bot token/receiver/delivery не настроены, production не переключался. Same-host бот не доказывает независимый мониторинг потери host. Для больших бэкапов предусмотрена авторизованная ссылка; ключ и незашифрованный transfer kit в Telegram не передаются.


Локальная проверка объединённого дерева:545 Python tests, PASS с19 явными environment/optional skips; native source structural/XMLDB/Mustache/JS checks PASS, PHP CLI локально отсутствует. Четыре новых release-scope проверки PASS. Полный GitHub RC и root workflows фиксируются отдельно на точном опубликованном SHA; native server restore из этого не следует.
