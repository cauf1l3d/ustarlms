# USTAR — backup and recovery evidence

Дата проверки: 2026-08-23  
Режим: read-only

## 1. Подтверждённые артефакты

| Artifact | Последний проверенный файл | Размер | Статус |
|---|---|---:|---|
| Moodle PostgreSQL dump | `/opt/ustar/backups/daily/moodle/db_2026-08-22.dump` | 6,705,540 B | Exists |
| Moodle data | `/opt/ustar/backups/daily/moodle/moodledata_2026-08-22.tar.gz` | 363,360,238 B | Exists |
| Git bundle | `/opt/ustar/backups/git/USTAR_1.5.1_STABLE.bundle` | 5,558,730 B | Exists + `.sha256` |
| Frontend encrypted artifact | `/opt/ustar/backups/recovery/artifacts/ustar-frontend-6d4f15….tar.gpg` | 70,532,759 B | Exists + `.sha256` |
| Offsite recovery bundle | `/opt/ustar/backups/recovery/offsite/ustar-recovery-20260822T034001Z.tar.gpg` | 740,116,589 B | Exists + `.sha256` |
| Architecture Studio canonical backup | `/opt/ustar/backups/architecture-studio/manual/2026-08-22T13-40-40-929Z-r929.tar.gz` | 6,584,790 B | Exists |
| Architecture Studio pre-restore | revision 2 and 5 archives | small state archives | Exists |

Предыдущий ежедневный комплект от 2026-08-21 также присутствует. Всего в Studio проверено 6 manual/pre-restore archives; revision history canonical state содержит 500 revision files.

## 2. Что доказано и что не доказано

Доказано:

- backup jobs создали файлы ожидаемых классов;
- новые файлы имеют ненулевой размер;
- для Git/recovery artifacts присутствуют checksum files;
- encrypted offsite bundle существует;
- Studio поддерживает manual и pre-restore backup.

Не доказано:

- что checksum всех файлов проверяется автоматически после записи;
- что encryption key доступен уполномоченным лицам в аварии;
- что последний DB dump восстанавливается и согласован с moodledata/code versions;
- что внешнее размещение действительно независимо от основного сервера;
- фактические RPO/RTO;
- восстановление DNS/TLS/Caddy/secrets/integrations;
- rollback конкретного нового релиза.

## 3. Mandatory isolated restore drill

Выполнять не на production:

1. Зафиксировать выбранный backup set, checksums, timestamp и software versions.
2. Поднять чистый isolated host/network.
3. Восстановить PostgreSQL dump.
4. Восстановить moodledata с согласованными permissions.
5. Развернуть точные code/images из Git bundle/recovery artifacts.
6. Восстановить конфигурацию через secrets process, не копируя секреты в отчёт.
7. Запустить schema upgrade/check только в test environment.
8. Проверить login, file access, routes, completion, cron, Studio state.
9. Выполнить synthetic employee/manager/HR/CEO smoke tests.
10. Зафиксировать время восстановления, потерю данных и deviations.
11. Уничтожить test credentials/data безопасно по окончании.

## 4. Release rollback set

Непосредственно перед change window создать и проверить:

- DB dump;
- moodledata snapshot;
- exact Docker image digests;
- Git bundle/tag/commit;
- Caddy/config snapshot без публикации секретов;
- canonical `target-decisions.json` + generated outputs;
- checksum manifest;
- пошаговый rollback runbook и ответственный.

Rollback trigger: P0/P1 security regression, auth failure, role boundary failure, data corruption, cron failure, route/completion mismatch или недопустимый performance regression.

## 5. Verdict

Backup presence: **PASS**.  
Recovery readiness на момент исходного read-only аудита: **NOT PROVEN**.  
Release gate на момент исходного read-only аудита: **BLOCKED до успешного isolated restore drill**.

Дополнительное замечание: 7 пользователей имеют capability создавать backups с user data согласно Moodle security check. До релиза требуется подтвердить, что это минимально необходимый круг и что экспорт/хранение персональных данных аудируется.

## 6. Выполнено после согласования аудита — 2026-08-23

Создан свежий pre-change backup:

- archive: `/var/backups/ustar/2026-08-22_22-17-34.tar.gz` (server UTC);
- size: 4,797,784 bytes;
- checksum file: `/var/backups/ustar/2026-08-22_22-17-34.tar.gz.sha256`;
- `sha256sum -c`: PASS;
- content verified: logical Moodle DB dump, `local_ustar` plugin archive, Caddyfile, Docker Compose config bundle and Moodle config.

Выполнен isolated restore drill в `/opt/ustar/test-env/ustar-final-audit-release`:

- отдельная Docker network `ustar-final-audit-net`;
- отдельные containers `ustar_audit_postgres`, `ustar_audit_redis`, `ustar_audit_moodle`;
- test Moodle доступен только на server loopback `127.0.0.1:18080`;
- fresh DB dump восстановлен: 91 account rows и 8 course rows совпали с production snapshot;
- `check_database_schema.php`: PASS;
- `upgrade.php --is-pending`: no pending upgrade;
- login HTTP 200, root 303 → login;
- test HR mappings: 404;
- synthetic employee/manager/HR/CEO login and page smoke: PASS.

Ограничение drill: `moodledata` взят как live isolated copy, а не из отдельного sealed pre-change moodledata archive. Поэтому DB restore и runtime recovery доказаны, но полный disaster-recovery комплект, RPO/RTO и offsite-key recovery остаются отдельным gate.

Промежуточный verdict этого первого drill: **PARTIAL PASS**. Он был заменён полным isolated DR rehearsal из раздела 8; production rollback rehearsal всё ещё не выполнялся.

## 7. P0 permission rollback point — 2026-08-23

Перед согласованным production permission hardening сохранён полный manifest прежних owner/group/mode:

- `/var/backups/ustar/p0-permission-manifests/2026-08-22_23-27-14/permissions.before` (server UTC);
- scope ограничен `config.php`, `public/local/ustar` и `public/theme/ustar`;
- restore helper входит в release bundle как `ops/restore_moodle_permissions.sh` и отказывается работать с manifest/path вне разрешённого scope.

После hardening: database schema PASS, login HTTP 200, web-process guard PASS. Rollback не потребовался и не выполнялся.

## 8. Full isolated DR rehearsal — 2026-08-23

Создан консистентный snapshot isolated среды с остановкой только test Moodle:

- snapshot: `/opt/ustar/test-env/ustar-final-audit-release/dr-snapshots/2026-08-23_00-02-30`;
- size: 445 MB;
- `moodle.sql.gz`, `moodle-code.tgz`, `moodledata.tgz`;
- `SHA256SUMS`: PASS для всех трёх артефактов;
- исходная isolated среда после snapshot: login HTTP 200, database schema PASS.

Snapshot восстановлен в независимый стек:

- root: `/opt/ustar/test-env/ustar-final-dr-restore-20260823`;
- containers: `ustar_dr_postgres`, `ustar_dr_redis`, `ustar_dr_moodle`;
- loopback validation URL: `http://127.0.0.1:18081`;
- restored counts: 96 test account rows, 8 course rows;
- schema PASS, login HTTP 200, обе HR mapping URL HTTP 404;
- capability matrix PASS;
- 34/34 denial и 18/18 allow protected entry-point regression tests PASS.

После валидации временные DR containers остановлены, но snapshot и restored data сохранены для повторного запуска. Это доказывает test-only процедуру полного restore. Production-grade sealed snapshot, offsite copy, ключи, RPO/RTO и rehearsal реального production rollback остаются release gate.

Текущий verdict: **ISOLATED DR PASS / PRODUCTION RECOVERY PARTIAL**. Полное test-only восстановление code+moodledata+DB доказано; независимость offsite, key custody, измеренные RPO/RTO и production rollback остаются недоказанными.

## 9. Final release-candidate snapshot and restore — 2026-08-23

После login polish, Academy icons и game runtime/catalog fixes создан новый полный snapshot:

- `/opt/ustar/test-env/ustar-final-audit-release/dr-snapshots/2026-08-23_01-00-56`;
- size: 445 MB;
- `moodle.sql.gz`, `moodle-code.tgz`, `moodledata.tgz`;
- все записи `SHA256SUMS`: PASS;
- source isolated Moodle автоматически возвращён из maintenance mode; login 200.

Snapshot восстановлен в отдельный final RC stack:

- root: `/opt/ustar/test-env/ustar-final-dr-restore-20260823-rc`;
- containers: `ustar_rc_postgres`, `ustar_rc_redis`, `ustar_rc_moodle`;
- validation URL: loopback `127.0.0.1:18082`;
- database schema PASS; 96 test account rows, 8 courses; login 200; обе HR mapping URL 404;
- exact hashes login/icon/game deltas PASS; 12 Academy PNG present;
- capability matrix PASS; 34/34 denial and 18/18 allow entry-point tests PASS.

RC containers остановлены после проверки; restored data и snapshot сохранены. Один прерванный snapshot без `SHA256SUMS` не удалён, а изолирован как:

`/opt/ustar/test-env/ustar-final-audit-release/failed-snapshots/2026-08-23_00-59-14.incomplete`

Он не является release artifact и не проходит restore guard.
