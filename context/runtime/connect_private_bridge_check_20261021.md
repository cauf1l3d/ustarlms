# Private bridge — limited readonly delivery check

Application2000 and fresh scheduled cron were accepted by the owner at c95e59dc.
Do not repeat upgrade, cold pair, backup preparation, HTTPS cutover, cron repair,
receiver installation/configuration or a whole source inventory.

This package checks only the next private-key placement/transport prerequisites.
It does not activate a bridge or change ownership/permissions. No key is generated,
Telegram is not called, no updates are consumed, the journal is not opened and SQL,
cron, backups and application migrations are not run. Existing HTTPS verification
can create a normal anonymous Moodle session/cache entry and access-log records.
Default mode is PLAN_ONLY, with no host access. Only --check is supported.

## Exact package

- Source:e4d7a154028ccf04b2198e951bf9631e5ef5a32f.
- Accepted application reference:1e8a8819f50785ebb4fb2640eeb9386c969d59d1,unchanged.
- File:`ustar-private-bridge-check-e4d7a154.pyz`.
- SHA256:`14de548410f1cc861e9f9eae8d79018ab4f6dee67c218a4f84d29fdae95075af`.
- [Download ZIP12307432021](https://github.com/cauf1l3d/ustarlms/actions/runs/39514608522/artifacts/12307432021).
- Downloaded CI bytes match an independent local Git assembly byte for byte.
- Explicitly not a full private-bridge activation package. No --activate switch exists.

Extract ZIP on the workstation. Transfer only the PYZ to
`/home/aduk/ustar-private-bridge-check-e4d7a154.pyz` on server1. Then:

```bash
sudo install -o root -g root -m 600 /home/aduk/ustar-private-bridge-check-e4d7a154.pyz /root/ustar-private-bridge-check-e4d7a154.pyz &&
printf '%s  %s\n' 14de548410f1cc861e9f9eae8d79018ab4f6dee67c218a4f84d29fdae95075af /root/ustar-private-bridge-check-e4d7a154.pyz | sudo sha256sum --check --strict - &&
sudo timeout --kill-after=3s 90s /usr/bin/python3 -I -B /root/ustar-private-bridge-check-e4d7a154.pyz --check
```

Only the JSON result is needed;do not send config.php,tokens,keys or private archives.
The check uses existing deployment/HTTPS/cron/backup/receiver installer locks.
`managed_operation_busy_retry_later` means another managed operation holds a lock;
let that operation finish. Never remove lock files.

## What the result proves

The protected parent for a root-managed include outside the accepted document root
is a new necessary fact. Ownership of config.php by UID33 alone does not prove it:
PHP must not be able to rename the parent and replace the include. The check refuses
foreign or writable ancestors and returns only safe path/UID/GID/mode metadata.
It does not repair/chmod/chown them by assumption.

Eight pinned transport/version files are compared,not all552 source files. Current
HTTPS ownership/live CA/leaf/cookie,fixed container directory bind,exact receiver unit
manifest and private config,one gateway candidate and free loopback18443 are checked.
The port is not reserved;candidate172.18.0.1 is not yet a proven effective PHP peer.
No private signed request or employee/link write happens in this check.
Database versions/schema are not re-queried;the accepted application receipt remains
its own evidence. `ready_for_private_bridge_preparation` is not activation acceptance.

## Source verification boundary

Sourcee4:both PHP8.2/8.3 jobs SUCCESS,886r16/136explicit skips,381PHP/24JS/57templates/
99tables. Local full suite in dependency venv:886tests/34environment skips PASS. Actual systemd scoped credentials/ACL/UID and Apache/OpenSSL/PHP runtime
job118943644504 SUCCESS. Wrong key blocks start;token/SQLite are unchanged. Native
profile covers public path variants,POST-only,signed denial,shared leaf,renewal,
lost ACK and recovery. The Apache Moodle bootstrap/Docker boundary is modeled;
the systemd API opener is synthetic. These are separate fixtures,not a combined
production Moodle/TLS/service acceptance.

Earlier5cb actual Moodle/PG passed124tests/629assertions and6concurrency,then the new
HTTP signed-scope check refused. Exacte4 whole Review39514608522 FAILED at the new Moodle HTTP scope gate. Diagnostic:
HTTPS=on/exact127.0.0.1 peer,but pre-bootstrap true was normalized to string"1";
strict boolean guard returned404bridge_disabled. Current delivery source moves its
plugin-only include after bootstrap and tests that actual compiler in native Moodle.
This frozen readonly package has no activation mode and never executes that compiler;
its check remains valid. Whole PR green is not claimed;full RC is skipped.
Self-review only,0subagents;agent has no server1 access or provider actions.

Next after the owner result and native scope gate:coordinated HTTPS tool/state
handoff,root-managed private include,independent256bit link.claim-only key scoped to
current bot,exact LoadCredential drop-ins and one receiver switch preserving cursor.
Then same-session link/confirm/revoke and separate opt-in notification delivery.
