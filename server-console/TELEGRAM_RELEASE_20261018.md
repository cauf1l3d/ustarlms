# Обновление пульта и подключение Telegram — 18 октября 2026

Это завершение выпуска, на котором остановился агент: исходник **1f605e4dad9a88040146086bfbfbe48d842004d5**, PR103. Установлен на server1 пока567e90d5; новый пакет ещё не применён. Подробности проверки — [receipt](../context/runtime/console_telegram_release_20261018.json). Эта инструкция не устанавливает домашний VPN.

## 1. Передать проверенный пакет

Скачать приложенный в ответе `ustar-console-1f605e4d.tar.gz` и через WinSCP положить в `/home/aduk/` с этим именем. Это внутренний tar.gz из CI artifact12118052115, не ZIP-обёртка GitHub. Размер190969B, SHA256:

```text
377eb5640d8c362b7aa8aab299d39ae30780c659f13deb0bde3e62d6934fc109
```

[CI39079252673](https://github.com/cauf1l3d/ustarlms/actions/runs/39079252673) завершился успешно:175 backend,45 Chromium, реальный Xray с DynamicUser/LoadCredential/запретом посторонних адресов, PostgreSQL monitor privileges, namespace HDD и updater rollback. Также успешны context39079252684/review39079252801. Агент скачал artifact, сверил digest ZIP, внутренний tar.gz/hash/SOURCE_COMMIT и состав. Это не результат проверки узлов подписки с server1.

## 2. Обновить установленный пульт

В SSH под `aduk` выполнить весь блок. Копия пакета сначала попадает в новый приватный каталог root и проверяется там. При несовпадении суммы распаковка/обновление не выполняются. Занятый каталог означает предыдущую попытку: не удалять его и не повторять блок вслепую, сначала прочитать её результат.

```bash
sudo -v
sudo -n bash <<'USTAR_CONSOLE_UPDATE'
set -Eeuo pipefail
umask 077
USTAR_RELEASE=/root/ustar-console-egress-1f605e4d
test ! -e "$USTAR_RELEASE"
test ! -L "$USTAR_RELEASE"
install -d -o root -g root -m 700 "$USTAR_RELEASE"
install -o root -g root -m 600 \
  /home/aduk/ustar-console-1f605e4d.tar.gz "$USTAR_RELEASE/release.tar.gz"
printf '%s  %s\n' \
  377eb5640d8c362b7aa8aab299d39ae30780c659f13deb0bde3e62d6934fc109 \
  "$USTAR_RELEASE/release.tar.gz" | sha256sum --check --strict -
tar --extract --gzip --no-same-owner \
  --file "$USTAR_RELEASE/release.tar.gz" --directory "$USTAR_RELEASE"
test "$(cat "$USTAR_RELEASE/ustar-console/SOURCE_COMMIT")" = \
  1f605e4dad9a88040146086bfbfbe48d842004d5
bash "$USTAR_RELEASE/ustar-console/deploy/update.sh"
USTAR_CONSOLE_UPDATE
```

Продолжать после `CONSOLE_UPDATE=PASS`. Updater кратко останавливает только пульт, сохраняет auth/CA/config и состояния служб, отказывает при queued/running jobs, откатывает прежний venv при неуспехе. Moodle, контейнеры Академии, её cron и настройки сети не меняет. Включены fixes HDD/SMART и startup Telegram. Повторять полный setup или старый точечный hotfix не требуется. Выведенный предыдущий venv сохранить до приёмки.

## 3. Установить Telegram-only Xray

Следующая команда — отдельно в обычном интерактивном SSH, не внутри heredoc/внешнего timeout:

```bash
sudo /opt/ustar-console/venv/bin/python \
  /root/ustar-console-egress-1f605e4d/ustar-console/tools/telegram_egress.py install
```

Helper скачает официальный pinned Xrayv26.3.27 linux/amd64, проверит ZIP/binary SHA256, затем запросит `Subscription URL (hidden):`. Вставить свою полную Happ/NOVAVPS ссылку локально и Enter. Ссылка не сохраняется. Поддерживаются secure VLESS TCP/gRPC с Reality/TLS. До15 кандидатов проверяются по очереди; выводятся только номера попыток. Это может занять несколько минут. Не передавать ссылку/профили агенту.

Успех: `telegram_https: verified`, `configuration_installed: true`, `service: active`. Служба слушает только127.0.0.1:18081; разрешён только api.telegram.org:443/TCP. TUN, host routes/DNS/firewall, глобальный proxy, входящие порты и IP forwarding не настраиваются. HTTPS Telegram сохраняет проверку CA и имени внутри CONNECT. При отсутствии рабочего узла постоянная служба не создаётся. При существующей установке повторный install отказывает, её состояние сохраняется. Один выбранный узел; автоматической смены/обновления подписки нет.

## 4. Получить ID, подключить бота и проверить ответ

До включения notifier получить ID через своего отдельного бота:

```bash
/opt/ustar-console/venv/bin/python \
  /opt/ustar-console-egress/tools/telegram_egress.py telegram-id
```

Ввести token скрыто. Helper покажет одноразовый текст `ustar-…`: отправить его своему боту в личном чате, затем Enter в SSH. Выводится `TELEGRAM_ID=…`. Existing webhook не удаляется; второй poller не запускать. Helper смотрит первые100 ожидающих updates без их удаления: если бот уже имеет большую очередь, новый challenge может не попасть в ответ. Повтор команды не очищает очередь; для этого пути нужен отдельный свежий бот, либо отдельная согласованная диагностика очереди. Если notifier уже работает, ID helper с ним одновременно не использовать.

```bash
sudo /opt/ustar-console/venv/bin/ustar-console-setup --telegram-only --telegram-proxy
```

Ввести token и полученный numeric ID. Wizard сохраняет proxy в приватном credential и перезапускает только notifier; пароль/CA/TOTP владельца не переустанавливает. Затем отправить боту `/start`, через10секунд `/status`. Фактический ответ в личном чате — обязательная приёмка доставки.

```bash
/opt/ustar-console/venv/bin/python \
  /opt/ustar-console-egress/tools/telegram_egress.py check
sudo systemctl is-active ustar-telegram-egress.service ustar-console-notify.service
sudo /opt/ustar-console/venv/bin/ustar-console-accept
```

Для продолжения достаточно прислать `CONSOLE_UPDATE`, безопасный JSON helper/check, выбранные строки acceptance и факт ответа `/status`. Token, subscription URL, xray.json, credentials и raw private logs не присылать. Active/HTTPS/API ACK сами по себе не доказывают, что сообщение получил владелец.

Домашний доступ — [следующий отдельный этап](HOME_ACCESS_PLAN_20261018.md). Бот может работать через исходящие соединения, когда из дома ещё нет доступа к ссылкам пульта.
