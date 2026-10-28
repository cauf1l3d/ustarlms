# USTAR — инструкции агентам

Актуально29.10.2026. Начать с [START_HERE](START_HERE.md), затем текущий домен.
Канонический код и контекст — main; кандидат PR110 выделен явно.

## Работа и разрешения

Владелец разрешил push; согласованная интеграция PR107/109 завершена28Oct.
[Основание](context/runtime/perf2802_integration_20261028.json).
Main app22607c69/local2802/theme2700. Owner29Oct подтвердил установку/status/cron
и основные browser-сценарии: [terminal](context/runtime/perf2802_owner_deploy_20261029.json),
[browser](context/runtime/perf2802_owner_browser_acceptance_20261029.json).

RSS-01+PERF-01: прежние повторы фото и пассивный рабочий день одним комплектом2803.
App ef903b7d/operator36be3f10, PR110:4CI SUCCESS, PHP1403/234, native357/1706,
actualPG/UID33 PASS. [Квалификация](context/runtime/feed2803_qualification_20261029.json),
[owner команды](docs/FEED_2803_INSTALL_RU.md). Архив совпадает с CI и скачанной
сохранённой копией. Owner apply2803 и реальный8h report ожидаются; кандидат не слит.
SQL/page, Web-SAPI OPcache, concurrency/SLO не измерены. Старые resize-варианты без
идентичности могут оставаться за details; исходные файлы не удаляются.

Новые слияния автоматически не делать. Старые операторы не переписывать.
Субагенты запрещены. Агент не подключается по SSH к production, не отправляет
сообщения/письма/архивы через провайдеров и не меняет сервер в рамках Git-работ.
Worker6c56 отдельный, без rollout. Docker rotation/health/recreate отложены до
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
