# Активная работа —03.11.2026

## LIBRARY-01: подготовка папки и повтор установки

Owner принял native Quiz и поручил проверить внешние lib01 архивы, объединить
знания/каталог/маркетплейс/назначения/мини-проверки и подготовить один выпуск.
[Квалификация](../runtime/library_qualification_20261102.json),
[план](../../docs/superpowers/plans/2026-11-02-library-unification.md),
[ход работы](../runtime/library_integration_progress_20261102.md).

App26bd4fe0/tool6ee71123, local/theme0200,649 файлов/108 таблиц. FullRC:415 native
тестов/1916assertions на5.1.1 и5.1.8,36 browser views, actual PG16 upgrade/repeat.
Архив6ee71123/db2f51a1 совпал с CI и скачанной сохранённой копией.
Продажи выключены, source ACL не расширены, мини-проверки дают только отметку.
Owner retry: [missing-parent receipt](../runtime/library_parent_recovery_20261103.json).
prepare273 упал до journal: нет tests/fixtures. Check всё ещё ready1100.
Локальный root fixture: install -d root:root0755 → тот же updater → active/replay.
Далее owner создаёт эту папку и повторяет установку; реальная приёмка ожидается.
Production остаётся app2150affe/1100/theme2700, worker267e5587. Merge не выполнялся.
SCORM и Quiz приняты владельцем; их диагностику не повторять.

## PERF-01 после Библиотеки

Нужен пассивный отчёт обычного рабочего дня: latency, SQL, cron lag, нагрузка.
Сборщик frozen9a поддерживает2900; сначала адаптировать для фактически установленной
версии. Фактический8h отчёт не получен. SQL/page, Web-SAPI OPcache и concurrency/SLO
не выводить из Apache aggregate или одного статуса служб. Старые cron19/failed2 и
историю backoff не сбрасывать. Изменения выбирать по измерениям.

## Принятое и ожидающее руководства

PR110/111 объединены по [поручению](../runtime/connect_feed_integration_order_20261101.json).
Main5cc02944/app2900; PR113/114 открыты, новых merge разрешений нет.
[Owner267](../runtime/connect_visual_owner_deploy_20261101.json) принял обоих ботов,
PNG/кнопки/читаемость, задачи/возврат/исполнение/фото после восстановления egress.
RSS и раскрытие вложений также приняты. Остальные процессы проверяет руководство;
публичный HTTPS и Mini App ждут согласования, Bitrix/WhatsApp не включены.

Инструкции владельцу — прямо в чат. Работа самостоятельная: субагенты, agent SSH,
provider sends и новые автоматические слияния запрещены. Docker rotation/recreate
отложены до Moodle/PHP upgrade. Существующие writers и датированные receipts сохранять.
