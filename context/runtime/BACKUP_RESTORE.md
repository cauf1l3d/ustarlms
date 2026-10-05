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

Snapshot сделан до последующих записей cron. Перед изменяющей операцией требуется свежая разовая согласованная копия, это не перенос регулярного расписания в начало цикла. Существующие архивы и результаты restore сохраняются до адресного решения; privacy старых dumps относится к INF-03, который владелец отложил после выбранных 1 → 7 → 6. 05.10 09:48:49 UTC получены и полностью проверены existing wrapper sources; hashes совпали. [CLI, состав, lock и короткие команды](recovery_contract_20261005.md): default — preflight, backup — `--backup --acknowledge-outage`, **`--recover` только возобновляет Moodle после прерывания, не восстанавливает архив**.

## Свежая разовая копия и retained lab 05.10

Local encrypted archive `ustar-recovery-20261005T101659Z-7ea0ad99.tar.gz.age`: SHA-256 `42b87cca41623d3433943989c4ab5049b93d375cbcd5a7ddf43038d840b676a8`, wrapper и независимый server hash совпали. Moodle возобновлён за 24.097 s, scheduled cron 10:24 exit0/new failures0, HTTP303. External copy/decryption нового архива ещё не подтверждены. Это выполнение владельцем, не прямой server access агента.

Вчерашний isolated restore и manual PASS учтены; screenshot подтверждает authenticated admin feed в своей области. Его containers удалены штатным stop, physical DB/code/data/state/report retained. [Resume helper и проверки](lab_resume_20261005.md) возобновляют эти данные без нового archive restore; server resume и candidate patch/rollback ещё не выполнены. Старый отчёт и snapshot не переписываются.

## Target и политика

Предписание сисадмина: автоматизация **на HDD, не на SERVEREXPRESS**. Текущий WD5000AAKS `/dev/sdb` провалил extended self-test и **не trusted target**. Нужен healthy HDD из INF-04. Автоматический fallback на SSD при пропавшем mount запрещён контрактом; target определяется по проверенным serial/UUID, не по случайному имени устройства.

Локальный HDD не обеспечивает независимость от потери основного сервера. Отдельный экземпляр/носитель и его доступность при аварии выбираются владельцем; ручной SERVEREXPRESS snapshot не разрешает включать расписание туда. Если independent copy не выбрана, соответствующий gate остаётся открытым.

RPO/RTO, retention, pause/window, бюджет и ответственные **не согласованы**. Политика «14 daily / 4 weekly» не принята. DB и moodledata должны образовывать одну согласованную точку; отдельная ежедневная БД и произвольные недельные files не гарантируют её.

## Контракт финального внедрения

INF-14 → OPS-02 в [полном плане](../roadmap/USTAR_AUDIT_REMEDIATION_PLAN_20261005_RU.md): проверенный target и bounded capacity → согласованная зашифрованная копия с integrity manifest/atomic completion → required independent copy → проверенный retention → расписание → fresh restore из scheduled результата.

Сохраняются cron v3/backup-lock/single-worker, private paths/logs, stop/resume/failure cleanup, mount validation, absence of other writers и consistent dump. Existing manual script создаёт local SSD export, не выполняет transfer или автоматизацию на HDD. В output SERVEREXPRESS упомянут как историческая инструкция скачать файл, не как сетевой target. Новый GitHub harness не устанавливает и не реконструирует host wrappers. Archive restore и полное host/service восстановление требуют отдельного проверенного процесса; текущий payload не содержит host cron/backup scripts/state или всю общую серверную инфраструктуру.

Отказные проверки на копии: missing mount/full disk/failed dump/interruption/failed transfer/hash/decryption/lock contention. Backup success только после всех обязательных проверок. Retention сохраняет необходимый проверенный набор. Мониторинг отслеживает запуск, transfer/checksum, resume cron и restore freshness.

Ключ не хранится только на исходном хосте или единственно рядом с архивом. Доступ к нему проверяется в сценарии потери хоста; секреты и recovery archive не коммитятся. OPS-02 дополняет восстановление Академии общим host/service runbook. Расписание, независимость и полная репетиция пока не выполнены.
