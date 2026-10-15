# Резервные копии и восстановление

## Текущий статус16Oct

Ежедневный HDD producer/scheduler установлен; scheduled13/14Oct успешны, mount/timer/no recovery marker после native reboot14Oct проверены. Начальная политика:03:30 Europe/Moscow, без catch-up/автоудаления, quota100GiB. RPO/RTO, долгий retention, независимость/уведомления и clean-host DR остаются отдельной приёмкой.

Владелец16Oct подтвердил получение transfer archive и передачу инструкции сисадмину. [Receipt](sysadmin_handoff_result_20261016.json). [Инструкция копии без остановки прода](production_copy_20261015.md). Ожидаем реальный импорт/вход/SCORM/reboot; передача не является restore PASS. Новый filename/hash в коротком подтверждении не предоставлен — не выдумывать.

## Файлы и скрипты

| Что | Путь или helper |
|---|---|
| Согласованный архив Академии | /srv/ustar-storage/backups/managed/ustar-recovery-*.tar.gz.age + JSON |
| HDD producer | /usr/local/sbin/USTAR_HDD_BACKUP_20261006.py |
| Daily scheduler/status | /usr/local/sbin/USTAR_HDD_SCHEDULE_20261006.py |
| Автономный комплект | /srv/ustar-storage/transfers/<run>/ustar-transfer.tar + .sha256 |
| Сборка без source stop | prepare_production_copy.sh → USTAR_BACKUP_TRANSFER_20261015.py --pack |
| Постоянная parallel copy | restore.py --start-production-copy → USTAR_PRODUCTION_RESTORE_20261015.py; свой URL, mail/cron off |
| Временный стенд | USTAR_BACKUP_TRANSFER_20261015.py --start-lab --duration30m/2h/1d/0 |
| Полный SSD file archive | /srv/ustar-storage/host-recovery-20261013T234210Z-874c1983/root.tar |
| SSD capture | /usr/local/sbin/USTAR_HOST_COLD_RECOVERY_20261013.py |

Академический архив содержит согласованные DB, code/config, moodledata и точные container images. Transfer tar дополнительно содержит ключ и pinned restore helpers: это private незашифрованный внешний комплект, его не отдавать в Telegram/Git. Для Telegram latest-backup предполагается именно зашифрованный .age и отдельная key custody.

SSD root.tar43.7GB — другой тип восстановления: owner r6 прочитан и223777 protected members проверены; он не зашифрован и не был развёрнут/загружен на чистом host. Не объявлять Academy kit полным бэкапом почты/DNS/ISPConfig/OS.

`--pack` читает существующий completed backup и не останавливает прод; данные соответствуют snapshot_completed_utc. `--pack-production` делает финальный снимок с maintenance/остановкой и сейчас не требуется. `--recover` у writer — resume-only, не archive restore. Не подменять один режим другим.

[Исторический контракт](recovery_contract_20261005.md), [HDD](hdd_backup_20261006.md), [scheduler](hdd_backup_schedule_20261006.md), [SSD result](host_cold_recovery_result_20261013.json). История04/05Oct/частные failure receipts сохранена в release ledger и исходных commits.
