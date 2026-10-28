# Активная работа —28.10.2026

## STUDIO-01 / PR107

Новое поручение: цвета конечного курса/левого меню и сворачиваемые верхние блоки
названия/синхронизации для увеличения полотна. Кандидат local2800/theme2700:
необязательная палитра без миграции fingerprint, оба SCORM builders, компактный
статус/восстановление при ошибке. PHP/Node/full npm/Chromium и R161275/46 PASS.
Source4c48dce7 квалифицирован на053b8c17: все четыре CI SUCCESS, обе native Moodle
матрицы352/1678; HTTP, fresh/upgrade/repeat и rollback PASS. На prod не применено. [План](../../docs/superpowers/plans/2026-10-28-studio-colors-canvas.md),
[прогресс](../runtime/studio_colors_canvas_progress_20261028.md),
[квалификация](../runtime/studio_colors_canvas_qualification_20261028.json).

Предыдущий2700: Academy shell, editable material bridge, Word, три вида каталога;
347тестов/1671проверка в обеих Moodle матрицах, HTTP/rollback PASS.
[Квалификация](../runtime/studio_materials_qualification_20261028.json).
Оператор01e1cef2/архив2600→2700 выдан после четырёх зелёных CI.
[Поставка](../runtime/studio_materials_delivery_20261028.json). Архив неизменен и
новых2800 правок не содержит. PR107 не сливать автоматически; агент не использует SSH.

## Основания production

Последняя точная версия из owner-terminal:2d95137a/local2600/theme0202 через62b0;
worker6c56 не обновлялся. [Установка](../runtime/course_studio_owner_deploy_20261027.json),
[post-deploy](../runtime/course_studio_post_deploy_owner_20261028.json): app_ready/DB/services,
scheduled27Oct20:22UTC exit0 без новых ошибок, базовый browser/import подтверждены.
28Oct владелец прислал скриншоты студии в Академии; новая точная версия/status не
показаны. Не подменять их замером хоста или установкой2800.
Установку2600 и историческую ошибку cron20Oct не повторять.

## Очередь и границы

Main содержит основу6c56/local2300, PR105/103/106 интегрированы через PR108.
[Сверка](../runtime/repository_reconciliation_20261026.json), [STATE](../roadmap/STATE.yaml).
После студии PERF-01: подтверждённые N+1 и пассивный baseline; INF-12: actor/scope/
upload/CSRF; путь сотрудника на телефоне. Не создавать второй каталог/прогресс/evidence.

Docker rotation/health/recreate отложены до обновления Moodle/PHP.
SSH/firewall — перед внешним доступом по поручению владельца. Копии переданы,
внешний restore unknown по решению владельца. Субагенты запрещены.
