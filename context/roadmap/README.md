# План и состояние USTAR

[STATE](STATE.yaml) — текущая очередь, [BACKLOG](BACKLOG.yaml) — статусы по идентификаторам, [ACTIVE](../tasks/ACTIVE.md) — **INF-06: доработка пульта по запросу18Oct17:03МСК**. Предыдущее закрытие14:42 отменено новым поручением; [перечень изменений](../../server-console/REVISIONS_20261018.md).

[План HTTPS](../tasks/HTTPS_NEXT_20261018.md) продолжает выбранные baseline/patch_OS/HTTPS после более позднего приоритета пульта. Сначала read-only подготовка и решения об имени/сертификате/доверии для действующего Apache, затем проверенная конфигурация и откат. Нативное переключение этой актуализацией не выполняется.

Независимый observer, home-access pilot и неподтверждённые native операции остаются INF-06-OPS. Результат сисадминского restore — OPS-02 waiting_external. Moodle5.2/PG17 отложены; SSD/Moodle patch/host maintenance не повторять.

Вход — только [START_HERE](../../START_HERE.md). История решений — [SESSION_LOG](SESSION_LOG.md), исходный [план аудита](USTAR_AUDIT_REMEDIATION_PLAN_20261005_RU.md), [сверка06–16Oct](../audits/AUDIT_RECONCILIATION_20261016_RU.md), [старые указатели](../archive/reconciliation_20261016/README.md). Датированные оригиналы не переписываются как новое измерение.
