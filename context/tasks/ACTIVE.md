# Активная работа —28.10.2026

## PERF-01 / PR109: серверная поставка2802

Владелец согласился на следующий этап интеграции и отдельной серверной поставки.
[Разрешение и source integration](../runtime/perf2802_integration_20261028.json).
PR107 слит как84a078ef; main содержит принятую Studio2801. PR109 переведён на main.
Query source aa2b09be прошёл все четыре CI; PHP1337/231, оба native354/1695.
[Предыдущая квалификация](../runtime/perf_query_batching_review_20261028.json).

App22607c69 фиксирует local2802/theme2700: три рабочих PHP-файла, version.php и один
добавленный native test относительно принятой Studio.589→590файлов;100таблиц без schema delta.
Готовится новый адаптер/точный Git builder поверх оператора81118e39 с отдельным журналом
/var/lib/ustar-perf-query-update. Обязательны точный2801 predecessor, shared locks,
paired copy/recovery, неизменные cron/task/worker guards, actual UID33 и PG legacy/fresh profiles.
[План](../../docs/superpowers/plans/2026-10-28-perf2802-delivery.md).
Новый оператор/релиз ещё квалифицируется;2802 на server1 не устанавливался.

## Принятая студия

STUDIO-01 завершён: app a3df5783/local2801/theme2700, оператор81118e39, архив принят по
owner «всё работает». [Квитанция](../runtime/studio_viewport_owner_acceptance_20261028.json).
Нового точного terminal status нет; последняя2600-квитанция остаётся отдельным фактом.
Worker6c56 не обновлялся. [Команды2801](../../docs/STUDIO_2801_INSTALL_RU.md).

## Границы

После green exact-head CI — слияние PR109 и отдельный комплект владельцу.
SSH, субагенты и отправки через провайдеров запрещены. Логи/health/recreate — в Moodle window.
Workday/SLO и внешний restore остаются отдельными задачами; общий p95 не доказывает ускорение.
