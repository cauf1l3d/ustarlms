# Быстрые ответы бота и проверка Mini App — 18 октября 2026

Владелец04:40МСК подтвердил, что команды обновления и кнопки работают. Остался вопрос задержки и доступа к Mini App; в11:49МСК продолжение возобновлено. Это owner report, без нового native module hash или измерения времени ответа. [Receipt](../context/runtime/console_telegram_latency_20261018.json).

## Какая сеть нужна Mini App

Нужен маршрут **с устройства, где открыт Telegram, к HTTPS-адресу пульта**. Физически находиться в офисе необязательно, если есть работающий частный доступ. Сейчас домашний VPN/маршрут ещё не установлен; для первой проверки проще использовать корпоративную сеть с доступом к server1. Если Telegram Desktop запущен на внутреннем ПК через RDP, важна сеть этого ПК; для Telegram на домашнем ПК — сеть домашнего ПК.

| Подключение устройства | Обычные ответы бота при доступном Telegram | Пульт внутри Telegram |
| --- | --- | --- |
| Корпоративная LAN/Wi-Fi с доступом к server1:8443 | Да | Проверить маршрут, сертификат и вход |
| Домашний интернет или мобильная сеть без частного маршрута | Да, пока server1 и его egress работают | Частный адрес пульта недоступен |
| Удалённое подключение с проверенным частным маршрутом | Да | Возможно после проверки HTTPS/WebView |

Кнопка Mini App открывает существующий пульт в WebView на устройстве. Установленный Xray предназначен для исходящих обращений серверного бота к Telegram; обратного туннеля к пульту он не создаёт. Новые порты, маршруты, DNS, firewall и TUN для этой проверки не настраиваются.

## Как проверить Mini App

1. В боте отправить `/panel` и взять фактический адрес. Для принятого LAN setup это `https://192.168.1.60:8443`.
2. На **том же телефоне или компьютере** открыть этот адрес в обычном браузере. Должен открыться вход в пульт без предупреждения о сертификате. Недоступный адрес означает, что сначала нужно обеспечить сетевой доступ. Предупреждение о сертификате означает отдельную настройку доверия CA.
3. При необходимости получить публичный сертификат для переноса через WinSCP:

```bash
sudo install -o aduk -g "$(id -gn aduk)" -m 644 \
  /etc/ustar-console/ustar-console-ca.crt /home/aduk/ustar-console-ca.crt
openssl x509 -in /home/aduk/ustar-console-ca.crt -noout -fingerprint -sha256
```

Перенести `ustar-console-ca.crt` на устройство и установить доверие именно своему CA; отпечаток доступен в выводе команды. На iPhone после установки профиля требуется вручную включить SSL/TLS-доверие: **Настройки → Основные → Об этом устройстве → Доверие сертификатам → USTAR Console LAN Root CA**. Это [официальное требование Apple](https://support.apple.com/en-us/102390). Для Android/Windows используется их хранилище доверенных CA. После установки снова проверить браузер.

4. На server1 включить нативную кнопку:

```bash
sudo /opt/ustar-console/venv/bin/ustar-console-setup --telegram-mini-app on
```

5. Для первой проверки использовать нативный Telegram на телефоне или Telegram Desktop. В личном чате боту отправить `/start`, нажать «🖥 Пульт». Пульт должен открыться **внутри Telegram**, показать существующий вход и после входа — реальные данные. Проверить обновление страницы и доступные роли действия. Telegram initData не используется для автоматического входа.
6. Если браузер работает, а WebView нет, это ещё не успешная приёмка Mini App: остаётся проверить ограничения конкретного клиента/доверие CA. Сеть сервера ради обхода этой ошибки не менять. Отключение кнопки:

```bash
sudo /opt/ustar-console/venv/bin/ustar-console-setup --telegram-mini-app off
```

После переключения снова `/start`. Меняется только opt-in и перезапускается notifier; token/proxy/IDs, пароль и CA сохраняются.

## Что исправлено в задержке

До исправления выполнялся short polling с `timeout=0`, затем фиксированная пауза10с. Это само добавляло до примерно10с ожидания в штатном цикле, помимо сети/отправки и очереди. В новом варианте `getUpdates(timeout=5)` ждёт сообщение на стороне Telegram и возвращается при его появлении; пять секунд — максимальное ожидание пустого запроса, не задержка каждого нажатия. Telegram рекомендует положительный timeout для [long polling](https://core.telegram.org/bots/api#getupdates).

После успешного цикла остаётся только yield0,2с; при ошибке polling сохраняется backoff10с. HTTP socket timeout8с, CA verification, CONNECT destination и service limits не менялись. Ожидающие алерты отправляются перед ожиданием. Снимок **и maintenance** читаются после него: плановый backup/restart, начавшийся во время long poll, не превращается в ложный инцидент. Ошибка приёма команд не останавливает оценку и отправку алертов.

Реальное время ответа на server1 ещё не измерено: TLS/Xray/Telegram, очередь и retries могут добавлять время. Сохраняется лимит4 команды за10с в личном чате; для оценки отклика нажимать по одной кнопке и ждать ответ.

## Установка исправления задержки

Источник: `4bb34f0b5f7f4e84a528f729c001597e9dc77950`. Все3 CI SUCCESS: [console39113400687](https://github.com/cauf1l3d/ustarlms/actions/runs/39113400687), context39113400739, review39113400704.230 backend/45 Chromium; actual Xray/PG16/LoadCredential0440/HDD namespace/TLS-broker/update rollback PASS. Artifact12131452167 скачан; ZIP digest, внутренний tar.gz/SHA256/SOURCE_COMMIT, все Python-модули wheel и observer.pyz сверены. Размер tar.gz209498B.

Используется уже проверенный updater полного wheel-пакета: не вводится отдельный root patch installer. Он сохраняет rollback venv и текущие credentials/CA/auth/очередь, отказывает при активной операции, кратко перезапускает службы пульта. Академия и host network не перенастраиваются.

Скачать приложенный `ustar-console-4bb34f0b.tar.gz`, через WinSCP положить в `/home/aduk/`, затем выполнить целиком:

```bash
sudo -v
sudo -n bash <<'USTAR_BOT_LATENCY'
set -Eeuo pipefail
umask 077
USTAR_RELEASE=$(mktemp -d /root/ustar-console-latency-4bb34f0b.XXXXXXXX)
install -o root -g root -m 600 \
  /home/aduk/ustar-console-4bb34f0b.tar.gz "$USTAR_RELEASE/release.tar.gz"
printf '%s  %s\n' 9b81f5e4c81ef21786a9c549db0a2123426bd296454220fa31963e7f4c9f3d35 \
  "$USTAR_RELEASE/release.tar.gz" | sha256sum --check --strict -
tar --extract --gzip --no-same-owner \
  --file "$USTAR_RELEASE/release.tar.gz" --directory "$USTAR_RELEASE"
test "$(cat "$USTAR_RELEASE/ustar-console/SOURCE_COMMIT")" = \
  4bb34f0b5f7f4e84a528f729c001597e9dc77950
bash "$USTAR_RELEASE/ustar-console/deploy/update.sh"
USTAR_BOT_LATENCY
```

Ожидается `CONSOLE_UPDATE=PASS`. Проверка:

```bash
sudo systemctl is-active ustar-console-notify.service ustar-telegram-egress.service
sudo /opt/ustar-console/venv/bin/ustar-console-accept
```

Затем `/start` и одна кнопка «📊 Сводка», дождаться ответа; отдельно «💻 Ресурсы». Сравнить отклик, затем при желании пройти Mini App шаги выше. Это owner native gate; локальные/CI fixtures не заменяют фактическое измерение и открытие в Telegram на устройстве.
