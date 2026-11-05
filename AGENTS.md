# USTAR — инструкции агентам

Актуально05.11.2026. Начать с [START_HERE](START_HERE.md), затем текущий домен.
Main2900/source5cc02944; PR110/111 слиты.
Worker267e5587 установлен: visuals_active/visuals_ready, recovery_required:false.
[Owner receipt](context/runtime/connect_visual_owner_deploy_20261101.json); PNG/читаемость и задачи/возврат/фото приняты.
Инструкции владельцу — прямо в чат короткими блоками команд.

## Работа и разрешения

Owner1Nov разрешил merge110/111; объединены после FullRC.
[Основание/проверки](context/runtime/connect_feed_integration_order_20261101.json).
App2900/theme2700 и worker267e5587 уже установлены владельцем; merge не deploy.
Задачи/возврат/фото/читаемость приняты. Owner принял прямой SCORM запуск51332a21;
SCORM/Quiz и первичная Библиотека приняты2–3Nov.
[Report](context/runtime/library_owner_navigation_20261103.json). Owner3Nov установил
marketplace4715383b/tool59f: success/exit0/postcheck active, «Отлично работает».
[Receipt](context/runtime/library_marketplace_owner_deploy_20261103.json).
Discovery app719bf47c/toolaa61d595 установлен5Nov: exit0/active.
[Receipt](context/runtime/library_discovery_owner_deploy_20261105.json). ROUTE-EDITOR-01:
Owner подтвердил45e не установлен. STUDIO-CSP-01: native YUI/CSP regression и cumulative
пакет с Route Studio от719bf47c; без unsafe-eval/core changes.
[Реализация](context/reviews/20261105/ROUTE_EDITOR_IMPLEMENTATION.md). Детальные роли и
PERF-01 остаются; HTTPS/Mini App ждут руководства. Старые cron19/failed2 не сбрасывать.

Connect: все роли через существующую Академию; Mini App и HTTPS в конце.
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
- [Аудит](context/reviews/20261103/AUDIT_RU.md): факты и гипотезы.
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

Различать Git source, CI, owner receipt и свежую проверку сервера. Payload не whole-host census; browser-приёмка не доказывает все роли, нагрузку и restore.
Принятые SSD/Moodle5.1.8/OS/reboot/DB-role/code/HTTPS/Connect циклы не повторять.
SSH/firewall — перед внешним доступом. Копии переданы, внешний restore unknown по
решению владельца. ИБП есть, автоматический shutdown не проверен. Остаточные
cron19/failed2 и wrapper20Oct сохранять; не сбрасывать историю ради показателя.

## Проверка и документация

Перед merge — source/context и применимые native/infra CI; полный RC на интеграции
на точном SHA. Обновлять STATE/ACTIVE/BACKLOG и generated inventories. Для owner
архива проверять SHA256, состав/ZIP CRC, byte identity CI и скачанную сохранённую копию.
Лимиты UTF-8: AGENTS6KiB, START_HERE4KiB, ACTIVE4KiB; check_context.py обязателен.
