# USTAR production source baseline — 2026-09-19

Canonical application source:

`integration/ustar-20260919@378d397152a8c83f8b0d046e2e561ab2732d6b02`

This baseline was created from the running production source, not reconstructed from a collection of old patch archives.

## Verified source scope

Production:

- `/opt/ustar/data/moodle/public/public/local/ustar`
- `/opt/ustar/data/moodle/public/public/theme/ustar`

Repository:

- `moodle/local/ustar`
- `moodle/theme/ustar`

Verification result:

`INDEX_SOURCE_BYTE_MATCH_OK`

Counts:

- local/ustar: 328 Git-source files
- theme/ustar: 53 Git-source files
- total publishable Git source: 381 files
- ignored runtime/developer backup artifacts: 33
- production inventory after cleanup pass: 414 files

The exact source manifest and artifact inventory are stored in the production-source commit under `release/20260919/`.

## Difference from the 2026-09-12 baseline

Previous baseline:

`integration/ustar-20260912@48326c6a011f58b985d251cb0462835bc1d37a16`

The 19.09 production source contains later effective production changes, including current route/assessment behavior, career-grade integration, organization/team presentation, forced retraining and login theme source.

The old branch and 20260912 release remain historical provenance only.

## Recovery

Full disaster-recovery snapshot:

`USTAR_FULL_CURRENT_20260919_113924.tar.gz`

SHA256:

`598177f211333d7d036abc025b9d21c3c7c3eb4cfb0bc10d004ff0fcff473f41`

The archive itself is deliberately not stored in GitHub.

## Excluded from Git

- PostgreSQL database
- moodledata
- Moodle root `config.php`
- runtime credentials/secrets
- Docker runtime state
- developer backup copies and patch remnants

`theme/ustar/config.php` is normal Moodle theme application source and is included.

## Remaining acceptance

R00 remains review for:

- DB schema / upgrade-path checks against the exact commit;
- browser smoke/acceptance;
- native tour DB/visual acceptance;
- clean-server DR restore test.

Do not deploy old intermediate ZIP/RC packages over this baseline.
