#!/usr/bin/env python3
"""Install the Telegram relink fix on the Studio Preview 0200 source profile."""
import argparse
import json
import os
from pathlib import Path
import re
import stat
import sys
import types

from connect_con02_state import capture
from studio_preview_patch import (
    ADAPTER_SHA, CSP_JOURNAL, LEGACY_SHA, LIBRARY_JOURNAL, MAINTENANCE,
    PRESERVED as PREVIEW_CONTROLS, WORKER, digest,
)

BASELINE = '2cb233d994c0e4588973cfce0577c9d466027ce2'
APPLICATION = '53fab3b6fa4a00f492ff355546a2948890ce7395'
CHANGED = (
    'local/ustar/classes/connect/identity_service.php',
    'local/ustar/connect.php',
    'local/ustar/templates/connect.mustache',
    'local/ustar/tests/connect_identity_test.php',
)
ADDED = ()
FILES = CHANGED
PREVIEW_JOURNAL = 'var/lib/ustar-studio-preview-2cb233d994c0/state.json'
PRESERVED = PREVIEW_CONTROLS + (PREVIEW_JOURNAL,)
PREVIEW_SHA = '2d87bd18b86a5680126fa9c56f1bd715dbff5aa028b0d9b361dd3e0fbf6ccc5d'


def runtime(preview=None, adapter=None, legacy=None):
    if preview is None:
        preview = Path(__file__).with_name('studio_preview_patch.py').read_bytes()
    if digest(preview) != PREVIEW_SHA:
        raise ValueError('frozen_preview_overlay_changed')

    # Reuse the previous guarded file transaction in an isolated module.
    overlay = types.ModuleType('_ustar_connect_relink_preview')
    overlay.__file__ = str(Path(__file__).with_name('studio_preview_patch.py'))
    exec(compile(preview, overlay.__file__, 'exec'), overlay.__dict__)
    overlay.BASELINE = BASELINE
    overlay.APPLICATION = APPLICATION
    overlay.CHANGED = CHANGED
    overlay.ADDED = ADDED
    overlay.FILES = FILES
    overlay.PRESERVED = PRESERVED
    overlay.ADAPTER_SOURCE = adapter
    overlay.LEGACY_SOURCE = legacy
    runtime_module = overlay.runtime()

    class Patch(runtime_module.Patch):
        def __init__(self, root, manifest, payload, host):
            super().__init__(root, manifest, payload, host)
            self.base = self.root / ('var/lib/ustar-connect-relink-' + APPLICATION[:12])

        def identity(self, state=None):
            journal = self.root / PREVIEW_JOURNAL
            image = capture(journal)
            runtime_module.require(image['kind'] == 'file', 'previous_studio_preview_update_required')
            parent = journal.parent.lstat()
            runtime_module.require(
                image['metadata']['uid'] == os.geteuid() and image['metadata']['mode'] == 0o600
                and parent.st_uid == os.geteuid() and stat.S_IMODE(parent.st_mode) == 0o700,
                'private_previous_studio_preview_journal_required',
            )
            runtime_module.require(
                json.loads(image['raw']).get('phase') == 'active', 'previous_studio_preview_update_not_active',
            )
            return super().identity(state)

    runtime_module.Patch = Patch
    return runtime_module


def main(argv=None, *, bundle=None):
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group()
    for name in ('check', 'upgrade', 'recover'):
        mode.add_argument('--' + name, action='store_true')
    parser.add_argument('--acknowledge-outage', action='store_true')
    args = parser.parse_args(argv)
    if not any((args.check, args.upgrade, args.recover)):
        print(json.dumps({'phase': 'PLAN_ONLY', 'host_access': False, 'source_only': True}))
        return 0
    if not args.check and not args.acknowledge_outage:
        parser.error('upgrade/recover requires --acknowledge-outage')
    try:
        path = Path(sys.argv[0]).absolute()
        image = capture(path)
        parent = path.parent.lstat()
        if (os.geteuid() != 0 or image['kind'] != 'file' or image['metadata']['uid'] != 0
                or image['metadata']['mode'] != 0o600 or not stat.S_ISDIR(parent.st_mode)
                or parent.st_uid != 0 or stat.S_IMODE(parent.st_mode) != 0o700):
            raise ValueError('private_root_artifact_required')
        if bundle is None:
            raise ValueError('use_built_verified_package')

        manifest, payload, preview, adapter, legacy = bundle
        module = runtime(preview, adapter, legacy)
        host = module.Host()
        operation = module.Patch(Path('/'), manifest, payload, host)
        result = operation.check() if args.check else operation.recover() if args.recover else operation.apply()
        result.update(local_ustar='2026110200', theme_ustar='2026110200', schema_changed=False,
            business_data_changed=False, cache_purged=host.cache_purged, database_changed=host.cache_purged)
        print(json.dumps(result, sort_keys=True), flush=True)
        return 2 if result['phase'] == 'recovery_required' else 0
    except Exception as error:
        reason = str(error) if re.fullmatch('[a-zA-Z0-9_:/.-]+', str(error)) else 'connect_relink_operation_failed'
        print(json.dumps({'phase': 'refused', 'reason': reason}, sort_keys=True), flush=True)
        return 2


if __name__ == '__main__':
    raise SystemExit(main())
