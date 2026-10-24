# CON-02A.1: личное подтверждение уведомления

План подтверждён владельцем 21.10.2026. В существующем центре уведомлений Академии
реализовано native «Принял». Прочтение, личное подтверждение, provider receipt
и выполнение задачи остаются самостоятельными фактами. На server1 этот срез
не устанавливался; application2000 с принятым overlay и worker/tool a86 сохранены.

## Поведение

Активный сотрудник подтверждает только своё local уведомление в обычной native
сессии: POST/sesskey, local/ustar:use, текущие account/employment и source=local.
Guest, login-as, view-as, unconfirmed/deleted/suspended и неактивная занятость
отклоняются. Ранее показанная кнопка не сохраняет полномочия; привязка Telegram
для native ACK не нужна. Core Moodle и local могут иметь одинаковый ID: ACK
не меняет core timeread и не принимает caller userid/role.

Первое ackat устойчиво к повтору и конкурентным read/ACK. GET, read и mark-all
не создают ACK. После нажатия показаны «Подтверждено» и первое время; read остаётся
«Прочитано», unread count уменьшается. Writer меняет только notification receipt,
не бизнес-задачу, checklist/grade или историю доставки.

Candidate: local_ustar2026102100/theme2026100202, 554files/99tables.
Новых таблиц/колонок, bridge scopes/operations и v1 wire fields нет.

## Проверено

Exact source4fb4cd17dc4fa9a73c659950cfeaa64aa11d4551:
[Context39679569635](https://github.com/cauf1l3d/ustarlms/actions/runs/39679569635) /
[Review39679569515](https://github.com/cauf1l3d/ustarlms/actions/runs/39679569515) SUCCESS.
[Машиночитаемая квитанция](connect_con02_ack_ci_20261021.json) содержит точные jobs,
SHA, артефакты и ограничения.

| Проверка | Результат |
|---|---|
| Actual Moodle5.1.8/PHP8.3.33/PostgreSQL16.15 | 138tests/685assertions PASS; writer2tests/6assertions отдельно |
| PostgreSQL controlled barrier | 4cases: frozen baseline теряет первый receipt, candidate сохраняет |
| Existing independent concurrency | 6scenarios PASS |
| Native2000→2100/repeat | History/task/delivery/preferences/link/schema/scope сохраняются |
| Native HTTP | Real login, GET no ACK, POST/CSRF/source/recipient/repeat PASS |
| Paired recovery | Actual DB+source+config+moodledata2000 restore PASS; старые restores сохранены |
| Frozen a86 | Actual PHP v1 lease/begin/finish packets + frozen parser PASS; provider synthetic |
| PHP8.2/8.3 | 941r16/152environment skips каждый job |
| Local exact-deps | 941r16/35environment skips; 4real-PHP SQL interleaving cases PASS |
| Chromium | ACK5widths320/390/768/1366/1920; existing60mobile/20onboarding/28link/100actor PASS каждый job |
| Actual systemd/UID33/TLS | PASS; notification pilot7tests/0skips |
| Full-RC historical Moodle/rollback/prepare/gate | Skipped по существующему label condition, не новый PASS |

Local PHP+SQLite воспроизводит stale-write настоящего PHP writer, но не является
native Moodle/PG. Browser использует actual Mustache/SCSS с synthetic transport;
реальный HTTP/session/CSRF проверен отдельным native fixture. Frozen worker
получает native packets с synthetic provider; live Telegram traffic не выполнялся.
Source ZIP артефакта12383672236 скачан и SHA256 сверен; финальные320/1366 screenshots
просмотрены автором. Native evidence подтверждено job logs и GitHub metadata;
скачивание native ZIP зависло при отключении рабочего окружения, его локальная
сверка хеша не заявляется.

## Решения и исправления fixtures

1. Сохранена void-сигнатура canonical writer. Stale whole-record update заменён
   условным SQL UPDATE с COALESCE первого ackat; read обновляет только unread.
   Две гонки воспроизведены до исправления, затем проверены на PostgreSQL.
2. Existing owned_links() допускает pending и не требует local/ustar:use.
   Новый adapter добавляет ACTIVE/capability, не требует linked Telegram и
   проверяет actor при каждом POST. available() — только read-only hint.
3. handle(method,input) следует existing link-management boundary; новый flow
   не опубликован как private provider bridge/actor_read API.
4. PostgreSQL barrier — отдельный con02_ack_concurrency.php. Trigger/clock hooks
   только тестовые. Moodle execute() отвергает внутренние semicolons PL/pgSQL:
   fixture DDL выполняет отдельное guarded native PG connection; writers — Moodle DB.
5. Старые source-bound helper guards не ослаблены. Положительные builds/fixtures
   используют exact frozen38d81ef8; старый installer отвергает новый application.
   Эти артефакты не являются installer для2100.
6. На скриншотах исправлены ошибки synthetic browser model: отсутствие явного
   unread=false наследовало root count; статический header count не исчезал после
   ACK. Обе проверки дали RED→GREEN, actual API не требовал изменения. Native тест
   отдельно проверяет реальный unread count и отзыв ранее показанного разрешения.
7. В disposable PHP CI image добавлен Python stdlib для frozen parser/native HTTP;
   предыдущий прогон прошёл138native tests/upgrade, затем остановился без python3.
   Это дефект комплектации fixture, не продуктовый RED.
8. Generated index однажды включил untracked report draft; draft перемещён вне
   публикуемого context и индекс пересобран. После завершения source CI рабочее
   окружение отключилось. Итоговые docs опубликованы через GitHub API; retrieval
   хеши проверены по исходному index, новый index пересчитан, CI проверяет генератор.

## Review, production и продолжение

Отдельный author self-review диапазона approved38d81ef8→4fb4cd17 выполнен:
actor/source/CSRF, stale presentation, SQL races, task/delivery preservation,
version/schema/frozen compatibility. Независимого reviewer не было по запрету
субагентов; это более слабая гарантия, чем независимое code review.0subagents,
main unmerged, agent SSH/provider sends отсутствуют.

На server1 application2000, принятый overlay одного файла notification_delivery.php
и worker/tool a86 сохранены. Принятый one-user Tasks pilot не повторяется; UID130
последний раз revoked. Нового guarded2100 production installer в этом плане нет.
Production release потребует отдельного согласованного cron target и handoff
HTTPS ownership/renewal, а не запуска старого source-bound installer.

Далее отдельный A.2 Telegram callback с versioned opt-in в одном receiver;
затем B routing/preferences и C Bitrix после проверки фактического portal/API.
Group1–10 controller остаётся design-only. Ручные live group tests deferred до
полного запуска по владельцу, не passed. MiniApp/SSO/role actions не закрыты.
