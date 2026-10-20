# Подготовка HTTPS для привязки Академии и бота

Owner20Oct подтвердил работу обновлённой Академии и текущий адрес
`http://ustar.local`. Это отдельная зависимость от внешнего HTTPS для Mini App.
Привязка аккаунта и private bridge сохраняют TLS-проверки. Чатовый путь не требует
Main Mini App,но не разрешает передачу challenge или service credential по HTTP.

## Текущий этап после owner17:43МСК

Narrow followup получен:80/443 — Apache,8443 — пульт. Все флаги RHS wwwroot
ложные;это непрямое выражение,не повод менять рабочий HTTP config.
Дальше [stage1 plan](../proposals/USTAR_ACADEMY_TLS_PROBE_PLAN_20261020.md) и
[verification ledger](../proposals/USTAR_ACADEMY_TLS_PROBE_PROGRESS_20261020.md):
отдельный DNS leaf30дней,одна additive Apache443 SNI site и только static health.
`--check` проверяет prerequisites без изменений конфигурации;делает обычный HEAD
к текущему login URL. `--prepare` сохраняет private receipt,выпускает leaf локально,
проверяет config/vhost/default/PIDs/HTTP/TLS и при ошибке снимает только свои изменения.
`--status` читает сохранённую квитанцию,это не новая live-проверка.
`--rollback` удаляет свой site/link/неизменённый health и делает graceful reload,
не трогая чужие vhosts,CA,config.php или private evidence.
В native CI ещё нужно проверить actual Apache до выдачи pinned apply command.
Обычный runtime — несколько секунд;crypto/host probes имеют ограниченные timeout.

URL: `https://ustar.local/__ustar_tls_probe__/ready.html`. Остальные пути нового
SNI site403;Академия пока на HTTP. Не обходить ошибку сертификата в браузере.
Leaf временный:renewal и production HTTPS cutover обязательны отдельным этапом;
private bridge и bot activation ещё не выполнены. CA key остаётся на сервере,
ни CA/key,ни serial-файлы не перезаписываются. Это внутреннее доверие,не публичный
HTTPS для Mini App вне офиса. Следующие блоки — датированная история,не повторять.

## Продолжение20Oct17:32МСК

Расширенный отчёт получен:Apache Academy vhost только80,proxy8082,без TLS,
с Include;default Caddyfile не содержит Academy name/upstream. `origin_expression`
— RHS wwwroot не literal,поэтому его нельзя выполнять или молча заменять.
CA/signing-key files пульта присутствуют с защищёнными metadata,содержимое не читалось.
Это не проверка срока CA,совпадения key/cert или доверия на клиенте.

Процессные labels снова `other`:allowlist kernel process names слишком узок.
Не делать из этого вывод о владельце443. Следующий короткий read-only блок
сопоставляет kernel PID→systemd cgroup и выводит только выбранные unit labels/
короткие kernel comm,без PID/IP/argv;из wwwroot только признаки выражения и hash.
Он не выполняет PHP,не печатает RHS и не изменяет конфигурации. Этот шаг заменяет
повторение уже выполненных больших probe. До определения TLS owner и review
wwwroot нет команды изменения shared443 или canonical origin.

```bash
sudo python3 - <<'PY'
import hashlib, json, re, subprocess
from pathlib import Path
result = {'read_only': True, 'services_changed': False}
try:
    raw = subprocess.run(['ss', '-H', '-ltnp'], capture_output=True,
                         text=True, timeout=10, check=True).stdout
    if len(raw) > 131072:
        raise ValueError()
    rows = []
    known = {'apache2.service', 'caddy.service', 'nginx.service', 'ustar-console.service'}
    for line in raw.splitlines():
        fields = line.split()
        if len(fields) < 5 or fields[3].rsplit(':', 1)[-1] not in {'80', '443', '8443'}:
            continue
        units = set()
        for pid in re.findall(r'pid=([0-9]{1,10})', line):
            try:
                group = Path('/proc', pid, 'cgroup').read_text()
                units.update(part for part in re.split(r'[/\n:]', group) if part in known)
            except OSError:
                pass
        names = re.findall(r'\("([A-Za-z][A-Za-z0-9_.-]{0,15})",pid=', line)
        rows.append({'port': int(fields[3].rsplit(':', 1)[-1]),
                     'units': sorted(units), 'kernel_process_names': sorted(set(names))})
    result['listeners'] = rows
except Exception:
    result['listeners'] = {'status': 'unavailable'}
try:
    path = Path('/opt/ustar/data/moodle/public/config.php')
    if path.stat().st_size > 131072 or any(p.is_symlink() for p in (path, *path.parents)):
        raise ValueError()
    data = path.read_bytes()
    if len(data) > 131072:
        raise ValueError()
    values = re.findall(rb'(?m)^\s*\$CFG->wwwroot\s*=\s*([^;\n]+);', data)
    value = values[0] if len(values) == 1 else b''
    result['wwwroot_features'] = {'assignment_count': len(values),
        'uses_SERVER': b'$_SERVER' in value, 'uses_ENV': b'$_ENV' in value,
        'uses_getenv': bool(re.search(rb'\bgetenv\s*\(', value)),
        'mentions_HTTP_HOST': b'HTTP_HOST' in value, 'mentions_HTTPS': b'HTTPS' in value,
        'contains_http_literal': b'http://' in value, 'contains_https_literal': b'https://' in value,
        'config_sha256': hashlib.sha256(data).hexdigest()}
except Exception:
    result['wwwroot_features'] = {'status': 'unavailable'}
print(json.dumps(result, ensure_ascii=False, indent=2))
PY
```

Подготовка TLS должна сохранить HTTP на время проверки сертификата и клиента,
не заменять unknown shared listener и не менять маршруты/DNS/Xray. Отдельный
loopback18443 допустим как предварительная проверка TLS,но сам по себе не является
HTTPS Академии или запуском бота. Реальная Academy TLS activation требует
проверенного webserver owner,canonical origin/cookies/redirects и private bridge
TLS contract;никаких ssl_verify=false или доверия произвольным forwarded headers.

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
