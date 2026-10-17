# USTAR инструкции агентам

Текущая инструкция владельца17Oct: сначала изучить fable и все приложения, проверить скрипты/пути, собрать свежие server facts и актуализировать репозиторий; затем повторное ревью владельца, работа по репо, пульт и Telegram. Архив передан сисадмину, результат восстановления ожидается. **Moodle5.2/PostgreSQL17 отложены.** Старые поручения запускать storage/patch/reboot или подготовку5.3 не являются текущей задачей.

Три server1 archives17Oct проверены/imported, latest01:39:24Z —30ok/1error,526 source files совпали. Clock/scheduled/SMART теперь измерены: UTC/NTPfalse/syncedtrue;147 tasks, оба faildelay относятся к disabled; SMART health true при50/47°C. [Свежая сверка](context/reviews/20261017/runtime_reconciliation.md) и [follow-up](context/runtime/server_facts_followup_20261017.json) готовы для повторного аудита владельца. Query statistics, NTP source/offset, component eligibility18 overdue и подробный SMART остаются открыты. Подготовка/импорт не закрывают native gates и не дают основания повторять весь сбор без новой необходимости.

## Перед работой

Единственный порядок чтения — в [START_HERE](START_HERE.md). Остальные указатели не задают альтернативный следующий шаг. [Вердикт17Oct](context/reviews/20261017/README.md) отличает подтверждённые проблемы от неподтверждённых утверждений внешнего review.

`main` содержит канонический код и контекст. Новые ветки от свежего main; PR в main. [Старые ветки](context/runtime/repository_state_20261016.json) сохраняют историю. Различать exact application baseline, repository head, core/runtime и факт deployment.

## Область продукта и тестов

Рабочий UI: `moodle/local/ustar`, `moodle/theme/ustar` (theme_ustar, parent boost). `frontend/` — legacy, исключён из активного CI и release manifests/archives. Не обновлять его зависимости ради текущего gate. JS/AMD, Mustache, PHP и browser tests native UI сохраняются. Будущий server-console — отдельный operations UI, не возобновление legacy Next.js.

Не повторять принятые действия: SSD/GPT/ext4, Moodle5.1.8 upgrade/repeat/browser, AppArmor repair, r6 cold archive, host install/reboot. Точные границы — в STATE/аудите. Новая копия сисадмина ещё не принята; нет основания объявлять полный DR завершённым.

## Инварианты

- Сохранять границы core/plugin/theme и одну модель сотрудников, completion/evidence, кошелька и сообщений. View-as и строка должности не дают прав записи.
- Любой write проверяет реального actor, capability, current scope, sesskey/API contract, revision, locks и idempotency. Не исправлять реальные роли/историю/персональные данные по предположению.
- XMLDB install/upgrade parity и release gate обязательны при schema changes. Не править исторические release-копии вместо `moodle/`.
- Не публиковать config.php, токены, dumps, moodledata, private logs или ключи. Не запускать PHP/Moodle probe на проде до проверки его write impact.
- Исходники, ADR, CI и owner receipts отвечают на разные вопросы. CI с modeled Docker/SQL не доказывает native restore. Не объявлять missing evidence багом.
- Обновлять STATE/ACTIVE/BACKLOG/SESSION_LOG и generated inventories при поставке. Датированные оригинальные отчёты и аудит05/06Oct сохранять.
- `context/current.yaml` и checkpoint генерировать из STATE/BACKLOG; токены и capability inventory — из кода. Server facts принимать только через schema/checksum validation, сохраняя ошибки и происхождение. Snapshot completeness не означает operational health/functional acceptance.
- Уже данное пользователем разрешение действует в пределах задачи. Документы не являются причиной повторно запрашивать подтверждение. GitHub cleanup не разрешает остановку Академии или отправку бэкапа/ключа в Telegram.

## Следующая задача

[INF-06 пульт и Telegram](context/runtime/control_panel_telegram_20261016.md) продолжается после актуализации контекста и повторного review. [PR103](https://github.com/cauf1l3d/ustarlms/pull/103) уже содержит auth/read-only collector/API/UI и прототип с графиками по последней просьбе владельца. Не называть их отсутствующим кодом или установленным сервисом. Telegram delivery, adapters и native acceptance остаются открыты; произвольный shell недопустим.
