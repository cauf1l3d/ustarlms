# Пилот виджета задач Telegram

В личном чате с ботом откройте **«Мои задачи»** и выберите своё обычное поручение. Карточка показывает статус, срок с часовым поясом и краткое описание. Для новой задачи нажмите **«Взять в работу»**. После подтверждения карточка покажет «В работе». **«Заполнить отчёт»** открывает штатную форму с текстом и фото. **«Подробности задачи»** открывает полное описание и обычные действия.

Для шаблонных чек-листов, переобучения, связанных назначений, завершённых задач и проверки руководителем остаётся штатный диалог. Если клиент не отображает карточку, нажмите «Подробности задачи» под сообщением. Для новой редакции откройте задачу заново из списка.

## Установка

Требуется установленный пакет чек-листа `5607ab6f` с версией `2026111301`. Пакет обновляет Академию и worker в одном окне обслуживания. Пилот задач по умолчанию выключен. [Установка предыдущего пакета](CONNECT_CHECKLIST_WIDGET_RU.md).

Положите архив в `/home/aduk`.

```bash
sudo bash <<'ROOT'
set -euo pipefail
umask 077
archive=ustar-connect-task-widget-04c8eff0.tar.gz
dir=/root/ustar-connect-task-widget-04c8eff0
test ! -L "$dir"
install -d -m 700 "$dir"
cd "$dir"
install -m 600 "/home/aduk/$archive" "$archive"
printf '%s  %s\n' \
  9c7c2b670babbf953da8c891e6810a55e02d85e6a12c356cd2915f4f041b28e1 \
  "$archive" | sha256sum --check --strict -
tar --no-same-owner -xzf "$archive"
sha256sum --check --strict --quiet SHA256SUMS
python3 -I -B update.py --check
ROOT
```

После `phase: ready` запустите обновление в окно обслуживания.

```bash
sudo systemd-run --wait --collect \
  --unit=ustar-connect-task-widget-20261114-01 \
  --property=Type=oneshot --property=TimeoutStartSec=30min \
  /usr/bin/python3 -I -B /root/ustar-connect-task-widget-04c8eff0/update.py \
  --upgrade --acknowledge-outage
```

```bash
sudo python3 -I -B /root/ustar-connect-task-widget-04c8eff0/update.py --check
```

Ожидаются `phase: active`, `local_ustar: 2026111400`, обе службы Connect запущены и `announcements_enabled: true` при ранее включённых рассылках. При `managed_operation_busy_retry_later` повторите проверку после текущей операции. При `recovery_required` сохраните вывод и выполните `--recover --acknowledge-outage` из этого же архива.

## Настройка и приёмка

```bash
sudo install -m 600 /opt/ustar/data/moodle/public/config.php \
  "/root/ustar-config-before-task-widget-$(date +%Y%m%d-%H%M%S).php"
sudoedit /opt/ustar/data/moodle/public/config.php
```

Добавьте строку после подключения Moodle и существующего моста. Замените `123` на реальный числовой Moodle ID связанного сотрудника. Массив поддерживает до десяти сотрудников. Пустой массив выключает пилот задач.

```php
$CFG->ustar_connect_task_widget_userids = [123];
```

```bash
sudo docker exec -u www-data ustar_moodle php -l /var/www/html/config.php
```

1. Назначьте тестовое обычное поручение. Сотрудник открывает его в «Моих задачах», сверяет статус, срок и описание.
2. Сотрудник нажимает «Взять в работу». В чате и Академии статус меняется на «В работе», в истории есть один переход.
3. Из исходной карточки сотрудник нажимает «Заполнить отчёт», вводит тестовый результат и подтверждает. В Академии появляется один отчёт, а задача переходит на проверку.
4. Проверьте открытие «Подробности задачи» из другой карточки, работу на используемых устройствах и один ежедневный чек-лист под тем же сотрудником, если он включён в оба пилота.
