# Обновление Академии2801→2802

Владелец29.10.2026 подтвердил успешную установку, status и scheduled cron;
[квитанция](../context/runtime/perf2802_owner_deploy_20261029.json).
Browser/workday/SLO acceptance остаются отдельным этапом.

Комплект `ustar-perf-query-install-3c1ddd09.tar.gz`, оператор
`3c1ddd099a1d7abdfa44ec3a9ebc54296a553618`, приложение
`22607c69a27c9be65d78fd407ce240f9dc2b8c8b`.
SHA256 архива: `1319be4822e366e705ce41bf3bf3121ed0e6fc374cc3208e0fc42047d7ccb9f0`.
Квалификация и ограничения: [квитанция](../context/runtime/perf2802_qualification_20261028.json).

Исходная версия — принятая Studio2801, theme2700, оператор81118e39 и его завершённый журнал.
Цель — local_ustar2026102802/theme_ustar2026102700. Меняется пакет запросов RSS/имён
операторского сезона; Studio и worker6c56 сохраняются. Установка включает короткий перерыв
работы Академии. Проверка `--check` выполняет только чтение и не создаёт журнал обновления.

Скачать архив в `/home/aduk/`, затем выполнить блок целиком:

```bash
sudo -v
sudo bash <<'ROOT'
set -euo pipefail
umask 077
install -d -o root -g root -m 700 /root/ustar-perf-query-install-3c1ddd09
install -o root -g root -m 600 \
  /home/aduk/ustar-perf-query-install-3c1ddd09.tar.gz \
  /root/ustar-perf-query-install-3c1ddd09/package.tar.gz
cd /root/ustar-perf-query-install-3c1ddd09
printf '%s  %s\n' 1319be4822e366e705ce41bf3bf3121ed0e6fc374cc3208e0fc42047d7ccb9f0 package.tar.gz | sha256sum --check --strict -
tar --extract --gzip --file package.tar.gz --no-same-owner --no-same-permissions
sha256sum --check --strict SHA256SUMS
/usr/bin/python3 -I -B update.py --check
ROOT
```

Продолжать после `ready_for_perf_update`, `local_ustar: 2026102801`, `host_changes: false`.
Успешно установленный2802 возвращает `application_ready`; повторный upgrade проверяет состояние.
Другой исходный пакет или незавершённый predecessor должны дать отказ — guard не обходить.

Запустить обновление как независимую systemd-службу:

```bash
sudo systemd-run --unit=ustar-perf-query-update-3c1ddd09 \
  --property=Type=oneshot --property=TimeoutStartSec=infinity \
  /usr/bin/python3 -I -B -u /root/ustar-perf-query-install-3c1ddd09/update.py \
  --upgrade --acknowledge-outage
sudo journalctl -fu ustar-perf-query-update-3c1ddd09.service -o cat
```

Дождаться `app_ready` и `Finished ...`. После этого Ctrl+C закрывает просмотр журнала;
сама служба работает независимо от терминала. Затем проверить:

```bash
sudo timeout --kill-after=2s 40s /usr/bin/python3 -I -B \
  /root/ustar-perf-query-install-3c1ddd09/update.py --status
sudo timeout --kill-after=2s 40s /usr/bin/python3 -I -B -u \
  /usr/local/sbin/USTAR_CRON_SETUP_20261005.py --status
```

Ожидаются app.phase `app_ready`, database_available `true`, local2802/theme2700,
receiver/notify active+enabled и успешный cron. `managed_operation_busy_retry_later`
означает продолжающуюся управляемую операцию: дождаться её завершения и повторить status.

Проверить открытие материалов/студии и операторских страниц RSS/сезона.
Старый агрегат Apache p95 не является доказательством ускорения этих страниц.

При `recovery_required` или прерывании обновления использовать тот же комплект:

```bash
sudo /usr/bin/python3 -I -B -u \
  /root/ustar-perf-query-install-3c1ddd09/update.py --recover
```

До публичной публикации recover возвращает парную копию2801; после неё завершает2802
и сохраняет новые бизнес-записи. Полный поздний restore удаляет все записи после снимка
и требует двух отдельных подтверждающих флагов; для обычного восстановления он не нужен.
Журнал `/var/lib/ustar-perf-query-update` и общие locks сохранять.
