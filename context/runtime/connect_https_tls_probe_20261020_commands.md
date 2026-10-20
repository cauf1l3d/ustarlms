# Stage1: проверка внутреннего HTTPS Академии

## Текущая остановка20Oct18:29МСК

Owner --check:download SHA PASS,refused wildcard_TLS_binding_required. Это refusal
до сертификата/vhost/reload,не ошибка действующего Apache. Старый --prepare ниже
не запускать. Отсутствие wildcard443 не отличает IP-specific vhosts от отсутствия
443-vhosts. Ограничение нельзя просто удалить:нужна проверка прежнего default.
Один read-only report из установленного pinned helper,без raw адресов/конфигов:

```bash
sudo python3 - <<'PY'
import fnmatch
import importlib.util
import ipaddress
import json

path = '/usr/local/sbin/USTAR_ACADEMY_TLS_PROBE_20261020.py'
spec = importlib.util.spec_from_file_location('ustar_tls_readonly', path)
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
rows = []
for binding, hosts in m.Host().vhosts().items():
    address, port = binding.rsplit(':', 1)
    if port != '443':
        continue
    if address == '*':
        kind = 'wildcard'
    else:
        ip = ipaddress.ip_address(address.strip('[]'))
        kind = ('loopback_' if ip.is_loopback else 'specific_') + str(ip.version)
    matches = lambda host: any(fnmatch.fnmatchcase('ustar.local', name)
        for name in [host['name'], *host['aliases']])
    rows.append({'binding_type': kind, 'vhosts': len(hosts),
        'academy_name_matches': any(matches(host) for host in hosts),
        'default_matches_academy': matches(hosts[0])})
modules = m.command(['apache2ctl', '-M'], combined=True)
print(json.dumps({'read_only': True, 'bindings_443': rows,
    'ssl_module_loaded': b'ssl_module' in modules,
    'configuration_changed': False}, indent=2))
PY
```

После этого — topology-specific решение и regression/native test;не очередной
полный preflight,не установка receiver/Academy,не смена Listener/network. Внизу
предыдущий handoff52a8a1ca,его prepare на текущем сервере заблокирован prerequisite.


Источник:52a8a1ca965b12e577e0295d64420c2a3e9fcb67.
SHA256 скрипта:ff1a08f565339429367a2873bf69964aa37d5c47b40b405750cd0dbdc480d62c.
Готов к выполнению владельцем:Context39447539405 и runtime118713514071 SUCCESS;
обе PHP source jobs SUCCESS,792r16/134CI skips.
[Точная квитанция](connect_academy_tls_probe_ci_20261020.json). На production ещё не запускался.

Это подготовка отдельной страницы сертификата,не перенос Академии на HTTPS.
Скрипт использует действующую CA пульта локально:проверяет ключ/сертификат/срок,
выпускает DNS leaf30дней,создаёт один SNI vhost Apache и статическую страницу.
Другие URL этого SNI host403. Текущий `http://ustar.local`,Moodle config/БД/данные,
маршруты,DNS,firewall,Xray,Caddy и пульт не изменяются. Выполняется только graceful
reload Apache после configtest и сравнения всех прежних virtual hosts/default.
При ошибке — откат своих файлов/link и graceful reload,если применение начиналось.
Поменянные посторонним процессом owned files не удаляются по предположению.
Private CA/key/serial не перезаписываются,ключи не экспортируются.

## 1. Скачать закреплённый скрипт и проверить prerequisites

```bash
sudo -v
sudo bash <<'ROOT'
set -euo pipefail
umask 077
ustar_tls_tool=$(mktemp /root/ustar-academy-tls.XXXXXXXX.py)
trap 'rm -f -- "$ustar_tls_tool"' EXIT
curl -fsSL --connect-timeout 10 --max-time 60 \
  https://raw.githubusercontent.com/cauf1l3d/ustarlms/52a8a1ca965b12e577e0295d64420c2a3e9fcb67/scripts/infra/academy_tls_probe.py \
  -o "$ustar_tls_tool"
printf '%s  %s\n' \
  ff1a08f565339429367a2873bf69964aa37d5c47b40b405750cd0dbdc480d62c \
  "$ustar_tls_tool" | sha256sum --check --strict -
test ! -L /usr/local/sbin/USTAR_ACADEMY_TLS_PROBE_20261020.py
install -o root -g root -m 600 "$ustar_tls_tool" \
  /usr/local/sbin/USTAR_ACADEMY_TLS_PROBE_20261020.py
python3 -I -B /usr/local/sbin/USTAR_ACADEMY_TLS_PROBE_20261020.py --check
ROOT
```

Ожидается `phase: ready_for_TLS_probe`. Проверка config.php только по закреплённому
hash96058835...,без выполнения PHP или изменения wwwroot. Проверяется действующая
конфигурация Apache,отсутствие конфликта имени443,CA/ключ и active/MainPID служб.
Обычный HEAD к login может создавать штатную web-session/log. Если `refused` —
не продолжать применение,передать только JSON reason. Сырые конфиги/ключи не нужны.

## 2. Подготовить TLS-страницу

```bash
sudo python3 -I -B /usr/local/sbin/USTAR_ACADEMY_TLS_PROBE_20261020.py --prepare
```

Ожидается `phase: tls_probe_ready`,публичные fingerprints CA/leaf,срок leaf.
Проверяется TLS chain,hostname,SNI и точное содержимое страницы с127.0.0.1 без
обхода проверки сертификата,затем равенство прежнего HTTP status,config hash и PID.
Повторный `--prepare` при ready проверяет live результат без нового leaf/reload.
Обычно несколько секунд;команды/сокеты ограничены timeout. Не запускать второй
экземпляр. После прерывания/незавершённой квитанции использовать `--rollback`.

## 3. Проверить доверие браузера

На компьютере и телефоне в офисной сети открыть:

`https://ustar.local/__ustar_tls_probe__/ready.html`

Нужна страница «USTAR: проверка HTTPS» без предупреждения о сертификате. Проверить
с нужным VPN и без него. Не нажимать «продолжить несмотря на ошибку». Серверный PASS
проверяет реальный chain/SNI,но не доказывает trust/DNS/reachability каждого клиента.
Этот внутренний адрес не открывает Mini App из внешнего интернета.

## 4. Состояние и откат

```bash
sudo python3 -I -B /usr/local/sbin/USTAR_ACADEMY_TLS_PROBE_20261020.py --status
```

Это сохранённая квитанция,не новая live проверка. Откат при необходимости:

```bash
sudo python3 -I -B /usr/local/sbin/USTAR_ACADEMY_TLS_PROBE_20261020.py --rollback
```

Ожидается `rolled_back`. Private evidence/leaf/key остаются под0700 для разбора,
CA/config/данные не затрагиваются. После принятия browser trust следующий отдельный
этап — canonical HTTPS+стандартный Moodle sslproxy,HTTP redirect,постоянное продление
сертификата,loopback-only TLS bridge и credentials/link/opt-in notifications.
Не использовать старый installer02 для новой candidate2026102000.
