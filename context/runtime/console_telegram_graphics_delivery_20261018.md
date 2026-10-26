# Проверенный выпуск графических карточек — 18 октября 2026

Исполняемый source: `b93e16afdb6fc9dac87bd116a1e42fae69e6c9e3`, [PR103](https://github.com/cauf1l3d/ustarlms/pull/103). Все3 CI SUCCESS: [console39118681109](https://github.com/cauf1l3d/ustarlms/actions/runs/39118681109), [context39118681162](https://github.com/cauf1l3d/ustarlms/actions/runs/39118681162), [review39118681129](https://github.com/cauf1l3d/ustarlms/actions/runs/39118681129).

312 backend и72 Chromium сценария прошли. Настоящие Xray/systemd credentials, PG16 monitor, HDD namespace, LAN TLS/broker и updater с отсутствовавшим Pillow/rollback прошли Ubuntu CI. Независимое ревью: Critical0, Important1 исправлен тестами RED→GREEN (временные ошибки Telegram не должны удалять PNG), Minor0. Мобильные CI-скриншоты HDD/событий проверены визуально; числа на тестовых скриншотах синтетические.

[Artifact12133242265](https://github.com/cauf1l3d/ustarlms/actions/runs/39118681109/artifacts/12133242265) скачан и проверен: ZIP digest/CRC, tar.gz hash/SOURCE_COMMIT,21 Python module wheel,4 font/license assets,10 observer modules и7 unit-файлов соответствуют source. Tar.gz370481Б, SHA256:

```text
4319f5602a83672c82e31e605d370ce5bac99ea92e5fd34fc9951a19d13b2207
```

В чат выдан сам `ustar-console-b93e16af.tar.gz`; GitHub artifact — ZIP с тем же tar.gz и отдельным SHA256. Не устанавливать ZIP как tar.gz. Подробности изменений и оставшихся источников — [runbook](../../server-console/TELEGRAM_GRAPHICS_20261018.md), доказательства — [receipt](console_telegram_graphics_20261018.json).

## Обновление уже работающего пульта

Скачать приложенный `ustar-console-b93e16af.tar.gz`, перенести WinSCP в `/home/aduk/`. На server1 выполнить целиком:

```bash
sudo -v
sudo -n bash <<'USTAR_GRAPHICS'
set -Eeuo pipefail
umask 077
USTAR_RELEASE=$(mktemp -d /root/ustar-console-graphics-b93e16af.XXXXXXXX)
install -o root -g root -m 600 \
  /home/aduk/ustar-console-b93e16af.tar.gz "$USTAR_RELEASE/release.tar.gz"
printf '%s  %s\n' 4319f5602a83672c82e31e605d370ce5bac99ea92e5fd34fc9951a19d13b2207 \
  "$USTAR_RELEASE/release.tar.gz" | sha256sum --check --strict -
tar --extract --gzip --no-same-owner \
  --file "$USTAR_RELEASE/release.tar.gz" --directory "$USTAR_RELEASE"
test "$(cat "$USTAR_RELEASE/ustar-console/SOURCE_COMMIT")" = \
  b93e16afdb6fc9dac87bd116a1e42fae69e6c9e3
bash "$USTAR_RELEASE/ustar-console/deploy/update.sh"
USTAR_GRAPHICS
```

Ожидается `CONSOLE_UPDATE=PASS`. Updater кратко останавливает только службы пульта, устанавливает hash-locked зависимости и wheel, проверяет запуск и при ошибке восстанавливает предыдущую venv. Требуется доступ сервера к источнику Python-пакетов, как при первой установке. Если есть queued/running job, updater отказывает: закончить штатную операцию, не удалять lock или данные. `ustar-console-setup` повторно запускать не нужно; пароль, CA, token/proxy/IDs и пропущенный TOTP сохраняются. Academy, маршруты/DNS/firewall не перенастраиваются.

## Проверка

```bash
sudo systemctl is-active ustar-console-notify.service ustar-telegram-egress.service
sudo /opt/ustar-console/venv/bin/ustar-console-accept
sudo sha256sum /opt/ustar-console/venv/lib/python3.12/site-packages/ustar_console/alerts.py
```

Для `alerts.py` ожидается:

```text
3951d41acb6363590ed1114b36f7b84818d34e5214d4efba72c55debbb77ef7d
```

Обновить страницу пульта. В «Сервере»/«Бэкапах» должны быть ёмкость HDD и inode, в «Событиях» — свежие правила и датированная история. Новая история начинается с первых записанных после обновления событий; пустой список до перехода нормален и не означает отсутствия прошлых проблем.

В личном чате бота нажимать по одной кнопке: «Сводка», «Ресурсы», «Академия», «Бэкапы», «Стенды», «Алерты». Ожидаются PNG и подробный текст. В «Ресурсах» графики строятся из доступной истории сборщика; в пропусках линия разрывается. Проверить читаемость и время ответа на своём устройстве. Графические карточки работают через существующее исходящее соединение бота; маршрут телефона к LAN-пульту и Mini App для них не нужны.

## Что этой поставкой не принято

Агент не подключался к server1 и не устанавливал пакет. Native update, получение PNG, фактическое время ответа, текущие браузерные роли и SHA скачанного бэкапа подтверждаются владельцем. Независимый наблюдатель, внешний HTTP и restore/RTO требуют своих источников/проверки; код не объявляет их выполненными. Домашний доступ остаётся отдельным userspace/SSH pilot из [плана](../../server-console/HOME_ACCESS_PLAN_20261018.md).
