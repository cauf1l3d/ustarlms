# Следующий этап пульт управления и Telegram

Задача INF-06 после GitHub-сверки16Oct. Рабочая Академия остаётся на Moodle5.1.8/PG16; Moodle5.2/PG17 отложены. UI-прототип PR103 в Linear-inspired graphite стиле сохраняется. Это дизайн, а не установленный backend/auth/бот.

## Продукт

Пульт на том же server1, без отдельного поддомена, с понятной компактной навигацией и логином/паролем. Защищённый доступ извне через выбранный HTTPS/VPN gateway; не публиковать admin interface по открытому HTTP. Не переписывать Академию в legacy Next.js.

Разделы: обзор, сервер, Академия, стенды с адресами/активностью/временем, бэкапы, SFTP, алерты и операции. Показывать реальные значения, их время и ошибки; никаких demo success на production. Пользователь должен видеть, какая среда и какой архив будут затронуты действием.

## Реализация по порядку

1. Auth/session/CSRF и ограниченный read-only collector/API. Свести host/container/HTTP/PG/cron/HDD/backup данные и freshness/NoData без Moodle GET writes и без cluster superuser/full Docker socket в UI.
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
