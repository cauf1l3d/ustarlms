# Активная работа —27.10.2026

## Сейчас: STUDIO-01 / PR107

По поручению владельца: исправление подготовки SCORM, академическое оформление,
удобство автора, импорт PDF в редактируемый текст и печатный экспорт PDF. Ветка
объединена с main ad939373; предыдущая принятая основа2300 сохранена; Studio2600 установлен владельцем отдельно от main.
[Инструкция](../../docs/COURSE_STUDIO_RU.md),
[проект](../../docs/superpowers/specs/2026-10-26-studio-readiness-design.md).
Full RC40441818519 на e9ffe6b5 пройден: обе Moodle матрицы335tests/1500assertions,
HTTP save/retry/prepare/actor PASS. Context40441818592, infra40441818514 и console
40441818488 зелёные; mandatory root/UID33 PASS. Владелец27Oct проверил SHA256 и
установил2d95137a через operator62b0da20: upgrade1/2, cron_written, public release,
app_ready/local2600; systemd Finished successfully. [Квитанция](../runtime/course_studio_owner_deploy_20261027.json).
Нужны post-deploy status, scheduled cron2600 и ручная приёмка PDF/shared save/SCORM.
Агент не подключался к серверу; PR107 остаётся открытым.
[Команды и архив](../../docs/COURSE_STUDIO_PROD_DEPLOY_20261027_RU.md).

## Принятая основа

Верификация аудитов и интеграция PR105/103/106 через PR108 завершены. Код Академии
побайтно совпадает с принятым payload6c56a5b6/local2300. Полный RC, context, infra и
console CI прошли; точные SHA — в ../runtime/repository_reconciliation_20261026.json.
[План](../proposals/PROD_TRUTH_PLAN_20261026.md),
[проверенные выводы](../reviews/20261026/VERIFIED_REVIEW_RU.md).

## Следующая работа

1. PERF-01: пассивный baseline горячих пользовательских сценариев; затем batch-запросы
   для подтверждённых feed/competition N+1, с проверкой видимости и полного результата.
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
PR107 Studio установлен владельцем, но не слит; не подменять ручную приёмку успешной установкой.

Ни эта очередь, ни успешный CI не означают применение новых изменений на server1.
