# RSS без повторов и измерение рабочего дня —2803

Комплект `ustar-feed-media-install-36be3f10.tar.gz` содержит установщик и отдельный
пассивный сборщик. Приложение `ef903b7d276f1d62c4879f8a943a435b48e89117`, оператор
`36be3f108f2eac6a3db728f93b4456a729b97a3e`. Исходная версия — принятая2802,
оператор3c1ddd09 с завершённым журналом. Цель local2026102803/theme2026102700.
Worker и схема БД сохраняются. Установка включает перерыв работы Академии.

SHA256 **архива**:
`f09b21a969c91b9ce5e884065573586779b50ce699aa789edc394f20bad9589a`.
Это отличается от хеша отдельного монолитного `update.py`.

## Установка

Скопировать архив в `/home/aduk/`, затем выполнить блок целиком:

```bash
sudo -v
sudo bash <<'ROOT'
set -euo pipefail
umask 077
install -d -o root -g root -m 700 /root/ustar-feed-media-install-36be3f10
install -o root -g root -m 600 \
  /home/aduk/ustar-feed-media-install-36be3f10.tar.gz \
  /root/ustar-feed-media-install-36be3f10/package.tar.gz
cd /root/ustar-feed-media-install-36be3f10
printf '%s  %s\n' f09b21a969c91b9ce5e884065573586779b50ce699aa789edc394f20bad9589a package.tar.gz | sha256sum --check --strict -
tar --extract --gzip --file package.tar.gz --no-same-owner --no-same-permissions
sha256sum --check --strict SHA256SUMS
/usr/bin/python3 -I -B update.py --check
ROOT
```

Продолжать после `ready_for_feed_media_update`, local2026102802, host_changes:false.
При другой версии или незавершённом predecessor guard не обходить.

```bash
sudo systemd-run --unit=ustar-feed-media-update-36be3f10 \
  --property=Type=oneshot --property=TimeoutStartSec=infinity \
  /usr/bin/python3 -I -B -u /root/ustar-feed-media-install-36be3f10/update.py \
  --upgrade --acknowledge-outage
sudo journalctl -fu ustar-feed-media-update-36be3f10.service -o cat
```

Дождаться `app_ready` и `Finished`. Ctrl+C закрывает просмотр журнала, отдельная
служба от терминала не зависит. Затем:

```bash
sudo timeout --kill-after=2s 40s /usr/bin/python3 -I -B \
  /root/ustar-feed-media-install-36be3f10/update.py --status
sudo timeout --kill-after=2s 40s /usr/bin/python3 -I -B -u \
  /usr/local/sbin/USTAR_CRON_SETUP_20261005.py --status
```

Ожидаются app_ready/DB available, local2803/theme2700, обе Connect-службы active/enabled,
успешный cron без новых ошибок. Исторические19 raw-overdue/failed2 не обнулять ради метрики.
В ленте проверить старую запись с повтором: одна обложка, дополнительные иллюстрации
раскрываются через «Ещё фото». Точные копии скрыты; исходные файлы/сохранения остаются.
Старые варианты разного размера без сохранённой исходной идентичности могут оставаться
в дополнительных фото. Для новых Leonardo URL одна исходная UUID-картинка выбирается один раз.

## Рабочий день:8часов, без дополнительной установки

Запустить **в начале обычного рабочего дня**, когда сотрудники пользуются Академией.
Сбор не создаёт искусственную нагрузку и не включает расширения PostgreSQL.
Интервал —60 секунд, продолжительность —8 часов, до481среза.
Каталог новый; при следующем отдельном прогоне использовать новый номер02.

```bash
sudo systemd-run --unit=ustar-workday-2803-01 \
  --property=Type=oneshot --property=TimeoutStartSec=9h \
  --property=MemoryMax=384M --property=CPUQuota=10% --property=Nice=10 \
  /usr/bin/python3 -I -B -u /root/ustar-feed-media-install-36be3f10/observe.py \
  --collect --duration-seconds 28800 --interval-seconds 60 \
  --output-dir /var/lib/ustar-workday-2803-01
```

Ход сбора:

```bash
sudo systemctl show ustar-workday-2803-01.service -p ActiveState -p SubState -p Result
sudo wc -l /var/lib/ustar-workday-2803-01/samples.jsonl
```

После завершения службы:

```bash
sudo cat /var/lib/ustar-workday-2803-01/summary.json
sudo bash <<'ROOT'
set -euo pipefail
umask 077
cd /var/lib/ustar-workday-2803-01
sha256sum --check --strict SHA256SUMS
tar --create --gzip --file /home/aduk/ustar-workday-2803-01.tar.gz \
  samples.jsonl summary.json SHA256SUMS
chown aduk:aduk /home/aduk/ustar-workday-2803-01.tar.gz
chmod 600 /home/aduk/ustar-workday-2803-01.tar.gz
ROOT
```

Передать полученный архив в чат. Он содержит только числовые агрегаты хоста/БД/cron
и Apache. Пустые, устаревшие, обрезанные источники и перезапуски отмечаются как пропуски.
Apache p50/p95/p99 включает статические ресурсы: это не измерение отдельных страниц,
SQL/page или браузерного LCP. Собственные READ ONLY транзакции наблюдателя включены в
счётчики PostgreSQL. Raw-overdue не учитывает effective overrides/отключённые компоненты.
После анализа отдельно согласуются сценарии, одновременная нагрузка и SLO.

При прерывании доступный JSONL можно проверить повторно с тем же интервалом:

```bash
sudo /usr/bin/python3 -I -B /root/ustar-feed-media-install-36be3f10/observe.py \
  --summarize /var/lib/ustar-workday-2803-01/samples.jsonl --interval-seconds 60
```

## Восстановление приложения

```bash
sudo /usr/bin/python3 -I -B -u \
  /root/ustar-feed-media-install-36be3f10/update.py --recover
```

До публикации recover возвращает согласованную2802; после публикации завершает2803,
сохраняя новые бизнес-записи. Поздний полный restore требует обоих destructive flags
и удаляет записи после снимка; для обычного recover он не нужен.
Журнал `/var/lib/ustar-feed-media-update` и общие locks сохранять.
