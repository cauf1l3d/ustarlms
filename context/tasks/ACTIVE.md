# Активные задачи USTAR

## Сейчас — INF-06: пульт после актуализации контекста17Oct

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
