# Активная работа —02.11.2026

## LEARN-ASSESS-01: запуск аттестации Студии

Owner2Nov установил app2150affe/toolb5ead9cb: active/exit0/active. SCORM открывается,
завершается и переводит дальше по настройкам. [Принято](../runtime/scorm_tracking_owner_20261102.json).
Новая проблема: точка открывает /course/view.php?id=30, теста не видно.
В Студии выбрать конкретный тест в «Учебная активность или тест», убрать курс/дубль
материала и опубликовать новую версию. Этот native CM путь уже поддерживается.
[Факты/проверка](../runtime/studio_assessment_route_20261102.json); owner outcome ещё не получен.

## PERF-01: производительность и стабильность обычного рабочего дня

PR110/111 объединены в main после единой FullRC по
[поручению владельца](../runtime/connect_feed_integration_order_20261101.json).
Main5cc02944/app2900; production2150affe/app1100/theme2700, worker267e5587.
Приложение/worker уже установлены владельцем; merge не означает новый deploy.

После проверки аттестации — пассивное наблюдение: latency, SQL, cron lag и нагрузка
в обычный рабочий день. observe.py из frozen9a поддерживает2900; для1100 сначала
нужен проверенный вариант сборщика. [Builder](../../scripts/infra/build_connect_role_bot_update.py).
Исходный workday_observer.py остаётся frozen2802/2803. Фактический8h отчёт ещё
не получен. SQL/page, Web-SAPI OPcache и concurrency/SLO не выводить из Apache
aggregate или одного ответа status. Изменения выбирать по полученным измерениям.

Старый cron/очереди не являются свежим замером; история в STATE и owner receipts.

## INTEGRATIONS-01: принятое и ожидающее руководства

Owner29Oct установил app2900 и включил role-bot штатным9a:
[receipt](../runtime/connect_role_bot_owner_deploy_20261029.json).
Owner1Nov восстановил egress (CONNECT200/HTTP302/TLS0), оба бота отвечают;
worker267 прошёл visuals_active/visuals_ready, recovery_required:false.
[Квитанция](../runtime/connect_visual_owner_deploy_20261101.json).
PNG/кнопки/читаемость, обмен задачами2учёток, возврат/исполнение и фото приняты.
Повторять эту приёмку не требуется. Остальные процессы с руководством с2Nov;
публичный HTTPS и Mini App ждут его согласования. Bitrix/WhatsApp не включены.

Существующие Academy actor/capability/scope/writers и история сохраняются.
Инструкции владельцу — прямо в чат. Самостоятельно, без субагентов; рациональные
проверки. Разрешённые merge110/111 завершены; новых автоматических слияний,
agent SSH и provider sends нет.

## RSS-01: принят владельцем

[Owner2803](../runtime/feed2803_owner_deploy_20261029.json) принял RSS и раскрытие
вложений через «Ещё». Код2803 входит в app2900/main. Старые операторы и
квалификационные квитанции сохраняются неизменными; новый deploy не требуется.
