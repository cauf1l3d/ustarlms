# Активная работа —27.10.2026

## Сейчас

Интеграция PR105/103/106 через PR108 завершена; её проверки остаются в силе.
На server1 затем установлен Studio payload2d95137/local2600 из отдельного PR107.
Main содержит прежний2300: не выдавать полный архив main за обновление Studio.
PERF candidate меняет только RSS stats/operator names и тесты; не установлен.
Owner27Oct HTTP aggregate314/300s,p95=200.508ms,4xx/5xx=0; источники работают.
CPU/RAM/I/O в коротком окне не насыщены;19raw-overdue только сторонние компоненты.
[Квитанция](../runtime/perf-20261027/observation.json),
[план](../proposals/PERF_QUERY_BATCHING_PLAN_20261027.md).
Полный RC интеграции26Oct: ../runtime/repository_reconciliation_20261026.json.
[План](../proposals/PROD_TRUTH_PLAN_20261026.md),
[проверенные выводы](../reviews/20261026/VERIFIED_REVIEW_RU.md).

## Следующая работа

1. PERF-01: RSS/operator batching RED→GREEN на production PHP+SQLite fixtures;
   native Moodle tests добавлены, пока не выполнены. Перед выпуском интегрировать
   подтверждённую Studio2600 и квалифицировать окончательный SHA. Дальше — реальные
   page/scenario timings, web SAPI OPcache и обычный рабочий день, не synthetic load.
2. INF-12/качество: отрицательные actor/scope/upload/CSRF сценарии, реестр публичных
   входов и опасных CLI, общий интерфейс истории доменных действий. Сначала проверить
   существующие guards/историю, не добавлять их дубль.
3. UX: проверить путь сотрудника от «Мой день» до результата обучения на телефоне.
   Не считать URL отдельным экраном и не сливать HR-authorize с self-service launch.
4. Архитектура: извлекать presenters/readers из изменяемых крупных классов с сохранением
   API; большой разбор route_model без задачи и тестов не начинать.

## Отложено

Docker rotation/health/recreate — следующее плановое обновление Moodle/PHP.
SSH/firewall — подготовка внешнего доступа по команде владельца.
Внешний restore — unknown, не переспрашивать; notification reserve и группы deferred.
PR107 Studio установлен по owner receipt; его ручная UI-приёмка ведётся отдельно.

Ни эта очередь, ни успешный CI не означают применение новых изменений на server1.
