# Локальный инфраструктурный пакет25Oct2026: исправление выборки

**Применён владельцем25Oct17:49МСК: local_batch_applied.**
69exports protected; control companion261files/35039995B, readback verified,
SHA256da7016184c1133299f07e998a15731c76725764a50a7ec421aba8b596a03d35f.
Timer active; journal measured/actionnone; containers unchanged, no business restore
or provider sends. [Owner receipt](infra_one_pass_owner_20261025.json#accepted_apply).
**--apply больше не запускать.** Follow-up подтверждён: UPS есть, старые retry задачи disabled; external restore
result явно unknown/copies handed over. Следующий [Caddy metadata-only срез](infra_caddy_listener_metadata_20261025.md).
All8 not complete; separate scheduled tick/external DR/access/power/observer/SLO facts
remain open. Original physical pairs and standard HDD backup remain required.

Текущий квалифицированный пакет `ustar-infra-one-pass-20261025-scopefix.py`,42892B/SHA256
`5067163fbfa6f0b4dd345c0d48b064cbd63fed377cc8446282ef46cf681f57e5`.
Source1c5152bdbeb67c6d06ab3f4bc00bd642a8b22be5; Review40202157683/Context40202157658
SUCCESS. Runtime job121195410529:43/43/no skips; both source1189/213environment skips
and browser PASS. PG16 job121196267679:158tests/830assertions+native guards PASS.
[Owner receipt](infra_one_pass_owner_20261025.json), [qualification](infra_one_pass_qualification_20261025.json).
История: owner подтвердил `metadata_restored` и sourceb9c0bee6 после второго отказа
unsafe_payload_directory. Первый отказ1bb1f693 был лимитом16MiB для21665285B journal;
исправленный64MiB reader сохранён. Прошлый e15c696c Review40197352367/Context40197352371
SUCCESS покрывал размер журнала, но synthetic fixture не имел физических baseline.
Scopefix теперь принят по owner local_batch_applied; DB/code hardening и старые stands не повторять.

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

## История выполненной команды

Скачать исправленный ZIP на компьютер, извлечь и перенести в `/home/aduk` только
`ustar-infra-one-pass-20261025-scopefix.py`. ZIP/дампы на сервере оставить с исходными
bytes/metadata: их замена/добавление в scanned home scope изменит guarded retry.
Qualification завершена; команда ниже уже выполнена владельцем17:49МСК. Не повторять --apply:

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

43focused root tests PASS/no skips. ActualUID33/999 physical copies/0777 cache/FIFO/
91677698B sparse media retained unchanged; controls included in real restricted-service
age/readback archive,21665285B journal and actualUID1000 b9 retry PASS. Local41/43 with
2environment skips (age/UID namespace); source CI1189/213skips each completed.
Current saved ZIPv4,15501B/SHA256
3eacbd653fe4a21a1fcee44dde3a28c64f776111172c02e9404041b804e6f104:
full hash/CRC/member hashes/extracted script SHA/default PLAN_ONLY and downloaded
saved copy verified. Full production coordinator not executed in CI; fixtures use
synthetic controls/keys and isolated namespaces; journal boundary modeled plus
production self-verification. Owner local_batch_applied receipt accepted25Oct17:49MSK; no repeat apply.
Ранее v3 ZIP14319B/SHA0e401b609f77eedfeedd9ca1fa85a79526d68e75e4c39fcda3328aabf9fbc86e
прошёл byte/CRC/default download checks, но содержит слишком широкий physical scope;
это historical qualification, не текущий apply пакет.
