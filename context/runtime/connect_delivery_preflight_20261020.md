# Проверка контрактов следующей поставки chat-link

Cron принят владельцем20Oct20:38МСК; HTTPS принят на компьютере/телефоне/VPN.
Повторять их установку или старый installer02b0548c не нужно.

Новый профиль существующего preflight сравнивает только два addon с установленным
payload830/local1905 и candidate2000, читает версии/список таблиц в READ ONLY SQL,
проверяет точные hashes управляемых HTTPS-файлов и cron v3/c5eabdce,
расписание/свежий scheduled receipt, состояния служб, loopback8082 и свободный18443.
Docker gateway выводится как кандидат peer, не как проверенный HTTP peer.
Не читает ключи/токен, не выполняет PHP/Moodle bootstrap или Telegram запросы,
не запускает задания, не создаёт listener и не меняет файлы/сервисы.

Пакет собирается из exact Git, а не рабочей директории:

```bash
python3 scripts/infra/build_connect_readiness.py --commit HEAD --profile chat-link --output-dir artifacts/connect-delivery
```

Без `--check` zipapp выводит `plan_only` и не обращается к server1.
CI artifact `connect-chat-link-delivery-readiness` содержит pyz, SHA256 и metadata.
Точный source: `4d70de5cfe881458cb065ef066cc1ddcc88e214d`.
Пакет: `ustar-connect-delivery-4d70de5c.pyz`.
SHA256: `67d3d13acb0d800e7031e5f09550d7c73e6cc553305f0f71963d0674295b56b8`.
[Точная квитанция](connect_delivery_preflight_ci_20261020.json).
Context39470413912 и Review39470413976 SUCCESS для exact source4d70de5c.
Обе PHP8.2/8.3:835r16/135skips;runtime14tests/UID33 и Chromium PASS.
Native Connect124tests/629assertions,6concurrency,paired rollback и actual
Moodle HTTPS/Secure-cookie PASS. Полный RC не заявлен;команда не для HEAD.

Скачать этот pyz и перенести в `/home/aduk/` через привычный WinSCP/SFTP.
Промежуточная fda8ff35-сборка заменена после self-review прав config.php;
не менять права ради probe. Старый preflightef9911f3/installer02 не повторять.

```bash
sudo -v
sudo -n bash <<'USTAR_CONNECT_DELIVERY_CHECK'
set -Eeuo pipefail
umask 077
USTAR_CONNECT_CHECK=$(mktemp /root/ustar-connect-delivery.XXXXXXXX.pyz)
trap 'rm -f -- "$USTAR_CONNECT_CHECK"' EXIT
install -m 600 -o root -g root -- /home/aduk/ustar-connect-delivery-4d70de5c.pyz "$USTAR_CONNECT_CHECK"
printf '%s  %s\n' 67d3d13acb0d800e7031e5f09550d7c73e6cc553305f0f71963d0674295b56b8 "$USTAR_CONNECT_CHECK" | sha256sum --check --strict -
/usr/bin/python3 -I -B "$USTAR_CONNECT_CHECK" --check
USTAR_CONNECT_DELIVERY_CHECK
```

Вывод можно передать целиком:выбраны source/DB inventory и контракты,без
config.php,CA key или bot token. Исходные private state/config и полные журналы
передавать не нужно. SQL ограничен READ ONLY и statement/lock timeout;общий deadline90с.
Служебный temp-файл относится только к запуску probe и удаляется при выходе.

`delivery_baseline_measured` означает только совпадение исходного состояния.
Это не разрешение на deployment, не проверка живого bridge и не активация бота.
`review_required` содержит отдельные результаты baseline/runtime contracts и
фиксированную причину; не отключать guards, не менять права/state ради успеха.

В следующей поставке обязательны fresh paired backup/recovery, согласованный
cron1905→2000, явный HTTPS ownership handoff с сохранением renewal/rollback,
private bridge/public denial, отдельный link-only key и обновление единственного
receiver без потери token/journal/cursor. Notify/Mini App этим probe не включаются.
[Текущий ledger и границы](../proposals/USTAR_CONNECT_PRIVATE_DELIVERY_PROGRESS_20261020.md).
