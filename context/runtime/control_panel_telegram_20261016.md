# Пульт управления и Telegram: текущий этап

**Продолжение17Oct17:03/17:09MSK:** владелец разрешил закончить остаток и устанавливать пульт; первый запуск во внутренней корпоративной сети. [Текущий LAN комплект/границы](../../server-console/LAN_ROLLOUT_20261017.md), [owner установка](../../server-console/INSTALL.md). Native HTTPS/PG monitor/history/NTP/SMART/TOTP/alerts/Telegram/download/fixed jobs/observer добавлены в PR103; source/receiver/role/overhead/host-loss/native acceptance ещё нужны. Full request stats/logs/SFTP/restore/TTL/admin UI остаются открыты. Архивы и main/production не переключены.

Задача INF-06 после GitHub-сверки16Oct. Рабочая Академия остаётся на Moodle5.1.8/PG16; Moodle5.2/PG17 отложены. UI-прототип PR103 в Linear-inspired graphite стиле сохраняется. Первый read-only backend/auth/UI добавлен в PR103; установленного на server1 пульта/бота пока нет. Владелец17Oct16:30MSK разрешил продолжить; [изменения по аудиту](../../server-console/AUDIT_FOLLOWUP_20261017.md).

## Архитектура подготовлена16Oct

По новой команде владельца сначала спроектированы [компоненты и доступ](../../server-console/ARCHITECTURE.md), [health/метрики/алерты](../../server-console/OBSERVABILITY.md) и [UI/UX Linear/Apple](../../server-console/DESIGN.md). Host-сервисы вне Moodle, Prometheus/Alertmanager, независимый observer, четыре health-домена, защищённая выдача backup и ограниченные jobs. Пороги и бюджеты — стартовая политика для измерения; auth/collector/API/UI первого среза реализованы в исходниках; bot и native deployment впереди.

## Продукт

Пульт на том же server1, без отдельного поддомена, с понятной компактной навигацией и логином/паролем. Защищённый доступ извне через выбранный HTTPS/VPN gateway; не публиковать admin interface по открытому HTTP. Не переписывать Академию в legacy Next.js.

Разделы: обзор, сервер, Академия, стенды с адресами/активностью/временем, бэкапы, SFTP, алерты и операции. Показывать реальные значения, их время и ошибки; никаких demo success на production. Пользователь должен видеть, какая среда и какой архив будут затронуты действием.

## Реализация по порядку

1. Auth/session/CSRF и ограниченный read-only collector/API. Свести host/container/HTTP/PG/cron/HDD/backup данные и freshness/NoData с проверкой побочных эффектов выбранного HTTP probe и без cluster superuser/full Docker socket в UI. Обычный Moodle GET может создавать session/log записи; не объявлять его абсолютно read-only.
2. Подключить настоящий UI вместо demo и Telegram alerting. Дедупликация failure/recovery, окно ожидаемой backup-паузы с пределом, повтор доставки и явный NoData. Отдельный внешний observer для потери server1.
3. Telegram команды разрешённых numeric user/chat IDs: status/metrics/Academy/labs/backup latest. Ответ latest содержит время/размер/SHA и выдачу последнего completed/hash-verified зашифрованного Academy archive; не запускает новый backup.
4. Ограниченные job adapters: manual backup, lab start/stop/TTL, Academy status/maintenance/restart и подготовка restore. Перед изменением показать цель, expected outage/data effect, lock и план; выполнение/audit/job state переживают закрытие браузера. Бот не исполняет произвольный shell.
5. Приёмка на реальном host: доступ/роли/запреты, receiver delivery, контролируемые failure/NoData/recovery, download/hash, concurrency/cancel/resume и отсутствие побочных изменений Академии/почты/DNS.

## Выдача бэкапа

Текущие Academy архивы около1GB, SSD root.tar43.7GB; передача прямым cloud Bot API sendDocument до50MB не покрывает этот объём. Основной вариант — короткоживущая download-ссылка пульта с проверкой оператора, фиксированным archive ID/hash, журналом и без directory traversal. Ссылка не должна давать доступ к recovery key или transfer tar с ключом. Архив .age остаётся зашифрованным; ключ получает сисадмин отдельным существующим безопасным каналом.

Telegram официально поддерживает local Bot API upload до2000MB, но отдельный daemon/диск/доступ пока не выбраны и не добавляются автоматически. Это альтернатива, не обещание текущей поставки. Первичный источник проверен16Oct: [Bot API](https://core.telegram.org/bots/api#senddocument), [local server](https://core.telegram.org/bots/api#using-a-local-bot-api-server).

Политика «актуальный»: последний успешный согласованный archive с verified manifest/hash, с явной датой snapshot. Не выдавать partial/last-attempt вместо last-success и не обещать текущие live-данные. Передача private архива через Telegram сейчас не выполняется.

## Границы и открытые настройки

Сохранять Academy identities/mounts/images, cron v3 single worker и maintenance locks. Нужные host mail/DNS/ISPConfig не останавливать общей кнопкой. Manual restore Академии, перенос и full SSD/whole-host restore — разные планы. Метрики не исправляют данные, route progress, роли или исторические task errors.

Для native внедрения нужны реальные bot token/operator IDs, защищённый endpoint и независимый observer, collector budgets и права helper. Это входные настройки следующей задачи; текущая GitHub-сверка их не выдумывает. BOT/API secrets не коммитятся. Результат восстановления сисадмина остаётся OPS-02 waiting_external и не блокирует начало read-only пульта.

## Реализация первого среза16Oct

[Исходники и границы](../../server-console/README.md), [установка/сверка](../../server-console/INSTALL.md). Collector пишет только sanitized snapshot, веб не получает Docker/root и не выполняет host jobs. История доступна через fixed Prometheus API; stack/rules/DB/cron/external observer/log adapters ещё не подключены. Полный health не зелёный при неизвестности обязательных проверок. Исходный demo сохранён отдельно. Сервер не изменялся.
