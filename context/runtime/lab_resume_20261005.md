# INF-01: свежая копия и возобновление сохранённого стенда

Свидетельства получены от владельца; прямого подключения агента к серверу нет. Application PR76, production core/OS/HTTPS и установленный cron этой поставкой не изменены. Порядок **1 → 7 → 6** сохраняется.

## Выполнено владельцем 05.10

Создан новый согласованный encrypted archive `ustar-recovery-20261005T101659Z-7ea0ad99.tar.gz.age` в `/var/lib/ustar-backup-export`. Built-in preflight прошёл до остановки, `MOODLE_RUNNING=YES`; stop-to-running **24.097 s**. Независимый вызов `sha256sum` на сервере совпал с wrapper summary:

`42b87cca41623d3433943989c4ab5049b93d375cbcd5a7ddf43038d840b676a8`.

Timestamp в имени — время формирования имени, не точное время завершения. Свежая external copy/hash/decryption ещё не подтверждены. Старый отдельный archive/key/decryption/restore PASS сохраняется; его не объявлять проверкой нового архива. Output SERVEREXPRESS — прежняя подсказка ручного скрипта, не расписание/transfer. Будущая автоматизация остаётся на healthy HDD.

Cron v3: scheduled pass **10:24:01.895695 → 10:24:03.145591 UTC**, 1.25 s, exit 0; schedule/container match true, adhoc queued/failed 0, USTAR errors/failed 0, new failures пусты, log не truncated, email_disabled false. Последний task log ID 4149797. Исторические 17 overdue / 2 scheduled failed сохранены; это не новые failures этого pass. Root HTTP через Apache вернул **303**, только redirect smoke.

Владелец подтвердил вчерашний isolated restore и успешные входы/работоспособность на `127.0.0.1:18084`; screenshot показывает authenticated admin feed. Полный roles/SCORM PASS остаётся owner-reported, screenshot сам по себе его не доказывает. Полную baseline-репетицию заново не запрашивать.

## Старые scripts и состояние стенда

| Source | SHA-256 | Review |
|---|---|---|
| `USTAR_RESTORE_LAB_20261004.py` | `71f6a1c45c25bfe97fe0be7d0a362bb5a72e742b77c167f3783f526c85950dac` | Полный static review; pinned engine |
| `USTAR_CRON_LAB_20261004.py` | `906b1e6fb342b83e6fa3c73ef7bffb590fd7602ed2d2bb3a4ece17cb713e8690` | Полный static review; local self-test PASS |
| `USTAR_RESTORE_PREFLIGHT_20261004.py` | `e00577aefcbcdd8ae3ebc1f30f98c899c3efd3b2b4e593b6ce0d26d09e2756da` | Полный static review; совпал с dependency SHA engine |

Raw private uploads не добавлены в Git. Restore engine привязан к snapshot 04.10. `--restore-lab` создаёт новый lab и отвергает existing ROOT; `--stop-lab` останавливает relay и **удаляет только owned containers**, сохраняет physical DB, site files, images и evidence. Resume flag отсутствует. Cron lab создаёт другую offline clone и убирает её контейнеры по завершении; не использовать его для повторного открытия web lab.

Owner output **10:59:56 UTC / 13:59:56 MSK**: `docker ps -a --filter name=ustar` содержит только production Moodle/PostgreSQL. Контейнеры recovery/cron labs отсутствуют. Retained `/var/lib/ustar-restore-lab/20261004T123406Z-741e3f72` содержит `state.json`, `report.json`, `pg.env`, code, moodledata и physical PostgreSQL directory. Наличие подтверждено `ls`, размеры всего стенда ещё не измерены. Имя host owner PostgreSQL directory зависит от отображения numeric UID; chown не выполнялся. Это не разрешение на cleanup.

## Новый helper

[`resume_restore_lab.py`](../../scripts/infra/resume_restore_lab.py) использует существующий root-protected, exact SHA engine и его pinned checker. Default — plan only; `--check` только читает retained paths/JSON/config, Docker images/containers, relay availability и ресурсы. PHP, DB, state writes и archive/key reads в check отсутствуют. Bind probe loopback не создаёт listener.

SHA-256 нового helper: `59dec234262dd98fac0aa09a81cc3af9649ab8518580f4d977f5659a18042282`.

`--resume-lab` под **тем же EX lock**, что old restore/stop, повторяет checks и пересоздаёт два lab containers с retained mounts. Старые IDs сохраняются в отдельном private state backup до замены. Требует явно stopped state, успешный old report, отсутствующие containers/relay/port, root-protected link-free read-only code и link-free existing PostgreSQL 16 cluster. Нельзя создавать новый пустой PG cluster вместо потерянного `PG_VERSION`. Images сверяются с state/preflight и exact local ID; pull/load отсутствуют. Free reserve 4 GiB, 10000 inodes, RAM available 4 GiB; это restart budget, не достаточность места для последующего upgrade rollback.

Полный lab config должен совпасть с literal generator pinned engine, включая сохранённые password salts/peppers. Env, PHP ini и Apache config проверяются без публикации credentials. После запуска PostgreSQL только **lab readonly SQL** сверяет USTAR versions и отсутствие enabled scheduled tasks. Web bootstrap/login могут записать lab runtime cache/session; code mount остаётся RO. Проверяются network=none/shared loopback, mail/cron disabled, no pending upgrade и HTTP login 200. Host-loopback relay ограничен **7200 s**. Production IDs/images/start times сравниваются до/после; exec/SQL/stop production отсутствуют.

При ошибке останавливаются/удаляются только принадлежащие этому lab containers по исходным ownership/mount/network guards. Physical data, old report, archive, images и new private logs сохраняются. Не продолжать upgrade при `LAB_RESUME_ERROR`/incomplete cleanup. Новый sanitized `resume-report-*.json` отдельный; исходный report не переписывается.

## Проверки и следующий checkpoint

- 10 новых portable offline tests: readonly check, config/env/Apache/sendmail drift, live/name collision, code/PG links, permissions, PG/image drift, enabled tasks, success и failure cleanup при HTTP/bootstrap/production drift.
- Локальная совместимость с генератором **реального предоставленного engine** проверена на synthetic temporary files, ownership calls mocked, без Docker/SQL/production.
- Полный local `tests/r16` suite: **52 tests PASS**, включая context boundaries и настоящий local MCP stdio handshake; requirements установлены в отдельном validation environment. `check_context.py` PASS: 526 files / 93 tables / 32 tasks. Content index: 162 files. CI exact delivery фиксируется в Git/PR; application generated maps и исторические fixtures сохраняются.
- При публикации PR82 helper ещё не был запущен. **Позднее owner execution 11:30:58 UTC: LAB_RESUME=PASS / HTTP_LOGIN=200 / PRODUCTION_CONTAINERS_UNCHANGED=PASS**, relay7200s, report `resume-report-20261005T113058Z-a0dc0bf6.json` в прежнем ROOT. Это не core upgrade/rollback; [актуальный checkpoint](patch_runtime_20261005.md).

Оператор получает helper из immutable delivery commit и проверяет SHA-256 до root install. SSH-команды одной физической строкой; `sudo -v` отдельно с ожиданием нового prompt, затем `sudo -n`. `--resume-lab` включает собственный preflight; отдельный `--check` необязателен. Ожидаются `LAB_RESUME=PASS`, `HTTP_LOGIN=200`, `PRODUCTION_CONTAINERS_UNCHANGED=PASS` и report path. При необходимости штатный old engine `--stop-lab` закрывает его без удаления data directories.

Далее — compatible official Moodle patch, CI PHP 8.3/core/PostgreSQL parity и согласованный lab code/DB/data rollback. Возобновление старого lab **не выполняет core upgrade** и не превращает snapshot 04.10 в свежую production acceptance. Extra consumers/USTAR homepage preservation остаются в подготовке candidate; свежая внешняя копия и host/service recovery для OS отдельно открыты. INF-01 остаётся in_progress до этих входных условий.
