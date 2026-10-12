# Ручная приёмка обновлённого стенда5.1.8

[Owner re-upgrade/repeat PASS](lab_patch_reupgrade_result_20261012.json) завершился
12Oct10:40:14UTC/13:40:14MSK,1109s/18min29s. Active stage core2025100608.00,
login200/4910 file contenthashes, tasks0/CLI mail blocked/loopback-only; source/
production identities и stage IDs/bind-root inodes прежние. Новый runxdgki_yo,
fresh paired baseline manifestd15a5002. Предыдущий [real paired rollback](lab_patch_restore_result_20261012.json)
runfylkhzxl/manifest156e4688 принят, same helper59b88e1 и candidate07dfa485 не
меняются. Новый report rollback=false относится к новой transaction; следующий
этап — authenticated QA, не повтор копирования/upgrade/restore.

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

## Первый короткий проход

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

## Следующий проход

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
без переписывания index.php. Это static support, не authenticated runtime PASS.

Source CLI/platform/file hashes/upgrade/repeat/paired restore proof уже принят в
своих областях. Application/helper bytes неизменны; exact59b88e1 Context58/source
PHP8.2/8.3 SUCCESS, frontend audit FAILURE/full gate не PASS, PR99 draft/unmerged.
Manual home/roles/SCORM/full-addon/Apache acceptance и production patch остаются
открыты; OS/required services/reboot/HTTPS/independent HDD recovery — следующие
согласованные этапы. Для ошибки доступа после expiry сначала bounded relay/status
diagnostic; не повторять core upgrade/restore ради открытия браузера.
