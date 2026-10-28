# Установка исправленной студии2800 на server1

28.10.2026. Фиксированный переход **2700 → 2800**. Приложение
4c48dce7dadd4da8f054f2acf0d6974bdaeecebc; установщик
5f57115d85fbe808069b9c72d6c0ea9fa6e13f02. Theme остаётся2700, worker не обновляется.
Успешный полный CI и сравнение архива зафиксированы в
[квитанции](../context/runtime/studio_colors_index_compat_20261028.json).

Скачайте `ustar-studio-colors-install-5f57115d.tar.gz`, загрузите через свой SFTP
в `/home/aduk/` без распаковки и переименования. Обновление требует короткого перерыва в Академии.

Исправлен отказ schema_indexes для точного ранее принятого legacy-профиля.
Старый352d пакет заменён; индексы БД установщик не исправляет.

## Подготовка и проверка

```bash
sudo -v
sudo bash <<'ROOT'
set -euo pipefail
umask 077
install -d -o root -g root -m 700 /root/ustar-studio-colors-install-5f57115d
install -o root -g root -m 600 \
  /home/aduk/ustar-studio-colors-install-5f57115d.tar.gz \
  /root/ustar-studio-colors-install-5f57115d/package.tar.gz
cd /root/ustar-studio-colors-install-5f57115d
printf '%s  %s\n' 4711766fac8d7374f5fce5d9c111b75b1bc360c87c17aa876560b578425b194e package.tar.gz | sha256sum --check --strict -
tar --extract --gzip --file package.tar.gz --no-same-owner --no-same-permissions
sha256sum --check --strict SHA256SUMS
/usr/bin/python3 -I -B update.py --check
ROOT
```

Продолжайте только при `phase=ready_for_colors_update`, `local_ustar=2026102700`,
`host_changes=false`. Дополнительно baseline_index_profile показывает
`server1_legacy_indexes_20261023` либо `xml_install_indexes`. Если `application_ready/local2800` — обновление уже завершено.
При `refused` остановитесь и передайте полный JSON; не удаляйте locks и не правьте
квитанции. `accepted_Materials2700_required` означает, что принятая2700 поставка
не подтверждена. Если на сервере ещё2600, сначала требуется прежнее обновление2700.
`managed_operation_busy_retry_later`: дождитесь завершения действующей операции.

## Обновление

```bash
sudo systemd-run --unit=ustar-studio-colors-update-5f57115d \
  --property=Type=oneshot --property=TimeoutStartSec=infinity \
  /usr/bin/python3 -I -B -u /root/ustar-studio-colors-install-5f57115d/update.py \
  --upgrade --acknowledge-outage
sudo journalctl -fu ustar-studio-colors-update-5f57115d.service -o cat
```

Дождитесь `app_ready/local2800` и `Finished` без ошибки. Ctrl+C после завершения
закрывает просмотр журнала; служба обновления запускается независимо от SSH-клиента.

## Проверка результата

```bash
sudo /usr/bin/python3 -I -B \
  /root/ustar-studio-colors-install-5f57115d/update.py --status
sudo timeout --kill-after=2s 40s \
  /usr/bin/python3 -I -B -u /usr/local/sbin/USTAR_CRON_SETUP_20261005.py --status
```

Ожидаются app_ready, DB available, local2800/theme2700, receiver+notify active/enabled.
В cron проверьте последний успешный scheduled запуск и отсутствие новых ошибок.
В браузере откройте студию заново: «Скрыть шапку» и «Оформление → Левое меню курса»;
проверьте сохранение, предпросмотр и открытие материала.

При прерывании или ошибке используйте **тот же файл**:

```bash
sudo /usr/bin/python3 -I -B -u \
  /root/ustar-studio-colors-install-5f57115d/update.py --recover
```

До публикации recovery возвращает сохранённую пару2700; после публикации завершает2800
и сохраняет новые записи. Полный поздний restore удаляет записи после снимка и
не применяется для обычного восстановления. Журнал `/var/lib/ustar-studio-colors-update`
и прежние журналы обновлений сохраняются. Эта инструкция не подтверждает установку на сервере.
