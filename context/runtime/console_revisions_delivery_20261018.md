# Доработка пульта: проверенный выпуск и установка

Исполняемый source: `1c881495ae78dd74cf37ca5a045944d1336d85ce`, [PR103](https://github.com/cauf1l3d/ustarlms/pull/103). Все три exact-source CI SUCCESS: [console39137246420](https://github.com/cauf1l3d/ustarlms/actions/runs/39137246420), [context39137246453](https://github.com/cauf1l3d/ustarlms/actions/runs/39137246453), [review39137246419](https://github.com/cauf1l3d/ustarlms/actions/runs/39137246419). Это проверка поставки; установка на server1 и приёмка устройств ещё не выполнены агентом.

Выпуск добавляет светлую/тёмную/системную тему, увеличение графиков, вкладки «Скрипты» и «Документация», реальные числовые request metrics и очищенные события служб. В каталоге27 описаний и43 документа с точными source SHA. Время и SHA файлов, измеренных на сервере, показываются отдельно от исходников. Кнопки четырёх существующих операций сохраняют план, роль, пароль/TOTP-if-enrolled и все проверки брокера; неизвестные скрипты не становятся исполняемыми.

355 backend PASS/1 skip на непривилегированном CI (локально root356PASS),114 Chromium PASS. Настоящие Ubuntu systemd/Xray/PG16/mount/TLS/update rollback fixtures PASS. Новый actual Apache fixture подтверждает запись в приватный каталог, два обычных запроса, исключение probe и частичное окно. Пропущенный ownership test заменяется реальной проверкой повторного экспорта публичного CA при native установке; production guards не ослаблены.577 context unit tests локально OK/20 environment skips.

Артефакт [12139739548](https://github.com/cauf1l3d/ustarlms/actions/runs/39137246420/artifacts/12139739548) скачан и проверен: ZIP SHA256/CRC, tar SHA256/SOURCE_COMMIT,23 Python-модуля wheel,4 font/license assets,3 compiled web assets,27 script hashes,43 doc hashes/content,10 observer modules и7 systemd units совпадают с exact source. ZIP514116Б SHA256 `94898041b6b5f4f3e62211624a0eb2f583f5fdddd317997d650201d3f11ae7a5`.

Внутри ZIP находятся `ustar-console.tar.gz` и его checksum. В чат выдан тот же tar под именем `ustar-console-1c881495.tar.gz`,513690Б, SHA256:

```text
0b7ecfa62fef556e39435125dee938467ccc96ee324392001f271598f01efaf5
```

## 1. Обновить работающий пульт и подключить числовой журнал

Скачать tar.gz из чата, через WinSCP положить в `/home/aduk/`. Если скачан GitHub ZIP, сначала извлечь из него tar.gz и назвать `ustar-console-1c881495.tar.gz`. Выполнить на server1 целиком:

```bash
sudo -v
sudo -n bash <<'USTAR_REVISION'
set -Eeuo pipefail
umask 077
USTAR_RELEASE=$(mktemp -d /root/ustar-console-revision-1c881495.XXXXXXXX)
printf 'RELEASE_DIRECTORY=%s\n' "$USTAR_RELEASE"
install -o root -g root -m 600 \
  /home/aduk/ustar-console-1c881495.tar.gz "$USTAR_RELEASE/release.tar.gz"
printf '%s  %s\n' \
  0b7ecfa62fef556e39435125dee938467ccc96ee324392001f271598f01efaf5 \
  "$USTAR_RELEASE/release.tar.gz" | sha256sum --check --strict -
tar --extract --gzip --no-same-owner \
  --file "$USTAR_RELEASE/release.tar.gz" --directory "$USTAR_RELEASE"
test "$(cat "$USTAR_RELEASE/ustar-console/SOURCE_COMMIT")" = \
  1c881495ae78dd74cf37ca5a045944d1336d85ce
bash "$USTAR_RELEASE/ustar-console/deploy/update.sh"
/opt/ustar-console/venv/bin/python \
  "$USTAR_RELEASE/ustar-console/tools/console_access.py" --repair-public-ca
install -o aduk -g "$(id -gn aduk)" -m 644 \
  /etc/ustar-console/ustar-console-ca.cer /home/aduk/ustar-console-ca.cer
/opt/ustar-console/venv/bin/python \
  "$USTAR_RELEASE/ustar-console/tools/configure_observability.py" --check
/opt/ustar-console/venv/bin/python \
  "$USTAR_RELEASE/ustar-console/tools/configure_observability.py" --install
systemctl start ustar-console-collect.service
/opt/ustar-console/venv/bin/ustar-console-accept
USTAR_REVISION
```

Ожидается checksum OK, `CONSOLE_UPDATE=PASS`, TLS `chain_and_IP_SAN=pass` и `live_certificate=matches_verified_leaf`, затем Apache `configured=true`. Updater кратко останавливает только службы пульта, сохраняет предыдущую venv и откатывается при своей ошибке. Нужен доступ к источнику hash-locked Python-зависимостей, как при первой установке. При queued/running job он отказывает: закончить штатную операцию, не удалять locks.

После `CONSOLE_UPDATE=PASS` последующие TLS/Apache-команды — отдельные шаги: их отказ не означает откат уже установленного пульта. Сохранить напечатанный `RELEASE_DIRECTORY`; при отказе прислать безопасный результат и остановиться на нём. Не ослаблять ownership guards и не передавать raw журналы/конфиги.

Apache helper проверяет единственный поддерживаемый vhost `ustar.local`, сохраняет backup, добавляет только numeric LogFormat/CustomLog/probe exclusion и выполняет configtest/graceful reload с rollback. Подробности: [request/log runbook](../../server-console/REQUEST_LOGS_20261018.md). Это не переключение Academy: containers, code, proxy/listeners/wwwroot не меняются. Пароль/token/proxy/IDs/TOTP/CA root, маршруты/DNS/firewall/VPN сохраняются. Setup повторно не запускать.

## 2. Убрать предупреждение HTTPS на своих устройствах

Через WinSCP забрать **только публичный** `/home/aduk/ustar-console-ca.cer`. Сохранить в «Загрузки». Сравнить SHA256 файла с `ca_sha256` из SSH-вывода. В обычном PowerShell:

```powershell
$ustarCA = "$env:USERPROFILE\Downloads\ustar-console-ca.cer"
Get-FileHash -Algorithm SHA256 $ustarCA
```

Только при совпадении:

```powershell
Import-Certificate -FilePath $ustarCA -CertStoreLocation Cert:\CurrentUser\Root
```

Полностью закрыть Chrome и открыть `https://192.168.1.60:8443` без обхода предупреждения. На iPhone установить тот же CA-профиль, затем Настройки → Основные → Об этом устройстве → Доверие сертификатам → USTAR Console LAN Root CA → полное доверие. Сначала проверить Safari, затем Mini App. Установка профиля и полное доверие — два отдельных действия; [инструкция Apple](https://support.apple.com/en-us/102390).

Закрытый `ca.key` не скачивать. Helper переэкспортирует существующий публичный CA, не меняет root/private keys. Ошибка chain/SAN/срока/live leaf требует локальной диагностики; переиздание CA или bypass не являются исправлением.

## 3. Проверить результат

Обновить страницу пульта, переключить все три темы, раскрыть график и проверить вкладки «Скрипты»/«Документация» на ПК и телефоне. Числовое окно —5мин реальных запросов. Открыть несколько страниц Академии, дождаться накопления окна и обновления сборщика. Неполное покрытие явно отмечено; пустой/старый источник остаётся неизвестным, а не нулём. Не создавать искусственную нагрузку на prod.

«Логи» показывают только очищенные категории проблем выбранных служб за5мин. Пустой список при доступном журнале означает отсутствие подходящих событий в окне. Независимый observer, внешний HTTP и restore/RTO не становятся измеренными этой установкой.

При включённом VPN проверить точный адрес в Safari. TLS-ошибка означает, что соединение дошло до HTTPS; timeout указывает на отдельную проверку маршрута. Название VPN-приложения и текущая сеть телефона пока не сообщены. Из домашней/мобильной сети обычный VPN-провайдер не создаёт доступ к офисному `192.168.1.60`. Нужен собственный приватный маршрут/контролируемый relay; текущий outgoing Telegram-only Xray его не даёт. До проверки на устройстве Mini App не принят. Графические карточки бота сохраняют отдельный исходящий канал и такого маршрута телефона не требуют.

INF-06 остаётся текущей до native/client результатов владельца; INF-07 — после неё. Агент не устанавливал этот пакет на server1, не менял сеть и не объявляет пульт принятым.
