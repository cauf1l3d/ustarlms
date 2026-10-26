# Connect: direct private-chat account link

Continuation approved by owner20Oct04:39MSK after successful Academy browser
smoke. Owner explicitly confirmed canonical Academy is still http://ustar.local.
Execute inline, no subagents. RFC section4 already makes the authenticated worker
the verifier of ordinary provider events; canonical identity and second native
owner/session confirmation remain unchanged.

## Bounded change and dependency

Extend the existing receiver/link.claim/native link-management flow. A private,
fresh Telegram `/start uc1_...` claims the existing10minute challenge through a
link-only scoped bridge key. The bot shows the numeric Telegram ID and explains
that the same Academy session must confirm it. Possession of a code is not login,
role assignment or a completed link. No registration passwords in chat.

Use optional `linking_enabled=false`, independent of `onboarding_enabled`.
No Main Mini App requirement for chat linking. Keep old strict config compatible,
one consumer/journal/cursor, durable dedup and uncertainty without automatic retry.
Store only proof hashes and existing transport metadata, never message/code bodies.
Native config `ustar_connect_link_transport='chat'` chooses `?start=uc1_...`;
default `miniapp` preserves `?startapp=...`. Browser cannot select transport.
HTTPS/CSRF/current actor/same-session confirmation and revocation gates remain.

HTTP canonical Academy blocks activation, independently of external Mini App.
Do not loosen HTTPS checks, spoof TLS with forwarded headers, change wwwroot,
router/DNS/Xray or enable services to hide that dependency. This slice finishes
chat-link code and an exact operator HTTPS readiness report. Internal HTTPS
transport/origin rollout is a separate reviewable server step after that report;
private bridge and notifications remain off until accepted. No claim that a
receiver source package by itself enables real Academy links.

## Tasks and evidence

1. Native tests first:chat URL/config selection,unsupported transport denial,
   unchanged HTTPS/session/confirm behavior. Publish test-only RED into existing
   draft PR106;observe exact new failures before native implementation.
2. Python tests first:private fresh canonical command,identity/event-derived proof,
   no Mini App requirement,forward/group/stale/malformed rejection,rate limiting,
   restart/replay/privacy,unknown result and bridge rejection,static readable replies,
   backward-compatible config and HTTPS-only bridge. Observe RED then implement.
3. Native adapter/template and actual Chromium390/1280 chat instructions;unchanged
   schema,version bump2026102000 and regenerated development inventory. Native
   canonical link claim/confirm/revoke path remains tested,including least-scope key.
4. Read-only HTTPS readiness probe:static wwwroot extraction,filtered container
   bind/port facts and host webserver service/listener presence. No PHP bootstrap,
   secret config output,probe to unknown URLs,files/service/network writes.
5. Full r16/source/context,privileged host and native Connect/Chromium CI. Publish
   exact source/results/limitations and operator command. Record owner browser smoke
   and HTTP dependency;do not re-run the accepted installer or receiver deployment.

Review:proof depends on authenticated Telegram origin,not user-supplied sender ID;
transport key grants only link.claim,not notification/registration/actor operations.
Second owner confirmation remains mandatory;unknown outcomes remain visible and
do not cause repeated claims. TLS readiness is evidence,not a deployment claim.
