# Telegram через подписку Happ/NOVAVPS

Owner18Oct02:07MSK получил `JSON_outbounds`:35 outbounds, из них15 VLESS,10 freedom,10 blackhole;10 gRPC/5 TCP,14 Reality/1 TLS, insecure flags0. Это классификация, не проверка параметров узлов или соединения. [Receipt](../context/runtime/console_vpn_format_20261018.json). URL/профили агенту не передавались.

Для server1 выбран Xray-core **v26.3.27 linux/amd64** из официального GitHub release. Архив SHA256 `23cd9af937744d97776ee35ecad4972cf4b2109d1e0fe6be9930467608f7c8ae`, извлечённый xray SHA256 `8255dd939c34cf966cc91517b6324dd3c8d0bcf49ffac8beca049a38c46845ed`; проверяются оба перед запуском. Это фиксированная версия, не скачивание latest при каждой установке. Дистрибутив и checksum проверены локально; не утверждать, что версия автоматически обновляется.

## Подготовка

Сначала применить проверенный console artifact, содержащий `telegram_transport.py` и `tools/telegram_egress.py`, через `deploy/update.sh` из root-owned staging directory. Выпуск567e90d5 и storage3305ecc9 этой возможности ещё не содержат. Updater сохраняет auth/CA/config и откатывает wheel при неуспехе; существующие console units не изменены. Новый helper не запускает полный setup и не меняет пароль владельца/TOTP.

Ниже `$USTAR_CONSOLE_STAGE` — тот же root-owned staging directory с распакованным проверенным artifact. Не подставлять user-writable checkout при запуске от root. В интерактивном SSH, без внешнего timeout:

```bash
sudo /opt/ustar-console/venv/bin/python \
  "$USTAR_CONSOLE_STAGE/ustar-console/tools/telegram_egress.py" install
```

После проверки/скачивания pinned binary появится `Subscription URL (hidden):`. Вставить полную ссылку локально и нажать Enter; символы не показываются. URL не сохраняется. GET bounded, same HTTPS origin/CA; fetch deadline30s после ввода. Перебираются до15 совместимых уникальных VLESS узлов, сначала TCP. DNS каждого кандидата ограничен12s, адрес закрепляется как public IPv4; private/reserved/loopback endpoints не принимаются. Временная проверка каждого узла работает с DynamicUser, LoadCredential и RuntimeMaxSec25s. Код/параметры provider profile не исполняются.

Копируются только клиентские VLESS/TCP/gRPC/Reality/TLS поля. Provider listeners, DNS, routing, log paths и API не импортируются. Certificate files, privateKey, reverse/dialerProxy/dependent chains, insecure TLS, иной transport/encryption отклоняются. Это ограниченный importer для наблюдаемого формата, не универсальный Happ converter. Неподдержанные кандидаты считаются и пропускаются. Если подходящего рабочего узла нет, постоянная служба не создаётся.

При успехе появится JSON с `telegram_https: verified`, `configuration_installed: true`, `service: active`. Проверка обращается к корню Telegram без bot token, не следует redirect и проверяет сертификат/hostname Telegram системными CA. Она не подтверждает авторизацию бота или доставку сообщения.

## Границы службы

- Только `127.0.0.1:18081`, HTTP CONNECT; правило разрешает `api.telegram.org:443/TCP`, default outbound blackhole. Другие домены, IP destinations и порты не получают этот маршрут.
- `ustar-telegram-egress.service` работает с DynamicUser, пустыми capabilities, NoNewPrivileges, ProtectSystem=strict, ProtectHome, PrivateDevices; MemoryMax128M/CPUQuota10%/TasksMax32. TUN, NET_ADMIN, forwarding и изменения host DNS/routes/firewall отсутствуют.
- `/etc/ustar-console-egress/xray.json` root0600, parent0700, передаётся через LoadCredential. Он содержит выбранные node credentials, поэтому не присылать его в чат/GitHub. Binary под `/opt/ustar-console-egress/v26.3.27/` доступен службе на исполнение; секретов там нет.
- Xray access/error logs отключены, stdout/stderr службы не публикуют remote parameters. Helper выводит только фиксированные причины и номера попыток. Нельзя публиковать raw profile или debug log.
- При неудачной первой установке удаляются только созданные этим запуском новая unit/config; Academy, console и другие services не останавливаются. Найденная существующая egress installation сохраняется: повторный install отказывает, для диагностики использовать check.
- Сохраняется один проверенный узел, runtime subscription refresh/автоматический failover не реализованы. После потери узла notifier сохраняет недоставленные сообщения в outbox. Перевыбор узла — отдельное обновление private config, не повторный full setup.

Проверка после установки:

```bash
/opt/ustar-console/venv/bin/python \
  /opt/ustar-console-egress/tools/telegram_egress.py check
sudo systemctl is-active ustar-telegram-egress.service
```

## Telegram ID и включение бота

Когда proxy проверен, получить свой numeric ID через private challenge. При активном notifier polling запускать диагностику следует отдельно, чтобы два poller не конкурировали.

```bash
/opt/ustar-console/venv/bin/python \
  /opt/ustar-console-egress/tools/telegram_egress.py telegram-id
```

Token вводится скрыто на сервере. Helper проверяет getMe и отсутствие webhook, показывает одноразовый текст `ustar-…`. Отправить его своему боту в личном чате, затем нажать Enter в SSH. Совпадать должны challenge text, private chat и from.id==chat.id; bot/forward исключены. Вывод только `TELEGRAM_ID=…`. Helper не сохраняет token и не подтверждает receiver ACK.

```bash
sudo /opt/ustar-console/venv/bin/ustar-console-setup --telegram-only --telegram-proxy
```

Ввести token и полученный numeric ID. Wizard сохраняет явный `proxy: http://127.0.0.1:18081` в root-private Telegram credential и перезапускает только notifier для перечитывания LoadCredential. Без `--telegram-proxy` остаётся прямой режим. Произвольный URL proxy и environment proxy/bypass не поддерживаются. HTTPS использует фиксированный CONNECT target и проверку сертификата/hostname Telegram, включая случай NO_PROXY=*; redirects не исполняются, токен и response bodies в error output не попадают.

Затем отправить `/start` и `/status` своему боту. Receiver ACK принимается только после фактического ответа владельцу. `ustar-console-accept` и notifier status — дополнительные checks, не замена ответу в Telegram. Доступ из дома к пульту на8443 настраивается отдельно через частный маршрут; эта служба даёт только исходящее соединение Bot API.

Источники: [официальный release](https://github.com/XTLS/Xray-core/releases/tag/v26.3.27), [release metadata/digests](https://api.github.com/repos/XTLS/Xray-core/releases/tags/v26.3.27), [HTTP inbound](https://xtls.github.io/en/config/inbounds/http.html), [VLESS](https://xtls.github.io/en/config/outbounds/vless.html), [transport](https://xtls.github.io/en/config/transport.html), [Python CONNECT/TLS](https://docs.python.org/3.12/library/http.client.html#http.client.HTTPConnection.set_tunnel).
