# Активная работа —28.10.2026

## PERF-01 / PR109

Владелец принял пакет Studio2801 и поручил PR109.
[Owner-подтверждение](../runtime/studio_viewport_owner_acceptance_20261028.json).
Приложение8d456031 интегрировано поверх принятой студии: RSS statistics одним SELECT,
повторные имена open season устранены, closed snapshot получает имена одним batch.
Сохранены права, account/employment filtering, все статусы, порядок/ties, limit100 и типы строк.

На Studio2801 воспроизведены7RED, затем9GREEN PHP/SQLite сценариев. Первое native CI
обнаружило3cursor-команды; buffered aggregate с MIN(id) исправляет причину, assertion1 сохранён.
После исправления localR16:1334/48environment skips PASS; source PHP8.2/8.3:1337/231 PASS.
Все четыре CI на ffc43884 SUCCESS; native/full RC40736531122:354теста/1695проверок
на каждом из двух Moodle/PostgreSQL стендов. Fresh/upgrade/repeat, HTTP и legacy schema PASS.
[Текущий review и точные результаты](../runtime/perf_query_batching_review_20261028.json).

PR109 остаётся Draft; требуются исходная интеграция и отдельная
versioned frozen delivery. Схема/версии, Studio, frozen operators и worker сохранены.
Server1 этим PR не обновлялся. Общий Apache p95 не подтверждает ускорение страниц/SLO.

## Завершённая студия / PR107

App a3df5783/local2801/theme2700, оператор81118e39; квалификация c7602edf и финальный
metadata9332829c: все четыре CI SUCCESS. Архив выдан, владелец сообщает «всё работает».
[Квитанция](../runtime/studio_viewport_delivery_20261028.json),
[команды](../../docs/STUDIO_2801_INSTALL_RU.md). STUDIO-01 завершён по owner acceptance.
Новой terminal-квитанции нет: последнее точное status/source подтверждение2600 хранится
отдельно, не переименовывать его в новую инвентаризацию. Worker6c56 не обновлялся.

## Границы

PR107 пока открыт: автоматическое слияние не разрешено. Main всё ещё содержит6c56/local2300.
Можно готовить и проверять PR109 поверх ветки принятой студии без слияния в main.
SSH, субагенты и отправки через провайдеров запрещены. Логи/health/recreate — в Moodle window.
Не повторять2600/cron20Oct или принятый инфраструктурный цикл; внешний restore unknown.
