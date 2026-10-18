# Runtime и эксплуатация

Владелец18Oct сообщил: бот отвечает. [Новый receipt](console_bot_working_20261018.json) принимает этот факт; прежний credential crash сохранён как история. Установлен полный пакет1f605 плюс последующее исправление notifier по контексту; точный native hash/hotfix stdout не прислан. В PR103 опубликован кодb275f899: понятные русские карточки, семь кнопок, доступные агрегаты и информативные автоалерты;223 backend PASS, два material review findings исправлены через RED→GREEN, все3 CI SUCCESS и artifact12120404826 сверены; Mini App — отдельный opt-in с существующим входом и обязательной проверкой доступа/HTTPS на телефоне. Сеть/прод/токен/CA не перенастраиваются. Новый пакетb275f899 ещё не установлен владельцем. [Выдача и команды](../../server-console/TELEGRAM_OPERATOR_20261018.md); [source/CI/artifact receipt](console_telegram_operator_20261018.json).

Owner18Oct02:07MSK получил [санитизированный результат](console_vpn_format_20261018.json): JSON_outbounds,15 VLESS (10 gRPC/5 TCP,14 Reality/1 TLS), insecure flags0, exit0. TTY retry успешен; это не проверка узлов/соединения. Подготовлены [Telegram-only Xray и явный CONNECT transport](../../server-console/VPN_TELEGRAM.md): loopback18081, только api.telegram.org:443, DynamicUser/LoadCredential, pinned binary SHA256, host routes/DNS не меняются. Локально175 backend/28 focused и реальные TCP/gRPC/Reality/TLS config checks PASS; native CI/artifact и owner install/ID/receiver ACK ожидаются. Full URL/config не получены, TOTP остаётся пропущенным; домашний доступ ещё отдельно.

**Owner18Oct00:27MSK:** [Telegram helper transport failure](console_telegram_transport_20261018.json), ID/persistent setup/receiver ACK не получены. DNS/direct curl IPv4+IPv6 запрошены без token; причина не установлена.

**Owner18Oct00:14MSK:** [HDD/SMART probes](console_storage_probe_20261018.json): host rw/sandbox ro, SMART oneshot0/timer active, SSD PASSED50°C/197+199 absent, HDD PASSED48°C/selected counters0. Приёмка и остальные probes без timestamp; не выводить возраст из receipt time. [Fix/update](../../server-console/STORAGE_FIX_20261018.md) в PR, native application pending. Старые receipts не перезаписаны.

**Продолжение18Oct:** [owner browser report17Oct23:59MSK](console_browser_open_20261017.json) подтверждает только открытие пульта; запросил Telegram/домашний доступ. Startup notifier argument defect исправлен с regression в PR, owner hotfix/receiver ACK ожидаются; [инструкция](../../server-console/REMOTE_ACCESS.md). Tailscale предложен, VPN не установлен. Старый install receipt сохраняется без изменений.

**Дополнение23:36MSK17Oct:** [owner terminal receipt установки пульта](console_native_install_20261017.json), exact artifact567e90d5. Local-CA TLS, свежий snapshot, web/broker/collector timer active и web identity isolation прошли limited gate. HDD critical/probe_failed и SMART no_data/probe_failed не устранены; причина ещё не измерена. TOTP/Telegram пропущены владельцем. Browser trust/login/roles/download hash/receiver/observer/jobs/overhead/restore остаются открыты. Это pasted output, без archive checksum/независимого SSH; более ранние snapshots не менялись.

**Дополнение14:24MSK17Oct:** [owner terminal receipt](external_network_result_20261017.json) — IPv4 выхода server1 совпал с target домашнего TCP2019 test; TcpTestSucceeded=False, отдельно ping timeout. Адреса/локальное имя не опубликованы. Один источник/маршрут, точное время probes неизвестно; NAT, другие источники/IPv6/API и listener hardening не установлены. Это отдельный terminal follow-up, не часть archive checksums.

Предыдущее дополнение13:59MSK17Oct — [gaps review](../reviews/20261017/gaps_reconciliation.md), [provenance](server_gaps_received_20261017.json): ACL, текущий SSH-контекст, Caddy2019 PID, metadata/hashes23 extras и selected du. ACL экспортирован после owner install пакета acl; writable parents/code, внешняя доступность и native gates остаются открыты.

Дополнительно проверены два core/extra ZIP: [разбор13:05MSK](../reviews/20261017/core_extra_reconciliation.md), [provenance](core_extra_received_20261017.json). Core24742 match/23 extras, ntpsec peer/offset, SMART attributes/history и component-skip explanations дополняют три старых snapshots, сохраняя их даты/ошибки. На момент этого более раннего среза getfacl отсутствовал; поздний gaps archive содержит ACL. Native gates открыты.

Датированный срез16Oct ниже сохранён по owner receipts. Свежие selected observations17Oct получены в трёх [owner archives](server_facts_received_20261017.json), без прямого подключения агента; [сверка17Oct](../reviews/20261017/runtime_reconciliation.md). Прежние [evidence](evidence_20261016.yaml), [карта сервера](server_map_20261016.md), [выполненное и остаток](../audits/AUDIT_RECONCILIATION_20261016_RU.md) сохраняют свои даты/границы.

- Moodle5.1.8, PHP8.3.33, PostgreSQL16.15; host Ubuntu24.04.5/kernel147/Docker29.9.0 приняты.
- HDD ежедневный03:30Moscow; успешные scheduled13/14Oct и postboot mount/timer подтверждены.
- [Архив получен и инструкция передана сисадмину](sysadmin_handoff_result_20261016.json); [runbook копии](production_copy_20261015.md). Restore ещё ожидается.
- [Пульт и Telegram](control_panel_telegram_20261016.md) — следующая задача. [Метрики](MONITORING.md), [backup contract](BACKUP_RESTORE.md).
- Moodle5.2 и PostgreSQL17 отложены. Старые5.3 docs/PR101 не выполнять автоматически.
- [CI](CI_SCOPE_20261016.md), [ветки](repository_state_20261016.json), [история](RELEASE_LEDGER.md), [операции](OPERATIONS.md), [security](SECURITY_BASELINE.md).
- [Review17Oct](../reviews/20261017/README.md) проверяет новые предложения; [три fresh server facts](server-facts/README.md) импортированы, [сверка и gaps](../reviews/20261017/runtime_reconciliation.md) готовы для повторного аудита. Порядок входа — только [START_HERE](../../START_HERE.md).

Dated reports05–15Oct сохраняются. Их pending/next-step относятся к своей дате; текущая очередь — STATE/ACTIVE. Не повторять SSD growth, Moodle patch, AppArmor repair или host install/reboot.

- [Проверенный Telegram/Xray выпуск18Oct](console_telegram_release_20261018.json): CI/artifact/source verification, не server1 acceptance.

- [Native update/Xray и отказ notifier credential guard18Oct](console_telegram_native_20261018.json).

- [Notifier credential ACL fix/actual PID1 CI18Oct](console_notifier_credential_fix_20261018.json), [owner hotfix](../../server-console/NOTIFIER_CREDENTIAL_FIX_20261018.md).
