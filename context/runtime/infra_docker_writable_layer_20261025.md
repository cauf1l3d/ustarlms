# Docker writable layer: недостающие метаданные25Oct2026

**Owner output accepted; этот общий count блок не повторять.**
Moodle2663paths (2452/usr,13/etc), PG9; known identities unchanged.
[Receipt](infra_docker_writable_layer_owner_20261025.json). Следующий только
[dependency buckets + SizeRw](infra_docker_layer_classification_20261025.md).
Docker lifecycle/rotation ещё не реализованы; команда ниже — история.
Source/privacy/companion приняты: [owner receipt](compose_source_privacy_owner_20261025.json).
Новый companion264files/35043569B/SHA083bbfda завершён21:39:16MSK, readback true.
Origin/profile/SSH/DB/cron/старые owner stands не повторять.

Для ротации live logs требуется recreation, потому что изменение daemon defaults
не меняет уже созданные контейнеры. В этом проекте frozen recovery/cron/HTTPS
consumers требуют coordinated identity handoff, а source Moodle имеет build.
Owner counts подтвердили изменения writable layer после создания контейнеров;
образ и Compose-файл не являются копией этого слоя. Назначение и bytes пока не
определены; новый следующий блок указан выше.

## Один блок на server1

```bash
sudo -v && sudo -n timeout --kill-after=3s 90s /usr/bin/python3 -I -B - <<'PY'
import json, subprocess

pins = {
    'ustar_moodle': ('64130d6157efbad3a290a39a64eaad29796b67c10004773e419cfa5ce4f0ecfc',
        'sha256:6d46230275ffc8b324afaff814979479cd0afc28bcac89c6fc380b8a0ed4ed8b'),
    'ustar_postgres': ('0d4d016c8f3f46e3278613daa02846a1833f470b326a9833f79f9ec7a331b7b6',
        'sha256:f1c3376c26f2609ab9f29f71f824103fe2fcd8ee0346485cb6122a4f93df6f94')}
roots = ('/etc', '/usr', '/opt', '/var', '/tmp', '/run', '/root', '/home')

def command(args, *, timeout=8):
    p = subprocess.run(['/usr/bin/docker', '--host', 'unix:///var/run/docker.sock', *args],
        stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        timeout=timeout, env={'PATH': '/usr/bin:/bin', 'LANG': 'C', 'LC_ALL': 'C'})
    if p.returncode or len(p.stdout) + len(p.stderr) > 8388608:
        raise ValueError('docker_metadata_unavailable')
    return p.stdout

def identities():
    form = '{"id":{{json .Id}},"image":{{json .Image}}}'
    rows = command(['container', 'inspect', '--format', form, *pins]).splitlines()
    if len(rows) != len(pins):
        raise ValueError('container_inventory_shape')
    for name, raw in zip(pins, rows):
        row = json.loads(raw)
        if (row.get('id'), row.get('image')) != pins[name]:
            raise ValueError('container_identity_drift')

def project(raw):
    counts = dict.fromkeys(('A', 'C', 'D'), 0)
    buckets = dict.fromkeys((*roots, 'other'), 0)
    for line in raw.decode('utf-8', errors='strict').splitlines():
        kind, sep, path = line.partition(' ')
        if kind not in counts or not sep or not path.startswith('/'):
            raise ValueError('unexpected_diff_shape')
        counts[kind] += 1
        bucket = next((p for p in roots if path == p or path.startswith(p + '/')), 'other')
        buckets[bucket] += 1
    return {'changes': counts, 'path_groups': buckets,
        'detailed_paths_published': False, 'file_contents_read': False}

try:
    identities()
    results = {name: project(command(['container', 'diff', pin[0]], timeout=25)) for name, pin in pins.items()}
    identities()
    print(json.dumps({'phase': 'writable_layer_metadata', 'identity_unchanged': True,
        'containers': results, 'configuration_changes': False, 'provider_called': False}))
except Exception as error:
    reason = str(error) if isinstance(error, ValueError) and str(error) in (
        'docker_metadata_unavailable', 'container_inventory_shape',
        'container_identity_drift', 'unexpected_diff_shape') else type(error).__name__
    print(json.dumps({'phase': 'metadata_unavailable', 'reason': reason,
        'configuration_changes': False, 'provider_called': False}))
    raise SystemExit(2)
PY
```

Вернуть только JSON. Отчёт показывает A/C/D counts и фиксированные directory buckets,
не произвольные paths/Env/commands/config/logs. Нет stop/start/build/pull/exec/DB writes,
сохранения файлов или модификации metadata на server1. CLI привязан к local socket,
запускается с очищенной environment; raw stderr не печатается. Проверки ID/image до
и после защищают измерение от очевидного пересоздания во время чтения, но не являются
полной аттестацией runtime/source equivalence. Bind/volume content этим не проверяется.

Нулевой diff сам по себе не разрешает recreate: нужно сохранить live runtime/config,
mounts/networks/images, writers/cron/HTTPS/recovery controls, qualified old→new identity
handoff и recovery before public writes. Ненулевой diff не доказывает, что каждое
изменение необходимо сохранять: runtime PID/log/cache paths могут быть нормальными;
конкретная классификация следует по metadata. Никакие paths не удалять/не коммитить
слой и не запускать compose up по этим counts.

## Исходники зависимостей

- connect_con02_contract / postgres_app_handoff: bind_identities из accepted app primitive.
- cron_release_update: exact actual containers == accepted containers guard.
- academy_https: runtime Moodle identity/config bind/port and renewal owner-state.
- hdd_backup: pinned images and operation-local container identity.
- host_cold_recovery: exact inventory, IDs/images/mounts; сохранение дополнительного
  stopped rollback container не является автоматически совместимым режимом.
- infra_one_pass: pinned root controllers unchanged; нет разрешения переписать их SHA
  или old journals только ради прохождения guard.

Автор проверяет shell/Python syntax, secret-sentinel projection и simulated Docker
boundary failures без доступа к production. Это metadata command, не новый полный
owner restore/UI/нагрузочный stand. Actual native Docker recreation ещё не выполнена.

Primary docs checked25Oct2026:
https://docs.docker.com/engine/logging/configure/
https://docs.docker.com/reference/cli/docker/container/diff/
