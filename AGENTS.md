# USTAR инструкции агентам

**18 октября17:03МСК: пульт возвращён владельцем на доработку.** Предыдущее закрытие14:42 сохранено только как история; HTTPS/доверие клиентов, Mini App/VPN, темы, крупные графики, скрипты, документация, реальные request metrics и очищенные журналы снова входят в текущую INF-06. [Запрос и границы](server-console/REVISIONS_20261018.md). Прямого SSH нет; клиентский маршрут/доверие не объявлять исправленными без проверки устройства.


**18 октября18:38МСК:** [владелец подтвердил](context/runtime/console_client_https_result_20261018.json) исчезновение HTTPS-предупреждений и офисный доступ с Happ/NovaVPS и без него после обновления сертификатов. Update1c881495 уже установлен. Не повторять установку/CA и не добавлять исключение маршрута; текущий остаток — проверка фактических новых данных/UI в INF-06.

## Перед работой

Единственный порядок чтения — в [START_HERE](START_HERE.md). Остальные указатели не задают альтернативный следующий шаг. [Вердикт17Oct](context/reviews/20261017/README.md) отличает подтверждённые проблемы от неподтверждённых утверждений внешнего review.

`main` содержит канонический код и контекст. Новые ветки от свежего main; PR в main. [Старые ветки](context/runtime/repository_state_20261016.json) сохраняют историю. Различать exact application baseline, repository head, core/runtime и факт deployment.

## Область продукта и тестов

Рабочий UI: `moodle/local/ustar`, `moodle/theme/ustar` (theme_ustar, parent boost). `frontend/` — legacy, исключён из активного CI и release manifests/archives. Не обновлять его зависимости ради текущего gate. JS/AMD, Mustache, PHP и browser tests native UI сохраняются. Будущий server-console — отдельный operations UI, не возобновление legacy Next.js.

Не повторять принятые действия: SSD/GPT/ext4, Moodle5.1.8 upgrade/repeat/browser, AppArmor repair, r6 cold archive, host install/reboot. Точные границы — в STATE/аудите. Новая копия сисадмина ещё не принята; нет основания объявлять полный DR завершённым.

## Инварианты

- Сохранять границы core/plugin/theme и одну модель сотрудников, completion/evidence, кошелька и сообщений. View-as и строка должности не дают прав записи.
- Любой write проверяет реального actor, capability, current scope, sesskey/API contract, revision, locks и idempotency. Не исправлять реальные роли/историю/персональные данные по предположению.
- XMLDB install/upgrade parity и release gate обязательны при schema changes. Не править исторические release-копии вместо `moodle/`.
- Не публиковать config.php, токены, dumps, moodledata, private logs или ключи. Не запускать PHP/Moodle probe на проде до проверки его write impact.
- Исходники, ADR, CI и owner receipts отвечают на разные вопросы. CI с modeled Docker/SQL не доказывает native restore. Не объявлять missing evidence багом.
- Обновлять STATE/ACTIVE/BACKLOG/SESSION_LOG и generated inventories при поставке. Датированные оригинальные отчёты и аудит05/06Oct сохранять.
- `context/current.yaml` и checkpoint генерировать из STATE/BACKLOG; токены и capability inventory — из кода. Server facts принимать только через schema/checksum validation, сохраняя ошибки и происхождение. Snapshot completeness не означает operational health/functional acceptance.
- Уже данное пользователем разрешение действует в пределах задачи. Документы не являются причиной повторно запрашивать подтверждение. GitHub cleanup не разрешает остановку Академии или отправку бэкапа/ключа в Telegram.

## Следующая задача

INF-06 возвращён на доработку запросом18Oct17:03МСК. [Объём](server-console/REVISIONS_20261018.md): HTTPS/client trust и Mini App/VPN diagnosis, темы/zoom, каталог скриптов/документации, реальные request metrics и очищенные события служб. Предыдущий receipt14:42 — история; не считать его текущей полной приёмкой. Native update1c881495 подтверждён terminal receipt18:04; client HTTPS/офисный доступ в обоих VPN-режимах подтверждены владельцем18:38. Отдельная матрица клиентов/фактические новые метрики ещё не присланы; это не доказательство неисправности.

После этой доработки — [INF-07: HTTPS Академии](context/tasks/HTTPS_NEXT_20261018.md), затем INF-02/03. Независимый observer/home access и неподтверждённые native операции отдельно в INF-06-OPS, restore/RTO — OPS-02 waiting_external. Moodle5.2/PG17 отложены.
