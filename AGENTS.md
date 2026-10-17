# USTAR инструкции агентам

Владелец17Oct16:30MSK подтвердил переход к пульту после сбора и актуализации контекста. Продолжать INF-06 в PR103 с требованиями аудита; [сверка реализации](server-console/AUDIT_FOLLOWUP_20261017.md). Предыдущий этап: изучены fable/приложения, исправлены скрипты/пути, проверены owner archives и контекст PR105. Это разрешение продолжить разработку, не новый результат независимого аудита или установка на server1. Архив передан сисадмину, результат восстановления ожидается. **Moodle5.2/PostgreSQL17 отложены.** Старые поручения запускать storage/patch/reboot или подготовку5.3 не являются текущей задачей.

Три 31-block snapshots17Oct сохранены; plugin/theme526 совпали. Отдельный core report:24742 matching/23 extras; NTP source/offset и SMART attributes/history уже получены. Последний gaps archive13:59MSK проверен: ACL44175 objects, SSH текущего aduk-контекста, Caddy PID862 на2019, hashes23 extras и selected du. ACL named/default/mask0, но4726 объектов UID33 owner-writable и writable parents сохраняют риск замены кода. [Последняя сверка](context/reviews/20261017/gaps_reconciliation.md), [provenance](context/runtime/server_gaps_received_20261017.json). Владелец установил только новый пакет acl по terminal receipt; сбор read-only. [Поздний внешний тест](context/runtime/external_network_result_20261017.json): с домашнего Windows ПК TCP2019 к IPv4 выхода сети не установился. Это один источник/маршрут; NAT, другие источники/IPv6 и API не проверены, listener finding не закрыт. SQL stats, полные SSH/runtime/native gates и restore остаются открыты. Контекст готов для повторного аудита владельца; старые данные и deferrals сохранены.

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
