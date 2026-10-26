# Установка Connect в Академию

Этот комплект обновляет только local_ustar2026100204→2026101905 и сохраняет
theme_ustar2026100202. Payload — ранее проверенный RC830d3703,552файла/99таблиц.
Сеть, Docker images, core Moodle5.1.8, другие плагины, receiver, ключи и настройки
бота не изменяются. Mini App и активация каналов остаются отдельными этапами.

## Подготовка

Нужны sudo, Python3.12 и согласованное окно обслуживания: во время свежего
бэкапа и обновления Академия временно недоступна. Продолжительность зависит от
объёма данных и скорости дисков; обещать фиксированное число минут нельзя.
Операция запускается отдельной systemd-службой и продолжится после выхода SSH.

Скачайте новый `ustar-connect-install-<commit>.tar.gz` и его контрольную сумму.
Старый `ustar-connect-academy-830d3703.tar.gz` содержит только исходники и здесь
не нужен. Загрузите новый архив через WinSCP в `/home/aduk/`. Сначала проверьте
его SHA256 по точному значению из сообщения выпуска. Не выбирайте архив через
wildcard, если в каталоге лежит несколько выпусков.

В командах ниже `PACKAGE.tar.gz` заменяется точным именем проверенного архива:

```bash
sudo install -d -o root -g root -m 700 /root/ustar-connect-install
sudo install -o root -g root -m 600 PACKAGE.tar.gz /root/ustar-connect-install/package.tar.gz
sudo tar --extract --gzip --file /root/ustar-connect-install/package.tar.gz --directory /root/ustar-connect-install --no-same-owner --no-same-permissions
sudo sh -c 'cd /root/ustar-connect-install && sha256sum --check --strict SHA256SUMS'
sudo python3 -I -B /root/ustar-connect-install/update.pyz --check
```

Копирование и распаковка ещё не обновляют Академию. Убедитесь, что все checksum
строки `OK`, а проверка выводит `ready_for_maintenance_window`. При отказе не
обходите проверки и не меняйте права/версии вручную; передайте короткий вывод
без config.php, дампов, ключей и `commands-private.log`.

## Запуск в выбранное окно обслуживания

```bash
sudo systemd-run --unit=ustar-connect-academy-update --property=Type=oneshot --property=TimeoutStartSec=infinity /usr/bin/python3 -I -B -u /root/ustar-connect-install/update.pyz --upgrade --acknowledge-outage
sudo journalctl -fu ustar-connect-academy-update.service -o cat
```

Ctrl+C закрывает просмотр журнала; отдельная служба продолжит работу.
Повторный запуск той же операции запрещён активной службой и журналом
транзакции. При аварийном выключении контейнеры не должны автоматически
запуститься с половиной обновления: их restart policy временно `no`.

Установщик проверяет fixed container IDs/images/binds, исходники и версии БД,
все существующие версии плагинов/флаги задач, mount HDD, pinned cron/backup
helpers, свободное место и inode с запасом для восстановления. Делает свежий
зашифрованный HDD-бэкап, проверяет квитанцию readback. Затем под существующей
EX-блокировкой backup/cron чисто останавливает web и PostgreSQL, проверяет
отсутствие других клиентов БД, копирует целиком code/config, moodledata и
physical PostgreSQL. Проверяет хеши и владельцев копии до изменения дополнений.

После замены только двух addon выполняет официальный Moodle CLI upgrade дважды
под maintenance; проверяет source hashes, неизменность остальных исходников,
версии,99таблиц, повторную схему, сохранность старых задач и новую cleanup task,
ссылки файлов. Перед открытием сайта фиксирует границу новых записей, возвращает
исходные restart policies и проверяет HTTP200 страницы входа.

## Результат

```bash
sudo systemctl show ustar-connect-academy-update.service -p ActiveState -p SubState -p Result -p ExecMainStatus
sudo python3 -I -B /root/ustar-connect-install/update.pyz --status
```

Успех: `CONNECT_ACADEMY_UPDATE=PASS; UPGRADE_REPEAT=PASS; BOT_ACTIVATION=PENDING`,
Result=success/ExecMainStatus=0 и phase=upgrade_complete. Затем самостоятельно
проверьте вход в Академию, тему USTAR и обычную работу пользователя. Эта проверка
не подтверждает связку с Telegram: bridge, account-link и доставка ещё отдельно.

## Восстановление

До открытия пользовательских записей сбой автоматически восстанавливает все
три дерева вместе. Ожидается `CONNECT_PAIRED_RECOVERY=PASS`; исходная попытка
обновления при этом завершается с ошибкой. При неудаче восстановления или
потере питания журнал и пара сохраняются приватно в
`/var/lib/ustar-connect-academy-update/update-...`; контейнеры остаются остановлены
с restart=no, если остановку удалось подтвердить. Не включайте их вручную поверх
частичного восстановления и не удаляйте эту копию для освобождения места.

Проверьте `--status`. После остановки предыдущей службы повторное восстановление:

```bash
sudo systemd-run --unit=ustar-connect-academy-recover --property=Type=oneshot --property=TimeoutStartSec=infinity /usr/bin/python3 -I -B -u /root/ustar-connect-install/update.pyz --restore --acknowledge-outage
sudo journalctl -fu ustar-connect-academy-recover.service -o cat
```

Если сайт уже был открыт после обновления или восстановления, установщик
запретит возврат старых данных без дополнительного
`--discard-writes-since-snapshot`. Этот флаг означает потерю ВСЕХ новых записей
с момента холодной копии, а не только Connect. Решение принимает владелец;
команду с этим флагом не выполнять как обычное устранение ошибки.

Если прерван сам HDD producer и остался его resume marker, требуется его
проверенная процедура recovery; addon installer не подменяет этот механизм.
Журнал `commands-private.log` может содержать конфиденциальный вывод CLI.
Не отправляйте его целиком в чат/GitHub. Копия содержит пароли/данные Академии,
хранится root-private, не скачивается и не отправляется ботом.
