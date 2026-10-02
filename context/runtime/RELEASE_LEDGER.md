# Реестр кода, проверок и deployment evidence

Сводка 02.10.2026. Application source в main консолидирован на PR76 `e1d57f5bc28f6964afd20aeff7620345b34a80fe`. История main сохранена: прежний main `99248772a91526c87c1a31f425d1a07906727152` является предком этого SHA. Полный CI #456 проверен через GitHub API: **source, frontend, rollback, prepare-rc, moodle-db, gate — success**. [Run](https://github.com/cauf1l3d/ustarlms/actions/runs/37023122306). Счётчики из описания PR76: 210 Moodle tests / 891 assertions, 60 Chromium scenarios; это CI на synthetic данных.

## Последние версии

| Поставка | Exact SHA | local / theme | Уровень подтверждения |
|---|---|---|---|
| PR16 / G00 | `0e1eba2ca08b11f929730ab09df7bc91732f0e62` | 2026092601 / 2026092601 | Пользовательский manifest 27.09: 424 совпадения, нулевой drift; [запись](20260927_prod_g00.md) |
| PR48 | `7cc5587d300c799fc43e3f95c2ebc01cf3e50988` | 2026092709 / 2026092702 | В доступной истории владельца DEPLOY_OK и LOGIN_HTTP=200, 28.09 |
| PR50 | `ccae2ca209bf93ca0d8ca7273cb4d366f8c6b2a8` | 2026092710 / 2026092703 | В доступной истории владельца DEPLOY_OK, LOGIN=200, FEED=303; redirect не проверяет авторизованный UI |
| PR64 | `06b0b75496b9e3fe613b1001fa7ae599cf7ced80` | 2026093001 / 2026092703 | [Isolated gate #390](20260930_task_workspace_rc.md); отдельное точное подтверждение установки здесь отсутствует |
| PR67 RC2 | `0b369f626a0bdfc03446a305ba4ce902444f4ef1` | 2026093002 / 2026092703 | Пользовательский серверный отчёт в истории 30.09: успешный deploy; последующие изменения ниже |
| PR70 | `301cc7229246e0f212733221acc4659b05418cc8` | 2026093004 / 2026093002 | Пользовательский отчёт 30.09: DEPLOY_SUCCESS=YES, 493 совпадения, backup/CSS/maintenance checks |
| PR71, установленная ранняя ревизия | `4cbb8ac37ef99051b9432e1e5c7c462f1472ad36` | 2026093006 / 2026093004 | Последний доступный exact source manifest: 505 совпадений, no drift, DEPLOY_SUCCESS=YES. [Сохранённое свидетельство](../../tests/stage/production.json). После deploy выявлен signed-in layout defect; эта версия не является рекомендацией отката |
| PR71, последующий hotfix | `c77ba822423855ea822be461efc91793e3189afe` | 2026100101 / 2026100101 | Код исправления; отдельный manifest установки в доступных материалах не найден |
| PR72 mobile/messages | `971be902c4554539650aafc39e81fbc61354de7b` | 2026100102 / 2026100102 | Принятие владельцем записано в ADR0013; это документированное сообщение, не свежий полный manifest |
| PR73 season/team | `fb4036f53928749f42cbaffbfc2f925ada8a7551` | 2026100201 / 2026100102 | Source/CI release; отдельное exact deployment evidence отсутствует |
| PR74 avatars/login | `dd6e06efc0d4b2a9cab68cce4745393dfc572b21` | 2026100202 / 2026100201 | Full CI #441 по PR; exact deployment manifest отсутствует |
| PR75 app icon | `79be8529f949cc86164d287c5089f7d4271a9ca5` | 2026100203 / 2026100202 | Full CI #443 по PR; после иконки пользователь сообщил «хорошо вроде работает». Это подтверждение поведения, не точный manifest |
| PR76 adaptation reset | `e1d57f5bc28f6964afd20aeff7620345b34a80fe` | 2026100204 / 2026100202 | Full CI #456 проверен; exact production deployment и reset/reassign под живыми ролями не подтверждены |

Промежуточные ветки и PR сохранены в [инвентаре](../archive/handoff_20261002/pr_inventory.json). Номер PR не идентифицирует immutable code: PR71 имел несколько существенно разных SHA.

## Текущее наблюдение владельца

02.10.2026 в запросе на консолидацию репозитория: академия работает более-менее стабильно, в ходе работы багов пока не выявлено. Это актуальная оценка эксплуатации. Она не устанавливает точный installed SHA, DB schema, состояние cron, нагрузочные показатели или результат восстановления.

История чата использована как свидетельство владельца; raw журналы этой сессией с сервера не получены. Git/CI проверены непосредственно. Поля current_source_commit/current_versions в STATE оставлены null до OPS-01. `tests/stage/production.json` и `runtime.json` остаются историческим fixture/preflight input и не переписаны ради более нового номера версии.

## Перед следующим релизом

Получить свежие version pair, manifest plugin/theme и сведения о текущем core/proxy/cron; сопоставить со всей таблицей. Если установлен PR75, проверенный PR76 installer рассчитан именно на него. Если PR76 уже установлен, повторное применение не требуется: проверить manifest и сценарий. Если baseline другой или есть drift — сначала разобрать различия, не обходить preflight.
