# Пробное обновление Moodle 5.1.8 и согласованное восстановление стенда

**Server checkpoint12Oct:** [owner upgrade/repeat PASS](lab_patch_upgrade_result_20261012.json),
finished00:04:42UTC, candidate5.1.8 active only on stage/login200/4910 hashes/tasks0.
Fresh paired baseline156e4688/runfylkhzxl retained. Next [actual paired rollback trial](lab_patch_rollback_handoff_20261012.md)
with the installed helper; production unchanged, browser/full-addon/rollback acceptance pending.


Получен [owner readiness PASS в22:56:35UTC11Oct](lab_patch_readiness_result_20261012.json).
Candidate0v8ncwgu/manifest07dfa485 проверен, PHP8.3.33/PG16.15 и обязательные
расширения подходят; исходные версии БД/57 addons и tasks0 совпали. Файл lab.ini
не читается CLI-пользователем33. Каждый дальнейший PHP-процесс получает явные
memory_limit512M/sendmail_path=/bin/false/displayOff/logOn и пустые prepend/append.
Это не ремонт native CLI/Apache и не подтверждение всех функций addons.

Операция `--upgrade-stage` выполнена владельцем с помощью
[helper](../../scripts/infra/lab_patch_upgrade.py). Прямого SSH/Docker доступа
агента нет; серверный запуск и результат предоставляет владелец. Source/CI и
server acceptance записываются отдельно. PR99 остаётся draft/unmerged; frontend
npm audit failure не отменяется и не превращается в полный release gate PASS.

## Граница и действия

Изменяется только `/var/lib/ustar-restore-lab/20261004T123406Z-741e3f72/patch-stage-20261005`
с контейнерами `ustar_patch_stage_20261005_web/pg`. Source lab18084, production,
первый frozen checkpoint77672fd0, accepted candidate и preserved-extra-files
сохраняются. Images не загружаются, Composer/cron не запускаются, файлы не удаляются.

1. Exclusive/nonblock существующего root coordination lock; SHA-pinned readiness
   и transitive builder/cold/preflight/resume/engine/profile. Повторяется accepted
   readonly guard, проверяются source/production identity/start time, отдельный
   бюджет cold pair/candidate/restore/inodes с4GiB reserve и2GiB allowance БД.
   Это оценка, а не hard limit роста БД.
2. Создаётся private transaction directory и durable marker. Проверяется владелец
   HTTP relay; останавливается relay и только stage web, затем stage PostgreSQL.
   Cold copy требует clean exit/noOOM/no postmaster.pid. Сохраняются текущие
   code/config, Moodledata, physicalPG и private controls, все metadata/bytes/hash
   проверяются, пишется complete manifest. Это новая копия **текущего mutable stage**
   непосредственно перед SQL, а не повтор `--prepare-stage` исходного стенда.
3. Пока web/PG остановлены, прежние дочерние элементы code-root переносятся в
   private retained directory. Accepted code независимо копируется в тот же
   bind-root inode. Container IDs, image/mount/network plan остаются прежними;
   код и PostgreSQL/Moodledata outer directories не заменяются.
4. Stage PostgreSQL/web запускаются; namespace только loopback, CFG dbhost/name,
   wwwroot18085, noemailever/cron0/adhoc0 и effective CLI policy проверяются.
   Официальный путь из SHA-проверенного пакета —
   `/var/www/html/admin/cli/upgrade.php`, **за пределами public**.
   Он запускается как33:33 с `--non-interactive`, затем ещё раз.
5. Проверяется DB core2025100608 (нулевые десятичные окончания эквивалентны),
   неизменность **57 сохранённых addons**; версии stock core plugins могут
   законно измениться. После каждого CLI только на stage отключаются все
   scheduled tasks. Проверяются no-upgrade-needed bootstrap, code hashes,
   bounded readonly DB/file reference queries и4910-подобный актуальный набор
   filedir SHA1. Relay18085 запускается на7200s, login200 и source/production
   identities проверяются повторно. Private state/report обновляются.

При успехе остаётся обновлённый стенд и свежая paired baseline. Вывод
`LAB_PATCH_UPGRADE=PASS; ... REPEAT=PASS; PAIRED_ROLLBACK_TEST_PENDING` ещё не
объявляет проверенный rollback или ручную приёмку homepage/SCORM/ролей/addons.
Следующий отдельный запуск `--restore-baseline` проверит реальный paired rollback,
затем тот же `--upgrade-stage` создаст новый run и повторит upgrade перед browser QA.

## Ошибка и восстановление

При исключении/timeout/INT/TERM/HUP helper сначала останавливает owned web/PG.
Это необходимо, поскольку оборванный docker-exec клиент может оставить PHP
в контейнере. Перед записью подтверждаются stopped state и bind-root identities.
Complete baseline снова проверяется. Изменённые code/Moodledata/PG children
переносятся в retained directory; **все три дерева** восстанавливаются из одного
cold manifest и проверяются до запуска PostgreSQL. Private bound controls должны
остаться теми же; state/report восстанавливаются. Исходный core/bootstrap/addons,
tasks0, filedir hashes и HTTP проверяются. Повторный signal не прерывает recovery.
Ошибка записи журнала не отменяет попытку остановки/восстановления.

Если ошибка случилась до изменения деревьев, возобновляется исходный stage;
report честно показывает `paired_trees_restored=false`. При невозможности
проверить копию/capacity/ownership helper сохраняет marker/files, подтверждает
остановку owned stage и выводит `STAGE_RECOVERY_REQUIRED`. Рабочий сайт не
останавливается. SIGKILL/обрыв питания не позволяют Python закончить recovery;
после восстановления SSH используется тот же helper с `--restore-baseline`.
Повтор после частичного restore сохраняет частичные файлы и снова восстанавливает
весь комплект. Запуск upgrade при существующей незавершённой/success transaction
отказан до повторного обновления. Никакого PG-only restore или старого меньшего GPT.

Private `commands-private.log`, pg.env, config.php, physicalPG и raw manifests
не публикуются. Публичный report не содержит паролей/SQL/PHP output/личных записей.
Нельзя отправлять приватный журнал целиком в GitHub; для диагностики готовится
отдельная обезличенная выдержка после просмотра.

## Проверки и состояние поставки

[Validation](lab_patch_upgrade_validation_20261012.json):23 dedicated tests PASS,
real filesystem copies/readback/no shared inodes, tamper/link/ownership/capacity
guards, retained bind-root inodes, successful upgrade/repeat mocked runtime,
actual three-tree restore, timeout during repeat, partial restore retry, duplicate
upgrade refusal и journal-write/recovery failure. Full r16 260 tests OK/11 skips:
10 private HDD capture engine tests не supplied,1 local PHP interpreter отсутствует.
Docker/PHP/Moodle/PG runtime этих новых сценариев mocked; live upgrade не приписан.
Local source structural checks выполняются с явным `--skip-php`; это не замена CI.

Private restore engine полностью прочитан40187bytes и SHA
71f6a1c45c25bfe97fe0be7d0a362bb5a72e742b77c167f3783f526c85950dac совпал;
AST API contract PASS, ownership/mount/exec/SQL/relay signatures проверены.
Исходник engine не добавлен в Git. Official package SHA0e8e2cde... был проверен
полностью; CLI upgrade source и repeat/no-upgrade exit0 прочитаны из этого пакета.
Implementation `59b88e1ae061bfa9ca4070ec74c4ffbf1629df44`, helper SHA256 `452d8fd682bc2878fee66aaa9c121528e707d944b943d90264c2d220a48133f4`.
Immutable remote blob3f42c59aeddde973d40bca9bc91a0e8c2235787b byte-identical PASS.
[Context58](https://github.com/cauf1l3d/ustarlms/actions/runs/38184389152) SUCCESS;
[Review494](https://github.com/cauf1l3d/ustarlms/actions/runs/38184389139) both complete
source PHP8.2/8.3 SUCCESS, frontend npm audit FAILURE; rollback/prepare-rc/moodle-db/gate
SKIPPED. Это source verification, не полный gate или live upgrade/restore.

Server upgrade/repeat выполнены владельцем12Oct00:04:42UTC PASS; реальный paired rollback
этого helper ещё не выполнен. Следующий шаг — --restore-baseline, не повторный upgrade.
Composer declarations не доказывают resolved dependencies; exif/soap optional
missing не основание для слепого install. После реального upgrade/restore и
повторного upgrade выполняются native login, USTAR home, роли/capabilities, SCORM,
completion/files и smoke всех сохранённых addons. Production patch, OS/AppArmor/
reboot/required services, HTTPS и independent backup recovery остаются следующими
отдельными приёмками выбранного порядка1→7→6.


## Точная команда владельцу

В существующем SSH-окне `aduk@server1:~$` выполнить строки по очереди. Каждая
команда ниже — одна физическая строка. `--upgrade-stage` может долго копировать
файлы; оставить SSH открытым, дождаться итогового вывода. Короткий внешний timeout
для операции не применяется; CLI внутри имеет1800s timeout с stage-only recovery.

```bash
sudo -v
curl -fsSL --connect-timeout 10 --max-time 60 https://raw.githubusercontent.com/cauf1l3d/ustarlms/59b88e1ae061bfa9ca4070ec74c4ffbf1629df44/scripts/infra/lab_patch_upgrade.py -o /home/aduk/USTAR_LAB_PATCH_UPGRADE_20261012.py
printf '%s  %s\n' 452d8fd682bc2878fee66aaa9c121528e707d944b943d90264c2d220a48133f4 /home/aduk/USTAR_LAB_PATCH_UPGRADE_20261012.py | sha256sum --check --strict - && sudo -n install -m 600 -o root -g root /home/aduk/USTAR_LAB_PATCH_UPGRADE_20261012.py /usr/local/sbin/USTAR_LAB_PATCH_UPGRADE_20261012.py
sudo -n bash -c 'set -euo pipefail; umask 077; printf "%s  %s\n" 452d8fd682bc2878fee66aaa9c121528e707d944b943d90264c2d220a48133f4 /usr/local/sbin/USTAR_LAB_PATCH_UPGRADE_20261012.py | sha256sum --check --strict -; python3 -I -B -u /usr/local/sbin/USTAR_LAB_PATCH_UPGRADE_20261012.py --upgrade-stage'
```

После вывода получить owner report. Следующий отдельный rollback trial или
восстановление при `STAGE_RECOVERY_REQUIRED`/обрыве питания:

```bash
sudo -v
sudo -n bash -c 'set -euo pipefail; umask 077; printf "%s  %s\n" 452d8fd682bc2878fee66aaa9c121528e707d944b943d90264c2d220a48133f4 /usr/local/sbin/USTAR_LAB_PATCH_UPGRADE_20261012.py | sha256sum --check --strict -; python3 -I -B -u /usr/local/sbin/USTAR_LAB_PATCH_UPGRADE_20261012.py --restore-baseline'
```

`LAB_PATCH_PAIRED_RESTORE=PASS` после successful upgrade возвращает исходную
baseline, а не оставляет обновлённый candidate. Для новой browser QA затем
повторяется `--upgrade-stage` после получения и проверки rollback report.
