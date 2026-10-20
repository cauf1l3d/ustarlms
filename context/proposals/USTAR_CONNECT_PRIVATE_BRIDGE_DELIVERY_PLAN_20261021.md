# Private loopback bridge delivery after owner application2000 acceptance

Continuation of the approved RFC/private chat-link plan,executed inline with no subagents.
[Owner receipt](../runtime/connect_chat_link_update_owner_20261021.json):installed
payload1e8a8819/local2000/theme0202/552files/99tables,new scheduled22:08UTC exit0,
Academy general browser-open accepted. No application reinstall or broad preflight repeat.

## Intended result

Existing receiver invokes only link.claim through a private loopback TLS endpoint.
The employee issues a challenge in the existing Academy session,opens private Telegram
and confirms the claimed identity in that same Academy session. The worker does not
log in a Moodle user or grant roles/employment. Notifications remain a separate opt-in
gate;external MiniApp remains deferred. The Academy application payload is unchanged.

## Transport and trust boundary

Keep the accepted canonical proxy architecture:worker verifies TLS on127.0.0.1:18443,
host Apache proxies only the fixed bridge path to the existing127.0.0.1:8082 backend.
Restrict the private listener to loopback and deny the bridge through public80/443.
Do not use ambient proxies,redirects,client-forwarded HTTPS headers or a public bridge.

Important source finding:actual Moodle5.1.8 public/lib/setuplib.php at
cc0bf17da8b5fbd290faf95b2b14a03d64331923 normalizes server HTTPS=on under the already
accepted CFG.sslproxy. The existing actual-Moodle HTTPS fixture proves this on HTTP.
Therefore the post-bootstrap bridge_http HTTPS flag is not independent evidence of
TLS on the container socket. Security acceptance must combine the trusted Apache TLS
listener,public path denial,exact backend peer,HMAC and least-scope key. Do not add
container TLS/modules,change Docker networking or weaken the existing PHP guard.

The20Oct delivery receipt already measured gateway172.18.0.1,loopback8082,free18443.
The installer rechecks the exact current binding and uses one exact candidate peer;
a signed round trip must establish it rather than allowlisting arbitrary peers.
No additional owner diagnostic inventory is required merely for documentation.

## Native configuration finding

Actual Moodle5.1.8/PG16 HTTP receipt on e4 showed HTTPS=on and exact localhost peer,
but a pre-bootstrap PHP boolean bridge flag became string"1" and correctly failed
`=== true`. Keep the existing strict PHP guard and unchanged application payload.
The plugin-only private include must execute after the core setup require returns;
canonical HTTPS/DB settings stay before bootstrap. The native fixture now uses the
same delivery compiler/include and tests UID33 group-read/no-write permissions.
The frozen e4 check package has no activation mode and never invokes its compiler;
its readonly readiness check remains valid. Future activation uses the corrected source.

## Ownership and lifetime

- Extend the existing HTTPS manager with a managed bridge profile. Preserve the
  private original HTTPS before-images,observability include,canonical origin,
  units/timer and unrelated vhosts. Public bridge denial and private listener must
  survive certificate renewal. HTTP rollback refuses while bridge activation exists.
- Renew one CA-signed leaf covering ustar.local,localhost and127.0.0.1;both owned TLS
  vhosts use that same leaf. Existing daily renewal remains the only certificate
  scheduler. Worker CA trust is a separate systemd credential,not a disabled TLS check.
- New root-managed PHP configuration include outside document root contains only the
  independent link HMAC/channel config. Runtime group may read it but cannot write it;
  preserve existing config.php ownership/mode/xattrs during its include handoff.
- Generate an independent256bit key locally. Allowed operations exactly[link.claim],
  provider telegram and tenant current bot ID. No registration/actor/notification scope.
  Never request keys/token in chat or commit them to Git.
- Existing receiver release installer supplies verified current chat-link code.
  One consumer is stopped before switch;token,StateDirectory,SQLite journal,dedup and
  cursor are preserved. Rollback never restores an old journal. Credential drop-ins
  for receiver/probe are exact owned files;unknown overrides refuse. Loopback-only
  kernel network policy is retained.
- Signed forbidden-operation probe establishes TLS,peer,HMAC and scope rejection
  before nonce consumption or employee writes. Existing probe runs it with the same
  systemd credentials/network isolation as receiver when linking is enabled.

## Tasks and verification

1. Record owner application2000/scheduled cron/browser receipt;leave historical
   receipts immutable and effective deployment/source inventory scopes distinct.
2. Tests first:managed vhost/profile rendering,renewal preserving public denial and
   private listener,config ownership/rollback ordering;link-scope probe must not mutate
   challenges,links or notification state. Existing legacy config remains compatible.
3. Guarded install/check/activate/status/deactivate transaction with durable private
   before-images,existing HTTPS/cron/backup/deployment locks and bounded operations.
   Default PLAN_ONLY. Never modify the frozen6aa updater or repeat its cold copy.
4. Real disposable Apache/OpenSSL→HTTP→actual Moodle5.1.8/PG16 proof:trusted canonical
   sslproxy,private signed scope rejection,public denial,exact peer;plus real service
   credentials/isolation,partial-write/lost-ACK recovery and administrator-drift tests.
5. Freeze exact source/receiver/hash;publish CI receipts and downloadable package.
   Owner acceptance:real private signed probe,Academy→Telegram→same browser confirm,
   replay/stale/group denial,unlink and denial after unlink. Then opt-in notify with
   fresh cutoff and no historical queue replay.

## Current boundary

Owner application update is complete. Source review of the existing transport and
Moodle bootstrap is complete. The private bridge/root transaction and updated scoped
probe still require implementation and native verification. No server activation,
credential generation or Telegram account-link/delivery occurred by the agent.
Author self-review only;owner forbids subagents. Main remains unmerged.
