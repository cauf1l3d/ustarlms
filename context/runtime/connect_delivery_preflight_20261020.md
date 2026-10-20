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
Финальный exact source/hash и owner-команда добавляются после проверки исправления
metadata compatibility. Не использовать промежуточную fda8ff35-сборку: она могла
ошибочно отказать при законном UID33 для config.php. Не менять права ради probe.
Новый пакет будет опубликован в CI artifact `connect-chat-link-delivery-readiness`.
Старый preflightef9911f3/installer02 повторно не запускать.

`delivery_baseline_measured` означает только совпадение исходного состояния.
Это не разрешение на deployment, не проверка живого bridge и не активация бота.
`review_required` содержит отдельные результаты baseline/runtime contracts и
фиксированную причину; не отключать guards, не менять права/state ради успеха.

В следующей поставке обязательны fresh paired backup/recovery, согласованный
cron1905→2000, явный HTTPS ownership handoff с сохранением renewal/rollback,
private bridge/public denial, отдельный link-only key и обновление единственного
receiver без потери token/journal/cursor. Notify/Mini App этим probe не включаются.
[Текущий ledger и границы](../proposals/USTAR_CONNECT_PRIVATE_DELIVERY_PROGRESS_20261020.md).
