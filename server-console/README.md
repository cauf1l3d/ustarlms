# USTAR Server Console

Следующая задача INF-06 после консолидации GitHub: пульт на том же server1 с логином/паролем, интерфейсом в стиле Linear и интеграцией Telegram. Текущие требования — [пульт и Telegram](../context/runtime/control_panel_telegram_20261016.md), очередь — [ACTIVE](../context/tasks/ACTIVE.md).

## Что уже есть

`prototype/index.html` — автономный интерактивный прототип с демонстрационными данными: обзор, сервер, Академия, стенды/адреса/активность, бэкапы, SFTP, алерты, операции и форма входа. Открывается локально без сборки, CDN и внешних запросов. Поля входа отключены; диалоги показывают план, ничего не выполняют. HTML сохранён без изменений из предыдущего прототипа PR103.

Это отдельный operations UI. Рабочий UI Академии остаётся в `moodle/local/ustar` и `moodle/theme/ustar`; `frontend/` — legacy вне текущего CI и выпуска.

## Что реализуем дальше

1. Защищённый endpoint, серверные сессии/авторизация и read-only collector/API с реальными метриками, временем измерения, ошибками и NoData.
2. Подключение UI, Telegram failure/recovery/NoData alerts и status/metrics для разрешённых операторов; внешний observer для потери самого server1.
3. Выдача последнего completed/hash-verified зашифрованного Academy-бэкапа через авторизованную ссылку с датой snapshot, размером и SHA. Recovery key и transfer kit с ключом не передаются в Telegram.
4. Ограниченные job adapters для manual backup, стендов/TTL, Academy maintenance/restart и подготовки restore, с планом последствий, блокировками и журналом.
5. Реальная приёмка авторизации, доставки, метрик, download/hash и операций на сервере.

Backend, auth, collectors, bot и доставка ещё не реализованы. Прямого подключения агента к server1 нет. Прототип не подключать к root/Docker socket и не публиковать как защищённую админку.

## Инструкции и проверка

Реальная инструкция текущей передачи сисадмину — [production copy](../context/runtime/production_copy_20261015.md). Владелец16Oct подтвердил получение архива и передачу инструкции; clean-host restore ожидается. Старый recovery-раздел прототипа — иллюстрация интерфейса15Oct, не актуальная инструкция выполнения переноса. Moodle5.2/PostgreSQL17 отложены.

Историческая проверка15Oct: JS syntax и jsdom navigation/filter/empty/backup-impact/restore-target checks PASS; dialog API моделировался. Chromium отсутствовал, загрузка не удалась: реальные desktop/mobile screenshots, keyboard/layout и visual QA остаются PENDING. При синхронизации с обновлённым main проверены неизменность HTML и diff только этих двух файлов; UI/production acceptance этим не заявляется.
