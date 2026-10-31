# Connect: восстановление Telegram и successor графики

Поручение владельца1Nov: сначала помочь восстановить оба Telegram-бота по свежим
логам, затем проверить внешний renderer, перенести полезные изменения в PR111,
выдать один проверенный архив и команды. Самостоятельно, без субагентов/SSH/merge.

Наблюдение31Oct20:51Z: четыре службы active; CONNECT200, затем reset/HTTP000;
receiver transport_unavailable, console warning/no_data. Общая egress-цепь сломана;
конкретный удалённый hop не установлен. Перезапуск и существующий pinned refresh
выполняет владелец. Свежая приёмка после восстановления ещё нужна.

Рабочий pointer5cc; старый visual journal cb7128/rolled_back. Старый5646 installer
откажет visual_journal_identity: это воспроизведённое ограничение перехода.

Выбор реализации: новый worker-only successor с отдельным журналом на commit,
проверяющий и сохраняющий старый terminal journal без изменения. Удаление/подмена
старого journal недопустимы. Активный/незавершённый старый visual сначала требует
его штатного recover. Frozen9a и visual-v1 остаются побайтно прежними.
Перед upgrade — readonly role probe; отказ сети не останавливает workers.
Повтор/recover сохраняют configs, cursor, uncertain receipts, app2900 и БД.
Recover проверенного baseline не зависит от внешнего TLS; trusted retrying workers
должны запускаться и при продолжающемся обрыве egress.

Renderer: проверенный исходник из files.zip, а не подмена receiver.pyz на сервере.
Принять палитру поверхностей, метрики/статусы, компактность и явное число строк.
Исправить quantization16→8→4, skip кадровых create actions и целостность нумерации.
Сокращение перечня объявляется в картинке и caption; скрытые строки сохраняют
описательные кнопки. Неизвестные буквы и слишком длинный текст — исходный fallback.
Брендовые surface/text совпадают с tokens; статусные цвета адаптированы для dark,
не выдаются за дословные токены. Telegram styles остаются клиентскими.

Один tar содержит pinned predecessor, frozen visual library, новый controller,
worker, отдельный ручной egress refresh и инструкцию; hashes/CRC/isolated smoke.
CI доказывает исходники/fixture, только owner проверяет живой Telegram.
