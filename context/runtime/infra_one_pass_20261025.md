# Единый локальный инфраструктурный пакет25Oct2026

Source87941fd918aad464f75f6829f705a20b0b2dfc36; script SHA256
`1bb1f6938e709f0361e83d88bb82ec738afce65059e1e0d3a217a93abc71df18` (40316B).
Review40187026858/Context40187026890 SUCCESS; existing PG16 job121150743119 PASS. Source1175/199skips на обеих
PHP PASS; root runtime121149903686:29/29/no skips и native markers PASS.
[Qualification/limits](infra_one_pass_qualification_20261025.json).
Новый пакет на сервере ещё не выполнялся; DB/code hardening уже приняты, не повторять.

Пакет защищает архивы/дампы USTAR с сохранением owner/bytes и metadata-only undo,
сохраняет отдельный encrypted control companion до/после и ставит timer около
04:00–04:02МСК. Journal исправляется только по воспроизведённому timeout/permission
reason и measured fresh service snapshot; иначе explicit unresolved/rollback.
Проверяются cron receipt, deduplicated listeners и TLS leaf. Academy не останавливается.
[Матрица всех8](infra_closure_matrix_20261025.md). Default operator PLAN_ONLY.

Распаковать текущий ZIP, положить .py в /home/aduk, выполнить один блок:

```bash
sudo -v
(
  set -euo pipefail
  sudo -n install -o root -g root -m 600 \
    /home/aduk/ustar-infra-one-pass-20261025.py \
    /root/ustar-infra-one-pass-20261025.py
  printf '%s  %s\n' \
    1bb1f6938e709f0361e83d88bb82ec738afce65059e1e0d3a217a93abc71df18 \
    /root/ustar-infra-one-pass-20261025.py |
  sudo -n sha256sum --check --strict -
  ustar_closure_report=$(mktemp --suffix=.json /home/aduk/ustar-infra-closure-result.XXXXXX)
  sudo -n timeout --kill-after=5s 300s /usr/bin/python3 -I -B \
    /root/ustar-infra-one-pass-20261025.py --apply | tee "$ustar_closure_report"
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
refuse. После полного metadata_restored тот же operator может повторить --apply
только при прежних before images/scope и отсутствии его созданных файлов.

State `/var/lib/ustar-infra-one-pass` root-only. Companion
`/srv/ustar-storage/backups/host-state`, quota5GiB/reserve8GiB, без удаления;
`recovery.key` исключён. Standard DB/CODE/DATA backup и pre-hardening archive
`ustar-recovery-20261025T003520Z-b6dfed1e.tar.gz.age` сохранить. Companion не whole-host
backup или atomic recovery pair; сверка generation/identity и clean-host restore
остаются обязательны до DR acceptance. Новые ручные exports создавать с `umask 077`.

Предыдущий survey0d5d05fc уже принят; повторно его не запускать по умолчанию.
[Сводка owner report](infra_survey_owner_20261025.json), original private report SHA
344965014f7cbee76c9588e61cb83433a3ff6f623002aaa2361d74deb63385b8.

Delivery ZIP15324B/SHA25688beee438291c06ad52e14544d769c4246c098a21e5318e6f0573093da4049af:
CRC, extracted script/default PLAN_ONLY и downloaded saved version2 readback PASS.
