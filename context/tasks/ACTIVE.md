# Активная работа —28.10.2026

## STUDIO-01 / PR107

Владелец сообщает «Все работает» после поставки исправленного2800; новая точная
terminal-версия не представлена. Source a3df5783/local2801/theme2700 добавляет масштаб
материала50–150% и независимые боковые панели. Node/browser/delayed-resize проверки
пройдены. Готовится отдельный2800→2801 архив: тот же paired snapshot/recovery, строгие
fresh/legacy индексы, прежняя task policy; старые операторы и worker неизменны.
[План](../../docs/superpowers/plans/2026-10-28-studio-viewport.md),
[квитанция поставки](../runtime/studio_viewport_delivery_20261028.json).
Первый context CI отказал из-за устаревшей generated описи; описи обновляются.
Установка2801 и её owner status ещё не выполнены; PR107 остаётся открыт.

## Основания production

Последняя точная owner-terminal версия:2d95137a/local2600/theme0202 через62b0;
worker6c56 не обновлялся. [Установка](../runtime/course_studio_owner_deploy_20261027.json),
[post-deploy](../runtime/course_studio_post_deploy_owner_20261028.json): app_ready/DB/services,
scheduled27Oct20:22UTC exit0 без новых ошибок, базовый browser/import подтверждены.
Позднее2700 пакет выдан, скриншоты показывают студию в Академии без точной версии.
Отказ2800 precheck не заменяет новый terminal status и не подтверждает upgrade.
Не повторять установку2600 или диагностику исторической cron ошибки20Oct.

## Очередь и границы

Main содержит основу6c56/local2300, PR105/103/106 интегрированы; PR107 открыт.
После студии PERF-01, INF-12 и путь сотрудника на телефоне.
Docker rotation/health/recreate отложены до обновления Moodle/PHP.
SSH/firewall — перед внешним доступом по поручению владельца; внешний restore unknown.
Субагенты/SSH/автоматический merge запрещены. Не создавать второй каталог/прогресс/evidence.
