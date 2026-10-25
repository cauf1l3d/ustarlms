# Docker: происхождение действующих контейнеров перед ротацией логов

Owner25Oct отложил SSH/key/firewall hardening до подготовки внешнего доступа.
Текущий административный вход aduk по логину/паролю внутри LAN принят владельцем.
Не запрашивать Windows key/agent probes и не возвращать SSH как prerequisite этой
локальной работы. Это scoped deferral, не PASS готовности к внешнему доступу.

## Уже известно; не повторять полный survey

Оба контейнера ранее running/restarts0/OOMfalse; owner one-pass сохранил identities.
У обоих json-file без log options и без healthcheck. Moodle опубликован только на
127.0.0.1:8082, PostgreSQL5432 не опубликован. Старые source/DB/code/host/backup/UI
приёмки сохраняются. Принятый survey не собирал Compose origin labels.

| Consumer source | Уже проверенная зависимость |
|---|---|
| connect_con02_contract.py Host._control | Recorded Id/Image должны совпадать, inspect проверяет pinned IDs/images/binds |
| postgres_app_handoff.py Host | IDs получены из embedded engine identities; это не разрешение заменить их после recreation |
| academy_https.py Host | Запрашивает id/running/mounts/ports у ustar_moodle; совместимость с owner journal нужно сохранить |
| hdd_backup.py validate_live/recovery | Сравнивает live Id с accepted preparation/recovery identity |
| host_cold_recovery.py same_containers | Отказ при изменении inventory/ID/image/mounts относительно marker |
| infra_one_pass.py CONTROLLERS | Exact SHA трёх установленных root controllers; companion не разрешает незаявленную замену их bytes |

Это source trace, не новый production runtime test. Установленные controllers
содержат frozen/embedded версии; правка текущего repository module сама их не обновит.

## Следующий owner read-only вывод — на server1

Один compound command, без sudo-input/paste splitting. Только четыре Compose labels
и имена двух контейнеров. Не печатает Env, commands, другие labels, config secrets
или содержимое файлов. Ни остановки, ни pull/build/up/recreate, ни daemon restart.

```bash
sudo -v && sudo -n timeout --kill-after=3s 15s docker inspect \
  --format '{"name":{{json .Name}},"compose_project":{{json (index .Config.Labels "com.docker.compose.project")}},"compose_service":{{json (index .Config.Labels "com.docker.compose.service")}},"compose_working_dir":{{json (index .Config.Labels "com.docker.compose.project.working_dir")}},"compose_config_files":{{json (index .Config.Labels "com.docker.compose.project.config_files")}}}' \
  ustar_moodle ustar_postgres
```

Вернуть две JSON строки либо ошибку. Пустые labels возможны у docker run/старого
Compose и не доказывают неисправность. Label path — подсказка о creation origin,
не доказательство, что нынешний файл совпадает с действующей конфигурацией. До
изменений нужны private before images и сравнение resolved semantics без публикации
секретов, затем coordinated handoff/recovery, а не слепой compose up.

## Граница следующей реализации

Docker применяет новые logging options к newly created containers. Изменение
daemon.json не ротирует уже действующие два контейнера. Для них требуется
recreation, которая меняет ID, и согласованный переход всех перечисленных guards.
Изучение origin labels — следующий недостающий факт, не исправление ротации и
не разрешение потерять legacy recovery. Существующие json logs не truncate/rename/
copytruncate и не редактировать через Docker internal state. Старые лаборатории,
physical pairs и backup archives сохраняются. В этом шаге нет app/DB/schema writes.

Primary reference checked25Oct: [Docker logging configuration](https://docs.docker.com/engine/logging/configure/).
Shell syntax checked locally; native production inspect агент не выполнял.
