**Current14Oct02:17MSK:** Owner r3 recovery completed at23:05:54UTC (services_resumed=true/unit exit0). Subsequent r4 reached selected_services_stopped, then FileNotFoundError before copy; finally restored services at23:08:40UTC. Live metadata-only scan completed;Docker root stopped at foreign-filesystem guard;actual cold missing path remains unknown. New source retains exact private failure path/operation/step and retries one complete initial manifest only on FileNotFoundError,never skips entries or retries post-copy verification.41 local tests PASS;exact Ubuntu CI and new server capture pending. No archive/package/reboot acceptance. See current host cold recovery contract/result.

**Current13Oct / owner17:26MSK + verified source:** [Stop metadata](../runtime/host_cold_recovery_result_20261013.json):successful amavis/Apache/caddy/cron jobs and UNIT_SUCCESS;source sequence localizes refusal to cron,past numeric status unrecorded,current14 units active/success. [Corrected contract/retry](../runtime/host_cold_recovery_20261013.md) source5d69566b/SHA94488652 distinguishes zero exit from configured SIGTERM,keeps inactive/dead/PID0/success and hard-kill/error refusals,records exact failed unit/stop metadata.29 local +29 file/model and2 actual Ubuntu systemd tests/no skips PASS. Next install verified helper/new-r3 unit;archive/install/reboot pending. AppArmor/518 acceptance remains closed;53/PG17+ after OS.

**Current13Oct17:07MSK:** [Owner r2 cold recovery result](../runtime/host_cold_recovery_result_20261013.json):installed00b15bc1/PASS,unit34da76b9,failed_resumed/SERVICE_UNCLEAN_STOP at14:05:16..17UTC,services_resumed=true,package/reboot=false. Disk preflight passed;source phase puts refusal before container stop/archive copy. Failed shared unit/past exit metadata unknown. Next read-only PID1 stop-window metadata/current unit policy;no new capture or resume for this restored attempt. [Contract](../runtime/host_cold_recovery_20261013.md). Sourcecf789621 unchanged;22 fixture passes are not server shutdown acceptance. Prior AppArmor/518 acceptance stays closed;host archive/install/reboot pending.

**Current13Oct / owner16:42MSK:** [Cold recovery preflight refusal](../runtime/host_cold_recovery_result_20261013.json): first unit failed13:00:01UTC before marker/pause/copy;only blocking mount is configfs at `/sys/kernel/config`. [Corrected source and retry](../runtime/host_cold_recovery_20261013.md) cf789621/SHA00b15bc1 accepts only that kernel type/path and still refuses foreign persistent mounts;22 local and exact Ubuntu root CI tests/no skips PASS. Install corrected helper and launch new `-r2` systemd unit;completed archive/package installation/reboot still pending. AppArmor/518 acceptance stays closed;53/PG17+ follows OS.

**Checkpoint13Oct15:04MSK:** [Fresh APT/boot/shared metadata](../runtime/host_packages_result_20261013.json):38 upgrades+25 new/zero removals,147 candidate kernel,139 running/146 installed fallback,SSD62.75GB/HDD475GB free,six containers/live-restore false,MariaDB no-password1045. [Next private cold SSD file recovery](../runtime/host_cold_recovery_20261013.md):17 local+exact Ubuntu root fixtures PASS;systemd-held capture/resume/readback before package installation/reboot. Source2d1b2502/SHA bcbbd378. AppArmor/518 acceptance closed;capture/install/reboot not executed;53/PG17+ follows OS. Raw owner upload remains private.

**Checkpoint13Oct14:24MSK:** [Actual AppArmor repair PASS](../runtime/host_apparmor_result_20261013.json):corrected93e1813e,unit active/success,Docker enforce/IDs/images/restart counts and selected Snap units preserved. Targeted repair CLOSED;do not rerun without a new error. [Next host package/boot/shared recovery metadata](../runtime/host_packages_handoff_20261013.md):refresh APT catalog,simulate,read kernel/GRUB/space/selected storage/readonly MariaDB engine metadata;prepare concrete recovery/restart plan. Packages/reboot not executed;full Snap runtime not inferred. Prior518/storage acceptance stays closed;53/PG17+ follows OS.

**Checkpoint13Oct13:18MSK:** [Exact profile/Snap observation](../runtime/host_apparmor_observation_20261013.json):missing Snap include line11,a308e931/root0:0/0644/dev2050/inode3151055;LXD socket listens and Snap mounts remain. Keep consumers and profile;[one conditional include repair](../runtime/host_apparmor_repair_20261013.md) prepared from current main c53723c,with original backup/compile before atomic replacement/AppArmor-only load/Docker enforcement checks. Repair/OS install/reboot pending. Prior owner518 upgrade/repeat/cron/HDD/browser acceptance closed;latest53 LTS after OS needs PG17+/addons/isolated recovery. Earlier entrypoint statements below are dated history,not an instruction to repeat completed storage or patch work.

# Протокол продолжения

**Приоритет владельца06.10 00:45MSK:** сначала оптимизировать хранение, обновить карту сервера и настроить автобэкапы на HDD; затем новый стенд и Moodle/ОС/HTTPS. Прежнее правило «автобэкапы в финале» заменено. PR87 `--prepare-stage` приостановлен. [Карта и scope](../runtime/server_map_20261006.md). Более ранние этапы/даты ниже сохраняются как история; эта команда определяет текущий порядок.

## База работы

Код и контекст совместно поддерживаются в `main`. Прочитать START_HERE и обязательный контекст, обновить refs, зафиксировать HEAD. Рабочая ветка — от main, короткий PR — в main. Не возобновлять старый stacked PR только потому, что он открыт или называется release.

Наличие application baseline SHA в STATE не означает, что documentation HEAD или production имеют тот же SHA. Перед выпуском сравнить релевантные деревья, manifest и версии; читать release ledger.

## Задача и поставка

Выбрать задачу из ACTIVE/BACKLOG в границах запроса владельца. Указать owner, branch, статус. Сначала проверить реальную реализацию, затем менять. Не перезаписывать чужую незавершённую работу. Сохранять ADR и историю. Инструкция владельца приоритетнее старых аудитов; предложения аудита не разрешают несвязанные production-операции.

Проверить `STATE.execution` и `BACKLOG.execution_gate`. DOC-02 завершён PR78; новая команда владельца разрешает пункты 1 → 7 → 6: baseline, Moodle/CI/ОС, затем HTTPS. Остальные задачи отложены до их завершения. Продолжать эту работу после GitHub-публикации; прежнее ожидание DOC-02 не требует повторного разрешения. При отсутствии production-доступа подготовить проверенный артефакт и конкретную команду оператору. Разовая согласованная recovery copy и stage/rollback перед изменениями входят в подготовку выбранных пунктов; регулярное расписание по последней команде владельца входит в подготовку до нового стенда.

Каждая поставка содержит:

- implementation SHA и список изменённых сценариев;
- реально выполненные проверки с SHA/environment и ссылкой на CI;
- отдельно deployment evidence или явное `not_verified`;
- при schema change — install/upgrade/repeat, согласованный backup и rollback;
- актуальные STATE/ACTIVE/BACKLOG/SESSION_LOG и source map при изменении application source.

| Статус | Смысл |
|---|---|
| ready | Следующая определённая работа |
| planned | Работа описана, зависимости/решения остаются |
| in_progress | Есть текущий исполнитель |
| review | Реализация и evidence подготовлены |
| stage_verified | Точный SHA прошёл соответствующий isolated runtime |
| released | Есть подтверждение установки этого SHA |
| done | Acceptance задачи выполнены; для docs достаточно опубликованного проверенного результата |
| deferred | Не выполнять до нового приоритета |

Старые R/G/N задачи сохраняются в архиве как критерии, не как действующая очередь. Не маркировать их все done задним числом. Остатки сведены в текущий BACKLOG.

## Проверки

Обычный PR запускает source/frontend; Moodle DB и rollback включаются отдельным full RC event. Скipped jobs не являются PASS. Штатный workflow: `.github/workflows/ustar-review-gate.yml`; инструкции — в `context/runtime/OPERATIONS.md`.

В documentation-only handoff достаточно проверить ссылки, YAML/JSON, provenance и равенство application trees уже проверенному SHA. Проверка новых context tools должна быть воспроизводимой. При изменении application code требуется новый соответствующий gate, а не заимствование старого результата.

Production доступ не подразумевается наличием GitHub. Когда он отсутствует, завершить source-анализ и подготовку, назвать точное недостающее evidence. Не объявлять весь проект заблокированным. Runtime collectors пишут отчёт в отдельный каталог; содержимое проверить перед публикацией. Не публиковать secrets, dumps, личные данные и config.php.
