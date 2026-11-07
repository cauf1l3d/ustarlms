# Редактирование SCORM —03Nov2026

**Установщик a8d44b20 заменён:** owner получил отказ до замены файлов; PHP lint
ошибочно разбирает JS/XML. Команды ниже — история, повторять их не нужно.
Исправленный пакет: [актуальная инструкция](STUDIO_SCORM_EDIT_LINT_INSTALL_RU.md).

Application `5e61c0d2566e8103d01121968d56852e26da99fa`.
Immutable operator `a8d44b206154343f49627f8b773f2633b2cd8802`.
Baseline: принятый marketplace4715383b/tool59f и его active journal.
Поставка заменяет шесть существующих файлов из649; версии0200, схема108таблиц и
worker267 сохраняются. Перезапуск web/очистка кеша требуют короткого перерыва.
`database_changed=true` при apply означает служебный кеш, бизнес-данные не восстанавливаются.
Все4 CI и8 FullRC jobs на точном toola8d44b20 SUCCESS; обе Moodle423tests/1957assertions.
Source PHP8.2/8.3, Chromium, native PostgreSQL, rollback и operator UID33 PASS.
Архив совпал побайтово с независимой сборкой и скачанной сохранённой копией.
Установка владельцем пока не подтверждена.
[Точная квитанция](../context/runtime/studio_scorm_edit_20261103.json).

## Установка владельцем

Загрузите `ustar-studio-scorm-edit-a8d44b20.tar.gz` в `/home/aduk/`.
Не повторяйте старые обновления. Новый precheck требует точный установленный4715383b;
любое отличие или чужая maintenance/незавершённый журнал блокируют применение.

```bash
sudo bash <<'ROOT'
set -euo pipefail
umask 077
ustar_scorm_archive=/home/aduk/ustar-studio-scorm-edit-a8d44b20.tar.gz
ustar_scorm_dir=/root/ustar-studio-scorm-edit-a8d44b20
printf '%s  %s\n' \
  6baaab0d91bdbfac683fc95e3a8cabb0ca09bafd12d4d8e59c7ef92cbcba4ded \
  "$ustar_scorm_archive" | sha256sum --check --strict -
test ! -L "$ustar_scorm_dir"
install -d -o root -g root -m 700 "$ustar_scorm_dir"
tar --no-same-owner -xzf "$ustar_scorm_archive" -C "$ustar_scorm_dir"
cd "$ustar_scorm_dir"
sha256sum --check --strict SHA256SUMS
python3 -I -B update.py --check
systemd-run --no-block --unit=ustar-studio-scorm-edit-20261103-01 \
  --property=Type=oneshot --property=TimeoutStartSec=240 \
  /usr/bin/python3 -I -B "$ustar_scorm_dir/update.py" \
  --upgrade --acknowledge-outage
ROOT
```

Затем отдельным блоком:

```bash
sudo systemctl show ustar-studio-scorm-edit-20261103-01.service \
  -p ActiveState -p SubState -p Result -p ExecMainStatus
sudo journalctl -u ustar-studio-scorm-edit-20261103-01.service -n 30 --no-pager
sudo python3 -I -B /root/ustar-studio-scorm-edit-a8d44b20/update.py --check
```

Ожидается `Result=success`, `ExecMainStatus=0`, конечный `phase=active`.
`inactive/dead` нормален после завершения oneshot. Контрольный `--check` readonly,
поля business_data_changed/schema_changed/worker_changed/database_changed=false.
Новый private journal: `/var/lib/ustar-studio-scorm-edit-5e61c0d2566e/state.json`.
Повторный apply идемпотентен. Recovery возвращает только исходники этого пакета,
не откатывая результаты обучения; при прерванной операции сначала смотрите её журнал.

## Работа и приёмка

Автор: «Материалы» → существующий SCORM, включая архивный → «Открыть в студии».
Для публикации/архива создаётся отдельный общий черновик, повторное открытие ведёт
в него. Действующая активность, статус и попытки сохраняются. Сотрудникам права
автора не выдаются. Для файла: «Файл» → импорт SCORM ZIP; внешнюю ZIP-обёртку также
можно выбрать целиком. Проверьте экраны, текст/изображения, предпросмотр, измените
текст, сохраните и откройте повторно; экспортируйте и импортируйте SCORM обратно.
Новый черновик публикуется штатным процессом, без автоматической подмены маршрута.
После установки обновите вкладку студии; URL ресурсов учитывает хеш нового model.js.

Поддержаны USTAR-исходники и статические HTML-курсы. Для стороннего курса на
скриптах/Canvas, форм, видео и проприетарных интерактивных объектов требуется
исходный проект конструктора. Скрипты импортированного пакета в редакторе не запускаются.
Приложенный владельцем курс:20 экранов/82 изображения, полный текст и байты сохранены.
