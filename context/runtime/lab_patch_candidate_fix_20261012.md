# Исправление stock/legacy githash.php — 12.10.2026 Moscow

**Текущий результат:** corrected builder4a681023 выполнен владельцем успешно;
[receipt](lab_patch_candidate_result_20261012.json) фиксирует exact candidate/manifest
SHA,29409 stock/19 roots/57 metadata/526 USTAR/full readback PASS. Следующий шаг —
[read-only readiness](lab_patch_readiness_20261012.md); builder не повторять.
Ниже сохранены причина первоначального отказа, исправление и уже выполненная команда.
[Прежний отказ](lab_patch_candidate_refusal_20261012.json) остаётся историей.

Причина воспроизведена на том же official tgz: root `githash.php` — packaging
metadata (`$githash = 'cc0bf17'`), добавленный при формировании архива; в прежней
Git core comparison этот legacy file входит в23 extras. Проверка stock-only
extraction предыдущего helper не проверила совмещение полного overlay namespace.

## Исправленный контракт

[Helper](../../scripts/infra/lab_patch_candidate.py), SHA256:
`bb71ddd01566341d506a560628453bdcec045de89af7f4a80774a205f2f059c2`.
Exact implementation: `4a6810234abf33773fb8efbeb7293ddcb21c17c6`; published helper
content fetched at this SHA exactly matches the tested local bytes.
[PR99](https://github.com/cauf1l3d/ustarlms/pull/99) draft создан после owner email verification;
[Context50](https://github.com/cauf1l3d/ustarlms/actions/runs/38175465744) и
[Review486](https://github.com/cauf1l3d/ustarlms/actions/runs/38175465742) Context completed success; Review: frontend audit failure, source PHP8.2/8.3 completed success;
rollback/moodle-db/prepare-rc/gate skipped. Full gate is not PASS, no merge.
Frontend/application code unchanged; audit threshold is not weakened. Оператору даётся immutable raw URL и SHA. Branch
`codex/lab-patch-candidate-20261011`, main basec53723c8d669a1013c518f34abf392143d211d9d.

Stock `code/githash.php` остаётся byte-identical официальному пакету. Legacy
`githash.php` копируется в `candidate/preserved-extra-files/githash.php`, вне
code/mounted tree, directory0700. Новый inode, прежние UID/GID/mode/size/SHA;
полный readback и directory fsync. Private manifest включает отдельный
`preserved_extra_entries`; printed report:23 extras preserved,22 in code,
`extra_files_preserved_separately=["githash.php"]` и preserved readback PASS.
Это точечное исключение для одного regular file; другие совпадения и совпадение
с directory остаются отказом. Addon root/core collisions остаются отказом.
Размер сохранённого legacy file уже входит в existing copy budget; control и
directory overhead входят в128MiB/1000 inode allowances,4GiB reserve остаётся.

Новый output prefix `patch-candidate-518-20261012-*`. Existing source/checkpoint,
workspace и прежний установленный helper не перезаписываются. Lock, dependency
pins, input checks, code extraction/readback, config preparation и failure
quarantine сохраняют [предыдущий contract](lab_patch_candidate_20261011.md).
Default plan-only, optional `--check` read-only; prepare не исполняет PHP/SQL,
не меняет контейнеры/cron/email, не подключает code к stage и не обновляет БД.
Relay HTTP TTL не влияет на подготовку code. Core5.1.8/19addons/USTAR homepage
runtime compatibility и paired upgrade/repeat/restore ещё не подтверждены.

## Проверки и публикация

[Local result](lab_patch_candidate_validation_20261012.json):33 candidate tests PASS,
full context suite197 OK/10 skipped (private HDD backup engine не предоставлен).
Проверены сохранение двух разных githash файлов, independent inode/hash/manifest,
отказ при другом collision/подмене file directory/порче сохранённого файла,
существующие lock/space/config/failure/interrupt guards.

**Выполнена вся сборка с настоящим SHA-pinned official tgz**,29409 stock files
readback PASS, вместе с synthetic private addon/config fixtures; не только
stock extraction отдельно. Все19 actual profile roots/23 actual extra names
проверены на collisions с полным catalog39376 entries, только githash исключён
из overlay в mounted code. Content synthetic fixture:2 addon roots/2 components/
2 USTAR files/3extras, не private server sources. Этот public archive test
в CI может быть skipped, если external fixture не предоставлен; обязательные
synthetic stock-collision tests остаются. Real addon runtime не назван PASS.

Code map regeneration/application526/tables93/tasks32 unchanged; context
index/check_context и diff checks выполняются перед publication. После owner
email verification GitHub accepted PR98 metadata update. Старый latest docs
run38171865960 — startup_failure, jobs empty, not PASS; corrected PR/CI status
фиксируется после публикации отдельно. Full RC PR83 не заменяет эти проверки.

## Команда оператору

В SSH `aduk@server1:~$` после `sudo -v`: скачать corrected helper по опубликованному
commit в `/home/aduk/USTAR_LAB_PATCH_CANDIDATE_20261012.py`, SHA check, установить
root:root0600 в `/usr/local/sbin/USTAR_LAB_PATCH_CANDIDATE_20261012.py`. Затем:

```bash
sudo -n bash -c 'set -euo pipefail; umask 077; printf "%s  %s\n" bb71ddd01566341d506a560628453bdcec045de89af7f4a80774a205f2f059c2 /usr/local/sbin/USTAR_LAB_PATCH_CANDIDATE_20261012.py | sha256sum --check --strict -; python3 -I -B -u /usr/local/sbin/USTAR_LAB_PATCH_CANDIDATE_20261012.py --prepare-candidate'
```

Без короткого outer timeout. Дождаться `LAB_PATCH_CANDIDATE_CODE=PASS; NOT_ACTIVATED;
NO_CORE_UPGRADE` и sanitized report с new directory/manifest SHA и preserved extra
hash status. При ошибке не retry/cleanup/activate; сохранить stdout/error.
После успешного code copy: dependency review и guarded stage switch/upgrade/repeat/
paired code/config/Moodledata/physicalPG restore, browser acceptance. Эти runtime
операции текущей командой не выполняются.
