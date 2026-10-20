# Direct private-chat link progress

Owner20Oct04:39MSK accepted general Academy browser smoke after installer02b0548c,
and authorized next stage. Current production addon payload830d3703/local2026101905;
new chat-link source is not deployed. Owner confirms http://ustar.local,not HTTPS.
No subagents. [Plan](USTAR_CONNECT_CHAT_LINK_PLAN_20261020.md).

## RED

- Python14tests:13expected errors for missing linking_enabled/send_link_notice;
  existing HTTPS-rejection case already passed. Then implementation14/14GREEN;
  two further link-only probe/local-config cases pass,now16tests.
- HTTPS readiness6tests:missing module RED,then6GREEN;one global-deadline case added,
  now7tests. It prints filtered metadata
  and never enables services,changes routing/DNS/proxy or executes PHP/Moodle.
- Native test-onlya40ea20f:Context39355448506 SUCCESS;source PHP8.2/8.3 and runtime
  PASS. Native job118409733988/Review39355448463 failed exactly2new assertions:
  startapp URL instead of start URL,and unsupported transport not disabled.
  All121prior native cases passed;123tests/614assertions/2failures observed before
  native implementation. This was intentional isolated RED,not a production issue.

## Implementation

Receiver independently opts into private-chat linking;onboarding/Mini App stays
off. Only a canonical fresh private /start uc1 command from authenticated Bot API
data can invoke link.claim;proof is a domain-separated digest of bot/update/message/
sender identity. Journal persists existing metadata/cursor,not code or message body.
Replay/restart/rate/forward/group/age/noncanonical guards tested. Unknown claim
outcome is durable,not retried automatically;human reply cannot complete the link.
TLS-only bridge and native HTTPS/current actor/session/CSRF guards remain.

Native server config selects chat or miniapp transport;browser cannot select it.
Code stays within the existing canonical challenge+claim+second confirmation.
local version2026102000,552files/99tables,no schema changes. Native fixture probes
derive candidate versions from source so accepted830 and new candidate are not
confused. Source-only installer02 continues embedding immutable830 payload.

Existing probe test double gained explicit linking_enabled=false for the expanded
Configuration shape;actual legacy configs still default false. Targeted41receiver,
16chat,7readiness and3probe cases pass. Full local r16:766tests/31explicit
environment skips PASS;source checks JS/XML/templates PASS,PHP not run locally.
Generated candidate inventory552/99 and context340files validate. Final
native/Chromium/privileged-host CI pending;local checks do not replace them.

## Boundaries and next

General browser smoke is accepted,not exhaustive role/learning/SSO/cron/restore QA.
HTTP Academy blocks sensitive link activation. A narrow read-only readiness tool
and operator instructions are prepared before choosing a staged internal HTTPS
rollout. Existing HTTP remains unchanged. No production install,config,credential,
service,bot send,main merge,network or router action occurred in this slice.
After accepted HTTPS:private bridge/link-only key,worker credentials,lifecycle,
owner two-sided link and controlled opt-in notification with fresh cutover.
