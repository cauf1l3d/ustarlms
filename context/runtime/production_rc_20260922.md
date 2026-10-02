# USTAR production RC runbook — 2026-09-22

Цель: единый выпуск кандидата этапов 1–6 с минимальным риском для рабочей Академии.
Production используется только для финального smoke/role acceptance после полного backup.
Разрушающие миграционные эксперименты, downgrade схемы и rollback-drill на production запрещены.

## 1. Release gate до production

В production допускается только один immutable SHA кандидата, для которого одновременно выполнено:

- единый PR #14 принят по реестру `context/roadmap/RC_AUDIT_ACCEPTANCE_20260924.md`;
- на одном и том же SHA завершились успешно source, frontend, rollback, prepare-rc, moodle-db и gate;
- сохранены CI source archive и release-manifest.json;
- production_manifest.py --require-match не обнаруживает drift относительно подтверждённого baseline;
- известен и проверен путь полного восстановления code + DB + moodledata одной согласованной точки;
- для SCORM Studio подтверждены создание Moodle activity, запуск, попытка, результат и завершение маршрута в DB и браузере;
- в браузере проверены создание Moodle Quiz через материалы, публикация, отправка попытки и результат;
- production-only файлы `.php.before_user_selector_fix` и `boards.*` разобраны отдельно: файл резервной копии удалён из публичного корня, судьба старого Boards кода подтверждена перед заменой.

Любое несовпадение SHA, manifest/hash, DB version или неизвестный drift = STOP.

До появления workflow в `main` полный gate запускается на PR №14 событием
назначения метки `full-rc`. Это однократный запрос на текущем head SHA: после
любого нового коммита метку нужно снять и назначить повторно, затем заново
проверить SHA и все пять зависимых задач. Зелёный обычный PR run содержит
только source/frontend и не даёт допуска к выпуску.

## 2. Подготовка окна

Перед изменением production:

1. Зафиксировать текущий commit/source snapshot и plugin version.
2. Включить maintenance mode.
3. Остановить cron/workers и любые интеграции, способные писать в Moodle во время upgrade.
4. Снять согласованный backup:
   - PostgreSQL;
   - moodledata;
   - текущий application code;
   - конфигурация/reverse-proxy только если она меняется релизом.
5. Проверить, что backup читается и его местоположение известно оператору.
6. Запустить release manifest preflight. При drift не применять RC.

Исторический Docker layout USTAR (проверить по месту до выполнения команд):

- application container: ustar_moodle;
- host code root: /opt/ustar/data/moodle/public;
- Moodle public dir in container: /var/www/html/public;
- Moodle CLI: определить из действующего монтирования, не полагаться на этот документ как на источник текущего пути.

## 3. Применение кандидата

Применять только файлы, перечисленные release manifest, в пределах:

- local/ustar;
- theme/ustar.

Не выполнять произвольный rsync --delete за пределами release manifest.
Не изменять Moodle core.

После применения точного source archive:

Сначала определить `$MOODLE_CLI_ROOT` внутри контейнера по наличию
`admin/cli/upgrade.php` и убедиться, что путь указывает на действующий
production-код. Затем выполнять команды от пользователя веб-сервера:

```bash
sudo docker exec -u www-data ustar_moodle php "$MOODLE_CLI_ROOT/admin/cli/upgrade.php" --non-interactive
sudo docker exec -u www-data ustar_moodle php "$MOODLE_CLI_ROOT/admin/cli/purge_caches.php"
```

Если build_theme_css.php отсутствует в данной версии Moodle, это не повод менять core:
использовать только существующий штатный/проектный способ сборки темы.

После upgrade повторно выполнить manifest/hash verification.
Не запускать старый код поверх уже обновлённой схемы.

## 4. Production smoke — обязательный порядок

Проверки выполнять в указанном порядке. После первого критического дефекта дальнейшую приёмку остановить.

### A. Технический smoke

- login page открывается без PHP notice/warning;
- dashboard и /local/ustar/route.php возвращают 200;
- cron/CLI не показывает schema mismatch;
- plugin version соответствует immutable RC;
- нет новых exception/error в Moodle/PHP logs после входа тестовых ролей.

### B. Сотрудник

На одном контролируемом тестовом сотруднике:

1. Открыть маршрут.
2. Убедиться, что старые completed points не потеряны.
3. Открыть обычный material/content step и вернуться в маршрут.
4. Открыть существующий Moodle SCORM через USTAR launcher.
5. Проверить resume и завершение существующего Moodle SCORM.
6. Пройти Studio Assessment неправильным ответом:
   - route point не закрывается;
   - reward/Evidence не создаются.
7. Пройти текущую source-version Studio Assessment успешно:
   - point закрывается после reconciliation маршрута;
   - создаётся ровно один completion_cycle;
   - повторная отправка тех же ответов не создаёт второй completion/reward;
   - Evidence и USCOIN/XP создаются только по действующей reward policy.
8. Если автор меняет source-version assessment, старый PASS не должен закрывать новую версию автоматически.

Отдельно проверить созданный в Studio SCORM ZIP: запуск Moodle activity, повторное
открытие, оценку и закрытие route point. До подтверждения этого сценария на
изолированной БД не использовать новый Studio SCORM как обязательный шаг.
Через конструктор Moodle Quiz проверить отправку попытки и итоговую оценку;
исторический Studio Assessment проверять отдельно, поскольку это другой runtime.

### C. Руководитель

- «Моя команда» показывает только разрешённый scope;
- сотрудник подразделения доступен, чужой scope недоступен;
- ручное переобучение создаётся только для разрешённого сотрудника;
- первая заявка на переход грейда появляется после выполнения опубликованного rule;
- approve доступен только фактическому текущему руководителю;
- повторное решение той же заявки блокируется.

### D. HR / HRD

- HR Workspace открывается без warning;
- создание/редактирование каталога работает;
- ошибка upload не оставляет частично сохранённую карточку;
- приватная заметка другого пользователя недоступна;
- task due date/result/comments сохраняются;
- HRD может назначать разрешённые поручения/переобучение;
- grade rules показывают отдельные правила каждого перехода.

### E. Генеральный директор / executive

- executive dashboard открывается;
- видимость компании соответствует executive scope;
- операции, которые должны быть read-only, не дают скрытой записи.

### F. Архив досок

- активный старый boards service не используется;
- владелец видит собственный archive;
- чужой archive недоступен;
- контрольная сумма архивной записи не меняется от просмотра.

## 5. Read-only data verification после smoke

Сравнить с pre-deploy snapshot как минимум:

- users/employment/assignments;
- routes/points/versions;
- route_progress и completion_cycle;
- assessment runtime/attempt history;
- Evidence;
- Economy ledger;
- forced retraining assignments/events;
- grade rules/requests/current grades;
- catalog/material records and File API references;
- board archive counts/checksums.

Допустимы только ожидаемые новые записи от заранее выбранных smoke-пользователей.
Не использовать реальные прохождения сотрудников для destructive тестов.

## 6. Release acceptance

RC считается принятым на production только если:

- CI SHA = deployed SHA;
- manifest после deploy совпадает;
- Moodle upgrade завершён без warning/error;
- все критические smoke A–F пройдены;
- новые completion/reward идемпотентны;
- существующая история обучения не изменилась вне smoke-пользователей;
- role/scope проверки не обнаружили расширения доступа;
- operator подтверждает наличие рабочего backup до снятия maintenance mode.

После этого:

1. включить cron/workers;
2. снять maintenance mode;
3. повторить короткий login/route smoke;
4. зафиксировать deployed SHA, DB/plugin version, время и backup id в release evidence.

## 7. Немедленный rollback

Rollback требуется при любом из условий:

- schema/upgrade error;
- потеря/массовое изменение progress или Evidence;
- двойные reward/completion;
- нарушение scope/permissions;
- login/route недоступен;
- file references повреждены;
- manifest mismatch после применения.

Rollback = восстановление одной согласованной точки:

1. maintenance mode остаётся включён;
2. остановить writers;
3. восстановить code snapshot;
4. восстановить PostgreSQL backup;
5. восстановить moodledata backup;
6. очистить caches;
7. проверить версии и hashes;
8. выполнить smoke старой версии;
9. только затем открыть систему.

Запрещено пытаться откатить Moodle upgrade уменьшением version.php или запускать старый код на новой схеме.
