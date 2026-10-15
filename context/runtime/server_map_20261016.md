# Карта сервера на16Oct

Это сверка owner evidence11–16Oct, не новый live inventory. [Machine evidence](evidence_20261016.yaml), [host](server.yaml), [Docker](docker.yaml), [Moodle](moodle.yaml).

| Область | Подтверждено | Граница |
|---|---|---|
| Академия | Moodle5.1.8/core2025100608.00, PHP8.3.33, PG16.15; local2026100204/theme2026100202 | Core accepted13Oct; exact plugin/theme manifest526 dated05Oct |
| Web | host Apache HTTP →127.0.0.1:8082→ustar_moodle | HTTPS Академии не принят; Caddy не объявлен её TLS proxy |
| Хост | Ubuntu24.04.5, kernel6.8.0-147, Docker29.9.0/containerd2.4.1 | Native boot14Oct, selected units healthy; не полный mail/DNS E2E |
| SSD | /dev/sda2 ext4, filesystem120032006144B, df117815881728B | GPT/partition/ext4 grown11Oct; UUID/quota preserved |
| HDD | /srv/ustar-storage, /dev/sdb1 ext4, UUID359a2bae-4e79-461a-ab72-1597f605d801 | options rw,nosuid,nodev,noexec; mounted after boot14Oct |
| Backup | /srv/ustar-storage/backups/managed | daily03:30Moscow/no catchup/no auto deletion; retention policy still open |
| Transfer | /srv/ustar-storage/transfers/<run>/ustar-transfer.tar + .sha256 | owner obtained archive16Oct; exact new name/hash not supplied in confirmation |
| SSD archive | /srv/ustar-storage/host-recovery-20261013T234210Z-874c1983/root.tar | 43704842240B/readback+223777 protected members; plaintext; not boot restored |
| Prod code/data | /opt/ustar/data/moodle/public, /opt/ustar/data/moodle/moodledata | Existing production bind mounts were RW; new-copy read-only code does not fix prod rights |
| Prod PostgreSQL | /opt/ustar/data/postgres | PG17 transition deferred; never mount PG16 physical data directly in PG17 |
| Retained labs | 4 web/PG containers exited0/restart=no after14Oct boot | IDs/policies preserved; not required to auto-start |
| Shared services | Apache, Caddy, mail, MariaDB, DNS, ISPConfig consumers | Retain; no blanket stop/removal through panel |

Актуальные свободное место/SMART/нагрузка16Oct не снимались. Старые расход/температура и handoff05/06Oct сохранены как dated evidence. Следующая работа — collectors/status в пульте; не новый storage/upgrade цикл.
