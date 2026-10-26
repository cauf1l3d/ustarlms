# ADR-0018: Container runtime

Дата:26.10.2026. Статус: принято в рамках текущей сверки; описывает границы, а не новый deploy.

Ротация логов/healthcheck и recreate выполняются в следующем плановом окне обновления
Moodle/PHP. Отдельный docker commit successor заморожен владельцем26Oct: ветка
infra/docker-successor-frozen,80870f7d; production apply не было.

Цель окна — воспроизводимый Dockerfile с проверенным составом пакетов и pinned image,
Compose logging/healthcheck, явные budgets и проверка восстановления. Нынешние
изменения writable layer — вход для инвентаризации, а не спецификация Dockerfile.
Конкретные max-size/max-file выбираются по измерению;50m/5 — предложение, не конфиг prod.

Для будущего routine discovery использовать compose project/service labels и image
identity вместе с mounts/process/ports, требовать единственного совпадения. Во время
операции фиксировать найденный container ID и отказывать при его смене. Одних labels
недостаточно; текущие источники project=stack/service=moodle|postgres, сеть ustar_network.
Не переписывать работающие pins до совместной проверки cron/HDD/HTTPS/backup recovery.

Основание: прямое решение владельца26Oct, metadata Docker29.9.0/Compose5.6.0,
context/runtime/docker_runtime_successor_20261026.md. Последствие: INF-05 accepted_risk
до окна/аномального роста; старый переход не блокирует application CI.
