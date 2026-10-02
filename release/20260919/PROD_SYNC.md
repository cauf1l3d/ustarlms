# USTAR production source reconciliation — 2026-09-19

This branch records the effective application source from the running
USTAR production instance.

## Previous canonical baseline

Branch:

`integration/ustar-20260912`

Commit:

`48326c6a011f58b985d251cb0462835bc1d37a16`

## Running production paths

- /opt/ustar/data/moodle/public/public/local/ustar
- /opt/ustar/data/moodle/public/public/theme/ustar

## Git source paths

- moodle/local/ustar
- moodle/theme/ustar

## Publishable Git source

- local/ustar: 328 files
- theme/ustar: 53 files

Every file stored in Git was verified byte-for-byte against the
production-derived working copy before commit.

Verification result:

`INDEX_SOURCE_BYTE_MATCH_OK`

Exact source hashes:

`source_manifest.sha256`

## Production inventory

The production source directories also contained 33 files
classified by the existing repository .gitignore as developer/runtime
backup artifacts.

They are intentionally not committed as active application source.

List:

`gitignored_production_artifacts.txt`

The complete production-directory inventory after the explicit
first cleanup pass is retained as metadata in:

`production_inventory_after_cleanup.sha256`

Total files in that inventory:

414

## Recovery snapshot

Archive:

`USTAR_FULL_CURRENT_20260919_113924.tar.gz`

SHA256:

`598177f211333d7d036abc025b9d21c3c7c3eb4cfb0bc10d004ff0fcff473f41`

The recovery archive itself is intentionally not stored in GitHub.

## Deliberately excluded from Git

- PostgreSQL database
- moodledata
- Moodle root config.php
- runtime credentials and secrets
- Docker runtime state
- developer backup copies
- historical patch remnants

The full recovery archive remains the disaster-recovery artifact for
non-Git runtime state.

## Important

`theme/ustar/config.php` is legitimate Moodle theme source and is included.

This source reconciliation does not mutate production.
