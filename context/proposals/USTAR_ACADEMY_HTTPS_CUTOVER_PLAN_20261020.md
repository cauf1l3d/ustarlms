# Academy canonical HTTPS and renewal implementation plan

> Execute inline with superpowers:executing-plans and TDD; owner forbids subagents.

**Goal:** Turn the owner-accepted static TLS probe into canonical Academy HTTPS with daily certificate renewal and reversible configuration-only cutover.
**Architecture:** Existing Apache80/443, loopback Moodle8082 and console CA. Reuse the exact installed TLS helper3303069b/SHA7b5fd3a6; preserve the existing HTTP vhost body and opaque observability Include in the TLS proxy. No new identity, database, router, DNS, Caddy or provider subsystem.
**Spec:** Existing TLS plan plus owner19:01 browser/phone/VPN acceptance and19:20 HTTP vhost report.

## Constraints and review focus

- Existing production Moodle source/schema/data stay untouched. Only config.php, owned probe vhost/health, existing ustar80 vhost and two new renewal units change.
- config.php baseline SHA96058835... and public CA fingerprintcc397ba4... remain pinned. Private before-images stay root0700 on host, never exported.
- Preserve Include /etc/apache2/ustar-console-observability.conf byte-for-byte; hash it before/after. Do not infer its content from filename or recreate it.
- HTTP302 must preserve path/query; HTTPS must have verified chain/hostname/exact leaf, no redirect loop, correct actual Moodle wwwroot/sslproxy/cookiesecure and secure session cookie.
- File bind versus directory bind: refuse file-only config mounts before any atomic replacement. Preserve Unix ownership/mode and xattrs.
- Apache config/reload/cache/health/timer failures and interrupted operations must restore only unchanged owned files. Never roll back business DB/files or other services.
- Renewal must survive reboot, avoid any Moodle/DB writes, preserve CA/key, verify new leaf before accepting it and recover a pending renewal after interruption.
- Working HTTP canonical origin becomes https://ustar.local; explicit --cutover --acknowledge-outage. Rollback restores HTTP80 plus the previously accepted static TLS probe.

## Task1: configuration transforms and transaction

Files: scripts/infra/academy_https.py; tests/r16/test_academy_https.py.
Interfaces: patch_config(raw)->bytes; proxy_and_redirect(raw,paths,cert,key,http_port=80,https_port=443,backend_port=8082)->(bytes,bytes); Manager(lib,paths,host,uid,baseline_config).check/cutover/status/rollback.
- [x] RED tests: indirect wwwroot preserved before managed overrides; one setup suffix only; duplicate managed block/refused trailing executable code; original proxy/Include preserved in TLS; HTTP proxy removed in redirect; unsupported vhost topology rejected.
- [x] Implement root-private durable snapshots, directory-bind/container hash/lint checks, exact vhost map comparison and hash/PID guards. All file mutations tracked before writes; config reload failure uses configuration-only recovery.
- [x] GREEN transaction tests: apply/repeat/rollback, unexpected administrator edits, reload/cache/TLS/timer failure, restart after interrupted transaction. Run full r16.

## Task2: daily renewal lifecycle

Same production file; renewal unit strings in it. No new Python dependency.
- [x] RED tests: default mode no host access; timer ExecStart uses fixed installed root script; no-op above10days; due renewal replaces exact leaf; failed or interrupted renewal restores prior TLS config; modified owned config is not overwritten.
- [x] Implement root-lock --renew with30day leaf/10day threshold, persistent daily timer, safe recovery of pending generation. New private key/cert remain0700 generation. Never restart Apache; graceful reload only.
- [x] GREEN root-filesystem tests and source/context checks.

## Task3: native evidence and owner handoff

Files: tests/stage/academy_https_native.py; tests/stage/academy_https_moodle.php; tests/stage/run-connect.sh; .github/workflows/ustar-review-gate.yml; runtime receipt/runbook.
- [x] Real isolated Apache/OpenSSL/PHP backend: copy opaque observability Include, canonical HTTP redirect preserving query, verified HTTPS proxy/login/session cookie, renew and forced failures/recovery. Model only systemd/container boundaries explicitly.
- [x] Existing real Moodle5.1.8/PostgreSQL16 fixture: run managed config overrides before native bootstrap, verify https wwwroot/sslproxy/cookiesecure/core URL, restore original config in finally. This complements Apache fixture; neither is production browser acceptance.
- [x] Publish exact source, inspect native and source CI, then provide pinned download/hash --check before owner cutover. No command to apply until required gates pass.

## Completion evidence

Implementation tasks are verified on source d1e61a3b; source/native CI and pinned
owner handoff are complete. [Execution record](USTAR_ACADEMY_HTTPS_CUTOVER_PROGRESS_20261020.md),
[exact CI receipt](../runtime/connect_academy_https_ci_20261020.json).
Owner production cutover and application browser acceptance remain pending.
Plan completion is not a deployment or business-bot activation claim.
