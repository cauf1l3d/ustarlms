# Server observation — 2026-09-12 20:48:24 UTC

Source: user-supplied server audit archive. Files are preserved as measured, not a live status feed. The audit compares production with 23243f3; its six differences and four new guide files are reconciled in the newer source commit. Historical comparison.md is deliberately not rewritten.

Verified locally: 366 regular source files match production manifest after reconciliation; theme/ustar/config.php was separately supplied and matches Git (367 files including that check). The other 155 production-only names resemble backup/intermediate copies and are not imported or deleted. Hidden backup/lock paths remain excluded. Full filesystem/schema equivalence is not claimed.

R16: user terminal shows APPLY_OK and 21 passing tests on server. Runtime reports a contextd unit definition but no matching process; enabled/active unit state was not measured. No daemon was started by this work. Moodle/USTAR database versions match installed version.php; Docker health checks are not configured.

Tours RC2: user confirms installation; all six payload files match production bytes. Native DB tour records and browser acceptance remain unverified. All snapshots are dated evidence, not instructions to deploy.

No working.patch, staged.patch, raw database dumps or backup file contents are included.
