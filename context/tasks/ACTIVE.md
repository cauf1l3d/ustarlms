# Активная работа —03.11.2026

## LIBRARY-01: Маркетплейс по отделам и запрет скачивания исходников

Owner03Nov: «полностью скрыт с возможностью открывать по отделам с админки»;
убрать скачивание исходников в Маркетплейсе. Причина235 открытых предложений:
seed6ee использовал free/published. [Fix receipt](../runtime/marketplace_department_fix_20261103.json).

Candidate4715383b: пустой allowlist закрывает вкладку, прямые ссылки и покупку;
администратор выбирает exact department IDs. Источники Word/PDF — только редактору,
проверка UI+pluginfile. Новые seed draft/assigned; старые офферы/права/результаты не
переписываются. Выданные/купленные «Мои материалы», каталог и маршрут сохраняются.
13 существующих файлов, schema/версии0200 прежние. All4CI/FullRC59f482ee SUCCESS; owner deploy pending.
[Работа и приёмка](../../docs/MARKETPLACE_DEPARTMENTS_INSTALL_RU.md).

Production f89dde49/tool5131 уже active/exit0/postcheck active:
[Owner receipt](../runtime/library_navigation_owner_deploy_20261103.json).
Повтор старых установок не нужен. 649файлов/108таблиц — fixed payload, не whole-host census.
Main2900, PR113/114 открыты, merge не разрешён. Worker267/cron — прежние dated receipts.

## PERF-01 после Библиотеки

Нужен пассивный отчёт обычного рабочего дня: latency, SQL, cron lag, нагрузка.
Сборщик frozen9a поддерживает2900; сначала адаптировать для фактически установленной
0200. Фактический8h отчёт не получен. SQL/page, Web-SAPI OPcache и concurrency/SLO
не выводить из Apache aggregate или одного статуса служб. Старые cron19/failed2 и
историю backoff не сбрасывать. Изменения выбирать по измерениям.

## Принятое и ожидающее руководства

PR110/111 объединены по [поручению](../runtime/connect_feed_integration_order_20261101.json).
Main5cc02944/app2900; PR113/114 открыты, новых merge разрешений нет.
[Owner267](../runtime/connect_visual_owner_deploy_20261101.json) принял обоих ботов,
PNG/кнопки/читаемость, задачи/возврат/исполнение/фото после восстановления egress.
RSS и раскрытие вложений также приняты. Остальные процессы проверяет руководство;
публичный HTTPS и Mini App ждут согласования, Bitrix/WhatsApp не включены.

Инструкции владельцу — прямо в чат. Работа самостоятельная: субагенты, agent SSH,
provider sends и новые автоматические слияния запрещены. Docker rotation/recreate
отложены до Moodle/PHP upgrade. Существующие writers и датированные receipts сохранять.
