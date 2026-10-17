#!/usr/bin/env bash
# Wheel-only update of an already configured LAN console, from a verified artifact.
set -Eeuo pipefail
umask 022
[[ $EUID == 0 ]] || { echo 'Run with sudo'; exit 1; }
ustar_console_source=$(cd -- "$(dirname -- "$0")/.." && pwd -P)
python3 - "$ustar_console_source" <<'PY'
import pathlib,stat,sys
base=pathlib.Path(sys.argv[1])
for path in [*base.parents,base,*base.rglob('*')]:
    info=path.lstat()
    if info.st_uid!=0 or info.st_mode&0o022 or stat.S_ISLNK(info.st_mode):
        raise SystemExit('Untrusted update source')
assert (base/'ustar_console-0.1.0-py3-none-any.whl').is_file()
for path in (base/'deploy').glob('ustar-console*'):
    target=pathlib.Path('/etc/systemd/system')/path.name
    if path.suffix in ('.service','.timer') and (not target.is_file() or target.read_bytes()!=path.read_bytes()):
        raise SystemExit('Unit differs; wheel-only update refused: '+path.name)
PY
ustar_console_jobs_clear() {
    /opt/ustar-console/venv/bin/python <<'PY'
import sqlite3
from ustar_console.config import load
assert load().tls, 'Configured native LAN console required'
with sqlite3.connect('file:/var/lib/ustar-console-ops/jobs.sqlite3?mode=ro',uri=True,timeout=2) as db:
    if db.execute("SELECT count(*) FROM jobs WHERE state IN ('queued','running')").fetchone()[0]:
        raise SystemExit('Console job queued/running; update refused')
PY
}
ustar_console_jobs_clear
ustar_console_active=()
for ustar_console_unit in ustar-console.service ustar-console-ops.service ustar-console-notify.service ustar-console-collect.timer ustar-console-smart.timer; do
    if systemctl is-active --quiet "$ustar_console_unit"; then ustar_console_active+=("$ustar_console_unit"); fi
done
ustar_console_was_active() { [[ " ${ustar_console_active[*]} " == *" $1 "* ]]; }
ustar_console_resume() {
    for ustar_console_unit in ustar-console-ops.service ustar-console.service; do
        if ustar_console_was_active "$ustar_console_unit"; then systemctl start "$ustar_console_unit" || return 1; fi
    done
    if ustar_console_was_active ustar-console-smart.timer; then systemctl start ustar-console-smart.service || return 1; fi
    if ustar_console_was_active ustar-console-collect.timer; then systemctl start ustar-console-collect.service || return 1; fi
    for ustar_console_unit in ustar-console-smart.timer ustar-console-collect.timer ustar-console-notify.service; do
        if ustar_console_was_active "$ustar_console_unit"; then systemctl start "$ustar_console_unit" || return 1; fi
    done
}
ustar_console_rollback=$(mktemp -d /opt/ustar-console-rollback.XXXXXX)
cp -a --reflink=auto /opt/ustar-console/venv "$ustar_console_rollback/venv"
ustar_console_stopped=0
ustar_console_changed=0
ustar_console_finish() {
    local ustar_console_exit=$?
    trap - EXIT
    set +e
    if ((ustar_console_exit!=0 && ustar_console_changed)); then
        if ! systemctl stop ustar-console.service ustar-console-ops.service ustar-console-notify.service ustar-console-collect.timer ustar-console-smart.timer ustar-console-collect.service ustar-console-smart.service; then
            echo 'CONSOLE_UPDATE=ROLLBACK_STOP_FAILED'; exit 1
        fi
        if mv /opt/ustar-console/venv "$ustar_console_rollback/failed-venv" && mv "$ustar_console_rollback/venv" /opt/ustar-console/venv; then
            echo 'CONSOLE_UPDATE=ROLLED_BACK'
        else
            echo 'CONSOLE_UPDATE=ROLLBACK_FAILED'; exit 1
        fi
    fi
    if ((ustar_console_stopped)); then ustar_console_resume || ustar_console_exit=1; fi
    exit "$ustar_console_exit"
}
trap ustar_console_finish EXIT
ustar_console_stopped=1
systemctl stop ustar-console.service
ustar_console_jobs_clear
systemctl stop ustar-console-collect.timer ustar-console-smart.timer ustar-console-collect.service ustar-console-smart.service ustar-console-ops.service ustar-console-notify.service
ustar_console_changed=1
/opt/ustar-console/venv/bin/python -m pip install --no-deps --force-reinstall "$ustar_console_source/ustar_console-0.1.0-py3-none-any.whl"
/opt/ustar-console/venv/bin/python -m pip check
ustar_console_resume
/opt/ustar-console/venv/bin/python <<'PY'
import json,ssl,time,urllib.error,urllib.request
from ustar_console.config import load
settings=load()
context=ssl.create_default_context(cafile='/etc/ustar-console/ustar-console-ca.crt')
opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPSHandler(context=context))
for attempt in range(20):
    try:
        with opener.open(settings.origin+'/healthz',timeout=1) as response:
            if json.load(response).get('status')=='alive': break
    except (OSError,urllib.error.URLError): pass
    time.sleep(.25)
else: raise SystemExit('Updated console did not become ready')
PY
/opt/ustar-console/venv/bin/ustar-console-accept > "$ustar_console_rollback/acceptance.json"
python3 - "$ustar_console_rollback/acceptance.json" <<'PY'
import json,sys
assert json.load(open(sys.argv[1]))['native_accepted'] is True, 'Updated console installation gate failed'
PY
cat "$ustar_console_rollback/acceptance.json"
ustar_console_stopped=0
printf 'CONSOLE_UPDATE=PASS\nPrevious installation: %s/venv\n' "$ustar_console_rollback"
