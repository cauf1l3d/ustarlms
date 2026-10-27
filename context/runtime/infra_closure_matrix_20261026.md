# Инфраструктура — актуальная матрица26Oct2026

Сверка принятых областей и решений владельца; не новая проверка сервера.
Источники: evidence_20261026.yaml, repository_reconciliation_20261026.json и
../roadmap/ACCEPTED_RISKS.yaml. Done ограничено указанной областью и датой.

| Область | Принято / выполнено | Остаток и пересмотр |
|---|---|---|
| Права/секреты | Обычный ustar_app, code/config hardening, private legacy/source/exports и control companion | Общая граница www-data/caddy требует отдельной проверки |
| Docker/место | Классификация слоя, source0600 и прежнее SSD growth приняты | Rotation/health/recreate accepted_risk до планового Moodle upgrade или аномального роста |
| Доступ | LAN login/password принят владельцем | SSH/firewall accepted_risk до подготовки внешнего доступа |
| Backup/DR | HDD routine и encrypted companion/readback приняты; копии переданы | Внешний restore unknown; retention/RPO/RTO требуют решения; whole-host DR не доказан |
| Наблюдение | Пульт, корректные NoData/свежесть и scoped журнальные измерения | Независимый host-loss observer/доставка и performance SLO не подтверждены |
| Питание/железо | ИБП присутствует; SMART/OS/boot приняты по dated receipts | Автоматический shutdown и новая динамика SMART unverified |
| Регламент | HTTPS owner и cron приняты | SMTP delivery и политика retention не завершены |
| Приёмка | Git/source/full RC/infra/console/context и scoped owner browser acceptance | Это не полная security/UX/performance/DR сертификация |

Старые next-step команды25Oct не возобновлять. Принятые проверки и явно отложенные
вопросы не блокируют независимую работу приложения. Новое наблюдение или изменение
периметра запускает пересмотр соответствующего риска.
