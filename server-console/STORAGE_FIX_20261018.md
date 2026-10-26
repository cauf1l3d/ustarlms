# HDD namespace и частичные SMART показания — 18 октября 2026

[Owner receipt00:14MSK](../context/runtime/console_storage_probe_20261018.json) подтверждает одинаковый HDD UUID, host rw и ProtectSystem=strict ro. Старый collector требовал rw в собственном пространстве монтирования, поэтому выдавал ложный required-check failure. SMART oneshot завершился0, timer active/waiting; HDD PASSED/48°C/selected counters0, SSD PASSED/50°C/часть счётчиков0, атрибуты197/199 не предоставлены. Общая метка отчёта20:50:22UTC сохранена. Точное время CLI/acceptance отсутствует; возраст или порядок чтения не выводится из времени получения сообщения.

## Исправление

Mount probe читает PID1 mountinfo через findmnt --task1 и собственную mount table. Проверяет exact target/UUID в обеих, одинаковый MAJ:MIN и rw в host view; local ro допустим. Никакого setns, перемонтирования или ReadWritePaths для HDD не добавляется. Настоящий host ro, missing/wrong UUID и несовпадение устройства по-прежнему отклоняются.

SMART сохраняет самооценку/температуру/известные счётчики. Отсутствующие197/199 перечислены, не заменены нулями. При PASSED с неполным набором выбранных атрибутов статус warning с причиной smart_partial_attributes. FAILED/известные bad sectors остаются critical. Ошибка чтения одного накопителя не скрывает свежие показания другого. Missing/stale/invalid/future receipt имеет отдельную причину NoData; TTL960s сохранён. Старые значения при истечении TTL не выдаются как текущие. Vendor wear остаётся неинтерпретированным. Самооценка PASSED не является полным тестом поверхности или ресурсом SSD.

UI показывает доступные значения каждого диска, самооценку и конкретные неизвестные атрибуты. Неизвестные значения — прочерк; свежесть контролируется и в браузере. Прототип пересобран из того же UI, demo adapter не меняется.

## Обновление установленного пульта

Новый artifact включает deploy/update.sh. Сначала root-stage/checksum/SOURCE_COMMIT из успешного exact CI, затем sudo bash path/to/ustar-console/deploy/update.sh. Полный setup не повторять.

Updater проверяет root-owned source и совпадение установленных units с комплектом: миграции unit settings он не выполняет. Дважды проверяет отсутствие queued/running jobs, закрывая web между проверками. Копирует предыдущий venv в root-private /opt/ustar-console-rollback.*, затем останавливает только console units и ставит wheel без зависимостей/сетевого resolver. CA/config/auth/Telegram credentials/DB roles/Moodle/firewall не изменяет. Восстанавливает исходно активные units; выключенный notifier остаётся выключенным. Если SMART/collector timers были активны, выполняет их фиксированные read-only probes для свежего snapshot. Проверяет TLS/limited native gate. При ошибке возвращает весь прежний venv, включая локальный Telegram patch, и исходные active states. При неудаче остановки/перемещения явно сообщает ROLLBACK_FAILED, не объявляет восстановление.

Обновление кратко прерывает доступ к самому пульту. Академия и её расписание работают отдельно. Резервный venv содержит код/зависимости пульта, не архив Академии; он сохраняется для отката, автоматического удаления нет. Операции host backup/restart/lab не запускаются.

## Проверка и остаток

125 backend tests локально PASS; регрессии воспроизводят старый namespace failure, частичный SMART и generic freshness failure до fix. Добавлены browser cases partial/unavailable/stale для трёх viewport. Native CI создаёт отдельную loop ext4 fixture: exact UUID/host rw/local ro + запрет записи и отрицательный host ro. Production paths/devices не монтируются. Update CI проверяет queued/running refusal, failure rollback с сохранением локального patch, затем успешное обновление с неизменными auth/CA/config и состоянием notifier. Результат exact published CI записывается в PR103; локально native fixture/Chromium не выполняются.

Применение нового artifact на server1 и свежий acceptance ещё ожидаются. Telegram/VPN остаются следующими действиями по владельцу; [инструкция](REMOTE_ACCESS.md). Receiver ACK/домашний маршрут, роли/скачивание/hash/observer/restore не приняты. Telegram startup fix предыдущего этапа включён в этот artifact.
