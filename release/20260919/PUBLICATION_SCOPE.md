# Publication scope — 2026-09-19

The repository owner requested reconciliation of GitHub with the current running production source.

## Canonical application source

Branch:

`integration/ustar-20260919`

Commit:

`378d397152a8c83f8b0d046e2e561ab2732d6b02`

The branch points to the exact production-source reconciliation commit originally pushed as `prod-sync-20260919`.

## Included

- effective `local/ustar` application source;
- effective `theme/ustar` application source;
- dated source manifests;
- production artifact classification;
- runtime reference metadata;
- recovery snapshot reference and SHA256.

## Excluded

The following are intentionally outside Git:

- production PostgreSQL data;
- moodledata;
- Moodle root credentials/config;
- environment secrets;
- Docker runtime volumes/state;
- the 1.7G recovery archive itself;
- developer backup/remnant files excluded by the existing repository `.gitignore`.

## Verification boundary

381 publishable source files were checked byte-for-byte against the production-derived source before the commit was pushed.

33 developer/runtime backup artifacts were recorded but not treated as active source.

This publication does not claim a full database/schema/browser/DR acceptance result. Those checks remain part of R00.
