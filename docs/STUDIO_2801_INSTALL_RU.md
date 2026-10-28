# Установка масштаба полотна и независимых панелей студии —2801

Переход: local_ustar2026102800 →2026102801; theme_ustar2026102700.
Приложение: a3df57831a5ed49b901e5116ee800f2217fb61e9.
Установщик:81118e39f54c9ac34475f640bf2b9d4d8a047f24.
Предыдущая поставка: исправленный2800/5f57115d. Обновление не создаёт/удаляет
индексы и не меняет worker. Проверяется точный589-файловый baseline и журнал2800.

Архив `ustar-studio-viewport-install-81118e39.tar.gz` (20393281байт).
SHA256: `0bf7e718dd64115dad4e635d7e62fc48843bbd6e5a24ffd6860377f792ba1ea5`.

Скопируйте архив в `/home/aduk/`, затем выполните:

```bash
sudo -v
sudo bash <<'ROOT'
set -euo pipefail
umask 077
install -d -o root -g root -m 700 /root/ustar-studio-viewport-install-81118e39
install -o root -g root -m 600 \
  /home/aduk/ustar-studio-viewport-install-81118e39.tar.gz \
  /root/ustar-studio-viewport-install-81118e39/package.tar.gz
cd /root/ustar-studio-viewport-install-81118e39
printf '%s  %s\n' 0bf7e718dd64115dad4e635d7e62fc48843bbd6e5a24ffd6860377f792ba1ea5 package.tar.gz | sha256sum --check --strict -
tar --extract --gzip --file package.tar.gz --no-same-owner --no-same-permissions
sha256sum --check --strict SHA256SUMS
/usr/bin/python3 -I -B update.py --check
ROOT
```

Ожидается `phase: ready_for_viewport_update`, `local_ustar: 2026102800`;
`baseline_index_profile`: `xml_install_indexes` либо `server1_legacy_indexes_20261023`.
Проверка не меняет приложение/БД и не вызывает провайдеров. Если `refused`, остановитесь
и передайте JSON: обходить guard или вручную исправлять индексы не требуется.
Если `application_ready`, эта2801 уже установлена и прошла проверку.

После успешного `ready_for_viewport_update` запустите обновление в согласованное окно:

```bash
sudo systemd-run --unit=ustar-studio-viewport-update-81118e39 \
  --property=Type=oneshot --property=TimeoutStartSec=infinity \
  /usr/bin/python3 -I -B -u /root/ustar-studio-viewport-install-81118e39/update.py \
  --upgrade --acknowledge-outage
sudo journalctl -fu ustar-studio-viewport-update-81118e39.service -o cat
```

После `Finished` нажмите Ctrl+C, чтобы выйти из просмотра журнала, и проверьте:

```bash
sudo /usr/bin/python3 -I -B \
  /root/ustar-studio-viewport-install-81118e39/update.py --status
sudo timeout --kill-after=2s 40s \
  /usr/bin/python3 -I -B -u \
  /usr/local/sbin/USTAR_CRON_SETUP_20261005.py --status
```

Ожидаются `app_ready`, `local_ustar: 2026102801`, `theme_ustar: 2026102700`, доступная
БД, действующие receiver/notify и успешный cron без новых ошибок.
Обновите вкладку студии Ctrl+F5. Внизу появится «Масштаб»50–150%; нажатие на процент
возвращает100%. «Экраны»/«Свойства» независимо скрывают и возвращают панели. Проверьте
изменение масштаба, редактирование, возврат панелей и сохранение после перезагрузки.

При прерывании установки используйте тот же файл с `--recover`. До публикации он
восстанавливает парную копию2800; после публикации завершает2801 и сохраняет новые
бизнес-записи. Журнал `/var/lib/ustar-studio-viewport-update` и блокировки не удалять.
`--restore` с подтверждением удаления записей после снимка не является обычным recovery.
Агент не подключается к production по SSH; финальная приёмка подтверждается выводом владельца.
