#!/usr/bin/env python3
"""Validate the actual public 5.3 package with the operator's exact parser."""
import importlib.util
import json
from pathlib import Path
import sys

root = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('major_archive_checked', root / 'scripts/infra/moodle53_stage_prepare.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
profile = json.loads((root / 'scripts/infra/moodle53_profile_20261014.json').read_text())
path = Path(sys.argv[1])
module.require(path.name == profile['archive_file'] and path.is_absolute(), 'CI_ARCHIVE_PATH_DIFFERS')
module.ASSETS = path.parent
with module.archive(profile) as (_, catalog):
    print(json.dumps({'archive_sha256': profile['archive_sha256'], 'package_catalog_checked': True,
                      'target_core_version': profile['target_core_version'], 'entries': len(catalog),
                      'expanded_bytes': sum(m.size for m in catalog.values()),
                      'has_public_vendor': 'public/vendor' in catalog, 'has_root_vendor': 'vendor' in catalog}, indent=2))
