# Изображения и шрифты в предпросмотре Студии —06.11.2026

Пакет app2cb233d9/tooled292313 исправляет ресурсы внутри изолированного Preview. Это дополнительное обновление поверх предыдущего ustar-studio-csp-f4d81940, app901ac9eb. Перед остановкой веба установщик сверяет полный source profile653files и active private CSP journal. Если предыдущий пакет отсутствует или есть другой код — отказ, без принудительного наложения.

Меняются только course_studio.php и course_studio/app.js. Local/theme2026110200 и108XMLDB tables прежние. Нужен краткий перезапуск веб-контейнера и purge caches; схемы, бизнес-данные, worker267 и core не меняются. Recovery возвращает только исходники/метаданные этого обновления и не откатывает новые учебные результаты.

[Квалификация](../context/runtime/studio_preview_qualification_20261106.json):4CI/11FullRC PASS на ed292313. Архив побайтно совпал с CI и сохранённой копией; server1 installation/functional acceptance ожидаются.

## Установка

Скачать ustar-studio-preview-ed292313.tar.gz из ссылки в чате и перенести на сервер в /home/aduk/ с тем же именем. SHA256:

```text
cf8c9b0b0f5fd6217cda3ed699fa8ea8f256e28c58764d7acb7fd87b7e1e5fb7
```

```bash
sudo bash <<'ROOT'
set -euo pipefail
umask 077

ustar_preview_archive=/home/aduk/ustar-studio-preview-ed292313.tar.gz
ustar_preview_dir=/root/ustar-studio-preview-ed292313

printf '%s  %s\n' \
  cf8c9b0b0f5fd6217cda3ed699fa8ea8f256e28c58764d7acb7fd87b7e1e5fb7 \
  "$ustar_preview_archive" | sha256sum --check --strict -

test ! -L "$ustar_preview_dir"
install -d -o root -g root -m 700 "$ustar_preview_dir"
tar --no-same-owner -xzf "$ustar_preview_archive" -C "$ustar_preview_dir"

cd "$ustar_preview_dir"
sha256sum --check --strict SHA256SUMS
python3 -I -B update.py --check

systemd-run --no-block --unit=ustar-studio-preview-20261106-01 \
  --property=Type=oneshot --property=TimeoutStartSec=240 \
  /usr/bin/python3 -I -B "$ustar_preview_dir/update.py" \
  --upgrade --acknowledge-outage
ROOT
```

После завершения:

```bash
sudo systemctl show ustar-studio-preview-20261106-01.service -p ActiveState -p SubState -p Result -p ExecMainStatus
sudo journalctl -u ustar-studio-preview-20261106-01.service -n 40 --no-pager
sudo python3 -I -B /root/ustar-studio-preview-ed292313/update.py --check
```

Норма: Result=success, ExecMainStatus=0, итоговый phase=active. Inactive/dead для завершившегося oneshot нормален. Readonly postcheck показывает database_changed/cache_purged=false; во время применения cache purge может дать database_changed=true — это не миграция или изменение учебных данных.

При managed_operation_busy_retry_later дождаться текущей операции; lock-файлы не удалять. При previous_studio_csp_update_required/not_active или несовпадении source сохранить вывод check/journal и сверить предыдущую установку; не накатывать старые архивы и не обходить guards. Если recovery_required или веб не восстановлен, сохранить журнал и использовать только recovery этого пакета:

```bash
sudo python3 -I -B /root/ustar-studio-preview-ed292313/update.py --recover --acknowledge-outage
```

Это аварийное действие по состоянию журнала, не обязательный шаг после успешной установки.

## Приёмка владельцем

1. Обновить вкладку Студии Ctrl+F5, открыть существующий курс с изображением, нажать «Предпросмотр», перейти на нужный экран. Изображения должны отображаться.
2. Если в курсе загружен свой шрифт, проверить его в Preview. Закрыть и снова открыть Preview; картинки в редакторе и Preview сохраняются.
3. Открыть консоль после обновления страницы. Новых Not allowed to load local resource blob: и прежних YUI EvalError быть не должно.
4. Изменить текст, сохранить, открыть курс в новой вкладке. При необходимости экспортировать SCORM и проверить курс с изображением обычным способом.

Передать вывод systemctl/journal/check и результат приёмки. Квалификация приложения и архива не означает фактическую установку на server1.
