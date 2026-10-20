# Подготовка HTTPS для привязки Академии и бота

Owner20Oct подтвердил работу обновлённой Академии и текущий адрес
`http://ustar.local`. Это отдельная зависимость от внешнего HTTPS для Mini App.
Привязка аккаунта и private bridge сохраняют TLS-проверки. Чатовый путь не требует
Main Mini App,но не разрешает передачу challenge или service credential по HTTP.

## Продолжение20Oct17:16МСК

[Owner report](connect_https_owner_20261020.json) получен:loopback8082 работает,
Apache/Caddy active,80/443/8443 заняты,18443 свободен. Origin parser unavailable
не подтверждает поломку Академии и не отменяет owner HTTP confirmation.
Владельцы443/8443 и effective Academy TLS ещё не определены. Console source из
PR103 использует собственный8443 и CA в `/etc/ustar-console/authority`;наличие
файлов не доказывает действительность сертификата/доверие клиентов.

Расширен тот же helper с `--check --details`:fixed parse-failure reason,allowlisted
process names/binding classes без IP/PID,static Academy virtual-host metadata,
default Caddyfile hints и CA metadata без чтения key/cert contents. Apache/Caddy
не запускаются для разбора;imports/env не исполняются. Это статические подсказки,
не полный разбор effective webserver configuration. Новая команда ниже использует
source10fc2677ef6abf40b7330346a53a2cf1a62210e0 и SHA256
994b5701fad01c155c13720d8d7eb16eeaf237423237ad0700c07a8c6c5dfbb2.
12targeted/771local r16 PASS(31explicit skips);проверяется только расширение
read-only инструмента,а не повторная приёмка Академии или новый TLS deployment.

```bash
sudo bash <<'ROOT'
set -euo pipefail
umask 077
ustar_https_probe=$(mktemp /root/ustar-connect-https.XXXXXXXX.py)
trap 'rm -f -- "$ustar_https_probe"' EXIT
curl -fsSL --connect-timeout 10 --max-time 60 \
  https://raw.githubusercontent.com/cauf1l3d/ustarlms/10fc2677ef6abf40b7330346a53a2cf1a62210e0/scripts/infra/connect_https_readiness.py \
  -o "$ustar_https_probe"
printf '%s  %s\n' 994b5701fad01c155c13720d8d7eb16eeaf237423237ad0700c07a8c6c5dfbb2 "$ustar_https_probe" | sha256sum --check --strict -
python3 -I -B "$ustar_https_probe" --check --details
ROOT
```

Передать только этот фильтрованный JSON. Для выбора существующего TLS listener
нужны `listeners.owners`,для origin refusal — `academy_origin.reason_code`.
Если статические virtual-host сведения недоступны,не разрешать изменение443 по
догадке. Старый блок ниже — история уже выполненной первичной проверки.

## Уже готово в коде,не активировано

- Receiver принимает `/start uc1_...` только из свежего личного сообщения Telegram,
  отдельный `linking_enabled=false` по умолчанию;Mini App может оставаться выключенным.
- Bridge key только с `operations=['link.claim']`;он не выдаёт actor/login/HR grants.
- В Академии `ustar_connect_link_transport='chat'` выбирает персональную start-ссылку;
  default miniapp сохраняется. Это не команда включения на текущем HTTP-сервере.
- После claim бот показывает реальный numeric ID,а владелец возвращается в ту же
  Academy-сессию и подтверждает связь. Пароли в чат не вводятся.
- Доставка уведомлений остаётся выключенной до HTTPS,связи и согласия пользователя.

## Выполненная первичная проверка

Запустить только `connect_https_readiness.py --check` из source
`1e8a8819f50785ebb4fb2640eeb9386c969d59d1`.
SHA256: `3678e9ac0058f9d4971aac62a7006d105f8b77962bc2c77f51b5c125f4d79ddd`.
[CI-квитанция](connect_chat_link_ci_20261020.json):Context39361024810/
Review39361024698 SUCCESS,766r16/124native/28link-browser;это не server acceptance.
Инструмент читает literal wwwroot
без PHP bootstrap,фильтрованные bind/port metadata `ustar_moodle`,состояние Apache/
Caddy и наличие listeners80/443/8443/18443. Он не выводит config.php,пароли,private
IP Docker,credentials,журналы или полную Docker inspect. Не выполняет SQL,запуск,
остановку,reload или сетевые настройки. Без --check выводится только план.

Результат `https_preparation_required` на нынешнем HTTP ожидаем. Даже обнаруженный
HTTPS-origin ещё не считается проверенным сертификатом или готовностью активации.
Не включать linking/onboarding/notifications по одному наличию свободного порта.

Команда ниже скачивает один helper в приватный временный файл `/root`,сверяет
точный hash,запускает только чтение и удаляет helper после выхода. Пароли и token
не запрашиваются инструментом;sudo может попросить обычный пароль администратора.

```bash
sudo bash <<'ROOT'
set -euo pipefail
umask 077
ustar_https_probe=$(mktemp /root/ustar-connect-https.XXXXXXXX.py)
trap 'rm -f -- "$ustar_https_probe"' EXIT
curl -fsSL --connect-timeout 10 --max-time 60 \
  https://raw.githubusercontent.com/cauf1l3d/ustarlms/1e8a8819f50785ebb4fb2640eeb9386c969d59d1/scripts/infra/connect_https_readiness.py \
  -o "$ustar_https_probe"
printf '%s  %s\n' 3678e9ac0058f9d4971aac62a7006d105f8b77962bc2c77f51b5c125f4d79ddd "$ustar_https_probe" | sha256sum --check --strict -
python3 -I -B "$ustar_https_probe" --check
ROOT
```

Вывести сюда только этот фильтрованный JSON-отчёт. По нему выбирается следующая
конкретная HTTPS-процедура;он не является командой установки или активации бота.

## Решение после отчёта

Подготовить конкретную схему internal HTTPS на существующем сервере,с отдельными
TLS/peer/scoped-key guards private bridge и проверкой Academy origin/cookies/
redirects. Сначала параллельная проверка сертификата и доступности;переключение
канонического origin только по отдельной reviewable процедуре с возвратом.
Сохранить HTTP-доступ до принятия HTTPS всеми нужными клиентами,не менять DNS,
маршруты,Xray,VPN или router в этой процедуре. Использовать уже занятый443 можно
только после проверки текущих virtual hosts;автоматически заменять ISPConfig/
другие сайты или занимать публичный listener нельзя. Выбор сертификата и доверие
на клиентах не подменяются ssl_verify=false или forwarded headers.

После принятия HTTPS:локально создать ограниченные ключи,сохранить их через
private files/LoadCredential,проверить link-only bridge,обновить receiver с
сохранением SQLite cursor,включить linking при onboarding=false,выполнить owner
claim+second confirmation/revoke. Затем отдельная проверочная доставка с новым
notify_since и opt-in;старую очередь массово не отправлять.

Новый чатовый candidate2026102000 ещё не установлен. Принятый installer02b0548c
содержит payload830/2026101905 и повторно для нового candidate не используется.
