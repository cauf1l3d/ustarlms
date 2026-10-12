# Реальный откат после успешного обновления стенда

Owner report получен12Oct11:29:51MSK, операция завершилась00:04:42UTC/03:04:42MSK.
[Точный результат](lab_patch_upgrade_result_20261012.json): Moodle5.1.8/core2025100608.00
активирован на отдельном patch stage, official upgrade и repeat PASS, login200,
4910 file contenthashes, tasks0/CLI mail blocker/loopback-only. Container IDs и
bind-root inodes stage прежние; source/production identity/start time unchanged.
Это реальный owner deployment evidence, а не live query агента.

Перед изменением создана complete paired baseline:

| Поле | Подтверждённое значение |
|---|---|
| Run directory | `/var/lib/ustar-restore-lab/20261004T123406Z-741e3f72/patch-upgrade-518-20261012-fylkhzxl` |
| Baseline manifest SHA256 | `156e468887a09992b6e6b69bc05f4733385e648546f17f3c102c98d5f50abce9` |
| Core после upgrade | `2025100608.00` |
| Core исходной baseline | `2025100601.03` |
| Helper implementation | `59b88e1ae061bfa9ca4070ec74c4ffbf1629df44` |
| Installed helper SHA256 | `452d8fd682bc2878fee66aaa9c121528e707d944b943d90264c2d220a48133f4` |

Следующая операция — **практический paired rollback trial** тем же установленным
helper с `--restore-baseline`. Новый download/install/prepare-stage/candidate
не требуется. Helper выбирает durable transaction marker в exact stage root,
проверяет root-private paths/state token/container IDs/images/mounts/root inodes,
останавливает только stage relay/web/PG, сверяет manifest и все cold copy hashes.
Изменённые code/Moodledata/physicalPG children сохраняются в отдельном retained
directory. Все три дерева восстанавливаются вместе в те же outer directory
inodes и проверяются до запуска PostgreSQL. Bound private controls должны совпасть;
исходные state/report возвращаются. Код5.1.8 не накладывается на старую БД при откате.

После запуска same stage containers проверяются исходные core/addon versions,
Moodle bootstrap, tasks0, effective CLI isolation, filedir hashes и HTTP login.
Fresh before/after source/production identity снимок берётся на момент rollback;
ночной HDD timer после upgrade не считается частью старого rollback snapshot.
Expired7200s relay корректно обрабатывается и запускается новый локальный relay.

В текущем SSH-окне `aduk@server1:~$`, две команды по очереди; каждая одной строкой:

```bash
sudo -v
sudo -n bash -c 'set -euo pipefail; umask 077; printf "%s  %s\n" 452d8fd682bc2878fee66aaa9c121528e707d944b943d90264c2d220a48133f4 /usr/local/sbin/USTAR_LAB_PATCH_UPGRADE_20261012.py | sha256sum --check --strict -; printf "PAIRED_RESTORE_START=%s\n" "$(date -u +%FT%TZ)"; python3 -I -B -u /usr/local/sbin/USTAR_LAB_PATCH_UPGRADE_20261012.py --restore-baseline; printf "PAIRED_RESTORE_FINISH=%s\n" "$(date -u +%FT%TZ)"'
```

Ожидается `LAB_PATCH_PAIRED_RESTORE=PASS; ORIGINAL_STAGE_BASELINE_RUNNING`,
`paired_trees_restored=true`, core2025100601.03, login200 и actual filehash count.
Только полученный owner output является acceptance этого trial. При ошибке
сохранить stdout/status; helper оставляет private copies/marker и пытается
подтвердить остановку owned stage. Не публиковать raw private command log, state,
config.php, pg.env, physicalPG или Moodledata. Рабочий сайт не является target.

После получения rollback PASS — новый `--upgrade-stage` тем же verified helper:
старый marker сохраняется в history, создаётся новый run/current-stage pair,
повторяются upgrade/repeat. Затем native authenticated browser QA: USTAR home,
разрешённые/запрещённые роли, SCORM/media/completion/files и preserved addons.
Production patch и OS/HTTPS следуют отдельными операциями после этой приёмки.

Full addon/home/manual QA, Apache settings, paired rollback и production deployment
пока не приняты. Current helper source CI59b88e1/Context58 и PHP8.2/8.3 SUCCESS;
frontend audit FAILURE/full gate не PASS, PR99 draft/unmerged. Перезапуск broad
тестов для этой documentation-only фиксации не нужен: helper bytes не меняются.
