# Compose source privacy: квалифицированный блок25Oct2026

**Владелец применил Compose0600, UID/GID1000 и содержимое сохранены; companion завершён.**
[Owner receipt](compose_source_privacy_owner_20261025.json). После первоначального
busy один retry успешно создал264-file/35043569B encrypted control archive:
`ustar-control-20261025T183912Z-d7f6f8e3.tar.gz.age`,
SHA256083bbfda3d26e85caca74393af76c4006e03c3278e0016a718829929b370c2f4,
readback_verified=true, finished21:39:16MSK. Original archive261files/SHAda701618
сохранён. Local source privacy + companion step закрыт; whole-host/offsite/DR не
утверждается. Конкретный прежний busy holder не определён, диагностика не нужна.

## Следующий шаг

[Missing Docker writable-layer metadata](infra_docker_writable_layer_20261025.md).
Source check/apply/companion повторять не требуется. Origin/profile и старые owner
stands приняты. Actual log rotation/coordinated frozen-ID lifecycle ещё открыты.

## Квалификация исходного кода
Review40217322352/Context40217322350 SUCCESS на dbee1d31d5e268299d292c663adb4c7c5d567307.
Runtime121241183532:15new tests/no skips, actual UID1000 retains read/UID33 denied,
private source copy denied to UID1000. Source jobs1204tests/227environment skips each,
PHP386/JS24/tables100/templates57/browser PASS; PG16/native guards PASS.
[Qualification](compose_source_privacy_qualification_20261025.json).

## Что меняет

Только /opt/ustar/apps/stack/docker-compose.yml0644→0600. Сохраняет UID/GID1000,
содержимое904B/SHA41de2ed3, inode,mtime и metadata родительских deployment directories.
Deployer остаётся владельцем и продолжает читать/редактировать файл. Group1000/
UID1000 directories не становятся root-owned; полный shared-host write policy не
закрывается. Значения трёх PG env entries не публиковались, наличие конкретного
пароля в файле не утверждается. No outage/Docker build/up/recreate/restart, app/DB
writes/SSH changes. Фактическая ротация логов и health lifecycle остаются отдельным
coordinated frozen-ID/recovery handoff; источник Moodle имеет build.

## Проверенный пакет и первоначальный запуск (история)

[ustar-compose-source-privacy-20261025.zip](https://chatgpt.com/library?file_id=libfile_da976d82c120819188728a03a9485cf6&account_id=personal) —4778B,
SHA256d4a5350e84b9a809f8571da92a6369836cf616421fc9a87db9bd6729b4ab368a.
Script7874B/SHA25620d282e9b24dcc141691271e6477ddc1f24a17979f27c87f94e0294294d70fdb.
Три members: script,README-compose-source.txt,SHA256SUMS.txt. CRC/member hashes/
extraction/compile/default PLAN_ONLY и saved download readback проверены.

Ниже первоначальный блок, уже выполненный владельцем; не запускать заново.
Ранее: распаковать на компьютере, перенести ТОЛЬКО .py в /home/aduk:

```bash
sudo -v && (
  set -euo pipefail
  sudo -n install -o root -g root -m 600 \
    /home/aduk/ustar-compose-source-privacy-20261025.py \
    /root/ustar-compose-source-privacy-20261025.py
  printf '%s  %s\n' \
    20d282e9b24dcc141691271e6477ddc1f24a17979f27c87f94e0294294d70fdb \
    /root/ustar-compose-source-privacy-20261025.py \
    5067163fbfa6f0b4dd345c0d48b064cbd63fed377cc8446282ef46cf681f57e5 \
    /usr/local/sbin/USTAR_INFRA_ONE_PASS_20261025.py |
  sudo -n sha256sum --check --strict -
  sudo -n timeout --kill-after=3s 30s /usr/bin/python3 -I -B \
    /root/ustar-compose-source-privacy-20261025.py --check
  sudo -n timeout --kill-after=3s 30s /usr/bin/python3 -I -B \
    /root/ustar-compose-source-privacy-20261025.py --apply
  sudo -n timeout --kill-after=5s 180s /usr/bin/python3 -I -B \
    /usr/local/sbin/USTAR_INFRA_ONE_PASS_20261025.py --backup-host-state
)
```

В первоначальном блоке ожидался ready_for_compose_source_privacy →
compose_source_private(uid/gid1000,mode0o600,contents_unchanged=true), затем existing
companion complete/readback_verified. Guard refusal не обходить chmod/delete/rebaseline.
Если encrypted capture откажет после mode change, private before images остаются;
source read protection автоматически не снимается. Разобрать именно public reason.

## Recovery scope

Новый child Store /var/lib/ustar-code-hardening/compose-source root0700/files0600:
state.json(single source before metadata),compose.yml.before(raw904B),operator.py
(exact adapter). Existing pinned infra core5067163f и существующие controller/
code-hardening/active one-pass journals не переписываются. Code-hardening lock
сериализует это с existing companion capture. Installed core необходимо сохранить.
Текущий companion автоматически включает child controls; не запускать one-pass
--apply снова, только --backup-host-state после этой операции.

Это снимок принятого source generation41de2ed3, не непрерывное отслеживание будущих
правок live Compose-файла. Hash/inode/owner/mtime/image drift отказывает. Наличие
копии файла не аттестует Dockerfile/build context или source/live equivalence.
Original SSD physical pairs/HDD DB-CODE-DATA и private recovery key нужны как раньше;
companion не whole-host/offsite/atomic physical pair и не external restore PASS.

Для mode-only undo использовать этот же квалифицированный script с --undo ДО
удаления/undo existing one-pass core; restore source contents/business rows не
выполняется. Images и encrypted archives остаются. После неизвестных изменений
source требуется qualified profile handoff, а не изменение saved before images.
SSH hardening отложен владельцем; LAN login/password принят. UPS presence/copies
handed over приняты, external restore explicitly unknown, не переспрашивать.
