# Stage 1 rollback / restore drill

Этот drill проверяет не наличие backup, а возможность вернуть согласованный baseline после применения Stage 1.

Он **не использует production config, production DB или production moodledata**. Docker project имеет отдельную PostgreSQL 16.10, внутреннюю сеть без host ports, fixture credentials и временные volumes/tmpfs.

## Что проверяется

1. Точный baseline `378d397152a8c83f8b0d046e2e561ab2732d6b02` разворачивается на Moodle 5.1.1.
2. Создаётся согласованный snapshot:
   - PostgreSQL custom-format dump;
   - moodledata;
   - `local/ustar` + `theme/ustar`;
   - stage config;
   - runtime/SHA references.
3. Применяется текущий candidate SHA через штатный Moodle upgrade.
4. Создаётся candidate-only изменение в DB и moodledata.
5. Выполняется полный rollback:
   - application source;
   - config;
   - moodledata;
   - полное пересоздание stage database из dump.
6. После restore доказывается:
   - candidate-only файл исчез;
   - DB marker вернулся к baseline;
   - plugin DB version снова совпадает с baseline code version;
   - plugin/theme byte tree совпадает с immutable baseline export;
   - Moodle bootstrap и повторный upgrade завершаются успешно.

Files-only rollback не считается успешным результатом.

## Запуск

Из корня repository:

```sh
bash scripts/stage1_rollback_drill.sh
```

Скрипт не принимает production paths или credentials. Перед запуском он проверяет resolved Compose и отказывается работать, если обнаружены production container/path references или host ports.

## Evidence

После успешного запуска:

```text
artifacts/rollback-drill/
  01-install-core.log
  02-baseline-preparation.log
  03-baseline-upgrade.log
  04-baseline-smoke.json
  05-snapshot-sha256.txt
  06-candidate-upgrade.log
  07-candidate-smoke.json
  08-db-drop.log
  09-db-create.log
  10-db-restore.log
  11-plugin-source-diff.txt
  12-theme-source-diff.txt
  13-restored-smoke.json
  14-post-restore-upgrade.log
  15-runtime.txt
  compose-resolved.yaml
  host-images.txt
  result.txt
```

Успех подтверждается только строкой:

```text
ROLLBACK_DRILL=PASS
```

и зелёным workflow job `rollback` на том же exact SHA.

## Что этот drill не доказывает

Он не заменяет отдельный R00 clean-server disaster-recovery restore полного production snapshot `USTAR_FULL_CURRENT_20260919_113924.tar.gz`. Полный production recovery архив содержит реальные DB/moodledata/runtime state и проверяется отдельно на чистом сервере.
