# USTAR Connect — ревизия легаси-бота: стратегия переноса

- Дата: 2026-10-18
- Статус: **исходники legacy handler не получены, документ предварительный**.
- Ветка: design/ustar-connect-20261018, базовый репозиторий main d9655074db9a06281fe16f378dbdbe1d279104a0.
- Относится к RFC: context/proposals/USTAR_CONNECT_RFC_20261018.md.
- Решение: **strangler migration** — переносить доказанные сценарии, события и полезный UI/UX; границу API/auth/transport реализовать по современным контрактам, не копируя старый обработчик в Moodle public root.

## Установленные факты

1. По датированным данным работы с сервером (21 августа 2026 года) обработчик Bitrix находился в /opt/ustar/apps/moodle/moodle/public/bitrix_bot_handler.php. Тогда он не копировался в канонический Git source в ожидании проверки секретов. Это **историческое наблюдение**, не проверка наличия файла 18 октября.
2. В main@d9655074 дерево 1457 путей не содержит имени bitrix_bot_handler.php / другого telegram/bitrix/whatsapp bot handler. В двух выборочно проверенных архивных деревьях (156adb5b и 0fafce6a) такого именованного обработчика также нет. Это не доказывает отсутствие файла в legacy host, snapshots и во всех Git refs.
3. В moodle/local/ustar/classes/target_core.php метод notify записывает локальный inbox + local_ustar_notify_delivery, но для серьёзных Bitrix delivery проставляет disabled / «No Bitrix delivery adapter is configured in this release».
4. context/architecture/notification_delivery_20260928.md подтверждает: нет работающего внешнего Bitrix adapter/scheduled task в зафиксированном source, и требуется сверка live worker/старых pending.
5. Нынешние workflow_notifications.php и task_workspace/worker.php сохраняют notification/escalation facts отдельно от доставки, а assessment_lifecycle.php владеет состоянием циклов/ремедиаций. Их НЕ нужно заменять прежним кодом.
6. Пользователь сообщил: легаси-бот давал сводки, эскалации и оценивание результатов. Без полного legacy source нельзя утверждать, как именно выполнялись проверка прав, подсчёт оценок, очередь, signature validation или retry.

## Решение по компонентам

| Legacy компонент | Критерий | Действие |
|---|---|---|
| Тексты сводок, форматирование, навигация, команды | Не зависят от истории БД и совпадают с новым UX | Переиспользовать как продуктовый контракт/тестовые фикстуры |
| Эскалационные сценарии, сроки, акторы, подтверждения | Сопоставляются с нынешним task_workspace/assessment_lifecycle, не противоречат ADR | Сверить, недостающие возможности реализовать в каноническом домене; не создавать второй escalation engine |
| Маппинг старых получателей, Bitrix ID, dedup keys | Подтверждены provider IDs и migration semantics | Миграция после preview, контролируемого snapshot и opt-in, не угадать по ФИО/телефону |
| Оценка Quiz/SCORM/HR | Использует canonical Moodle grade / USTAR assessment services и explicit capability/scope | Переиспользовать сценарий; проверяемый новый API facade вызывает существующий writer; не копировать legacy SQL |
| Provider HTTP client, event parse/send formatter | Stateless, безопасен, совместим с актуальным Bitrix24 API | Допускается перенос за интерфейс адаптера после тестов/секрет-аудита |
| Встроенные bearer tokens, generic webhook, password/username login, query-string writes, raw SQL update/grade, прямой Moodle config.php bootstrap из публичного handler | Риск bypass RBAC/replay, устаревший transport | Переписать, не переносить без изменений |
| Legacy frontend/Next.js | Вне активного CI/release | Не реанимировать ради бота |
| Server INF-06 Telegram control | Отдельный операционный privilege realm | Не объединять с USTAR Connect identity или bot token |

## Как получить исходники без раскрытия секретов

Проверить на server1 только доступность файлов, метаданные и имена зависимостей. Содержимое .env, config.php и PHP-файлов в общий чат **не вставлять**, пока не пройдена отдельная локальная проверка токенов/секретов. Возможные исторические пути:

- /opt/ustar/apps/moodle/moodle/public/bitrix_bot_handler.php
- /opt/ustar/data/moodle/public/public/bitrix_bot_handler.php
- /opt/ustar/source/moodle-custom/ustar-live-src/

Если в файлах обнаружены секреты, выполнять только локальное безопасное редактирование/санитизацию на копии; не добавлять сырые legacy скрипты в Git. Проверить текущую БД на старые pending через read-only операторскую диагностику, не восстанавливать их доставку без выяснения истории и дублей.

## Acceptance gate перед выбором конкретного legacy reuse

- Снимок и хеш исходника + происхождение/canonical file path, исключены secrets;
- Граф вызовов из handler, работа с событиями, БД и provider API;
- Сопоставление каждой команды с актуальным service/capability/object scope;
- Repeat/idempotency/concurrency/revoke/report privacy tests;
- Учтены изменения Moodle 5.1.8, текущий org resolver и HR/HRD capability разделение;
- Не выполнять legacy script на production во время аудита: возможны скрытые запись и уведомления.

**Вывод:** не чистый greenfield бизнес-логики и не прямой перенос legacy handler; гибридная миграция через подтверждённые бизнес-контракты с новым изолированным внешним transport/auth и сохранением canonical USTAR state.
