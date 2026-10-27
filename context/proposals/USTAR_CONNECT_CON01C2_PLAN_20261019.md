# CON01C.2 — existing-account two-sided link management

19Oct2026 12:55MSK owner requested remaining work independent of external address. Execute personally (executing-plans/TDD), no subagents. Base cdd4909d/PR106; C1 exact Context39219098545/Review39219098394 SUCCESS. Spec: RFC section4 and existing identity_service/telegram_link_payload contract.

## Goal and boundary

Complete the existing-account binding UI over the existing uc1_ protocol: authenticated Academy owner issues a10minute challenge, opens a Main Mini App direct link, verified Telegram signed start_param claims it, then the SAME Academy owner/session explicitly confirms the displayed numeric subject. Revoke/list are owner-only. No passwords collected by bot; no new login/MFA bypass, Moodle cookie, employee role or auth handoff. Full remote login/recovery/step-up remains a separate slice.

## Files/interfaces

- Native: classes/connect/link_management.php (fixed actions/config/view model), identity_service::owned_links() (read-only, real actor), connect.php (require_login/HTTPS/no-store/POST-only CSRF), templates/connect.mustache and profile link. Public config `$CFG->ustar_connect_channels['telegram']=['tenant'=>'verified_bot_id','username'=>'ustarcorp_bot']`; absent config disables new issue, not owner revocation. No credential in config example.
- Worker: existing /api/launch routes signed uc1_ through existing link.claim; ul1_ keeps existing launch.exchange. RAM receipt scope link_confirmation is separate from onboarding entry; no code/proof/raw initData retained. Cookie+CSRF rotate. Only fixed claimed/uncertain state; status explicitly means Academy confirmation still required, not linked. No automatic retry after unknown outcome. Existing server rate limit max3launch/session applies.
- UI: render claim receipt and return-to-Academy instruction; no registration/options from link-only receipt. Existing-account instructions point to native profile→messenger connections. No generated private code in URLs other than allowed native Telegram startapp link; no GET mutations or raw secrets in logs.

## Tasks (RED→GREEN)

1. Native tests connect_link_management_test: disabled/malformed server config, read-only GET, HTTPS and CSRF/method denial, tenant not caller-selected, owner/session confirmation, same-owner repeat, cross-owner denial, revoke when issue disabled, current pending/suspended/service/view-as checks, no secret storage. Observe native RED in isolated CI before implementation; implement adapter/page/template, extend native filter. Existing identity lock/transaction controls stay canonical; no new schema. Native final suite PASS.
2. WSGI tests for signed uc1_, forgery/age/noncanonical, privacy/rotation, replay/reload/timeout uncertainty and no registration or Moodle session. Browser tests actual assets at390/1280, confirmation receipt, failure, logout, no horizontal overflow/storage. Observe RED before worker/UI edits, then GREEN.
3. Full r16/source/context, native+browser CI, personal final review, update context/inventory and PR106. Commands duplicated in chat. Main/production/router/BotFather/bot API untouched.

## Review focus

Proof must come only from signed start_param; no code/user/tenant from public body. Link receipt cannot become registration/employee grant. Changing native owner/sesskey/revoke invalidates old confirmation; previews remain owner-only. Unknown network outcome must not auto-claim again. Proxy HTTPS/Host metadata and real WebView cookies remain deployment acceptance, not synthetic test proof.

## Decisions

Use the already implemented link.claim+telegram_link_payload rather than create a second entry-linked claim protocol. Cost: user starts linking from an authenticated Academy session and must return to it; first remote login without an existing session is not supplied by this slice. Token supplied in chat was not used/stored; owner must revoke it before installation. Only public bot username retained. CrazeDNS em2025.netcraze.pro is a proposed ingress candidate, not verified availability or deployment acceptance.
