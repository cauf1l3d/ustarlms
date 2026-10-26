#!/usr/bin/env bash
# Run from a root-owned checkout/artifact after verifying its pinned source SHA.
set -euo pipefail
[[ $EUID == 0 ]] || { echo 'Run with sudo'; exit 1; }
ustar_console_source=$(cd -- "$(dirname -- "$0")/.." && pwd -P)
[[ -f "$ustar_console_source/ustar_console-0.1.0-py3-none-any.whl" ]] || { echo 'Use the built console artifact'; exit 1; }
# Never execute pip/build tools from a user-writable source as root.
python3 - "$ustar_console_source" <<'PY'
import os, pathlib, stat, sys
base=pathlib.Path(sys.argv[1])
for p in [*base.parents,base,*base.rglob('*')]:
    s=p.lstat()
    if s.st_uid != 0 or s.st_mode & 0o022 or stat.S_ISLNK(s.st_mode):
        raise SystemExit('Source must be root-owned, without writable files or symlinks: '+str(p))
PY
if ! getent passwd ustar-console >/dev/null; then
    useradd --system --user-group --home-dir /var/lib/ustar-console --shell /usr/sbin/nologin ustar-console
fi
[[ $(id -Gn ustar-console) == ustar-console ]] || { echo 'Refusing an identity with supplementary groups'; exit 1; }
if ! getent passwd ustar-notify >/dev/null; then
    useradd --system --user-group --home-dir /var/lib/ustar-console-notify --shell /usr/sbin/nologin ustar-notify
fi
install -d -o root -g root -m 0755 /opt/ustar-console
install -d -o root -g ustar-console -m 0750 /etc/ustar-console
install -d -o ustar-console -g ustar-console -m 0700 /var/lib/ustar-console
if [[ ! -e /etc/ustar-console/config.json ]]; then
    install -o root -g ustar-console -m 0640 "$ustar_console_source/deploy/config.example.json" /etc/ustar-console/config.json
fi
python3 -m venv /opt/ustar-console/venv
/opt/ustar-console/venv/bin/pip install --require-hashes -r "$ustar_console_source/requirements.lock"
/opt/ustar-console/venv/bin/pip install --no-deps --force-reinstall "$ustar_console_source/ustar_console-0.1.0-py3-none-any.whl"
install -o root -g root -m 0644 "$ustar_console_source"/deploy/ustar-console*.service /etc/systemd/system/
install -o root -g root -m 0644 "$ustar_console_source"/deploy/ustar-console*.timer /etc/systemd/system/
systemctl daemon-reload
install -o root -g root -m 0600 "$ustar_console_source/deploy/database-monitor.sql" /etc/ustar-console/database-monitor.sql
echo 'Installed without starting services. Run: sudo /opt/ustar-console/venv/bin/ustar-console-setup'
