# USTAR — инструкции агентам

Актуально29.10.2026. Начать с [START_HERE](START_HERE.md), затем читать только
документы текущего домена. Канонический код и контекст — main.

## Текущая задача и разрешения

Владелец разрешил push и28Oct согласился на интеграцию и серверный комплект.
[Квитанция](context/runtime/perf2802_integration_20261028.json). PR107/109 слиты:84a078ef/01904eda.
Main: app22607c69/local2802/theme2700; worker6c56 сохраняется отдельно.
Оператор3c1ddd09 прошёл все четыре CI: PHP1363/232, native354/1695, actualPG/UID33 PASS.
[Квалификация/архив](context/runtime/perf2802_qualification_20261028.json),
[команды владельцу](docs/PERF_2802_INSTALL_RU.md). Архив и скачанная сохранённая копия сверены.
Владелец29Oct прислал успешные check2801, upgrade2802, status и scheduled cron.
[Квитанция production2802](context/runtime/perf2802_owner_deploy_20261029.json):
app_ready/DB available, local2802/theme2700, обе Connect-службы active/enabled.
Cron exit0/34.311s, новые ошибки отсутствуют; overdue63→19, failed2, adhoc1→0.
Текущая работа PERF-01: отдельная browser/workday/concurrency/SLO acceptance.
Агент сервер не менял;590файлов/100таблиц — payload оператора, не новая инвентаризация хоста.
Старые операторы не переписывать. Новые слияния вне этого этапа автоматически не делать.
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
Prod payload22607c69/local2802/theme2700: owner29Oct app_ready;
[квитанция](context/runtime/perf2802_owner_deploy_20261029.json). Worker6c56 не обновлялся.
Post-deploy и базовый импорт: [квитанция](context/runtime/course_studio_post_deploy_owner_20261028.json).
Отдельные PDF/conflict/employee сценарии не заявлены; это не свежий manifest всего хоста.
Принятые SSD/Moodle5.1.8/OS/reboot/DB-role/code/HTTPS/Connect проверки не повторять.
SSH/firewall — только при подготовке внешнего доступа. Копии переданы; внешний restore
остаётся unknown по решению владельца. Наличие ИБП не доказывает автоматический shutdown.

## Проверка и документация

Перед merge — source/context и применимые native/infra CI; полный RC на интеграции.
После изменения обновлять STATE/ACTIVE/BACKLOG и generated inventories. Историю
хранить в dated receipts/archive, не наращивать журнал в каждом входном документе.
Лимиты UTF-8: AGENTS6KiB, START_HERE4KiB, ACTIVE4KiB; проверяет check_context.py.
Для файла владельцу проверять SHA256, ZIP CRC/состав и скачанную сохранённую копию.
