# Academy TLS probe implementation plan

Execute inline with superpowers:executing-plans;owner forbids subagents. Existing
authorization covers preparing tested server tools and instructions,not pretending
agent has SSH. Owner17:43 confirms Apache owns80/443,console owns8443;current
config SHA25696058835f27c21b99db7bde137677921223a5b0e013b1c6fa3a1a3415ac4361d.

Goal:real trusted TLS/browser acceptance for `ustar.local`
before changing Moodle origin. Scope is one additive Apache443 probe vhost.
Existing HTTP Academy and all unrelated virtual hosts stay configured as before.

## Architecture and boundaries

New `scripts/infra/academy_tls_probe.py`,stdlib Python3.12/OpenSSL/Apache2.4.
Default plan,explicit --check/--prepare/--status/--rollback. CLI always uses fixed
production paths,ports80/443 and root ownership. Test fixtures inject filesystem
paths/host boundary by import;there is no production bypass flag.

Use protected `/etc/ustar-console/authority/ca.crt` and ca.key locally. Verify
certificate purpose/expiry/public-key match;never export key or modify CA/serial
files. Issue a separate30day serverAuth leaf DNS:ustar.local with random serial.
Private stage state/cert/key under `/var/lib/ustar-academy-tls-probe`0700;
public static HTML under `/srv/ustar-academy-tls-probe`0755,files0644.

Add only `zzzz-ustar-academy-tls-probe.conf` in Apache sites-available plus managed
sites-enabled symlink. No Listen directive/new port/firewall/DNS/route change.
TLS1.2/1.3,exact ServerName `ustar.local`,no HSTS,ProxyPassInherit Off,
ProxyPass / !,default static handler,all paths denied except
`/__ustar_tls_probe__/ready.html`. No Moodle proxy or PHP handler. No request logs
for this probe host. SSL private key is read only by normal Apache root master.

Before reload:configtest and native vhost dump. Parse effective bindings,default
ordering,names/aliases and source locations. Refuse preexisting443 name/wildcard
matching `ustar.local`,ambiguous dump or changed preexisting vhosts. New host must
append to existing wildcard443 binding without becoming its default. Never rename
or rewrite another host. Snapshot Caddy/console/Apache active/MainPID and HTTP HEAD
status;ordinary HEAD may create the usual web session/log,not business changes.

Durable root-private state before enabling the host. Validate leaf/CA TLS over
127.0.0.1:443 with real SNI `ustar.local` and exact expected static body;no disabled
certificate verification,redirects,proxy environment or DNS dependency. Existing
HTTP status,config SHA and other service identities must remain equal. On failure
remove only unchanged owned enabled link,configtest and graceful reload. Preserve
private evidence/material;never stop/restart Apache or touch Moodle/data/DB.
Modified owned artifacts cause explicit recovery refusal,not deletion by guess.
SIGTERM/interrupts use the same rollback;SIGKILL leaves recoverable durable state.

This is NOT Academy HTTPS/Mini App/link activation. Browser must open the probe URL
without a certificate exception. Then a separate procedure can switch canonical
wwwroot to HTTPS,enable standard Moodle sslproxy and redirect old HTTP links.
Official Moodle5.1 setuplib supports sslproxy by setting server HTTPS after bootstrap;
no caller-forwarded header trust is added. Permanent TLS renewal,private bridge
loopback access restriction/keys,worker credentials/link/revoke and opt-in delivery
remain mandatory later steps. Console trust already reported by owner is not a
substitute for this new DNS leaf acceptance by the required client population.

## Tasks and verification

1. [x] Tests first for native-vhost parser/default preservation/name collision;
   safe static vhost;default plan no host access;real filesystem prepare/retry/
   rollback/partial reload/modified artifact/crash receipt;actual OpenSSL key match,
   signer unchanged and certificate DNS/purpose/expiry validation. Observe RED.
2. [x] Implement Host/Paths/Manager and bounded sanitized CLI;21targeted tests PASS,including CLI umask077 and native-padding regressions.
   Missing/writable/symlinked/hardlinked files,wrong baseline hash and existing
   unmanaged paths fail before reload. Certificate commands never print private
   stdout/exception args. Single writer lock and source/config rechecks are required.
3. [x] Native isolated Apache+OpenSSL fixture on high ports,own config/CA/default
   host. Verify SNI leaf,403 other paths,unchanged default host certificate/HTTP,
   graceful rollback and invalid config recovery. CI may install Apache/OpenSSL
   only on disposable runner;never production dependencies. No live provider.
4. [x] Full r16/source/context checks,personal security review,no subagents.
   Publish exact code/CI/hash and pinned --check→--prepare→browser→--status steps,
   with --rollback available. Record owner facts separately from code/CI/deploy.

## Review focus

- Existing default443 host must survive addition and removal;test single/named
  dumps,aliases,wildcards,changed row order and every unrelated binding.
- Browser certificate trust must remain real;test DNS mismatch/untrusted CA/HTTP
  wrong body and HTTP/HTTPS endpoints,without CERT_NONE.
- Failure/restart must not leave an accepted state with an unverified host;test
  durable preparing receipt and explicit rollback,including changed owned file.
- Shared services/HTTP baseline must be preserved;test changed PID/status/config
  and no stop/restart/Docker/Moodle write operations.
- Files/keys must stay private and command output sanitized;test unsafe file type,
  ownership/mode/link,CA key mismatch and subprocess errors containing a sentinel.

Implementation52a8a1ca verified:Context39447539405,source PHP8.2/8.3 and actual
Apache runtime118713514071 SUCCESS.792r16/134CI skips;21targeted local.
[CI receipt](../runtime/connect_academy_tls_probe_ci_20261020.json),
[pinned owner commands](../runtime/connect_https_tls_probe_20261020_commands.md).
No owner deployment/browser acceptance yet.
