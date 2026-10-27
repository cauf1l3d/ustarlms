# Студия USTAR: установка и приёмка на production

По решению владельца27Oct принимаем на production. Выполняет владелец на server1;
агент не подключается к серверу. Переход local_ustar2300→2600, theme0202 без изменений.
Целевое приложение2d95137ac85a09ad4c513edae8a792152153c840 уже прошло full RC40434525376.
Operator62b0da2020bc27569c0bd4f8ac96b676f29b7490 должен иметь успешную квалификацию
в CI PR107. Более поздние documentation commits не заменяют этот файл оператора.

Архив: `ustar-studio-install-62b0da20.tar.gz`.
SHA256: `98f486fe525b3abdcb5ef899d0948ab9083712317ff392e7c8fa0f4aa05ec9cf`.
Внутри4обычных файла: update.py, README.txt, INSTALL.json, SHA256SUMS.
SHA256 update.py: `7cb18a45dbd54dcdb6af50c44e11008989ed2702a3c3337494442e6de964343c`.
Никаких config.php, production-данных, паролей и ключей в архиве нет.

## 1. Разместить и проверить

Загрузить архив через WinSCP в `/home/aduk/` под указанным именем.
Выполнить целиком на server1:

```bash
sudo bash <<'ROOT'
set -euo pipefail
umask 077
install -d -o root -g root -m 700 /root/ustar-studio-install-62b0da20
install -o root -g root -m 600 \
  /home/aduk/ustar-studio-install-62b0da20.tar.gz \
  /root/ustar-studio-install-62b0da20/package.tar.gz
cd /root/ustar-studio-install-62b0da20
printf '%s  %s\n' 98f486fe525b3abdcb5ef899d0948ab9083712317ff392e7c8fa0f4aa05ec9cf package.tar.gz | sha256sum --check --strict -
tar --extract --gzip --file package.tar.gz --no-same-owner --no-same-permissions
sha256sum --check --strict SHA256SUMS
/usr/bin/python3 -I -B update.py --check
ROOT
```

Ожидается `phase=ready_for_studio_update`, `local_ustar=2026102300`,
`host_changes=false`, `provider_called=false`. Этот этап только проверяет сервер.
При отказе не запускать upgrade и не исправлять hashes/права/locks ради PASS.
Нужны только phase/reason для разбора; приватные журналы/конфиги не публиковать.
`application_ready` означает, что тот же пакет уже установлен: переходить к статусу.

Проверяются принятые2300source/100tables, текущие настройки cron-задач, сохранённые
CON02/Telegram журналы, исходный worker6c56, cron2300, HTTPS owner, pins и запас места.
Наличие 100таблиц само по себе не считается совпадением схемы: сверяются поля/индексы.
Допускается только прежняя точно известная пара исторических пропусков индексов;
контроллер их не создаёт и не ремонтирует.

## 2. Установить в окно обслуживания

Дождаться успешного check и выбрать время без редактирования пользователями.
Будет временная недоступность: свежий HDD-бэкап, остановка действующих writers,
согласованная холодная копия code/data/PG, установка582проверенных файлов, два
официальных Moodle upgrade, версия cron2600, проверка входа и возврат прежних
состояний служб/restart policy. Копирование может занять несколько минут.
Контейнеры не пересоздаются; worker/ключи/SQLite/fence/HTTPS/Compose не меняются.

```bash
sudo systemd-run --unit=ustar-studio-update-62b0da20 \
  --property=Type=oneshot --property=TimeoutStartSec=infinity \
  /usr/bin/python3 -I -B -u /root/ustar-studio-install-62b0da20/update.py \
  --upgrade --acknowledge-outage
sudo journalctl -fu ustar-studio-update-62b0da20.service -o cat
```

Разрыв SSH не останавливает systemd-службу. Ctrl+C завершает только просмотр журнала.
Не запускать вторую установку одновременно и не удалять lock-файлы.
Если такое имя службы уже существует, сначала проверить журнал и статус.

## 3. Статус и ручная приёмка

```bash
sudo /usr/bin/python3 -I -B /root/ustar-studio-install-62b0da20/update.py --status
```

Успех: `phase=app_ready`, `versions.local_ustar=2026102600`, database_available=true
и прежние active/enabled для Connect-служб. `baseline_restored` с
upgrade_succeeded=false означает восстановление2300, а не успешную установку.
Сохранить тот же update.py и закрытый журнал `/var/lib/ustar-course-studio-update`.

Начать с одного чернового курса через Материалы→Студия курсов: текст/изображение,
перезагрузка и второй браузер автора, PDF с кириллицей, печатный экспорт, два нажатия
«Сохранить в материалы» без дубликатов, карточка/аудитория и запуск сотрудником.
Публикация отдельная; массовый workspace переносить после проверки этого сценария.
Проверить действующий scheduled cron штатным status без его повторной установки:

```bash
sudo -n timeout --kill-after=2s 40s /usr/bin/python3 -I -B -u /usr/local/sbin/USTAR_CRON_SETUP_20261005.py --status
```

Нужен новый завершённый scheduled run с version2600 и exit0, не старый результат2300.
Внешний restore и все Telegram flows этим пакетом заново не принимаются.

## 4. Восстановление прерванной операции

Использовать **тот же файл**, без замены его другой сборкой и без правки state:

```bash
sudo /usr/bin/python3 -I -B -u /root/ustar-studio-install-62b0da20/update.py --recover
```

До public release команда восстанавливает согласованную2300пару. После него
завершает2600 и сохраняет более новые пользовательские записи. Если выводит
recovery_required, оставляет защиту и приватную диагностику; не подменять состояние.

Полный поздний возврат к снимку поддерживает только отдельный явный
`--restore --acknowledge-outage --discard-writes-since-snapshot`. Он удаляет **все
бизнес-записи после снимка** в восстанавливаемой БД, включая новые курсы/задачи/ACK.
Это не обычная команда приёмки, не автоматический fallback и не полный DR внешних
сервисов. Обычное восстановление после сбоя — `--recover` выше.
