# Core, NTP, SMART и компоненты задач — 17 октября 2026

Владелец передал два ZIP и terminal transcript в 13:05:42 MSK. Оба архива проверены; [provenance](../../runtime/core_extra_received_20261017.json) фиксирует SHA256, состав и hashes файлов. Это дополнительные owner-executed observations на заявленном server1, без агентского SSH и независимой идентификации хоста. Предыдущие три [31-block snapshots](../../runtime/server-facts/README.md), их ошибки и датированные receipts сохранены.

| Область | Новое наблюдение | Граница вывода |
|---|---|---|
| Core | 24 742 matching, 0 changed/missing/errors/skipped, 23 extra | Точный upstream scope после исключений; extras содержат только имена |
| Время | Активен `ntpsec`; выбран peer `194.58.206.20`, offset −0,2024 ms | Одно измерение, без долгосрочного SLO и отдельного host stratum |
| SMART | Оба health=true; SSD 50°C, HDD 49°C; оба exit 64 | Bit 6: ATA error log records; здоровье не сводится к одному health flag |
| HDD residual | 5/196/197/198/199 raw=0; failed и более поздний successful long test сохранены | Нового self-test не было; историческую ошибку не удалять |
| 18 overdue | Все относятся к отключённым по DB flags компонентам; исходники подтверждают skip policy | Обоснованное объяснение, с условием совпадения effective runtime config с DB; не native cron acceptance |
| ACL | Обе команды: `getfacl: command not found` | ACL не измерен; это не доказательство отсутствия ACL или проблем прав |

## Проверка сбора

Core ZIP: 41 member, 1 839 819 bytes, SHA256 `edb2f12eb897e2cb441e708a9bfdd047e31725db37c329ab37ff7ef61fc62d41`. Collector SHA256 `898667ddd621f2254b2d1db533fedf9f2edc0d07cb82a83ba02c6926df745c9c` совпал с pinned source в bddd8b94. Три report-файла, их checksums и вложенный tar проверены; [оригинальный payload](../../runtime/core-facts/20261017T100015Z-edb2f12e/README.md) сохранён побайтно. Загруженные Git config/hooks не выполнялись. Pack/idx прочитаны в новом доверенном bare repo; matching paths точно равны eligible reference tree, а 30 использованных файлов правил планировщика совпали по Git blob SHA с полученными через официальный `moodle/moodle` API.

Extra ZIP: 35 members, 15 977 bytes, SHA256 `0411e053b97cd6bb235276e8e85ceaaa11ab4f73e50b6a1c70ccdbf37b0e69ab`. Все 33 probe-файла соответствуют `checksums.sha256`. В этом ZIP нет вложенного tar: пользователь передал папку, тогда как `EXTRA_REPORT` на сервере указывает на соседний архив. [Производный JSON](../../runtime/server-extra/20261017T095914Z-0411e053/observations.json) содержит allowlisted данные; raw ZIP/SMART/NTP вывод не опубликован из-за идентификаторов дисков и остальных NTP peers. Hashes связывают производные факты с оригиналами, но не заменяют независимую аттестацию хоста.

Extra probes выполнялись с timeout 20s, kill-after 2s и ограничением размера вывода. SQL передавался в `psql -X -w` с read-only transaction default, statement_timeout 3s и lock_timeout 500ms. Core запускался с timeout 180s, без PHP/bootstrap/SQL и с явным upstream override. Это описание выполненной владельцем команды; агент её на production не повторял. SMART измерен в 09:59:14 UTC, core — 10:00:15 UTC, прежние задачи — 01:39:24 UTC. Для остальных extra probes отдельные timestamp не записаны; не приписывать им точное время SMART.

## Core и 23 дополнительных файла

Эталон [Moodle release 5.1.8](https://github.com/moodle/moodle/commit/cc0bf17da8b5fbd290faf95b2b14a03d64331923) соответствует уже принятому patch candidate. Команда корректно использовала внешний `/opt/ustar/data/moodle/public` с вложенным `public/`, и передала `--reference-commit cc0bf17...`; default collector остаётся историческим commit для 05Oct. Ошибки пути или выбранного эталона в этом запуске нет.

В заявленной reference области изменённых и отсутствующих core files нет. `complete=true/match=false`, exit 3 вызваны extras. Config, hidden paths, tests, node_modules, root vendor и перечисленные plugin roots исключены. Это не подтверждение чистоты всего deployment, DB schema, пользовательских PHP handlers или функциональной приёмки. `public/index.php` сейчас listed matching upstream; исторический отчёт 05Oct с отличием сохранён, новая навигационная приёмка из этого не следует.

Набор 23 extra names совпадает с 05Oct; дата текущего отчёта не означает, что эти файлы появились сегодня. Полный список — в [оригинале](../../runtime/core-facts/20261017T100015Z-edb2f12e/core_comparison.json). Для целевой проверки INF-03 особенно важны:

- `public/config.php.before_ip_change_20260830T203238Z` и `public/index.php.backup_ustar` внутри вложенного public root: проверить route/vhost/access rules и возможную выдачу исходника;
- пять config backups во внешнем root и `bx_grade_tokens.txt`: проверить права, владельцев и маршрутизацию; содержимое не собрано;
- `githash.php`, два внешних `gradehelper*`, четыре public handler/helper PHP, семь `public/reports/*.pdf` и `wwwroot`: установить назначение, доступ и происхождение.

Наличие имени под public не доказывает публичную доступность. Автоматический HTTP GET обработчиков может иметь side effects; никаких GET, открытий config contents, удалений, переносов или изменений permissions здесь не было.

## Реальный источник времени

`ntpsec.service` loaded/active/running/enabled. Повторные строки этого же Id у `ntp`, `ntpd` и `ntpsec` — aliases, а не три клиента. Timesyncd masked/inactive; chrony не установлен. Ошибки `timedatectl timesync-status` и `chronyc` относятся к другим клиентам.

`ntpq -pn` exit 0 содержит выбранный `*194.58.206.20`: peer stratum 1, reach `377` (octal), poll 1024s, delay 67,9720ms, offset **−0,2024ms**, jitter 0,1690ms. Это stratum peer, не измеренный local-host stratum. Семантика вывода — [официальный ntpq manual](https://docs.ntpsec.org/latest/ntpq.html).

Timedated одновременно сообщает `Timezone=Etc/UTC`, `CanNTP=no`, `NTP=no`, `NTPSynchronized=yes`. Эти флаги не отменяют наблюдаемый активный ntpsec и выбранный peer; причина различия интеграции timedated отдельно не измерена. UTC не требует изменения ради backup schedule Europe/Moscow. Одно показание не подтверждает устойчивую точность или отсутствие будущих скачков.

## SMART: текущие значения и история

Полный экспорт атрибутов и history в рамках `smartctl -j -n standby,0 -x` получен на обоих дисках. **64 = 1 << 6**: device error log содержит записи. Это не bit 0/1/2 (ошибка вызова/открытия/SMART command), не bit 3 (failing SMART health). По [официальному smartctl manual](https://github.com/smartmontools/smartmontools/blob/master/smartmontools/smartctl.8.in), bit 7 не учитывает старую failed self-test запись после более нового успешного extended test. Поэтому exit 64 совместим с сохранённым старым HDD failed test. В JSON отдельно сохранён blob SHA проверенного manual; его master ref не выдаётся за pinned release 7.4.

**SSD `/dev/sda` — WALRAM 120GB/FW201105:** 26 230 power-on hours, health=true, 50°C; SMART DB не распознаёт модель. Все 27 экспортированных атрибутов сохранены. Reallocated 5, reallocation events 196, offline uncorrectable 198 — raw 0. Атрибут 197 отсутствует, его значение не подменено нулём. Error log count 8, доступны 4 записи; последние содержат lifetime 25 130/25 127h, не новую текущую дату. Self-test log count 0. Raw wear 177=19 581 860 при normalized 100/threshold 50 не позволяет заявить «100% ресурса»; vendor raw 241/242 не переведены в TBW без подтверждения единиц.

**HDD `/dev/sdb` — WDC WD5000AAKS-00YGA0:** 16 663 power-on hours, health=true, 49°C. Все 17 экспортированных атрибутов сохранены. Reallocated 5, events 196, pending 197, offline uncorrectable 198, CRC 199 — raw 0. Это новое измерение residual 198, а не исправление старого отчёта. Error log count 9; lifetime fields в записях равны 0 и не позволяют датировать их как новые ошибки.

Self-test history: failed extended read test на 16 359h/LBA 752045700; более новый **successful extended test на 16 385h**, затем в older history — short test aborted by host на 7 391h. Последний успешный test отстаёт от текущего power-on counter на 278h; это не тест, запущенный 17Oct. Нулевая текущая 198 не стирает failed history и не доказывает отсутствие всех аппаратных рисков. Аппаратный резерв, backup redundancy и длительный trend по INF-04 остаются открыты.

## Почему эти 18 overdue не равны 18 сбоям cron

В старом snapshot все 18 имеют DB task `disabled=false`, `last_run=0`, `fail_delay=0`. Новые read-only SQL results показывают:

| Компоненты | Задач | Наблюдение DB |
|---|---:|---|
| auth_oauth2 | 1 | auth=email; oauth2 отсутствует |
| block_rss_client | 1 | visible=0 |
| enrol category/fee/flatfile/imsenterprise/lti/meta/paypal | 10 | enabled только manual,guest,self,cohort |
| factor_grace, factor_nosetup | 2 | enabled=0 у обоих factors |
| mod_bigbluebuttonbn | 1 | visible=0 |
| repository_dropbox/filesystem/onedrive | 3 | Для этих типов нет mdl_repository rows |

В exact [manager.php](https://github.com/moodle/moodle/blob/cc0bf17da8b5fbd290faf95b2b14a03d64331923/public/lib/classes/task/manager.php) scheduler пропускает disabled component, кроме task override. Все 18 task classes непосредственно наследуют scheduled_task и не переопределяют `get_run_if_component_disabled()`; default возвращает false. Проверены plugininfo auth/enrol/block/mod/repository, MFA factor implementation и его base. `tool_mfa.enabled=0` тоже наблюдается, но для двух factor tasks основание — собственный factor.enabled и plugininfo policy. Пути, blob SHA и line references — в derivative JSON.

Следовательно, для всех 18 есть source-based объяснение ожидаемого пропуска при сохранении наблюдаемых DB settings. Это не основание включать плагины или принудительно запускать задачи. Effective `$CFG`/forced plugin settings, bootstrap/plugin API, cache и фактический runtime skip не измерены; DB flags и task snapshot сняты в разное время. Эта граница остаётся в coverage. Disabled registration/H5P faildelay, SMTP и полный cron cycle требуют отдельной приёмки INF-11.

## Что ещё не получено

- Query statistics: `shared_preload_libraries` всё ещё пуст, `compute_query_id=auto`, source default, pending_restart=false. `pg_stat_statements` ранее available/not installed; модуль и статистика не активированы, рестарта не было.
- ACL обеих выбранных областей не получен из-за отсутствующего getfacl; effective file/parent/replace access остаётся INF-03.
- Effective SSH Match, TCP2019 process/external reachability, Academy TLS/cookies/login и full native authorization/API/UI states не проверены.
- Exact vendor SSD wear/TBW interpretation, аппаратный резерв и long-term trends не подтверждены.
- Clean-host restore, measured RTO и offsite/key custody ожидают результата сисадмина. Новый backup/restore этим сбором не выполнялся.

Контекст готов к повторному аудиту владельца с этими границами. [Validation](core_extra_validation.json) и [coverage](../../coverage.yaml) отделяют проверку архивов/исходников/CI от native acceptance. После review — repo work, пульт PR103 и Telegram по прежней очереди. Moodle5.2/PG17 и Privacy остаются отложены.
