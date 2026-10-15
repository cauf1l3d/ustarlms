## Apache/PHP accepted12Oct16:34MSK

[Owner receipt](lab_patch_apache_result_20261012.json) confirms working upgraded-stage containers after relay expiry, all Apache PHP settings,zero tasks and unchanged code/container identities. Next dependency/full CI and relevant addons; no repeated core operations or full-addon PASS inferred.

## Обновление по ответу владельца12Oct15:50МСК

Владелец подтвердил предыдущие HR read checks фразой «все работало».
Принимаются список/карточка, материал/документ и запрошенный просмотр аттестаций
в owner-reported scope; новые записи и выставление оценок не проверены.
Доступ закончился по сообщению владельца; состояние контейнеров не измерено.
Далее Apache/PHP inspection без relay/upgrade. Full addon/CI/production pending.

# Ручная приёмка обновлённого стенда5.1.8

[Owner re-upgrade/repeat PASS](lab_patch_reupgrade_result_20261012.json) завершился
12Oct10:40:14UTC/13:40:14MSK,1109s/18min29s. Active stage core2025100608.00,
login200/4910 file contenthashes, tasks0/CLI mail blocked/loopback-only; source/
production identities и stage IDs/bind-root inodes прежние. Новый runxdgki_yo,
fresh paired baseline manifestd15a5002. Предыдущий [real paired rollback](lab_patch_restore_result_20261012.json)
runfylkhzxl/manifest156e4688 принят, same helper59b88e1 и candidate07dfa485 не
меняются. Новый report rollback=false относится к новой transaction; следующий
этап — authenticated QA, не повтор копирования/upgrade/restore.

## Принятые результаты и текущий проход12Oct15:25MSK

[Receipt](lab_patch_browser_result_20261012.json) сохраняет screenshots14:10 и exact
owner ответы14:41/15:25. Basic admin SCORM navigation/progress/logout-login/home
приняты14:41. «Всё работает»15:25 отвечает на preceding checks отдельных employee/
manager accounts: свои learning/tasks, non-HR direct HR page denial, manager team
и обучение. Этот smoke принят по owner report; account IDs/new screenshots/logs
не присланы. Full HR/write/cross-scope/full-addon acceptance не выводится.
Passed checks не повторять без новой ошибки; original screenshots не публикуются.

Следующий проход на **http://127.0.0.1:18085/** под существующей реальной HR учёткой:

| Проверка | Ожидаемое поведение | Что записать |
|---|---|---|
| `/local/ustar/hr.php` | Загружается список сотрудников и выбранная карточка | Список/card PASS или точное действие/ошибка |
| `/local/ustar/materials.php` | Библиотека и существующий материал загружаются; document/file открывается или скачивается штатно | Отдельно library/detail/file PASS; если документа нет — NOT_TESTED |
| `/local/ustar/hr_quiz_grading.php`, если actual HR grading capability есть | Открывается список проверок и существующая попытка для просмотра | Queue/view PASS; пустой список не доказывает attempt view или выставление оценки |

Контроллеры hr.php/materials.php требуют system HR capability; grading требует
active actor + gradeassessments/hrmanage (`classes/hr_access.php`). Обычная HR роль
не равна HRD или site admin; не расширять права ради обхода expected denial. Нет
полученных evidence о новых writes/grades/completion. Для этого короткого прохода
достаточно existing records/file/view; персональные списки/пароли не передавать.
Новая ошибка после patch отделяется от существующего product issue.

После HR/file/assessment smoke — bounded Apache/PHP settings/isolation proof и
relevant preserved-addon runtime.19 roots/57 metadata сохранены, CLI upgrade PASS
не доказывает работу всех сторонних addons. Есть AI/external integration roots;
при offline namespace недоступность external provider не означает потерю данных
или permission bug. Не включать внешнюю сеть/mail/cron для прохода без определения
конкретного проверяемого контракта. Источник inventory — pinned stage profile.
Frontend audit FAILURE/full gate not PASS сохраняются отдельным CI условием до
production release; не обходить thresholds. Ни одна эта doc publication не
обновляет production core. Stage upgrade/repeat/prior paired restore приняты.

Если relay истёк, inspect/resume only relay/status; core operations не повторять
ради browser доступа. По unchanged7200s policy ориентир15:40MSK approximate,
actual unit deadline не запрашивался агентом. История первых admin checks ниже.

## Доступ с Windows ПК

URL стенда: **http://127.0.0.1:18085/** в браузере на Windows через SSH local
forwarding к `aduk@192.168.1.60`. Server relay привязан к host loopback18085,
контейнеры остаются в offline shared namespace. Уже работающий tunnel18085 из
предыдущей browser проверки можно использовать. Его actual current state на ПК
агент не видит. Закрытие SSH session закрывает её forwarding; server relay имеет
отдельный7200s runtime. По времени latest helper ориентир доступа до15:40MSK;
точное истечение/ActiveState сейчас не запрашивалось на сервере.

В PuTTY для session192.168.1.60:

| Настройка | Значение |
|---|---|
| Panel | Connection → SSH → Tunnels |
| Source port | `127.0.0.1:18085` |
| Destination | `127.0.0.1:18085` |
| Forwarding type | Local |

Добавить mapping через Add и открыть/сохранить эту SSH session. Если это уже
existing configured session, достаточно сохранить её открытой. Не создавать
второй listener на занятый local18085. Официальный [PuTTY port-forwarding manual](https://the.earth.li/~sgtatham/putty/latest/htmldoc/Chapter3.html#using-port-forwarding)
описывает эти source/destination/Local/Add параметры и lifetime session.

## История первого admin прохода — выполнен по owner report14:41

1. Открыть приватное окно браузера на Windows и http://127.0.0.1:18085/.
   Выполнить свежий login существующего администратора в копии академии.
   Пароли/одноразовые коды не передаются агенту и не публикуются.
2. Зафиксировать фактическую страницу и URL после входа. Затем перейти на корень
   http://127.0.0.1:18085/ и проверить автоматический переход на USTAR home.
   Прямой адрес для отдельной проверки: http://127.0.0.1:18085/local/ustar/home.php.
   Главная/меню/стили/изображения должны загрузиться; actual redirect/login не
   объявляются PASS только по stock-code/config metadata.
3. Открыть уже существующий SCORM через обычный интерфейс клона. Проверить текст,
   изображения, переключение раздела, сохранение прогресса после refresh и
   повторного входа. Completion проверять отдельным действием на клоне, без
   сброса реального production обучения. Предыдущий11Oct screenshot со SCORM
   относится к original-core stage, не к сегодняшнему5.1.8.
4. Сохранить краткий owner результат для каждого шага: PASS/ошибка, URL и действия
   воспроизведения; screenshot при необходимости читается приватно, публичный
   receipt обезличивается. Fresh login, home redirect/render и SCORM persistence
   фиксировать по отдельности. Отсутствие visible ошибки не закрывает все роли.

## Исторический полный перечень ролей и дополнений

После первого результата проверить existing реальные HR/manager/employee accounts,
разрешённые/запрещённые действия по их capabilities/current scope, материалы/файлы,
маршруты/задачи/лента/чаты/аттестации и runtime всех сохранённых19 addon roots/57
metadata. View-as не считается проверкой отдельного real actor. Никакие production
роли или маршруты не меняются ради QA. Тестовые записи идут только в clone18085.
Новый bug после patch отделять от ранее открытых product задач. Full addon
compatibility нельзя вывести из Composer metadata или успешного CLI upgrade.

## Что уже проверено статически

Официальный пакет5.1.8 SHA0e8e2cdeecf24512b75e24158fa224ed08a20c608b4e1065cb2068832467665c
повторно совпал. В `public/lib/moodlelib.php` функции `get_home_page()` и
`get_default_home_page_url()` поддерживают relative URL в `$CFG->defaulthomepage`,
используют PARAM_LOCALURL/HOMEPAGE_URL; `public/index.php` перенаправляет на него.
Поэтому candidate config path `/local/ustar/home.php` имеет stock-core поддержку
без переписывания index.php. Static support проверен по package source; actual home transition позднее принят
по owner report14:41 без exact URL/independent browser trace.

Source CLI/platform/file hashes/upgrade/repeat/paired restore proof уже принят в
своих областях. Application/helper bytes неизменны; exact59b88e1 Context58/source
PHP8.2/8.3 SUCCESS, frontend audit FAILURE/full gate не PASS, PR99 draft/unmerged.
Basic admin browser SCORM/progress/login/home accepted in owner-reported scope;
Employee/manager requested smoke accepted15:25; HR/completion/grades/full-addon/
Apache и production patch остаются открыты.
OS/required services/reboot/HTTPS/independent HDD recovery — следующие
согласованные этапы. Для ошибки доступа после expiry сначала bounded relay/status
diagnostic; не повторять core upgrade/restore ради открытия браузера.
