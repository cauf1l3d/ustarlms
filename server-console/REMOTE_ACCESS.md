# Telegram и доступ к пульту из дома

Owner17Oct23:59MSK подтвердил открытие пульта браузером. Это не приёмка входа/ролей/CA trust. TOTP остаётся пропущенным; HDD/SMART diagnostics ожидаются. Telegram и VPN пока не подключены.

Продолжение18Oct: [HDD/SMART diagnosis и fix/update](STORAGE_FIX_20261018.md) подготовлены, новый artifact ещё требует применения. Owner00:27MSK ID helper получил transport_unavailable на getUpdates: ID/config/ACK не подтверждены. Запрошены tokenless DNS/direct IPv4+IPv6 проверки; блокировка/ошибочный token/конкретная сеть не установлены. Код также использует этот общий transport code для некорректного/слишком большого ответа; токен в диагностический URL не вставлять.

## Telegram

Серверный worker опрашивает Bot API исходящими HTTPS запросами getUpdates; входящий webhook и публикация порта не нужны. Нужны отдельный бот и разрешённый numeric private user/chat ID, совпадающие друг с другом. Бот выдаёт статус/метрики/ссылку на последний encrypted backup; host jobs выполняются через пульт с авторизацией.

На установленном567e90d5 обнаружена startup ошибка: main вызывал getWebhookInfo без обязательного data argument. В PR исправлено на явный пустой payload; regression воспроизводит прежний TypeError и проверяет первый полный цикл/status receipt.111 backend tests PASS. Native delivery и применение owner hotfix ещё не подтверждены. Исправленный alerts.py SHA256:94903be5412a0e48d21c8154afcda9cf8880b5bd670c0e83400b2df95d8ecd39. Для точечного hotfix скачать именно файл из проверенного fix commit, скопировать в root-owned source stage, проверить этот hash и заменить только /opt/ustar-console/venv/lib/python3.12/site-packages/ustar_console/alerts.py с owner root/mode0644. Перед заменой остановить только notifier, если он уже запускался. Переустановка wheel/зависимостей и повтор полного wizard не требуются.

1. В официальном [BotFather](https://t.me/BotFather) выполнить /newbot. Token хранить локально и вводить скрыто. Не использовать бота с existing webhook или вторым poller.
2. Получить свой numeric ID через собственный dedicated бот. getUpdates позволяет прочитать from.id из сообщения, отправленного владельцем. Проверять конкретное личное сообщение/одноразовую строку, не выбирать автоматически первого отправителя и не отправлять token сторонним ID-ботам.
3. После hotfix выполнить:

```bash
sudo /opt/ustar-console/venv/bin/ustar-console-setup --telegram-only
```

Ввести token и numeric ID (несколько — через запятую без пробелов). Пароль владельца и CA не запрашиваются повторно. Existing webhook отклоняется без удаления. Wizard запускает notifier; token хранится root-private и передаётся службе через LoadCredential.

4. В личном чате своего бота отправить /start, затем /status. Другие команды — /metrics, /academy, /labs, /backup latest. Между командами выдержать10с: действует ограничение ответов. /backup latest выдаёт metadata+авторизованную ссылку пульта, без ключа/нового capture. Для ссылки из дома нужен настроенный частный маршрут.
5. Проверить systemctl is-active ustar-console-notify.service и получение ответа разрешённым владельцем. Active/API ACK не заменяют receiver ACK; до ответа владельца delivery acceptance открыта.

## Предложенный частный маршрут

Tailscale на server1 и домашнем ПК связывает разрешённые устройства через зашифрованную сеть. Обычно не требует ручного port forwarding; при невозможности прямого соединения доступен DERP relay. Исходящий доступ к coordination/relay HTTPS и Telegram всё равно требуется. Корпоративная сеть с полностью запрещённым egress не может использовать эти внешние сервисы без разрешённого пути.

Текущий console bind/origin/cert привязан к LAN IPv4. Python Settings также отвергает CGNAT IPv4 как non-private; просто подставлять100.x Tailscale IP в config нельзя. Для этого установленного выпуска предложен subnet route только192.168.1.60/32 и grant только выбранному владельцу/устройствам на TCP8443. Это проектное применение официального subnet-router механизма, не нативно проверенный маршрут. Не объявлять всю LAN/default route. Новый Tailscale router меняет интерфейсы/правила host networking; перед установкой сверить существующие Docker/firewall/IP forwarding и доступ из локальной сети. Отдельная route approval и отсутствие более широкого default allow обязательны для заявленного ограничения.

Последовательность: установить клиенты с официального источника; подключить к одному tailnet; на сервере настроить только нужный host route; одобрить маршрут в admin console; настроить узкий grant; на домашнем Windows включить VPN; импортировать public console CA; открыть прежний HTTPS LAN адрес. Проверить доступ к8443 и отсутствие доступа к посторонним портам/узлам. Windows принимает одобренные subnet routes автоматически. Если домашний компьютер сам использует адрес цели, сначала устранить этот конфликт. Конкретные owner-команды готовить с фактической политикой/состоянием сети; VPN пока не устанавливался.

Источники, проверенные18Oct00MSK: [Telegram создание бота](https://core.telegram.org/bots/tutorial#obtain-your-bot-token), [Bot API/getUpdates](https://core.telegram.org/bots/api#getupdates), [Tailscale firewall и DERP](https://tailscale.com/docs/reference/faq/firewall-ports), [subnet routers/route approval/grants](https://tailscale.com/docs/features/subnet-routers). Tailscale routing/ACL и console HTTPS — отдельные слои. Приёмка host-loss по-прежнему требует другого работающего LAN observer.
