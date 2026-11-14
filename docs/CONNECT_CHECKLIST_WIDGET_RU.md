# Пилот чек-листа в Telegram

В боте откройте **«Чек-листы»**, выберите назначенный на сегодня короткий список, ответьте «Да» или «Нет» по каждому пункту и нажмите **«Отправить в Академию»**. Длинная карточка разбивается на страницы внутри того же сообщения. В чате появится количество выполненных пунктов. В Академии эта же запись видна в разделе «Задачи → Чек-листы». До отправки выбранные ответы сохраняются на текущем устройстве в этом сообщении.

Пошаговая форма под виджетом позволяет оставить комментарий или заполнить список на клиенте без поддержки виджетов. Длинные списки, существующие черновики и исправления продолжают идти через свои обычные формы. Если список изменился или закончился рабочий день, откройте его заново из меню бота. При временной ошибке повторите отправку тех же ответов. Академия сохранит одну итоговую запись.

## Установка на server1

Пакет применяется к приложению и Connect из поставки `7fa3233f` с версией Академии `2026111300`. Архив обновляет приложение и worker в одном окне обслуживания. Пилот по умолчанию выключен.

Положите `ustar-connect-checklist-widget-5607ab6f.tar.gz` в `/home/aduk` и проверьте его перед установкой.

```bash
sudo bash <<'ROOT'
set -euo pipefail
umask 077
archive=ustar-connect-checklist-widget-5607ab6f.tar.gz
dir=/root/ustar-connect-checklist-widget-5607ab6f
test ! -L "$dir"
install -d -m 700 "$dir"
cd "$dir"
install -m 600 "/home/aduk/$archive" "$archive"
printf '%s  %s\n' \
  0153f18bb689938179779659d55c0c8a074a588bc8fa3492c4d23f9d79e73c82 \
  "$archive" | sha256sum --check --strict -
tar --no-same-owner -xzf "$archive"
sha256sum --check --strict --quiet SHA256SUMS
python3 -I -B update.py --check
ROOT
```

После `phase: ready` запустите установку.

```bash
sudo systemd-run --wait --collect \
  --unit=ustar-connect-checklist-widget-20261113-01 \
  --property=Type=oneshot --property=TimeoutStartSec=30min \
  /usr/bin/python3 -I -B /root/ustar-connect-checklist-widget-5607ab6f/update.py \
  --upgrade --acknowledge-outage
```

```bash
sudo python3 -I -B /root/ustar-connect-checklist-widget-5607ab6f/update.py --check
```

Ожидаемый результат — `phase: active`, `local_ustar: 2026111301`, приложение и worker `5607ab6f`, `announcements_enabled: true` при ранее включённых рассылках, обе службы Connect запущены. При `managed_operation_busy_retry_later` повторите проверку после завершения текущей операции. При `recovery_required` сохраните вывод и выполните `--recover --acknowledge-outage` из того же архива. [Проверки установки и состава пакета](../context/runtime/connect_checklist_widget_qualification_20261113.json).

### Если отсутствует файл блокировки Connect

При точном ответе `managed_lock_missing:/run/ustar-connect-install.lock` проверка останавливается до сверки версий. Этот файл хранится в служебном каталоге `/run`. Создайте отсутствующий файл и повторите проверку распакованного пакета.

```bash
sudo python3 -I -B - <<'PY'
import os
import stat

path = "/run/ustar-connect-install.lock"
parent = os.lstat("/run")
if not (stat.S_ISDIR(parent.st_mode) and parent.st_uid == 0 and not parent.st_mode & 0o022):
    raise SystemExit("unsafe_runtime_parent")

os.umask(0o077)
flags = os.O_NOFOLLOW | os.O_NONBLOCK
try:
    fd = os.open(path, flags | os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
except FileExistsError:
    fd = os.open(path, flags | os.O_RDONLY)

try:
    info = os.fstat(fd)
    live = os.lstat(path)
    if not (stat.S_ISREG(info.st_mode) and info.st_uid == 0 and info.st_nlink == 1
            and stat.S_IMODE(info.st_mode) == 0o600
            and (info.st_dev, info.st_ino) == (live.st_dev, live.st_ino)):
        raise SystemExit("unsafe_managed_lock")
finally:
    os.close(fd)
print("connect_install_lock_ready")
PY

sudo python3 -I -B /root/ustar-connect-checklist-widget-5607ab6f/update.py --check
```

Команда сохраняет уже существующий корректный файл вместе с его inode и содержимым. Установку запускайте после `phase: ready`. При `application_source_drift`, `unexpected_worker` или другом отказе сохраните новый JSON для разбора.

## Настройка пилота

Для первого прогона назначьте одному связанному сотруднику действующий список из 1–5 коротких пунктов, до 65 символов каждый. Назначение делается в Студии чек-листов Академии. Подойдёт и существующий короткий список, у которого ещё нет записи за сегодня. Выбирайте тестовый список без реальных операционных отметок.

Сохраните защищённую копию конфигурации и откройте её для редактирования.

```bash
sudo install -m 600 /opt/ustar/data/moodle/public/config.php \
  "/root/ustar-config-before-checklist-widget-$(date +%Y%m%d-%H%M%S).php"
sudoedit /opt/ustar/data/moodle/public/config.php
```

Добавьте строку в конец PHP-конфигурации, после `lib/setup.php` и подключения существующего моста. Вставьте фактический числовой Moodle ID сотрудника вместо `123`. Можно перечислить до десяти ID.

```php
$CFG->ustar_connect_checklist_widget_userids = [123];
```

```bash
sudo docker exec -u www-data ustar_moodle php -l /var/www/html/config.php
```

Для закрытия пилота установите пустой массив `[]`. Настройку читает Moodle при запросе. После включения откройте «Чек-листы» заново.

## Проверка в чате

1. Откройте список под пилотной учётной записью. Проверьте виджет и кнопку «Пошаговая форма».
2. На тестовом списке отметьте один пункт «Нет», остальные «Да». До последнего ответа кнопка отправки недоступна.
3. Отправьте и откройте запись за этот день в Академии. Число выполненных пунктов, отрицательный ответ и одна итоговая запись должны совпасть.
4. Проверьте ту же карточку на используемых клиентских устройствах. На другом списке или в другой день проверьте пошаговую форму и комментарий. При включении на несколько сотрудников убедитесь, что обычный диалог доступен и сотруднику вне списка пилота.

Используется нативный HTML-виджет в личном чате. Его формат и совместимость с клиентами описаны в документации Telegram. [Bot API](https://core.telegram.org/bots/api#sendwidget), [формат виджетов](https://core.telegram.org/bots/widgets).
