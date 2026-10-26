# Guarded private include parent preparation

Owner receipt: [readonly refusal](connect_private_bridge_parent_owner_20261021.json).
Exact outer directory `/opt/ustar/data/moodle/public` is UID33/GID33/0755.
This is the directory above the accepted document root ending in `/public/public`.
The refusal is a safety guard, not an Academy outage. The check made no config change.

## Scope and review

This bounded continuation of the approved root-managed include plan protects only
the outer directory. Exact candidate33:33/0755 and protected ancestors are required.
The operation first validates all remaining narrow delivery inputs, including live
HTTPS, eight transport/version files, fixed backend binding/gateway and receiver
config/units. It does not repeat the full source/DB inventory, cron or backup.
Normal Moodle bootstrap may create an anonymous session/cache/access-log entry.

Descriptor-based fchown changes only directory UID33 to0. GID33,mode0755,extended
attributes and the content/metadata of every child remain unchanged. Config.php
retains its accepted UID/mode/xattrs. No recursive chmod/chown,service restart,
container recreation,key generation,Telegram API call or bridge activation occurs.

A private journal `/var/lib/ustar-connect-private-parent/state.json` preserves the
original UID/GID/mode,device/inode and xattr digests before the change. Existing
deployment/HTTPS/cron/backup/receiver locks serialize it. Interrupted prepare may
be repeated with the same command;unknown identity/metadata drift refuses.
`--restore-include-parent` restores the recorded UID only while no bridge/include/
key/drop-in exists and legacy HTTPS ownership remains accepted. Once the bridge
is configured, restore refuses;deactivation must precede any parent restoration.
Do not manually delete this journal or use recursive ownership commands.

## Frozen package

- Source: `e58354d3638e0a058b9ff20e53615de13c2e13e2`.
- Tree: `155919f1c2fd922cc65f7faf5ac09963ce06207d`.
- File: `ustar-private-bridge-check-e58354d3.pyz`.
- SHA256: `20038cba4bcb0e50e9af965c9c9bf66fd9a85f00b2c64e01532d3bee21cb38ae`.
- Application payload remains `1e8a8819f50785ebb4fb2640eeb9386c969d59d1`.
- Default PLAN_ONLY, `--check` is readonly; only the explicit preparation flag
  changes directory ownership. No bridge activation mode exists.

[Exact CI receipt](connect_private_parent_prepare_ci_20261021.json):Context39519644446
and Review39519644489 SUCCESS,both PHP source jobs895tests/145explicit skips. Runtime118960063858
SUCCESS includes9strict real UID33 parent tests with zero skips.
[Download ZIP12311131323](https://github.com/cauf1l3d/ustarlms/actions/runs/39519644489/artifacts/12311131323).
Downloaded CI bytes equal the independent local exact Git build. Actual Moodle/PG
124tests/629assertions+6concurrency,private UID33 include/scope and both paired restore
gates also pass on this exact source. Full RC is skipped.
Author self-review only,0subagents;no server1/provider operation by agent.

## Owner execution

Upload the frozen PYZ with WinSCP to `/home/aduk/ustar-private-bridge-check-e58354d3.pyz`.
In SSH:

```bash
sudo -v
sudo install -o root -g root -m 600 /home/aduk/ustar-private-bridge-check-e58354d3.pyz /root/ustar-private-bridge-check-e58354d3.pyz &&
printf '%s  %s\n' 20038cba4bcb0e50e9af965c9c9bf66fd9a85f00b2c64e01532d3bee21cb38ae /root/ustar-private-bridge-check-e58354d3.pyz | sudo sha256sum --check --strict - &&
sudo timeout --kill-after=3s 90s /usr/bin/python3 -I -B /root/ustar-private-bridge-check-e58354d3.pyz --prepare-include-parent
```

Send only the JSON. Expected phase is `include_parent_prepared`,after UID0/GID33/
mode493(decimal0755),recursive=false,configuration_changed=false,bridge_activated=false.
If an ancestor or another prerequisite refuses,the operation makes no ownership
change. `managed_operation_busy_retry_later` means wait for the existing managed
operation;do not remove locks. A post-change interruption has a durable journal:
repeat the same command rather than manually changing ownership.

This result is not account-link acceptance. The coordinated HTTPS ownership/key/
credential/receiver activation transaction and same-session link/revoke remain next.
