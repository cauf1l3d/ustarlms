#!/usr/bin/env bash
set -euo pipefail
ustar_console_root=$(cd -- "$(dirname -- "$0")" && pwd -P)
cd "$ustar_console_root"
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
mkdir -p "$ustar_console_stage/ustar-console/deploy"
cp dist/ustar_console-0.1.0-py3-none-any.whl requirements.lock INSTALL.md "$ustar_console_stage/ustar-console/"
cp deploy/config.example.json deploy/install.sh deploy/ustar-console*.service deploy/ustar-console-collect.timer "$ustar_console_stage/ustar-console/deploy/"
git rev-parse HEAD > "$ustar_console_stage/ustar-console/SOURCE_COMMIT"
tar -C "$ustar_console_stage" -czf dist/ustar-console.tar.gz ustar-console
cd dist
sha256sum ustar-console.tar.gz > ustar-console.tar.gz.sha256
