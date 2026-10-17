# Сбор и импорт server facts

Исправленная замена приложенных17Oct shell-черновиков. [Причины изменений](../../context/reviews/20261017/README.md).

## Что собирается

`server_facts.py collect` использует только стандартную библиотеку Python3 и имеющиеся host tools. Он не устанавливает пакеты и не запускает/останавливает службы. Никакого `require config.php`, bootstrap Moodle, запроса к login, запуска cron/бэкапа/restore, теста поверхности диска или изменения ролей/файлов приложения.

| Область | Содержимое |
|---|---|
| Хост/время | OS/kernel/cores/RAM/load, NTP/timezone; root/HDD bytes/inodes/UUID и выбранные mount flags |
| Docker | Только `ustar_moodle` и `ustar_postgres`: identity, restart/OOM/health, лимиты, выбранная ротация логов, mounts. Без Env/произвольных log options |
| Код | Public root определяется по bind mounts и двум USTAR-компонентам; config ищется отдельно на текущем/родительском уровне. Прочитанные literal-флаги не выдаются за эффективные настройки веб-сессии |
| Manifest | SHA256 plugin/theme без содержимого, с исключением credentials/ссылок/спецфайлов. При импорте отдельная сверка с526 исходниками baseline; core/moodledata этой сверкой не покрыты |
| Права | UID/GID/mode/ACL выбранных code/config/data paths и проверка `test -w` от `www-data` внутри контейнера без записи |
| PostgreSQL | Роли, владельцы, разрешённые настройки, расширения, размеры/scan counts, подключения; query statistics только с query_id/числами, без SQL-текста |
| Cron/копии | Scheduled/adhoc metadata; sanitized receipt текущего cron, exit worker и task-failures отдельно; backup success/attempt/readback receipt, stat файла и recovery marker |
| Доступ | Выбранные systemd properties, порты с классом адреса без IP, глобальные SSH-параметры, агрегат firewall, Apache modules/ports, публичные certificate dates/fingerprints |
| Пакеты/логи | Несколько важных версий, размер journal и верхнего уровня log files; без содержимого журналов и без `du /` |
| SMART | Только по `--smart`: `smartctl -n standby,0 -H -A -j`; без self-test/пробуждения спящего диска. Необязательный пропуск сохранён |

Все SQL-соединения имеют `psql -X -w`, `ON_ERROR_STOP`, `default_transaction_read_only`, `statement_timeout=3000` и `lock_timeout=500`. Роль берётся из окружения уже работающего PG-контейнера без чтения/вывода пароля, БД приложения — из статического литерала config. Если это неоднозначно, зависимые проверки остаются неизвестными. Имена роли `postgres`, prefix `mdl_` и host public root не предполагаются.

Обычная диагностика может увеличивать системные counters, query statistics и OS/Docker audit logs; запись служебных отчётов выполняется только в новый каталог. Это не функциональная запись в Moodle. `scripts/collect_runtime.py` сохранён для прежних сценариев: он выполняет Moodle bootstrap до включения SQL read-only, поэтому в этом сборе автоматически не вызывается.

## Запуск на server1

Запускать проверенный `server_facts.py` из опубликованного коммита. Точная команда с immutable GitHub SHA и SHA256 исходника приведена в PR/сообщении владельцу. Не запускайте приложенный shell-черновик из `context/reviews/.../submitted`.

```sh
sudo python3 -B /path/to/verified/server_facts.py collect --handoff-user aduk
```

Выход по умолчанию — **новый** закрытый каталог `/var/tmp/ustar-server-facts-<UTC>-<nonce>`, не mountpoint бэкапов. Можно задать `--output-dir /var/tmp/<new-name>`. При неоднозначном обнаружении — `--moodle-root` с конкретным уже обнаруженным каталогом; он не может указать произвольный иной target.

Root нужен для PG/Docker, приватных receipts, ACL/sshd/firewall и части host-проверок, не только SMART. `--handoff-user aduk` передаёт этому пользователю только готовый отчёт: каталог0700, файлы0600. После этого `report.tar.gz` можно скачать через WinSCP под aduk. Источники/бэкапы/ключи не копируются.

Первый owner-run17Oct сохранил partial report:17 блоков ok,13 error, SMART not requested; archive ещё не передан. [Разбор совместимости](../../context/reviews/20261017/collector_compatibility.md). Исправлены optional Docker Health/log Config и допустимый `~` в Debian package versions. После получения новой pinned команды повторный collect создаёт другой каталог и сохраняет прежний отчёт. SQL/query/health log text и Docker Env по-прежнему не экспортируются. Go нужен только для regression-тестов шаблона, не для запуска на server1.

В ходе сбора печатаются только имена блоков и статусы. Обычно это минуты, а не часы: команда ограничена4с и512КиБ суммарного вывода; проход по plugin/theme ограничен20с/1500файлами. После180с новые блоки не запускаются; уже начатый блок завершает ограниченные проверки. Не выполняется неограниченный обход HDD/корня.

`RC=0` — все обязательные наблюдения собраны; `RC=1` — сохранён частичный снимок. В обоих случаях печатается `REPORT=.../report.tar.gz`. `RC=2` — отказ проверки/экспорта. Отсутствие `pg_stat_statements` делает соответствующий блок недоступным; это не доказывает, что PERF-01 никогда не выполнялся.

## Проверка и импорт на рабочей копии репозитория

Передайте один `report.tar.gz`. Архив не содержит config, query text, cookies, SQL dump, moodledata, private logs или ключи. Сырые headers/логи вручную не добавлять.

```sh
python3 scripts/infra/server_facts.py validate /path/to/report.tar.gz
python3 scripts/infra/server_facts.py import /path/to/report.tar.gz --repo "$PWD"
```

Импорт требует PyYAML из `harness/ustar-contextd/requirements.txt`. Он читает только четыре разрешённых archive members без распаковки путей; отвергает links/traversal/duplicates/oversize, неизвестные поля, секретные значения и несогласованные checksums/status/error lists. Исходные файлы не меняются. Повторный/более старый снимок не заменяет current pointer. Снимок с часами более10мин в будущем не принимается автоматически.

После проверки создаются новый immutable каталог, источник сверки manifest, указатель в STATE и генерируемые current/checkpoint/index. Остальные production receipts/статусы задач не переписываются. Неуспех любого post-import gate откатывает созданные файлы и указатели. Автоматического commit/push нет.

## Что останется отдельной приёмкой

Read-only snapshot не измеряет реальный RTO/восстановление, не доказывает вход/SCORM/mail/DNS/TLS с клиентского устройства, не заменяет effective SSH Match/user/ACL-negative tests, offsite-copy/key custody и длительную baseline-нагрузку. Полная карта core и API/Privacy contracts — самостоятельные области. Эти пробелы отражены в coverage, не скрыты заявлением «100%».
