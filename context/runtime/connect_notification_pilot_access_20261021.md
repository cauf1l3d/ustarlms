# Notification pilot: staged release access refusal, 21Oct2026

## Owner evidence

Frozen a86e2080 PYZ SHA d06b88dc081028ce860774fbbe290dadb1c7a55bef82bd1ae135de8a5bd1ad33 verified.
Activation for numeric Moodle UID130 reached receiver_switch and refused
host_command_failed; recovery_state=inactive. No activation success claimed.
Owner journal21Oct11:35:34–11:35:35UTC (14:35MSK): original no-poll probe
telegram_verified, link_scope=verified. At11:35:56UTC new probe Python cannot open
/opt/ustar-connect/current/receiver.pyz: EACCES. Old receiver restarted11:35:57UTC,
then successful polling with failures0. Status notification_pilot_inactive,
sender_active=false, linking_preserved=true, business_data_restored=false.
The notify unit was not started; ConditionResult=no is not the root failure.

## Reproduction and fix

Pilot CLI sets umask077. stage used Path.mkdir(mode=0755), so the actual newly
created release directory became0700. Atomic publication retained that mode;
service users could not traverse it. Public files themselves use explicit0644.
Regression test test_service_can_read_staged_release_with_private_umask observed
RED493!=448 (0755!=0700), then GREEN after using existing connect_install.mkdir,
which explicitly sets0755 before atomic publication. Tests retain token0600.
Strict UID33 CI additionally opens receiver.pyz and notifications.pyz from that
release after dropping UID/GID. Earlier UID33 pilot tests did not set the CLI
umask and modeled systemd; their PASS did not cover this failure boundary.
Nine targeted tests and full937r16/50environment skips PASS in the exact stage
dependency venv. First system-Python full run had3missing-MCP import errors;
installing the pinned dependencies resolved them. Recovery command6isolated cases
(normal/idempotent, code drift, extra file, symlink, wrong current, writable parent) PASS.
New strict UID33 CI remains pending at publication.
No cause attributed to Telegram connectivity or browser CA trust.

## Guarded recovery of the owner's existing frozen package

Do not replace the source-bound inactive a86 journal with a different frozen
package. Repair only the non-current a86 immutable public-code cache directory.
First run the current frozen --status: expect notification_pilot_inactive and
pilot_userids=[130], sender_active=false. The old receiver must be active.
The following command verifies the root bundle, exact archive and installed
code, inventory, current old-release pointer and Unix metadata before changing
one directory from0700 to0755. It is idempotent for already0755. Any unknown drift
refuses; no recursive chmod, credential write, service operation or API call.

```bash
sudo -n /usr/bin/python3 -I - <<'USTAR_NOTIFY_ACCESS'
import hashlib, io, json, os, stat, sys, tarfile
from pathlib import Path
p = Path('/root/ustar-notify-pilot-a86e2080.pyz')
expected = 'd06b88dc081028ce860774fbbe290dadb1c7a55bef82bd1ae135de8a5bd1ad33'
def require(ok, reason):
    if not ok:
        raise SystemExit('STOP: ' + reason)
st = p.lstat()
require(stat.S_ISREG(st.st_mode) and st.st_uid == 0 and st.st_nlink == 1
        and stat.S_IMODE(st.st_mode) == 0o600, 'unsafe_bundle')
require(hashlib.sha256(p.read_bytes()).hexdigest() == expected, 'bundle_hash_mismatch')
sys.path.insert(0, str(p))
from connect_notification_pilot import bundle
from connect_install import Installation
ref, _, archive, _ = bundle(p)
i = Installation()
old = i.prefix / 'releases' / ref['bridge_release']
release = i.prefix / 'releases' / ref['tool_commit']
require(i.current() == old, 'expected_old_receiver_release')
with tarfile.open(fileobj=io.BytesIO(archive), mode='r:gz') as t:
    values = {m.name: t.extractfile(m).read() for m in t.getmembers()}
values.pop('manifest.json')
require(i.release_files(release) == values, 'staged_release_bytes_changed')
require({str(x.relative_to(release)) for x in release.rglob('*')}
        == set(values) | {'manifest.json', 'units'}, 'unexpected_release_file')
for name in (*values, 'manifest.json', 'units'):
    st = (release / name).lstat()
    require(st.st_uid == 0 and st.st_gid == 0 and not stat.S_ISLNK(st.st_mode)
            and stat.S_IMODE(st.st_mode) == (0o755 if name == 'units' else 0o644),
            'unexpected_release_permissions')
st = release.lstat()
require(stat.S_ISDIR(st.st_mode) and st.st_uid == 0 and st.st_gid == 0
        and stat.S_IMODE(st.st_mode) in (0o700, 0o755), 'unexpected_release_directory')
fd = os.open(release, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
try:
    require((os.fstat(fd).st_dev, os.fstat(fd).st_ino) == (st.st_dev, st.st_ino), 'release_changed')
    os.fchmod(fd, 0o755)
    os.fsync(fd)
finally:
    os.close(fd)
print(json.dumps({'phase': 'staged_release_access_repaired', 'mode': '0755', 'content_changed': False}))
USTAR_NOTIFY_ACCESS
```

Expected staged_release_access_repaired, mode0755, content_changed=false.
Then reuse the original verified package for the same UID:

```bash
sudo /usr/bin/python3 -I -B /root/ustar-notify-pilot-a86e2080.pyz --activate --pilot-userid 130
```

Old package prepare rechecks current identity/scope and all staged release bytes
before switching. Native personal opt-in/new-task delivery/revoke remain pending.
Owner already saved opt-in and Tasks, all severities, Europe/Moscow, quiet22–08,
interval1min (screenshot21Oct14:26MSK). Preferences are not activation evidence.

## Separate browser trust item

Owner live openssl validates ustar.local against the unchanged
CA cc397ba406aa58445e4f57a5e2644df20bd746273403e555f3df736694d5935f.
Windows time21Oct13:38MSK matches validity; WORKGROUP and both Trusted Root stores
lack CN=USTAR Console LAN Root CA. Name error reportedly gone; phone/browser trust
not accepted. Owner considered sysadmin distribution/public hostname, chose no
migration and explicitly resumed Telegram. Keep ustar.local unchanged; issuing a fresh CA or dropping TLS verification is not a remedy.
No agent SSH/provider sends;0subagents;main unmerged.
