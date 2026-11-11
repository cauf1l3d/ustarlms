# Библиотека: исправление выдачи и навигации — 04.11.2026

Пакет01 для установленного SCORM app5e61c0d2/tool4aee1296. Новый app719bf47c,
операторaa61d595. Local/theme остаются2026110200, схема/worker не меняются.
Шесть существующих addon файлов, полный manifest649; проверяется точное состояние
всего payload. Старые архивы и журналы не изменяются.

Исправляет: поиск назначений после500, ложный переход в скрытый Маркетплейс из
каталога и проверок, сброс раздела каталога при поиске, отсутствие предыдущей
страницы, неверное сообщение пустого поиска. Проверка ACL остаётся серверной;
доступные назначения/покупки и обязательный маршрут сохраняются.

Не включает U03/U04/U09/U10 админского редактора, общий N+1, Studio lookup,
Connect retention, privacy и инфраструктурные изменения. Подробнее:
[углублённый аудит](../context/reviews/20261104/DEEP_AUDIT_RU.md).

Архив: `ustar-library-discovery-aa61d595.tar.gz`,124077байта.
SHA256: `07ae14865da94e73f02bd314819f44a715166b156ec55174e9292754534fd666`.
Архив выдаётся владельцу после успешной квалификации, не является уже установленным.

Скопировать файл в `/home/aduk/`, выполнить в согласованное короткое окно:

```bash
sudo bash <<'ROOT'
set -euo pipefail
umask 077
ustar_fix_archive=/home/aduk/ustar-library-discovery-aa61d595.tar.gz
ustar_fix_dir=/root/ustar-library-discovery-aa61d595
printf '%s  %s\n' \
  07ae14865da94e73f02bd314819f44a715166b156ec55174e9292754534fd666 \
  "$ustar_fix_archive" | sha256sum --check --strict -
test ! -L "$ustar_fix_dir"
install -d -o root -g root -m 700 "$ustar_fix_dir"
tar --no-same-owner -xzf "$ustar_fix_archive" -C "$ustar_fix_dir"
cd "$ustar_fix_dir"
sha256sum --check --strict SHA256SUMS
python3 -I -B update.py --check
systemd-run --no-block --unit=ustar-library-discovery-20261104-01 \
  --property=Type=oneshot --property=TimeoutStartSec=240 \
  /usr/bin/python3 -I -B "$ustar_fix_dir/update.py" \
  --upgrade --acknowledge-outage
ROOT
```

Проверить отдельно, когда unit закончит работу:

```bash
sudo systemctl show ustar-library-discovery-20261104-01.service -p ActiveState -p SubState -p Result -p ExecMainStatus
sudo journalctl -u ustar-library-discovery-20261104-01.service -n 40 --no-pager
sudo python3 -I -B /root/ustar-library-discovery-aa61d595/update.py --check
```

Ожидается `Result=success`, `ExecMainStatus=0`, конечный `phase=active`.
`inactive/dead` после oneshot — нормально. Apply очищает кэши и кратко перезапускает
веб-контейнер; это может отразиться как `database_changed=true`, при этом
`business_data_changed=false` и `schema_changed=false`. Readonly check ничего не меняет.

Если `managed_operation_busy_retry_later`, не удалять lock-файлы: дождаться окончания
текущей управляемой операции, повторить check. При ином отказе или незавершённой
операции сохранить журнал/check; не запускать старый SCORM архив поверх нового.
Recovery умеет восстановить только source/metadata этого пакета без DB restore;
его запуск определять по фактической фазе, не применять как плановый второй шаг.

Ролевая приёмка: сотрудник закрытого отдела не видит ссылки в недоступный shop;
выданный материал открывается; после отзыва/истечения не открывается; поиск в
подразделе сохраняет его; предыдущая страница работает; пустой поиск сообщает об
отсутствии совпадений. Проверять501+ назначение на стенде, не создавать массовые
тестовые grants в production ради приёмки.
