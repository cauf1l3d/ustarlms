# PR71 production incident: shell and login — 2026-10-01

Baseline installed by the user: `4cbb8ac37ef99051b9432e1e5c7c462f1472ad36`,
local 2026093006 / theme 2026093004; complete manifest, 505 matching files;
backup `/home/aduk/ustar-pr71-backup-20261001T001931Z`. User-executed evidence;
this session has no production connection.

Production acceptance failed: after login Moodle threw
`Cannot call moodle_page::add_body_class after output has been started`.
The source called add_body_class from a theme layout. Moodle invokes layouts
while printing the header; page classes can no longer be mutated at that state.
Width classes now pass through the renderer's body_attributes() argument,
as the existing activity classes already did. The fix covers every USTAR shell
entry and preserves notebook/catalog wide layout.

Earlier CI passed DB tests and synthetic UI fixtures. Deployment public HTTP
checks saw 303 redirects for product pages and did not exercise signed-in
rendering. Those results did not establish authenticated runtime acceptance.

New stage HTTP smoke uses the actual isolated Moodle installation, native login
form token, synthetic admin password and cookie jar. It reproduces the error on
the exact installed PR71 baseline, then requires HTTP 200 and the correct shell
body classes on home/notebook/catalog after upgrade. Redirects are rejected.
No production credentials or user data are used. Cookie jar is removed.

Login visual also rejected: screenshot exposed raster-panel edges, mismatch of
background, scale and spacing. Login now renders one native composition with
shared header/footer, equal desktop columns and unified surface, typography,
gold accents and inline SVG icons. No reference-image crops, raster text,
independent poster surface or forced artwork height. Core Moodle still owns
forms, tokens, password reveal, errors, reset, guest login, language and SSO;
USTAR's internal registration remains on the same login layout.

Hotfix versions local/theme 2026100101, no schema or business-policy change.
Installer `scripts/release/deploy_pr71_hotfix.sh` requires exact installed PR71
manifest and version pair; retains backup, checksum transfer, explicit file
modes/readability, cache and OPcache reset, upgrade/version checks. Public smoke
is labeled as such and does not claim production signed-in acceptance.
Full exact-SHA gate and visual verification must be recorded in PR71 before
issuing the deployment command. Production installation remains user-executed.

## First authenticated gate

Run 36797444337 reproduced the baseline body-class exception and then rendered
home and notebook correctly on d69ff8f4. It rejected catalog HTTP 500. Its HTML
artifact identified an E_USER_NOTICE promoted to exception in developer mode:
PAGE context unset at catalog.php:99, where fallbackimage initialised OUTPUT
before set_context/layout. Catalog now configures PAGE before any presenter or
image URL. This also protects populated catalog browse/detail paths. The gate
was correctly red and must not be used as release evidence.

Chromium fixtures compiled all 18 actual theme SCSS partials and rendered the
native login template with representative forms. Five widths (1920/1366/1024/
768/390) × login/error/registration passed: no horizontal overflow, no raster
images, equal desktop column widths. Desktop headings now share their baseline.
These visual fixtures are distinct from the actual signed-in Moodle HTTP smoke.
Hotfix installer simulation passed success, lint-before-copy, upgrade failure and
HTTP failure scenarios; post-copy errors retain maintenance. Docker/DB/HTTP are
mocked in the installer simulation, with real Git manifests and rsync transfer.
