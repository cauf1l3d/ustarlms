# Активная работа —28.10.2026

## STUDIO-01 / PR107

Приложение4c48dce7/local2800/theme2700: Academy Studio, editable material bridge,
Word/PDF, три вида каталога, палитра конечного курса и сворачиваемая шапка.
[Инструкция студии](../../docs/COURSE_STUDIO_RU.md).

Owner --check352d отказал schema_indexes при верных суммах; upgrade не запускался.
Причина воспроизведена на ранее принятом legacy-профиле после theme2700.
Исправленный оператор5f57115d квалифицирован: все четыре CI SUCCESS, обе PHP
матрицы1309R16/230skips, обе Moodle352/1678; mandatory realPG100table fresh/legacy
и7SQL отказов PASS. Старый352d заменён, архив сохранён/скачан/сверен и выдан.
Owner --check и установка исправленного пакета ожидаются.
[Команды](../../docs/STUDIO_2800_INSTALL_RU.md),
[квитанция и причина](../runtime/studio_colors_index_compat_20261028.json).
Проверки не создают/удаляют production indexes. App4c48dce7 и старые операторы неизменны.

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
