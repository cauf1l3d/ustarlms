# USTAR инструкции агентам

**18 октября14:42МСК: пульт и Telegram приняты владельцем, этап INF-06 завершён.** [Owner receipt](context/runtime/console_owner_acceptance_20261018.json). Подтверждение относится к рабочему поставленному пульту; exact installed SHA и отдельная матрица native проверок не присланы. Не продолжать старые install/setup/UI задачи.

Следующий этап — **INF-07: HTTPS самой Академии**, [план подготовки](context/tasks/HTTPS_NEXT_20261018.md). Эксплуатационные остатки сохранены отдельно и не возобновляют закрытый этап. История поставок/диагностики — [SESSION_LOG](context/roadmap/SESSION_LOG.md) и [runtime](context/runtime/README.md); датированные receipts не переписывать. Обновление контекста находится в открытом PR103, слияние в main не выполнено.

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

[INF-07: HTTPS Академии](context/tasks/HTTPS_NEXT_20261018.md). Возобновляется ранее выбранная очередь baseline → patch/CI/ОС → HTTPS после более позднего приоритета пульта. Сначала read-only сверка и план для действующего Apache: внутреннее имя/CA, wwwroot/proxy, redirects/cookies, реальные клиенты и откат. Текущий запрос актуализирует контекст, не запускает переключение сервера.

Пульт/Telegram INF-06 завершены по owner acceptance18Oct14:42МСК. Не повторять setup, установку или UI-доработки без нового запроса. Остатки эксплуатационной приёмки/observer/home access сохранены в INF-06-OPS, restore/RTO — OPS-02 waiting_external. После HTTPS — незавершённые INF-02/03; Moodle5.2/PG17 отложены.
