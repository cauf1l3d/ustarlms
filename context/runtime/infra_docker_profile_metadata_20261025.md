# Docker stack: метаданные фактического Compose-файла

Owner labels подтверждают creation origin обоих контейнеров: project stack,
services moodle/postgres, workdir /opt/ustar/apps/stack, один файл
/opt/ustar/apps/stack/docker-compose.yml. Это не доказательство, что нынешний файл
совпадает с созданными контейнерами. Старый survey проверял /opt/ustar/docker-compose.yml;
его сведения не доказывают metadata/content/backup coverage фактического файла.

## Один следующий read-only блок на server1

Показывает metadata двух source files и родителей, SHA самого Compose-файла,
allowlisted logging options и boolean/count-only service summary. Env values,
healthcheck commands, image refs, build args, mounts и конфигурация не печатаются.
Не выполнять config --environment/--variables или полный docker inspect/config.

Compose config запускается в новой network namespace с --host unix:///dev/null,
то есть без сети и без связи с настоящим Docker daemon. Remote includes не могут
скачать данные через сеть/daemon. DOCKER_HOST также ограничивает дочерние Docker CLI.
--no-interpolate/--no-env-resolution и явный --env-file /dev/null отключают подстановку
переменных и чтение service env files. Inline literals/defaults могут остаться внутри
модели; она обрабатывается только в RAM, наружу выводится выбранная проекция.
Render failure не доказывает неисправность production; raw stderr не печатается.

```bash
sudo -v && sudo -n timeout --kill-after=3s 45s /usr/bin/python3 -I -B - <<'PY'
import hashlib, json, os, re, stat, subprocess
from pathlib import Path

base = Path('/opt/ustar/apps/stack')
source = base / 'docker-compose.yml'

def metadata(path):
    try:
        info = path.lstat()
        return {'path': str(path), 'uid': info.st_uid, 'gid': info.st_gid,
            'mode': oct(stat.S_IMODE(info.st_mode)), 'bytes': info.st_size,
            'kind': 'symlink' if stat.S_ISLNK(info.st_mode) else
                'directory' if stat.S_ISDIR(info.st_mode) else
                'regular' if stat.S_ISREG(info.st_mode) else 'other'}
    except FileNotFoundError:
        return {'path': str(path), 'kind': 'missing'}

def project(model):
    services = model.get('services') or {}
    result = {'service_count': len(services), 'expected_services': {}}
    for name in ('moodle', 'postgres'):
        service = services.get(name)
        if not isinstance(service, dict):
            result['expected_services'][name] = {'present': False}
            continue
        logging = service.get('logging') or {}
        options = logging.get('options') or {}
        safe = {}
        for key in ('max-size', 'max-file', 'compress', 'mode', 'max-buffer-size'):
            if key not in options:
                continue
            value = str(options[key]).lower()
            pattern = r'[1-9][0-9]{0,9}' if key == 'max-file' else (
                r'(?:true|false)' if key == 'compress' else
                r'(?:blocking|non-blocking)' if key == 'mode' else
                r'[1-9][0-9]{0,9}(?:[kmgt]i?b?|b)?')
            safe[key] = value if re.fullmatch(pattern, value) else 'unresolved_or_other'
        driver = logging.get('driver')
        health = service.get('healthcheck')
        result['expected_services'][name] = {
            'present': True, 'image_field_present': 'image' in service,
            'build_present': 'build' in service, 'logging_present': 'logging' in service,
            'log_driver': driver if driver in ('json-file', 'local', 'journald', None) else 'other',
            'log_options': safe, 'other_log_option_count': len(set(options) - set(safe)),
            'healthcheck_present': isinstance(health, dict),
            'healthcheck_disabled': health.get('disable') is True if isinstance(health, dict) else False,
            'environment_count': len(service.get('environment') or {}),
            'env_file_count': len(service.get('env_file') or []),
            'volume_count': len(service.get('volumes') or []),
            'port_count': len(service.get('ports') or [])}
    return result

try:
    paths = [Path('/opt'), Path('/opt/ustar'), Path('/opt/ustar/apps'), base, source, base / '.env']
    records = [metadata(path) for path in paths]
    print(json.dumps({'source_metadata': records}), flush=True)
    if any(row['kind'] != 'directory' for row in records[:4]) or records[4]['kind'] != 'regular':
        raise ValueError('source_path_shape')
    with source.open('rb') as stream:
        before = os.fstat(stream.fileno())
        raw = stream.read(1048577)
        if len(raw) > 1048576:
            raise ValueError('source_size_bound')
    source_sha = hashlib.sha256(raw).hexdigest()
    argv = ['/usr/bin/unshare', '--net', '--', '/usr/bin/docker', '--host', 'unix:///dev/null',
        'compose', '--project-directory', str(base), '-p', 'stack', '-f', str(source),
        '--env-file', '/dev/null', 'config', '--format', 'json',
        '--no-interpolate', '--no-env-resolution']
    process = subprocess.run(argv, cwd=base, stdin=subprocess.DEVNULL,
        stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=20,
        env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG': 'C', 'LC_ALL': 'C',
            'DOCKER_HOST': 'unix:///dev/null'})
    if len(process.stdout) + len(process.stderr) > 2097152:
        raise ValueError('render_size_bound')
    if process.returncode:
        category = 'namespace_unavailable' if b'unshare failed' in process.stderr else (
            'unsupported_option' if b'unknown flag' in process.stderr else 'compose_render_failed')
        print(json.dumps({'compose_profile': {'status': category,
            'returncode': process.returncode, 'source_sha256': source_sha}}), flush=True)
    else:
        result = project(json.loads(process.stdout))
        after = source.stat()
        fields = ('st_dev', 'st_ino', 'st_size', 'st_mtime_ns', 'st_ctime_ns')
        if any(getattr(before, key) != getattr(after, key) for key in fields):
            raise ValueError('source_changed')
        result.update(status='metadata_rendered_without_environment_resolution',
            source_sha256=source_sha, live_equivalence_verified=False)
        print(json.dumps({'compose_profile': result}), flush=True)
except Exception as error:
    print(json.dumps({'compose_profile': {'status': 'unavailable',
        'error_type': type(error).__name__}}), flush=True)
PY
```

Вернуть JSON output. Source hash относится только к указанному файлу; local includes,
env files, Docker writable layers и live model equivalence этим не аттестованы.
Если namespace/Compose rendering недоступны, не обходить ограничения запуском
нефильтрованной команды и не делать compose up/down. Следующее действие зависит от
полученных metadata. Healthcheck/build commands не запускаются; container IDs/images,
DB/code/data, service policies, SSH и provider configuration не меняются.

Shell/Python syntax и synthetic secret-sentinel projection проверяются локально.
Native production render агент не выполнял; full coordinator/rotation не готовы.
Primary docs checked25Oct: [Compose config](https://docs.docker.com/reference/cli/docker/compose/config/),
[Compose file/project precedence](https://docs.docker.com/reference/cli/docker/compose/).
