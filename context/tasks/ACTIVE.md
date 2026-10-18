# Активные задачи USTAR

## Сейчас — INF-06: пульт после актуализации контекста17Oct

Продолжение18Oct: [выпуск1f605e4d проверен](../runtime/console_telegram_release_20261018.json): все3 CI SUCCESS,175 backend/45 Chromium, actual Xray DynamicUser/LoadCredential/default-deny и updater rollback PASS. Artifact12118052115 скачан, ZIP/внутренний tar.gz/SOURCE_COMMIT сверены; [точная выдача и4 шага установки](../../server-console/TELEGRAM_RELEASE_20261018.md). На server1 по-прежнему подтверждён только567e90d5; новый update, узлы подписки, ID/config и receiver ACK ожидаются. По новому требованию стабильности сети прежний subnet route отменён как план: [следующий кандидат — userspace/SSH](../../server-console/HOME_ACCESS_PLAN_20261018.md), Windows pilot обязателен; VPN не установлен. Сеть/прод агентом не менялись.

Owner18Oct02:07MSK получил [санитизированный результат](../runtime/console_vpn_format_20261018.json): JSON_outbounds,15 VLESS (10 gRPC/5 TCP,14 Reality/1 TLS), insecure flags0, exit0. TTY retry успешен; это не проверка узлов/соединения. Подготовлены [Telegram-only Xray и явный CONNECT transport](../../server-console/VPN_TELEGRAM.md): loopback18081, только api.telegram.org:443, DynamicUser/LoadCredential, pinned binary SHA256, host routes/DNS не меняются. Локально175 backend/28 focused и реальные TCP/gRPC/Reality/TLS config checks PASS; native CI/artifact и owner install/ID/receiver ACK ожидаются. Full URL/config не получены, TOTP остаётся пропущенным; домашний доступ ещё отдельно.

Owner00:27MSK [Telegram ID helper](../runtime/console_telegram_transport_20261018.json) прошёл локальный token format parser, но getUpdates завершился transport_unavailable. ID/config/delivery не подтверждены. Запрошены date/DNS/direct curl IPv4+IPv6 без token; не приписывать блокировку, TLS, ошибочный token или IPv6 без результатов. Работа над storage artifact продолжается параллельно с owner diagnostics.

Owner18Oct00:14MSK прислал [диагностику HDD/SMART](../runtime/console_storage_probe_20261018.json): UUID совпадает, host rw/service ro, SMART oneshot0/timer active. SSD PASSED50°C с отсутствующими197/199, HDD PASSED48°C и selected counters0. Timestamp SMART20:50UTC не задаёт время остальных команд. В PR подготовлены dual-view mount/identity check, точные причины SMART NoData/partial и per-disk UI;125 backend PASS. [Fix/update runbook](../../server-console/STORAGE_FIX_20261018.md), guarded wheel updater с rollback/auth/CA preservation; owner installation/fresh receipt ожидаются. Прежние install/browser receipts неизменны. Telegram/VPN запрос сохраняется, delivery/access ещё не подтверждены.

Владелец17Oct23:59MSK подтвердил, что пульт открывается в браузере, и запросил Telegram/доступ из дома без входящих портов. Нашлась ошибка startup notifier: getWebhookInfo без обязательного data argument. Исправлена в PR; новый regression воспроизводит TypeError до fix и проходит полный первый цикл после fix. Установленный567e90d5 требует точечного owner hotfix перед Telegram setup;111 backend tests PASS. Receiver ACK не получен. [Telegram и частный доступ](../../server-console/REMOTE_ACCESS.md) описывают polling, ручной numeric ID и предложенный Tailscale route только к host/port; VPN не установлен. CA trust/login/roles из фразы «открывается» не следуют. HDD/SMART diagnostics по-прежнему ожидаются.

Владелец установил CI artifact567e90d5; [terminal receipt23:36MSK](../runtime/console_native_install_20261017.json) подтверждает local-CA TLS, свежий snapshot, active web/broker/collector timer и изоляцию web identity. Limited native_accepted=true не принимает все источники: HDD critical/probe_failed и SMART no_data/probe_failed требуют диагностики. TOTP и Telegram пропущены по выбору владельца, notifier inactive. Следом проверить host mount против sandbox сборщика, SMART timer/receipt, затем CA trust и вход в браузере. У агента нет SSH. [Объём и остаток](../../server-console/LAN_ROLLOUT_20261017.md), [установка](../../server-console/INSTALL.md). Native роли/download hash/receiver/observer/jobs/overhead/restore, p95/5xx/logs/SFTP/TTL/admin UI остаются открыты.

Последний gaps archive13:59MSK проверен:6/6 probes, все24 checksums/CRC и output schemas согласованы. [Сверка](../reviews/20261017/gaps_reconciliation.md), [provenance](../runtime/server_gaps_received_20261017.json), [coverage](../coverage.yaml). ACL44175 code objects/seven parents: extended entries0, но4726 UID33 owner-writable и writable outer root/config/plugin/parent подтверждают незакрытый риск замены по POSIX. SSH текущего aduk-контекста получен, Caddy PID862 владеет2019 loopback/all sockets; [поздний внешний тест](../runtime/external_network_result_20261017.json) с домашнего Windows ПК дал TCP2019=False к IPv4 выхода сети. Это один источник/маршрут; NAT, другие источники/IPv6/API и listener hardening не проверены. Все23 extras получили metadata/SHA256, selected du не равен полному SSD/containerd inventory. Владелец установил acl2.3.2-1build1.1; agent SSH/production changes не было. Старые31-block snapshots/plugin526/core24742/NTP/SMART/backup receipts не менялись. SQL stats, runtime/SSH/TLS/native/DR gates и INF-01/P0 целиком не закрыты; контекст готов для повторного аудита владельца.

## Согласованный полный объём INF-06

Реализовать [пульт управления и Telegram](../runtime/control_panel_telegram_20261016.md), опираясь на свежий main и прототип PR103. Linear-inspired UI на том же сервере, login/password и защищённый доступ; метрики/алерты, стенды/адреса/TTL, SFTP, ручные backup/restore и управляемые операции Академии. Telegram показывает статус/метрики, алертит failure/recovery/NoData и выдаёт последний проверенный зашифрованный бэкап по запросу разрешённого оператора.

В [PR103](https://github.com/cauf1l3d/ustarlms/pull/103) исходный read-only этап98559017 дополнен Telegram/job/download/LAN setup. Установленный владельцем567e90d5 прошёл все три CI;110 backend и36 Chromium cases успешны. PR открыт, main не merged. Новый owner receipt принимает только установочный gate; native delivery/source/role/restore acceptance не завершены. Зависимости INF-02/03/05/07 остаются отдельными рисками; не создавать для пульта неограниченный root/Docker socket ради обхода.

## Ожидание внешнего результата OPS-02

Владелец16Oct подтвердил: архив получен, инструкция передана сисадмину. [Receipt](../runtime/sysadmin_handoff_result_20261016.json). Ожидаются clean-host import, вход разными ролями, файлы/SCORM, сохранение прогресса и reboot копии. Не повторять упаковку без новой необходимости, не считать успешную передачу доказанным restore.

## Отложено PLAT-01

Moodle5.2 и PostgreSQL17 по команде16Oct. PR101/загруженные5.3 assets — история исследований, не согласованная текущая версия для запуска. Не создавать новый стенд и не переключать production платформу в рамках пульта.

## Принятые этапы

SSD11Oct; full-addon lab upgrade/repeat/paired rollback12Oct; production5.1.8 upgrade/repeat/brief browser/cron13Oct; AppArmor13Oct; r6 SSD archive/63 packages/native reboot/mount/ID-policy14Oct; archive handoff16Oct. Детали и ограничения — [аудит](../audits/AUDIT_RECONCILIATION_20261016_RU.md).

Общий остаток, включая DB superuser, runtime code write, HTTPS, SMTP/disabled tasks, retention/offsite, ACL/Privacy/performance — [BACKLOG](../roadmap/BACKLOG.yaml). Ошибка route refresh не исправлялась и сохраняется в OPS-01.
