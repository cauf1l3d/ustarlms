# USTAR — инструкции агентам

Актуально29.10.2026. Начать с [START_HERE](START_HERE.md), затем текущий домен.
Канонический baseline — main2802; активный кандидат — PR111/app2900/worker5cc02944.
Owner99 preflight отказал до upgrade:2803 cron сравнивался с2802. Исправленный tool9a68246b
прошёл регрессии и actual systemd/UID33/TLS/recovery; архив0860a867 совпал с CI и
скачанной копией. App/worker5cc сохраняют предыдущую FullRC квалификацию.
[Квитанция](context/runtime/connect_role_bot_cron_preflight_fix_20261029.json),
[команды](docs/CONNECT_BOT_2900_INSTALL_RU.md). Production ещё2803/worker6c56;
новый owner preflight, установка и реальная приёмка ролей/Telegram впереди.

## Работа и разрешения

Владелец разрешил push; согласованная интеграция PR107/109 завершена28Oct.
[Основание](context/runtime/perf2802_integration_20261028.json).
Main source app22607c69/local2802; PR110 source2803 ещё не слит. Owner29Oct
установил app ef903b7d/operator36be3f10, local2803/theme2700 и принял RSS:
[receipt](context/runtime/feed2803_owner_deploy_20261029.json). App_ready/DB available,
receiver+notify active/enabled. Последний cron03:37MSK exit0/1.282s без новых ошибок;
текущий adhoc1/overdue56/failed2 не выдавать за разгруженную очередь.
Реальный рабочий день ожидается; напоминание30Oct11:00МСК создано.

Owner29Oct возобновил полный Connect: кнопки/короткие пояснения/визуальные сводки,
все роли через существующую Академию; Mini App и внешний HTTPS в конце.
[Поручение](context/runtime/connect_bot_owner_order_20261029.json).
Самостоятельная реализация, без субагентов, соразмерные проверки, один целый выпуск.
[Квалификация2803](context/runtime/feed2803_qualification_20261029.json) сохраняет
4CI/native357/1706/actualPG/UID33. Архив/старые операторы неизменны.

Новые слияния автоматически не делать. Старые операторы не переписывать.
Субагенты запрещены. Агент не подключается по SSH к production, не отправляет
сообщения/письма/архивы через провайдеров и не меняет сервер в рамках Git-работ.
Production worker6c56 сохраняется до owner установки согласованного9a68246b/worker5cc02944. Docker rotation/health/recreate отложены до
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
