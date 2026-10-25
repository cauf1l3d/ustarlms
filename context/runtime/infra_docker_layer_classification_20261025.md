# Moodle writable layer: классификация для подготовки ротации25Oct2026

Owner counts приняты: [receipt](infra_docker_writable_layer_owner_20261025.json).
Moodle2618A/45C/0D=2663paths:2452/usr,13/etc,61/var,131/tmp,3/run,3/root.
PostgreSQL6A/3C/0D=9paths:4/run,4/root,1other. Known IDs/images unchanged.
Это paths (включая directories), не только файлы/не размер логов; native diff
не определяет необходимый runtime dependency из одних counts. Старый общий
count/origin/profile блок не повторять; below уточняет только Moodle layout и SizeRw.

## Одно чтение уточняющих metadata

```bash
sudo -v && sudo -n timeout --kill-after=3s 90s /usr/bin/python3 -I -B - <<'PY'
import json, re, subprocess

name = 'ustar_moodle'
pin = ('64130d6157efbad3a290a39a64eaad29796b67c10004773e419cfa5ce4f0ecfc',
       'sha256:6d46230275ffc8b324afaff814979479cd0afc28bcac89c6fc380b8a0ed4ed8b')
groups = {
    'php_extensions': ('/usr/local/lib/php/extensions',),
    'php_headers': ('/usr/local/include/php',),
    'php_config': ('/usr/local/etc/php', '/etc/php'),
    'apache_config': ('/etc/apache2',),
    'certificates': ('/etc/ssl', '/usr/local/share/ca-certificates', '/usr/share/ca-certificates'),
    'node_libraries': ('/usr/local/lib/node_modules', '/usr/lib/node_modules'),
    'local_binaries': ('/usr/local/bin', '/usr/local/sbin'),
    'system_binaries': ('/usr/bin', '/usr/sbin', '/bin', '/sbin'),
    'other_headers': ('/usr/local/include', '/usr/include'),
    'system_libraries': ('/usr/lib', '/lib', '/lib64'),
    'shared_assets': ('/usr/share',),
    'package_metadata': ('/var/lib/dpkg', '/var/lib/apt', '/var/cache/apt'),
    'runtime_temp_logs': ('/run', '/tmp', '/var/run', '/var/tmp', '/var/log'),
    'other_config': ('/etc',), 'other_usr': ('/usr',),
    'other_var': ('/var',), 'root_home': ('/root',)}

def query(args, timeout=8):
    p = subprocess.run(['/usr/bin/docker', '--host', 'unix:///var/run/docker.sock', *args],
        stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        timeout=timeout, env={'PATH': '/usr/bin:/bin', 'LANG': 'C', 'LC_ALL': 'C'})
    if p.returncode or len(p.stdout) + len(p.stderr) > 8388608:
        raise ValueError('docker_metadata_unavailable')
    return p.stdout

def guard():
    form = '{"id":{{json .Id}},"image":{{json .Image}}}'
    row = json.loads(query(['container', 'inspect', '--format', form, name]))
    if (row.get('id'), row.get('image')) != pin:
        raise ValueError('container_identity_drift')

def classify(raw):
    result = dict.fromkeys(('python_libraries', *groups, 'other'), 0)
    for line in raw.decode('utf-8', errors='strict').splitlines():
        kind, sep, path = line.partition(' ')
        if kind not in ('A', 'C', 'D') or not sep or not path.startswith('/'):
            raise ValueError('unexpected_diff_shape')
        bucket = 'python_libraries' if re.match(r'^/usr/(?:local/)?lib/python[0-9]+(?:\.[0-9]+)*(?:/|$)', path) else next(
            (key for key, prefixes in groups.items() if any(path == p or path.startswith(p + '/') for p in prefixes)), 'other')
        result[bucket] += 1
    return result

try:
    guard()
    raw_size = query(['container', 'inspect', '--size', '--format', '{{json .SizeRw}}', pin[0]], 25)
    size = json.loads(raw_size)
    if type(size) is not int or size < 0:
        raise ValueError('writable_size_unavailable')
    result = classify(query(['container', 'diff', pin[0]], 25))
    guard()
    print(json.dumps({'phase': 'moodle_layer_classified', 'identity_unchanged': True,
        'writable_bytes': size, 'path_groups': result,
        'configuration_changes': False, 'provider_called': False}))
except Exception as error:
    allowed = ('docker_metadata_unavailable', 'container_identity_drift',
               'unexpected_diff_shape', 'writable_size_unavailable')
    reason = str(error) if isinstance(error, ValueError) and str(error) in allowed else type(error).__name__
    print(json.dumps({'phase': 'metadata_unavailable', 'reason': reason,
        'configuration_changes': False, 'provider_called': False}))
    raise SystemExit(2)
PY
```

Вернуть JSON. На server1 нет file writes, container stop/start/build/pull/commit/exec,
app/SQL/provider calls или raw Env/config/path/log output. Docker local socket,
очищенная environment, before/after exact ID/image, per-command timeouts8/25/25/8s
и outer90s. SizeRw — Docker-reported bytes created/changed relative to image,
не итоговый размер архива или резерв диска. Counts по названным группам — только
совпадение path prefix, не чтение содержимого/проверка file kind или app dependency.
Prefix runtime_temp_logs не означает разрешение удалить или исключить эти paths:
необходимость сохранения отдельно определяется до cutover.

Actual Docker recreation/log rotation не выполнены. Не выбирать docker commit,
слепой compose up/build/pull, rootfs copy или изменение frozen controller pins по
этой статистике. Сохранение writable changes и полный live configuration/identity/
cron/HTTPS/recovery handoff обязательны для квалифицированного successor.
SSH/key/firewall deferred; UPS/copies handed over accepted; external restore unknown.
Accepted DB/code/SSD/Moodle/OS/HTTPS/backup/Connect stands не повторять.

Автор проверяет exact shell/Python syntax и simulated local Docker output: prefix
boundaries, Python versions, precedence, secret-sentinel suppression, before/after
identity drift, invalid size/diff, command failure/timeout/oversize. Native server1
не вызывался агентом. No new user-facing install/archive required.

Primary docs checked25Oct2026:
https://docs.docker.com/reference/cli/docker/container/inspect/
https://docs.docker.com/reference/cli/docker/inspect/#inspect-the-size-of-a-container--s---size
https://docs.docker.com/reference/cli/docker/container/diff/
