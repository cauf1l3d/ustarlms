# USTAR — инструкции агентам

Актуально26.10.2026. Начать с [START_HERE](START_HERE.md), затем читать только
документы текущего домена. Канонический код и контекст — main.

## Текущая задача и разрешения

Владелец разрешил push и слияние проверенных PR105/103/106 для актуализации main.
PR107 Studio27Oct установлен владельцем (payload2d95137/local2600), ручная UI
приёмка ведётся отдельно. Main2300/PERF candidate не выдавать за полный prod update.
Ротация логов, healthcheck и пересоздание контейнеров отложены до планового обновления
Moodle/PHP. Эксперимент сохранён в infra/docker-successor-frozen и не поставлен.
Субагенты запрещены. Агент не подключается по SSH к production, не отправляет
сообщения/письма/архивы через провайдеров и не меняет сервер в рамках Git-работ.

## Источники истины

- [STATE](context/roadmap/STATE.yaml): текущие версии, точные SHA и ссылки на квитанции.
- [ACTIVE](context/tasks/ACTIVE.md): работа сейчас; [BACKLOG](context/roadmap/BACKLOG.yaml): задачи.
- [Проверка ревью](context/reviews/20261026/VERIFIED_REVIEW_RU.md): факты и гипотезы.
- [Принятые риски](context/roadmap/ACCEPTED_RISKS.yaml): владелец, дата и пересмотр.
- [ADR](context/decisions/README.md): принятые архитектурные решения.
- [Архив контекста](context/archive/reconciliation_20261026): прежние входные документы.

## Границы

Рабочий UI — moodle/local/ustar и moodle/theme/ustar (родитель boost).
frontend — legacy, не включать в релизы или активный gate. Connect worker — канал
существующей Академии; server-console — отдельная ops-авторизация, не Academy login.
Не создавать вторую модель сотрудников, сообщений, evidence, балансов или прогресса.

Любая запись проверяет реального actor, capability, scope, sesskey/контракт, revision,
блокировку и идемпотентность. View-as не даёт записи. XMLDB install/upgrade parity
обязательна. Исторические миграции не переписывать ради форматирования.
Не публиковать config.php, токены, дампы, moodledata, private logs и ключи.

Различать Git source, CI, квитанцию владельца и свежую проверку сервера.
Prod payload6c56a5b6/local2300 подтверждён владельцем; это не свежий manifest всего хоста.
Принятые SSD/Moodle5.1.8/OS/reboot/DB-role/code/HTTPS/Connect проверки не повторять.
SSH/firewall — только при подготовке внешнего доступа. Копии переданы; внешний restore
остаётся unknown по решению владельца. Наличие ИБП не доказывает автоматический shutdown.

## Проверка и документация

Перед merge — source/context и применимые native/infra CI; полный RC на интеграции.
После изменения обновлять STATE/ACTIVE/BACKLOG и generated inventories. Историю
хранить в dated receipts/archive, не наращивать журнал в каждом входном документе.
Лимиты UTF-8: AGENTS6KiB, START_HERE4KiB, ACTIVE4KiB; проверяет check_context.py.
Для файла владельцу проверять SHA256, ZIP CRC/состав и скачанную сохранённую копию.
