# PERF-01 — результаты и границы27Oct2026

Owner transcript: [неизменённый файл](perf-20261027/owner_2031.txt),
[машинная квитанция](perf-20261027/observation.json). Агент не подключался к server1.

В23:31МСК HTTP source measured:314request/300s,RPS1.0467,p95=200.508ms,
4xx=5xx=0. Это время обработки запроса Apache, включая assets, не браузерный
TTFB/LCP и не отдельный route/workspace сценарий. Первый22:40срез source_stale
не был измеренным нулём; после реальных посещений источник обновился. Logging
исправления по этому результату не требуются. Полный рабочий день/SLO ещё открыты.

CPU idle97–100%,~29.3GiB доступной RAM, swap512KiB без si/so, I/O wait0%
в наблюдаемом коротком окне. Между PG snapshots20:31:15.136 и20:31:27.294:
+25commit,+1rollback (observer READ ONLY/ROLLBACK включён),+6block reads,
+4120buffer hits; deadlocks0/temp_bytes без роста. Это не число SQL/page.
track_io_timing=off: нули времени I/O не являются измеренным временем диска.
Новый postmaster start19:58:50UTC и version.php SHA отличают второй срез от первого;
между срезами установлен Studio2600, workload-rate между ними не вычислять.

19raw-overdue имеют null last_run/faildelay0 и принадлежат enrol/repository/factor/
block_rss/BigBlueButton/auth_oauth2, ни одной local_ustar. Moodle v5.1.8 manager
дополнительно пропускает get_disabled (с overrides), deprecated plugintypes и
отключённый component, если task не разрешил get_run_if_component_disabled.
Исходник: https://github.com/moodle/moodle/blob/v5.1.8/public/lib/classes/task/manager.php
Фактические enabled/override каждого server1 component здесь не исполнялись;
не называть19 подтверждёнными сбоями и не сбрасывать nextruntime/faildelay ради метрики.

## Изменение запросов

- RSS enrichment stats: N отдельных source queries →1 для выбранных sources,
 0для пустого списка. Recordset сохраняет несколько status на один sourceid;
  legacy single-source API и неизвестные statuses сохранены.
- Open operator season: убраны2N повторных participant/user name queries,
  используются имена уже полученного scoreboard. Accounts/employment filtering
  внутри scoreboard остаётся прежним; все его запросы не объявляются O(1).
- Closed operator season: N name queries →1 bound IN batch (до100user IDs).
  Snapshot order/ties/limit, row stdClass и missing-user fallback сохранены.

Локально настоящий PHP8.3.6+PDO SQLite:9cases,7ожидаемых RED до правки, все GREEN
после. Account classification/fullname/core capabilities — узкие fixture boundaries,
это не native Moodle/PG или подтверждение prod latency. Native Moodle PHPUnit
query_batching_test добавлен для групп source+status/empty/one read и20closed
participants/result shape/read budget. Native gate пока не выполнен.

Полный локальный r16:1241tests,44environment skips,1197исполненных PASS;93.312s.
Source checks:387PHP/24JS/100tables/57templates PASS. Context/source candidate
558files и secret guard PASS. PHPCLI8.3.6 извлечён из Ubuntu snapshot в отдельный
scratch runtime; это не productionPHP8.3.33 и не обновление системных пакетов.

Авторский review,0субагентов: recordset close/finally, unknown statuses, IN params,
scope/guard-before-query, open account filtering, closed missing user/type/order,
отсутствие schema/version/server/config/Studio изменений просмотрены отдельно.
Closed выбран отдельный batch вместо join ради прежней формы записи; цена — одна
дополнительная round-trip против join. Процент ускорения страниц не заявляется.

Выпуск: интеграция с принятой Studio2600 → финальный version/source SHA → applicable
native/full RC → совместимый owner package. Полный source archive main2300 для
server1 после Studio не применять. Docker logging/recreate всё ещё deferred.
