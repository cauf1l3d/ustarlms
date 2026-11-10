# Установка пакета управления Библиотекой

Пакет f374d2d9 обновляет поиск материалов, сотрудников, проверок и истории назначений. Редактор поддерживает до 100 вопросов, сохраняет выбранные ответы при перестановке и восстанавливает введённые поля после отказа сохранения. [Инструкция автору](LIBRARY_MANAGEMENT_RU.md).

Установка требует точного состава исходников Studio Preview 2cb233d9 и его активного журнала. При несовпадении установщик остановится до перезапуска веба. В процессе обновления веб-контейнер кратковременно перезапустится и Moodle очистит кэши. Версии local/theme 2026110200, схема и данные обучения сохраняются.

[Квалификация](../context/runtime/library_management_qualification_20261110.json) включает браузерные и PostgreSQL проверки на Moodle 5.1.1 и 5.1.8.

## Установка на server1

Скачайте `ustar-library-management-f374d2d9.tar.gz` и перенесите в `/home/aduk/` с тем же именем.

```bash
sudo bash <<'ROOT'
set -euo pipefail
umask 077

ustar_management_archive=/home/aduk/ustar-library-management-f374d2d9.tar.gz
ustar_management_dir=/root/ustar-library-management-f374d2d9

printf '%s  %s\n' \
  edda60bf09f005e469678bb7e22428e7f2ac2d326bd98c20df4e2c1f8b4c1ea3 \
  "$ustar_management_archive" | sha256sum --check --strict -

test ! -L "$ustar_management_dir"
install -d -o root -g root -m 700 "$ustar_management_dir"
tar --no-same-owner -xzf "$ustar_management_archive" -C "$ustar_management_dir"

cd "$ustar_management_dir"
sha256sum --check --strict SHA256SUMS
python3 -I -B update.py --check

systemd-run --no-block --unit=ustar-library-management-20261110-01 \
  --property=Type=oneshot --property=TimeoutStartSec=240 \
  /usr/bin/python3 -I -B "$ustar_management_dir/update.py" \
  --upgrade --acknowledge-outage
ROOT
```

Когда служба завершится, проверьте результат.

```bash
sudo systemctl show ustar-library-management-20261110-01.service -p ActiveState -p SubState -p Result -p ExecMainStatus
sudo journalctl -u ustar-library-management-20261110-01.service -n 40 --no-pager
sudo python3 -I -B /root/ustar-library-management-f374d2d9/update.py --check
```

Ожидаются `Result=success`, `ExecMainStatus=0` и `phase=active`. Для завершившегося oneshot нормальны `inactive` и `dead`. При применении `database_changed=true` отражает очистку кэшей Moodle. Итоговая проверка только читает состояние и показывает `database_changed=false`.

При `managed_operation_busy_retry_later` дождитесь завершения текущей операции. При `previous_studio_preview_update_required`, `previous_studio_preview_update_not_active` или ошибке проверки исходников сохраните вывод проверки. Для восстановления после прерывания используйте журнал этого пакета и режим `--recover --acknowledge-outage`. Он восстанавливает исходники, права и метаданные файлов по журналу.

```bash
sudo python3 -I -B /root/ustar-library-management-f374d2d9/update.py --recover --acknowledge-outage
```

## Приёмка

1. Откройте «Библиотека → Управление». Найдите материал по части названия и старое назначение через поиск в истории. Откройте существующую карточку и убедитесь, что её материал отображается при поиске.
2. В «Редакторе проверок знаний» добавьте шесть вопросов, заполните ответы и сохраните. Переместите вопрос, удалите другой и снова сохраните. Откройте проверку в новой вкладке и сверьте порядок и правильные ответы.
3. Откройте курс с изображением в предпросмотре Студии. Сверьте отображение изображения с предыдущей приёмкой.

Передайте итоговый вывод `systemctl`, журнала и `--check` вместе с результатом приёмки.
