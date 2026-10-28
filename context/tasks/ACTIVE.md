# Активная работа —28.10.2026

## PERF-01: owner apply2802 и workday/SLO

PR107/109 слиты в main как84a078ef/01904eda. Main source — app22607c69/local2802/theme2700.
Оператор3c1ddd09 принимает только принятый2801/81118e39 и использует новый журнал
/var/lib/ustar-perf-query-update.589→590файлов,100таблиц без schema delta; worker6c56 без rollout.
[Интеграция](../runtime/perf2802_integration_20261028.json),
[квалификация](../runtime/perf2802_qualification_20261028.json).

Все четыре CI на3c1ddd09 SUCCESS: PHP8.2/8.3 по1363/232, оба native Moodle354/1695.
Actual PG16 fresh/paired-legacy profiles и7SQL отказов, mandatory UID33 PASS.
Парное восстановление/added-file rollback, process-loss recovery, task/cron/source guards сохранены.
Архив1319be48... сверён побайтно с CI; сохранённая копия скачана и проверена.
[Команды установки/проверки](../../docs/PERF_2802_INSTALL_RU.md).

Следующее действие — владелец проверяет2801 через --check, устанавливает2802 и присылает --status.
Установка2802 на server1 ещё не подтверждена; source integration не является server receipt.
Более широкий workday/concurrency/SLO baseline остаётся открытым, ускорение страниц не заявлено.

## Принятая студия и границы

STUDIO-01 завершён по owner acceptance2801. Последний точный terminal status2600 хранится
отдельно; не превращать общее «всё работает» в новую whole-host инвентаризацию.
[Owner-приёмка](../runtime/studio_viewport_owner_acceptance_20261028.json).
SSH, субагенты и отправки через провайдеров запрещены. Логи/health/recreate — в Moodle window.
Внешний restore unknown; принятые инфраструктурные циклы не повторять.
