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
