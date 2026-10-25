# Caddy2019: следующий metadata-only срез25Oct2026

**Текущее решение25Oct:** SSH hardening/key diagnostics отложены владельцем до
внешнего доступа; LAN login/password принят. Следующий [Docker origin metadata](infra_docker_origin_20261025.md).
Прежние next-step ссылки ниже — история; не повторять SSH/Caddy/task probes или apply.

**Owner output accepted25Oct; do not repeat this command.** IPv4 loopback API and
separate IPv6 wildcard HTTP static_response classified, caddy.service/config path
and container ports received. [Receipt](infra_closure_followup_owner_20261025.json#port2019).
No evidence of externally exposed admin API from this listener. No Caddy change
applied. Next: [fresh workstation SSH-key connection](infra_ssh_key_access_20261025.md).
The rationale and command below are preserved history.

Owner подтверждает обе TCP записи2019 за caddy/PID862. Назначение wildcard socket
ещё не установлено: admin endpoint, отдельный HTTP/layer4 server или remote admin.
Предыдущие unit/docker команды потерялись при вставке; standalone --format failed,
это не свидетельство Docker failure. Cron retry rows уже получены: обе disabled1,
старые last runs; SQL/task/cron recovery больше не повторять.

Ниже один compound shell command с heredoc, который Bash собирает до sudo prompt.
Children получают DEVNULL stdin. Только metadata commands и один loopback GET;
нет config writes/reload/POST/PATCH/PUT/DELETE, запуска tasks или provider sends.
JSON читается в RAM до1MiB; raw config/body/stderr/auth/Env не выводятся. Выборка
только unit fields/config path, family-specific sockets, container ports и allowlist
admin/listen, remote/listen, server listen, handler types/proxy dial addresses.
Unknown values/types/addresses не отражаются как raw values. HTTP redirects не идут.

```bash
sudo -v && sudo -n timeout --kill-after=3s 50s /usr/bin/python3 -I -B - <<'PY'
import http.client, json, re, subprocess

def run(argv):
    p = subprocess.run(argv, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL, timeout=8,
        env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LC_ALL': 'C'})
    if len(p.stdout) > 65536:
        raise ValueError('metadata_output_bound')
    return p.returncode, p.stdout.decode('utf-8', errors='replace')

def address(value):
    if not isinstance(value, str) or len(value) > 200:
        return None
    if re.fullmatch(r'(?:tcp[46]?/)?[A-Za-z0-9_.:\[\]*-]*:[0-9]{1,5}', value):
        return value
    if re.fullmatch(r'unix/[A-Za-z0-9_./-]+', value):
        return value
    return None

def unit_metadata(text):
    props = dict(row.split('=', 1) for row in text.splitlines() if '=' in row)
    start = props.get('ExecStart', '')
    result = {k: props[k] for k in ('MainPID', 'User', 'Group', 'SupplementaryGroups',
        'FragmentPath', 'WorkingDirectory', 'ControlGroup') if k in props}
    match = re.search(r'--config[= ]+(/[A-Za-z0-9_./-]+)(?=[ ;}]|$)', start)
    result['config_file_arg'] = match.group(1) if match else None
    result['resume_arg_present'] = bool(re.search(r'(?:^| )--resume(?: |;|$)', start))
    return result

def projection(config):
    admin = config.get('admin') or {}
    result = {'admin_listen': address(admin.get('listen')),
        'admin_listen_explicit': 'listen' in admin,
        'remote_admin_listen': address((admin.get('remote') or {}).get('listen')),
        'servers': []}
    def routes(items, handlers, dials, depth=0):
        if not isinstance(items, list) or depth > 8:
            return
        for route in items[:200]:
            for item in route.get('handle', [])[:100]:
                kind = item.get('handler', '')
                if re.fullmatch(r'[a-zA-Z0-9_.-]{1,80}', kind):
                    handlers.add(kind)
                if kind == 'reverse_proxy':
                    for upstream in item.get('upstreams', [])[:100]:
                        dial = address(upstream.get('dial'))
                        if dial:
                            dials.add(dial)
                if kind == 'subroute':
                    routes(item.get('routes', []), handlers, dials, depth+1)
    for appname in ('http', 'layer4'):
        servers = ((config.get('apps') or {}).get(appname) or {}).get('servers') or {}
        for server in list(servers.values())[:32]:
            listeners = server.get('listen') or []
            handlers, dials = set(), set()
            routes(server.get('routes', []), handlers, dials)
            result['servers'].append({'app': appname,
                'listen': [v for v in map(address, listeners) if v],
                'handlers': sorted(handlers), 'proxy_dials': sorted(dials)})
    return result

commands = [
    ('sockets_ipv4', ['/usr/bin/ss', '-H', '-lntp', '-4', 'sport = :2019']),
    ('sockets_ipv6', ['/usr/bin/ss', '-H', '-lntp', '-6', 'sport = :2019']),
    ('unit', ['/usr/bin/systemctl', 'show', 'caddy.service', '-p', 'MainPID',
        '-p', 'User', '-p', 'Group', '-p', 'SupplementaryGroups', '-p', 'FragmentPath',
        '-p', 'WorkingDirectory', '-p', 'ControlGroup', '-p', 'ExecStart']),
    ('container_ports', ['/usr/bin/docker', 'ps', '--format', '{{.Names}}\t{{.Ports}}'])]
caddy_observed = False
for label, argv in commands:
    try:
        code, text = run(argv)
        if label.startswith('sockets_') and code == 0 and '("caddy",pid=' in text:
            caddy_observed = True
        value = unit_metadata(text) if label == 'unit' else text.splitlines()
        print(json.dumps({label: value, 'returncode': code}), flush=True)
    except Exception as error:
        print(json.dumps({label: {'error_type': type(error).__name__}}), flush=True)

connection = None
try:
    if not caddy_observed:
        raise ValueError('caddy_socket_owner_not_confirmed')
    connection = http.client.HTTPConnection('127.0.0.1', 2019, timeout=4)
    connection.request('GET', '/config/', headers={'Host': 'localhost:2019',
        'Accept': 'application/json', 'Connection': 'close'})
    response = connection.getresponse()
    if response.status != 200:
        print(json.dumps({'live_config_metadata': {'http_status': response.status}}))
    else:
        body = response.read(1048577)
        if len(body) > 1048576:
            raise ValueError('config_size_bound')
        print(json.dumps({'live_config_metadata': projection(json.loads(body))}))
except Exception as error:
    print(json.dumps({'live_config_metadata': {'error_type': type(error).__name__}}))
finally:
    if connection:
        connection.close()
PY
```

Вернуть только вывод. Не отправлять raw Caddyfile/JSON/Env/credentials. Missing
admin.listen в JSON означает лишь неявное значение (CADDY_ADMIN может менять default),
не доказательство wildcard admin API. Handler/address summary не доказывает полноту
auth policy и внешнюю reachability. До установления назначения не менять bind,
firewall/SSH/container IDs или Source-of-Truth/config reload workflow.

Primary docs checked25Oct: [GET config API](https://caddyserver.com/docs/api#Get-config-path),
[JSON config/admin and HTTP servers](https://caddyserver.com/docs/json/),
[global admin options](https://caddyserver.com/docs/caddyfile/options#admin).
Runtime projection is metadata-only; source/operator5067163f remains unchanged.
