# USTAR — инструкции агентам

Актуально01.11.2026. Начать с [START_HERE](START_HERE.md), затем текущий домен.
Канонический main — app2900/source5cc02944; PR110/111 слиты. Owner29Oct установил app2900/theme2700 через9a:
[receipt](context/runtime/connect_role_bot_owner_deploy_20261029.json).
Owner1Nov восстановил egress (CONNECT200/HTTP302/TLS0), оба бота отвечают.
Worker267e5587 установлен: visuals_active/visuals_ready, recovery_required:false.
[Owner receipt](context/runtime/connect_visual_owner_deploy_20261101.json); PNG/читаемость и задачи/возврат/фото приняты.
Инструкции владельцу — прямо в чат короткими блоками команд.
[Квалификация](context/runtime/connect_role_bot_cron_preflight_fix_20261029.json),
[команды](docs/CONNECT_BOT_2900_INSTALL_RU.md).

## Работа и разрешения

Owner1Nov разрешил merge110/111; объединены после FullRC.
[Основание/проверки](context/runtime/connect_feed_integration_order_20261101.json).
App2900/theme2700 и worker267e5587 уже установлены владельцем; merge не deploy.
Задачи/возврат/фото/читаемость приняты. Owner принял прямой SCORM запуск51332a21;
точного apply receipt нет. LEARN-SCORM-02 утверждён; реализация/проверки в ACTIVE.
После него PERF-01: пассивный рабочий день. Остальные процессы с руководством
с2Nov; HTTPS/Mini App ждут согласования. Старые cron19/failed2 не сбрасывать.

Owner29Oct возобновил полный Connect: кнопки/короткие пояснения/визуальные сводки,
все роли через существующую Академию; Mini App и внешний HTTPS в конце.
[Поручение](context/runtime/connect_bot_owner_order_20261029.json).
Самостоятельная реализация, без субагентов, соразмерные проверки, один целый выпуск.
[Квалификация2803](context/runtime/feed2803_qualification_20261029.json) сохраняет
4CI/native357/1706/actualPG/UID33. Архив/старые операторы неизменны.

Разрешённое объединение110/111 завершено; новые слияния без поручения не делать.
Старые операторы неизменны.
Субагенты запрещены. Агент не подключается по SSH к production, не отправляет
сообщения/письма/архивы через провайдеров и не меняет сервер в рамках Git-работ.
Docker rotation/health/recreate отложены до
планового Moodle/PHP upgrade; эксперимент infra/docker-successor-frozen не поставлен.

## Источники истины

- [STATE](context/roadmap/STATE.yaml): версии/SHA/квитанции и степень уверенности.
- [ACTIVE](context/tasks/ACTIVE.md), [BACKLOG](context/roadmap/BACKLOG.yaml): текущая работа.
- [Ревью](context/reviews/20261026/VERIFIED_REVIEW_RU.md): факты и гипотезы.
- [Риски](context/roadmap/ACCEPTED_RISKS.yaml), [ADR](context/decisions/README.md).
- Датированные runtime receipts и archive сохраняют историю; входные документы краткие.

## Границы

Рабочий UI — moodle/local/ustar и moodle/theme/ustar, родитель boost.
frontend — legacy, вне релиза/активного gate. Connect — канал той же Академии;
server-console имеет отдельную ops-авторизацию, не Academy login.
Не создавать вторую модель сотрудников, сообщений, evidence, балансов или прогресса.
Запись проверяет реального actor, capability, scope, sesskey/контракт, revision,
блокировку и идемпотентность. View-as не даёт записи. Install/upgrade XMLDB parity
обязательна; исторические миграции не переписывать ради форматирования.
Не публиковать config.php, токены, дампы, moodledata, private logs и ключи.

Различать Git source, CI, owner receipt и свежую проверку сервера. Payload590/100
не является whole-host manifest. Общая browser-приёмка не доказывает все роли,
PDF/conflict/employee edge cases, производительность или полное восстановление.
Принятые SSD/Moodle5.1.8/OS/reboot/DB-role/code/HTTPS/Connect циклы не повторять.
SSH/firewall — перед внешним доступом. Копии переданы, внешний restore unknown по
решению владельца. ИБП есть, автоматический shutdown не проверен. Остаточные
cron19/failed2 и wrapper20Oct сохранять; не сбрасывать историю ради показателя.

## Проверка и документация

Перед merge — source/context и применимые native/infra CI; полный RC на интеграции
на точном SHA. Обновлять STATE/ACTIVE/BACKLOG и generated inventories. Для owner
архива проверять SHA256, состав/ZIP CRC, byte identity CI и скачанную сохранённую копию.
Лимиты UTF-8: AGENTS6KiB, START_HERE4KiB, ACTIVE4KiB; check_context.py обязателен.
