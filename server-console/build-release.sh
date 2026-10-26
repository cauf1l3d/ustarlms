#!/usr/bin/env bash
set -euo pipefail
ustar_console_root=$(cd -- "$(dirname -- "$0")" && pwd -P)
cd "$ustar_console_root"
python3 tools/build_knowledge.py
npm ci --ignore-scripts --no-fund --no-audit --prefix ui
npm run build --prefix ui
mkdir -p backend/ustar_console/web
rm -rf backend/ustar_console/web/assets
cp -a ui/dist/. backend/ustar_console/web/
# setuptools can otherwise retain assets from an earlier local build/lib tree.
rm -rf backend/build
python3 -m build --wheel --no-isolation --outdir dist backend
ustar_console_stage=$(mktemp -d)
trap 'rm -rf -- "$ustar_console_stage"' EXIT
mkdir -p "$ustar_console_stage/ustar-console/deploy" "$ustar_console_stage/ustar-console/tools"
cp dist/ustar_console-0.1.0-py3-none-any.whl requirements.lock INSTALL.md VPN_TELEGRAM.md REVISIONS_20261018.md REQUEST_LOGS_20261018.md ACCESS_FIX_20261018.md ADMIN_ALERTS_20261018.md HOST_LOSS_20261018.md "$ustar_console_stage/ustar-console/"
cp deploy/config.example.json deploy/database-monitor.sql deploy/install.sh deploy/update.sh deploy/ustar-console*.service deploy/ustar-console*.timer "$ustar_console_stage/ustar-console/deploy/"
cp tools/subscription_probe.py tools/telegram_egress.py tools/configure_observability.py tools/console_access.py tools/observability_diagnose.py "$ustar_console_stage/ustar-console/tools/"
python3 - "$ustar_console_root" "$ustar_console_stage/ustar-console/observer.pyz" <<'PY'
from pathlib import Path
import sys, tempfile, zipapp
root,target=map(Path,sys.argv[1:])
with tempfile.TemporaryDirectory() as folder:
    stage=Path(folder); package=stage/'ustar_console'; package.mkdir()
    (package/'__init__.py').write_text('')
    for name in ('observer','alerts','collector','data','cron','hardware','config','telegram_transport','telegram_views','telegram_cards','notifier_control'):
        (package/(name+'.py')).write_bytes((root/'backend/ustar_console'/(name+'.py')).read_bytes())
    (stage/'__main__.py').write_text('from ustar_console.observer import main\nmain()\n')
    zipapp.create_archive(stage,target,interpreter='/usr/bin/env python3',compressed=True)
PY
git rev-parse HEAD > "$ustar_console_stage/ustar-console/SOURCE_COMMIT"
tar -C "$ustar_console_stage" -czf dist/ustar-console.tar.gz ustar-console
cd dist
sha256sum ustar-console.tar.gz > ustar-console.tar.gz.sha256
