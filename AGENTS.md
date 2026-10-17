# USTAR инструкции агентам

Owner18Oct00:27MSK [Telegram ID helper failure](context/runtime/console_telegram_transport_20261018.json): getUpdates transport_unavailable, ID/config/ACK не получены. Запрошены tokenless DNS/direct IPv4+IPv6 probes. Не просить token и не делать вывод о блокировке/неверном token без диагностики. Storage fixes/artifact продолжаются; Telegram и домашний VPN остаются задачей владельца.

Owner18Oct00:14MSK прислал [host/sandbox и SMART probes](context/runtime/console_storage_probe_20261018.json): HDD host rw/service ro подтверждает defect проверки, SMART oneshot0/timer active; SSD197/199 не предоставлены, оба health_passed=true. [Fix/update](server-console/STORAGE_FIX_20261018.md) подготовлен в PR,125 backend PASS; новый artifact/owner update и свежая приёмка ещё ожидаются. Нельзя объявлять физическую неисправность из старого probe_failed или подставлять нули вместо атрибутов. Защита служб сохраняется. Старые receipts неизменны; Telegram/VPN запрос остаётся активным.

Owner17Oct23:59MSK: пульт открывается в браузере; запрошены Telegram и домашний доступ без входящих портов. [Новый receipt](context/runtime/console_browser_open_20261017.json), [инструкция](server-console/REMOTE_ACCESS.md). Startup notifier defect getWebhookInfo без data argument исправлен в PR;111 backend tests PASS, точечный owner hotfix и receiver ACK ещё ожидаются. Tailscale single-host route предложен, не установлен; не объявлять VPN/бот подключёнными. TOTP остаётся пропущенным по выбору владельца; login/roles/CA trust и HDD/SMART diagnosis открыты.

Владелец установил пульт567e90d5 в корпоративной LAN; [receipt17Oct23:36MSK](context/runtime/console_native_install_20261017.json) подтверждает local-CA TLS, fresh snapshot, web/broker/collector timer active и изоляцию web identity. Limited native_accepted=true не закрывает источники: HDD critical/probe_failed, SMART no_data/probe_failed требуют диагностики host mount/sandbox и SMART timer/receipt. TOTP и Telegram владелец пропустил; не включать их вопреки этому выбору. Browser trust/login/roles/download hash/receiver/observer/jobs/overhead/restore остаются открыты. [LAN выпуск](server-console/LAN_ROLLOUT_20261017.md), [установка](server-console/INSTALL.md). Нет прямого SSH: owner-команды; tokens/password/TOTP/CA keys только локально.

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

[INF-06 пульт и Telegram](context/runtime/control_panel_telegram_20261016.md) продолжается после актуализации контекста и повторного review. [PR103](https://github.com/cauf1l3d/ustarlms/pull/103) содержит LAN пульт и прототип с графиками. Установлен567e90d5 по owner receipt; последующие fixes ещё требуют применения. Различать код, установленный выпуск и native acceptance. Telegram delivery, adapters и оставшиеся native gates открыты; произвольный shell недопустим.
