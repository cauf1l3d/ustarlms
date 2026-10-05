# Резервные копии и восстановление

Сводка 05.10.2026 по [owner evidence](evidence_20261005.yaml), §§4.2/8 аудита и [уточнениям](../audits/AUDIT_RECONCILIATION_20261005_RU.md). Это паспорт результата и контракт будущей работы, не установленное расписание.

| Поле | Подтверждённое значение |
|---|---|
| Ручная копия | `ustar-recovery-20261004T123406Z-741e3f72.tar.gz.age` |
| SHA-256 | `ebf2a8756f07ba5b1b537c6888f7198c1d6107edc90f96d3816d9b7e4b7d4cb2` |
| Состав | DB, code/config, moodledata, container images |
| Консистентность | Moodle остановлен на 27.944 с; PostgreSQL онлайн; проверены внутренние суммы |
| Внешний экземпляр | Ручной перенос на SERVEREXPRESS, G; checksum PASS по выводу владельца |
| Ключ | Отдельная копия и decryption проверены по выводу владельца; содержимое/место в Git не публикуется |
| Isolated restore | 125 с / 4910 files; HTTP 200, admin/files/user roles/SCORM launch/progress PASS в своей области |
| Не доказано | Полный clean-host restore почты/DNS/ISPConfig; гарантированный RTO/RPO; потеря общей площадки |
| Регулярность | **Не установлена**, финальный этап текущего инфраструктурного цикла |

Snapshot сделан до последующих записей cron. Перед изменяющей операцией требуется свежая разовая согласованная копия, это не перенос регулярного расписания в начало цикла. Существующие архивы и результаты restore сохраняются до адресного решения; privacy старых dumps исправляется сейчас в INF-03.

## Target и политика

Предписание сисадмина: автоматизация **на HDD, не на SERVEREXPRESS**. Текущий WD5000AAKS `/dev/sdb` провалил extended self-test и **не trusted target**. Нужен healthy HDD из INF-04. Автоматический fallback на SSD при пропавшем mount запрещён контрактом; target определяется по проверенным serial/UUID, не по случайному имени устройства.

Локальный HDD не обеспечивает независимость от потери основного сервера. Отдельный экземпляр/носитель и его доступность при аварии выбираются владельцем; ручной SERVEREXPRESS snapshot не разрешает включать расписание туда. Если independent copy не выбрана, соответствующий gate остаётся открытым.

RPO/RTO, retention, pause/window, бюджет и ответственные **не согласованы**. Политика «14 daily / 4 weekly» не принята. DB и moodledata должны образовывать одну согласованную точку; отдельная ежедневная БД и произвольные недельные files не гарантируют её.

## Контракт финального внедрения

INF-14 → OPS-02 в [полном плане](../roadmap/USTAR_AUDIT_REMEDIATION_PLAN_20261005_RU.md): проверенный target и bounded capacity → согласованная зашифрованная копия с integrity manifest/atomic completion → required independent copy → проверенный retention → расписание → fresh restore из scheduled результата.

Сохраняются cron v3/backup-lock/single-worker, private paths/logs, stop/resume/failure cleanup, mount validation, absence of other writers и consistent dump. Источник установленного host script и его текущий SHA ещё нужно получить у оператора; новый GitHub harness не устанавливает и не реконструирует его.

Отказные проверки на копии: missing mount/full disk/failed dump/interruption/failed transfer/hash/decryption/lock contention. Backup success только после всех обязательных проверок. Retention сохраняет необходимый проверенный набор. Мониторинг отслеживает запуск, transfer/checksum, resume cron и restore freshness.

Ключ не хранится только на исходном хосте или единственно рядом с архивом. Доступ к нему проверяется в сценарии потери хоста; секреты и recovery archive не коммитятся. OPS-02 дополняет восстановление Академии общим host/service runbook. Расписание, независимость и полная репетиция пока не выполнены.
