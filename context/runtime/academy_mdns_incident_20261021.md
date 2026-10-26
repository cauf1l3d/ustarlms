# Academy name resolution — 21 October 2026

Priority: owner requires access for the whole office LAN without router DHCP changes
or individual Windows/phone rules. Frozen notification pilot a86e2080 stays pending
until browser access on office clients is accepted. Existing links are not reinstalled.
Owner receipt: `academy_mdns_owner_20261021.json`; evidence is terminal/screenshot,
not independent agent SSH. The browser-with-hosts success does not prove LAN DNS.

## Evidence and recovery

Windows Ethernet receives Google8.8.4.4/8.8.8.8 through DHCP, ManualDNS empty;
DHCPServer192.168.1.1 previously reported. VPN used172.19.0.2. Direct DNS request
against192.168.1.60 resolves Academy. These observations do not establish a change
in yesterday's client DNS. NRPT attempt failed WIN32_5 and was not applied; owner
explicitly rejected per-device remedies as the solution for this incident.

BIND and Avahi were running. Avahi's persistent hosts file contained only comments,
its FQDN was server1.local, and D-Bus GetState returned1=REGISTERING. Publisher
ustar-academy-mdns.service was running with no restarts but had not registered the
alias. Avahi restarted21Oct06:43UTC/09:43MSK, then did not reach a measured RUNNING
state until recovery. Root cause of that restart/stuck registration remains unknown.
A lost earlier temporary publication is only a hypothesis, not proven history.
The HTTPS deployment source does not modify DNS/DHCP/Avahi configuration.

Owner installed the following new persistent unit (root-owned0644); avahi-utils
was already installed, zero packages changed. Default server hostname and Avahi
configuration/service files were not rewritten. no-reverse avoids a competing PTR
record for the server's existing address; no-fail reconnects after daemon restart.

```ini
[Unit]
Description=USTAR Academy LAN hostname publication
Wants=avahi-daemon.service network-online.target
After=avahi-daemon.service network-online.target

[Service]
Type=simple
User=avahi
Group=avahi
ExecStart=/usr/bin/avahi-publish-address --no-reverse --no-fail ustar.local 192.168.1.60
Restart=on-failure
RestartSec=3
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes

[Install]
WantedBy=multi-user.target
```

Path: `/etc/systemd/system/ustar-academy-mdns.service`, enabled. Initial self-resolve
timed out because daemon state was REGISTERING; service existence was not acceptance.
Owner restarted only Avahi (terminal contains multiple restart commands). Latest
start10:23:27UTC, Server startup complete10:23:28UTC, publisher samePID established
ustar.local10:23:29UTC/13:23:29MSK. GetState2=RUNNING and local avahi-resolve returns
192.168.1.60. This accepts server-side publication only; not all-network browser QA.

## Next and future operation

Owner opens `https://ustar.local` from an office Wi-Fi phone and a PC without hosts.
Do not use nslookup against Google as mDNS acceptance. Check actual native browser
resolution, HTTPS trust and login separately. mDNS covers supporting clients on a
reachable multicast link; no automatic promise for isolated Wi-Fi, other VLANs or
VPN. If client access still fails, observe UDP5353 on enp4s0 during one client test
before changing firewall/reflectors or stacking fixes. Do not return to per-PC
configuration as the whole-office remedy. Exact client results are still pending.

Health checks (read only):

```bash
sudo busctl --system --timeout=5 call org.freedesktop.Avahi / org.freedesktop.Avahi.Server GetState
timeout 15s avahi-resolve-host-name -4 ustar.local
sudo journalctl -u ustar-academy-mdns.service -n 20 --no-pager -o short-iso
```

A measured return to state1 is an incident: inspect timestamps/interfaces/daemon
state; do not add blind periodic restarts or assume Docker is the cause. Current
logs show Docker interfaces but do not prove them responsible. Browser access and
boot/long-term stability have not been measured. Rollback only this new publisher:
`sudo systemctl disable --now ustar-academy-mdns.service` (does not restore business
data or alter router/Apache/Moodle/bridge). Leave shared Avahi running.

After LAN acceptance resume the already frozen one-employee notification pilot:
`connect_notification_pilot_20261021.md`, SHA d06b88dc..., sourcea86e2080.
No pilot activation/new messages/DB or provider writes by the agent.0subagents.
