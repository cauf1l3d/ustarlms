# Единый локальный инфраструктурный пакет25Oct2026

Текущий state-size fix SHA256
`b9c0bee69e2f6cf2d0508ae24cbdacffdc07904249d00afb555de6fa2a5b1a19` (40792B), CI pending.
Первый apply SHA1bb1f693 отказал `unsafe_root_json`: hardening journal21665285B,
DB809387B, own15657B; все root0:0/0600. Это несовпадение16MiB читателя с accepted
48MiB producer/64MiB capture. Reader64MiB, per-file64MiB и aggregate192MiB bounded;
права/владелец/link/xattrs guards сохранены. [Квитанция](infra_one_pass_owner_20261025.json).
Old source87941fd9 had Review40187026858/Context40187026890 SUCCESS and existing
PG16 job121150743119 PASS, but its fixture did not cover the observed journal size.
Исправленная версия допускает ровно predecessor1bb1f693 только после полной
metadata_restored с прежними exports before images/scope и отсутствием всех своих
managed files. Old/new SHA сохраняются в private state. Журналы не удалять/не менять.
Следующий owner retry разрешён после qualification; текущий пакет ещё не применён.
[Qualification/limits](infra_one_pass_qualification_20261025.json).

Пакет защищает архивы/дампы USTAR с сохранением owner/bytes и metadata-only undo,
сохраняет отдельный encrypted control companion до/после и ставит timer около
04:00–04:02МСК. Journal исправляется только по воспроизведённому timeout/permission
reason и measured fresh service snapshot; иначе explicit unresolved/rollback.
Проверяются cron receipt, deduplicated listeners и TLS leaf. Academy не останавливается.
[Матрица всех8](infra_closure_matrix_20261025.md). Default operator PLAN_ONLY.

После qualification скачать текущий ZIP на свой компьютер, извлечь и перенести в
`/home/aduk` только `ustar-infra-one-pass-20261025-statefix.py`. Существующие ZIP/дампы
на сервере оставить с исходными bytes/metadata: exact retry guard проверяет scope и
before images; новая/заменённая ZIP копия внутри home изменила бы эту выборку.
Выполнить один блок:

```bash
sudo -v
(
  set -euo pipefail
  sudo -n install -o root -g root -m 600 \
    /home/aduk/ustar-infra-one-pass-20261025-statefix.py \
    /root/ustar-infra-one-pass-20261025-statefix.py
  printf '%s  %s\n' \
    b9c0bee69e2f6cf2d0508ae24cbdacffdc07904249d00afb555de6fa2a5b1a19 \
    /root/ustar-infra-one-pass-20261025-statefix.py |
  sudo -n sha256sum --check --strict -
  ustar_closure_report=$(mktemp --suffix=.json /home/aduk/ustar-infra-closure-result.XXXXXX)
  sudo -n timeout --kill-after=5s 300s /usr/bin/python3 -I -B \
    /root/ustar-infra-one-pass-20261025-statefix.py --apply | tee "$ustar_closure_report"
  printf '\nREPORT_FILE=%s\n' "$ustar_closure_report"
)
```

Ожидается `phase=local_batch_applied`, `control_backup.readback_verified=true`,
`companion_timer_active=true`; journal может остаться unresolved — это не PASS.
`infrastructure_complete=false` сохраняется: Docker lifecycle/shared-host access/
external DR/offsite/key custody/UPS/observer/delivery/SLO не закрываются догадкой.
Вернуть итог JSON, не private `/var/lib` state/keys/raw logs.

`--check` читает текущее состояние. После успешного active не повторять --apply.
`--recover`/`--undo` возвращают прежние metadata/свои units и сохраняют encrypted
copies; business rows не восстанавливаются. Unknown bytes/inode/size/mtime drift
refuse. После полного metadata_restored тот же operator или исправленный successor для
одного pinned predecessor может повторить --apply только при прежних before
images/scope и отсутствии его созданных файлов. При refusal вернуть reason; не
стирать/редактировать private state и не менять права исходных журналов вручную.

State `/var/lib/ustar-infra-one-pass` root-only. Companion
`/srv/ustar-storage/backups/host-state`, quota5GiB/reserve8GiB, без удаления;
`recovery.key` исключён. Standard DB/CODE/DATA backup и pre-hardening archive
`ustar-recovery-20261025T003520Z-b6dfed1e.tar.gz.age` сохранить. Companion не whole-host
backup или atomic recovery pair; сверка generation/identity и clean-host restore
остаются обязательны до DR acceptance. Новые ручные exports создавать с `umask 077`.

Предыдущий survey0d5d05fc уже принят; повторно его не запускать по умолчанию.
[Сводка owner report](infra_survey_owner_20261025.json), original private report SHA
344965014f7cbee76c9588e61cb83433a3ff6f623002aaa2361d74deb63385b8.

Historical delivery v2 ZIP15324B/SHA88beee438291c06ad52e14544d769c4246c098a21e5318e6f0573093da4049af
passed download/CRC/default checks but contained the incompatible16MiB reader.
Updated delivery/readback qualification pending; v2 is superseded, not a retry fix.
