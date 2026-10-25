# Локальный инфраструктурный пакет25Oct2026: исправление выборки

Текущий candidate `ustar-infra-one-pass-20261025-scopefix.py`,42892B/SHA256
`5067163fbfa6f0b4dd345c0d48b064cbd63fed377cc8446282ef46cf681f57e5`; CI pending.
[Owner receipt](infra_one_pass_owner_20261025.json), [qualification](infra_one_pass_qualification_20261025.json).
Owner подтверждает `metadata_restored` и sourceb9c0bee6 после второго отказа
unsafe_payload_directory. Первый отказ1bb1f693 был лимитом16MiB для21665285B journal;
исправленный64MiB reader сохранён. Прошлый e15c696c Review40197352367/Context40197352371
SUCCESS покрывал размер журнала, но synthetic fixture не имел физических baseline.
Новый success на production не заявлен. DB/code hardening и старые stands не повторять.

## Исправленный scope

В четырёх `/var/lib/ustar-connect-{telegram,con02,academy,chat-link}-update`
physical trees сохраняют исходные UID33/999/0777 и гигабайты данных. Companion
исключает до обхода только дочерние `baseline`, `retained-original-addons`,
`retained-original-code`, `retained-before-restore-<32hex>`, `restore-code-<32hex>`
в известных `app-<32hex>` / `update-<8lowercase_or_digit_or_underscore>` generations.
Не меняет/не удаляет эти деревья. Другие каталоги продолжают строгую проверку.
Generation control images, primitive/transaction journals, baseline-manifest.json и
cron control images включаются; lock/private-*/commands-private.log исключены.
Manifest/receipt явно `physical_recovery_trees_included=false`.

Полный metadata-only pass по выбранным controls выполняется до подготовки
транзакции. Проверяет owner/write/link/xattr/file/count/aggregate bounds без чтения
содержимого. Actual capture повторяет guards под existing writer locks. При отказе
добавляется безопасный относительный `path`, если доступен; raw stderr/values нет.
64MiB/file,192MiB aggregate,10000files; recovery.key исключён. Новая попытка принимает
только known1bb1f693/b9c0bee6 или same source, exact metadata_restored, прежние export
before images/scope и отсутствие всех созданных managed files. Source history
сохраняется; journals не удалять/редактировать, метаданные вручную не менять.

## Один повтор после qualification

Скачать исправленный ZIP на компьютер, извлечь и перенести в `/home/aduk` только
`ustar-infra-one-pass-20261025-scopefix.py`. ZIP/дампы на сервере оставить с исходными
bytes/metadata: их замена/добавление в scanned home scope изменит guarded retry.
Qualification pending; owner command ниже подготовлен, до квалификации не выполнять.

```bash
sudo -v
(
  set -euo pipefail
  sudo -n install -o root -g root -m 600 \
    /home/aduk/ustar-infra-one-pass-20261025-scopefix.py \
    /root/ustar-infra-one-pass-20261025-scopefix.py
  printf '%s  %s\n' \
    5067163fbfa6f0b4dd345c0d48b064cbd63fed377cc8446282ef46cf681f57e5 \
    /root/ustar-infra-one-pass-20261025-scopefix.py |
  sudo -n sha256sum --check --strict -
  ustar_closure_report=$(mktemp --suffix=.json /home/aduk/ustar-infra-closure-result.XXXXXX)
  sudo -n timeout --kill-after=5s 300s /usr/bin/python3 -I -B \
    /root/ustar-infra-one-pass-20261025-scopefix.py --apply | tee "$ustar_closure_report"
  printf '\nREPORT_FILE=%s\n' "$ustar_closure_report"
)
```

Ожидается local_batch_applied, control_backup.readback_verified=true,
companion_timer_active=true. Журнал может быть unresolved; это не measured PASS.
При refusal вернуть sanitized JSON/path, без private journal/keys/passwords.
--check читает guards/metadata/status, default полностью offline PLAN_ONLY.
--undo/--recover возвращают metadata/свои units, не бизнес-строки; encrypted copies
сохраняются. После active не повторять --apply.

## Границы результата

Пакет ограничивает доступ group/other к выбранным USTAR exports, сохраняет encrypted
control companion до/после, ставит timer04:00–04:02МСК, диагностирует journal только
в sandbox actual collector, сохраняет adjustment только с fresh measured snapshot.
Возвращает cron receipt/listeners/verified active TLS leaf; Academy не останавливает.
Companion target `/srv/ustar-storage/backups/host-state`, root0700, quota5GiB,
reserve8GiB, без удаления. Private state `/var/lib/ustar-infra-one-pass` root0700.

Companion не whole-host backup или atomic pair с DB/CODE/DATA и не переносит старые
physical recovery trees. Их manifests в companion не заменяют их байты. Сохранять
исходные SSD pairs и standard encrypted HDD backup, включая pre-hardening archive
ustar-recovery-20261025T003520Z-b6dfed1e.tar.gz.age. Generation/identity pairing,
qualified clean-host restore/offsite/key custody остаются открыты. [All8 matrix](infra_closure_matrix_20261025.md).
Docker lifecycle, alternative SSH/shared-host policy/www-data trust boundary,
physical UPS/independent observer/delivery/SMTP/SLO и TLS-renewal recovery требуют
отдельных реальных подтверждений. Полные8 не закрыты; повторять прежние owner stands
и survey0d5d05fc по умолчанию не нужно.

## Проверки

43focused tests41PASS/2local age/UID namespace skips. Native CI должен без пропусков
проверить actualUID33/999 physical copies/0777 cache/FIFO/91677698B sparse media,
передачу controls в real restricted-service age archive,21MiB journal и b9 retry.
Source/runtime/native CI и saved ZIP download readback текущей версии pending.
Ранее v3 ZIP14319B/SHA0e401b609f77eedfeedd9ca1fa85a79526d68e75e4c39fcda3328aabf9fbc86e
прошёл byte/CRC/default download checks, но содержит слишком широкий physical scope;
это historical qualification, не текущий apply пакет.
