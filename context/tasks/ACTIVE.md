# Активная работа —28.10.2026

## STUDIO-01 / PR107

2801: app a3df5783/оператор81118e39 квалифицирован на c7602edf. Масштаб материала50–150%,
независимые панели и восстановление их настроек после focus/reload. Все четыре CI SUCCESS;
обе PHP1335/231skips, обе Moodle352/1678, actualPG100table fresh/legacy и7SQL отказов PASS.
R16 локально1332/48skips; mandatory actualUID33 read/write-denial PASS.
Архив byte-identical CI; сохранённая копия скачана и SHA256 сверена.
[Команды установки2801](../../docs/STUDIO_2801_INSTALL_RU.md),
[квитанция](../runtime/studio_viewport_delivery_20261028.json).
Owner2801 --check/apply ожидаются. По2800 владелец сообщает «Все работает», точного нового
terminal status нет. Рабочий код, прежние операторы и worker6c56 не менялись после квалификации.
Новый пакет сохраняет строгие fresh/legacy индексы и task policy, парную копию/recovery.
PR107 открыт, не слит. Самопроверка выполнена без субагентов по AGENTS.

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
