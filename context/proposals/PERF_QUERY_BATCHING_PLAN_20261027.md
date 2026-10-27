# PERF-01: два ограниченных SQL-исправления —27.10.2026

Основание: owner read-only срезы19:40UTC и20:31UTC27Oct; текущий main ad939373.
Все дальнейшие действия — подготовка исходников, не изменение server1.
Второй срез после Studio:314HTTP/300s,p95=200.508ms,4xx=5xx=0; это агрегат Apache
включая assets, не latency каждого пользовательского сценария и не рабочий день.
CPU/RAM/swap/I/O в этом окне не показывают насыщения.19overdue принадлежат сторонним
компонентам; disabled=0 не учитывает component enabled/override/deprecation Moodle.
Их failure/due поля не переписывать. В обоих срезах feed_admin/competition SHA совпали.

## Границы и решения

Владелец разрешил следующий performance блок после получения замеров. Метод работы:
самостоятельно,0субагентов, тест→исправление→проверка. Новое разрешение на измерения
не требуется. Не повторять принятый инфраструктурный цикл и не создавать новый
Docker transition/таймер/инсталлятор. Logging/recreate остаются в Moodle window.
Тесты расширяют существующие suites; отдельный workflow не создавать.

Studio PR107/source2d95137 после owner apply уже имеет local2600, main ещё2300.
Этот PR меняет только прежние три PHP-файла и тесты/контекст; version/schema не
меняются до интеграции с подтверждённым Studio release. Полный архив main2300
не является допустимым обновлением server1. Выпуск потребует нового frozen SHA
после интеграции и полного применимого gate, затем отдельного комплекта владельцу.

## Задачи

1. RSS: тест нескольких sources/статусов/пустого source/постороннего source,
   включая повторяющийся sourceid в группах. Добавить source_stats_batch(array $ids),
   один bound IN query/recordset с закрытием; сохранить source_stats API и все
   прежние нулевые/неизвестные статусы. feed_admin получает всю статистику один раз.
2. Competition: open operator_season уже получает fullname через scoreboard.
   Удалить повторные participant/user lookups только для этого пути; закрытый
   snapshot получает имена одним bound IN query без изменения списка/сортировки/limit100.
   Тестировать одинаковые места, исходный тип строк array/stdClass, пустой список,
   отсутствующего user и denied operator. Не менять participant-facing scoreboard.
3. Проверить синтаксис/source/context/secret и применимые suites. Native Moodle
   PHPUnit обязан подтвердить реальные bound SQL и query-count; локальные fixtures
   не выдавать за native Moodle/production acceptance.
4. Оформить один PR с evidence до/после по SQL-count. Не заявлять процент ускорения
   страниц по одному общему Apache p95 и не публиковать комплект для старой базы.

## Review focus

Ruling: для closed snapshot выбран отдельный batch IN вместо LEFT JOIN: сохраняет
прежнюю форму результата без временных полей fullname и требует всего одного
дополнительного запроса на максимум100строк. Цена: одна round-trip относительно join.

Moodle first-column key не теряет группы source+status; пустой список не делает
полный scan; recordset закрывается; неизвестные статусы сохранены; имена доступны
только после operator guard; join не меняет число/порядок/ties; closed missing user
имеет прежний fallback; Studio-файлы и текущая версия не перезаписываются.

## Выполнение27Oct

Задачи1–2: production PHP+SQLite9cases; до правки7ожидаемых RED, после9GREEN.
Задача3: source checks и полный localr16 1241/44skips PASS; context/secret PASS.
Native Moodle/PG PHPUnit добавлен и пока не выполнен. Это блокер выпуска, не
причина маркировать старый baseline2600 как неизвестный или повторять его деплой.
Final review: авторская отдельная проверка по Review focus,0субагентов по решению
владельца; blocking code findings не выявлены. Неподтверждённые domain-wide
accounts query count, browser latency/SLO и component-enabled19 остаются вне
двух исправлений; их прежние guards и данные не менялись.
