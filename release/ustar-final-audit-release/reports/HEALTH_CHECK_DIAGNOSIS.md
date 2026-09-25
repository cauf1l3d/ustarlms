# USTAR Moodle health-check diagnosis

Дата: 2026-08-23  
Режим: read-only production comparison + isolated verification  
Production changes: **none**

## 1. `core_publicpaths`

The built-in security report expects HTTP 404 for every private-path probe. All tested files returned 404, including `.git/HEAD`, `composer.json`, `composer.lock`, install XML, readmes, upgrade files, PHPUnit and Behat fixtures.

Five directory-form probes returned HTTP 308:

- `/vendor/` → `/vendor`
- `/node_modules/` → `/node_modules`
- `/.git/` → `/.git`
- `/.upgradenotes/` → `/.upgradenotes`
- `/lib/classes/` → `/lib/classes`

Every redirect target returned 404. Two arbitrary nonexistent trailing-slash paths produced the same Caddy 308 normalisation followed by 404. Therefore the ERROR is a strict-status false positive caused by canonical slash redirects, not evidence that private files are served.

Verdict: **no direct disclosure reproduced; health-check noise remains**. A future Caddy rule may return 404 before path normalisation for Moodle private patterns, but that configuration change should be rehearsed separately and is not a P0 data-exposure fix.

## 2. `local_ai_manager_tenantcolumn_identifiers_valid`

Both production and isolated `admin/cli/checks.php` return the same ERROR. Installed plugin version: `2026071000`.

Facts:

- configured `tenantcolumn = institution`;
- tenant restriction is disabled (`restricttenants = 0`);
- plugin config has one tenant identifier: `default`;
- 71 undeleted accounts have non-empty `institution`; CURRENT uses this Moodle field for job/position labels;
- only `HRD` among the observed non-empty institution identifiers matches the plugin's Latin-only tenant regex; Russian job labels and punctuation are rejected;
- alternative supported columns are not a clean fix: `department` has 74 non-empty business values; `city` has six non-empty values including Cyrillic;
- blank values fall back to tenant `default`, while invalid non-empty values can cause AI configuration to be hidden/invalid.

Root cause: an HR/business field is being reused as an AI tenant identifier. This is a source-of-truth collision, not corrupt user data.

Do not “fix” it by transliterating or erasing HR fields. TARGET decision required:

1. single default AI tenant with a vendor-supported way to ignore user HR columns; or
2. a dedicated canonical Latin tenant identifier mapped independently from Position/Department; or
3. disable/remove the AI Manager component if it has no approved owner, privacy scope and use case.

Verdict: **real configuration conflict / production blocker for AI availability and governance**. No automatic production change was made because the safe option depends on TARGET identity, tenancy and data-access decisions.

## 3. Current checks result

| Environment | Result |
|-|-|
| Production | one ERROR: `local_ai_manager_tenantcolumn_identifiers_valid`; exit 2 |
| Isolated | same ERROR plus expected cron/adhoc warnings because no test cron runner; exit 2 |
| Database schema | PASS |
| Production loopback login | PASS |
| Isolated login | PASS |

`core_publicpaths` belongs to the security overview rather than the status CLI output; its exact probes are documented above.
