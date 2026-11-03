# Исправленный установщик SCORM —03Nov2026

Application `5e61c0d2566e8103d01121968d56852e26da99fa` прежний.
Новый оператор `4aee12968ceefe6189143f3c40f59976831f6513`, baseline4715383b.
Старый a8 завершился отказом до остановки web и замены файлов, последний owner
postcheck ready. Recovery и повтор a8 не нужны. Ошибка была в установщике:
PHP lint разбирал JS/XML. Новый отдельный оператор передаёт в PHP только три
PHP-файла из шести; повреждённый PHP по-прежнему блокирует установку до перерыва.

Новый оператор прошёл13 CI tests, включая реальные PHP stdin/short_open_tag=1,
actualUID33/root-group/xattrs, recovery и сохранность источников. Архив CI побайтово
совпал с независимой сборкой и скачанной сохранённой копией. Код приложения не
менялся: его квалификация обеих Moodle423tests/1957assertions, браузера и отката
сохраняется на точном app5e61. Повторный общий CI запущен автоматически; это отдельный
статус от уже завершённых13 проверок исправленного оператора.
[Квитанция](../context/runtime/studio_scorm_edit_lint_recovery_20261103.json).

## Установка владельцем

Положите новый `ustar-studio-scorm-edit-lint-4aee1296.tar.gz` в `/home/aduk/`.
Нужен короткий перерыв web. Схема, версии0200, worker267 и бизнес-данные сохраняются.

```bash
sudo bash <<'ROOT'
set -euo pipefail
umask 077
ustar_scorm_archive=/home/aduk/ustar-studio-scorm-edit-lint-4aee1296.tar.gz
ustar_scorm_dir=/root/ustar-studio-scorm-edit-lint-4aee1296
printf '%s  %s\n' \
  d558a90a1bfbe8952c8482567f63e53f15887b4d650afd90a650c4d680c654e2 \
  "$ustar_scorm_archive" | sha256sum --check --strict -
test ! -L "$ustar_scorm_dir"
install -d -o root -g root -m 700 "$ustar_scorm_dir"
tar --no-same-owner -xzf "$ustar_scorm_archive" -C "$ustar_scorm_dir"
cd "$ustar_scorm_dir"
sha256sum --check --strict SHA256SUMS
python3 -I -B update.py --check
systemd-run --no-block --unit=ustar-studio-scorm-edit-20261103-02 \
  --property=Type=oneshot --property=TimeoutStartSec=240 \
  /usr/bin/python3 -I -B "$ustar_scorm_dir/update.py" \
  --upgrade --acknowledge-outage
ROOT
```

После завершения отдельным блоком:

```bash
sudo systemctl show ustar-studio-scorm-edit-20261103-02.service \
  -p ActiveState -p SubState -p Result -p ExecMainStatus
sudo journalctl -u ustar-studio-scorm-edit-20261103-02.service -n 30 --no-pager
sudo python3 -I -B /root/ustar-studio-scorm-edit-lint-4aee1296/update.py --check
```

Ожидается Result=success, ExecMainStatus=0 и конечный phase=active.
Новый журнал: `/var/lib/ustar-studio-scorm-edit-lint-5e61c0d2566e/state.json`.
Старый failed unit и его каталог не удаляются; их история сохраняется.
`database_changed=true` при apply означает только служебный cache purge; бизнес-БД
не восстанавливается. Контрольный check readonly и возвращает false для изменений.
После active обновите вкладку и проверьте «Материалы → SCORM → Открыть в студии»,
импорт ZIP, изменение текста, сохранение и повторное открытие. Исходный материал,
его статус и история остаются на месте; публикация черновика выполняется отдельно.
