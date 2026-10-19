# Execution ledger — plan: USTAR_CONNECT_CON01C4_PLAN_20261019.md

Owner19Oct15:31MSK: continue after C3. Base941a853cbfd03eaefd691fdbdf413607d9a92aba/tree f27a69294fbaaeae11d97edaec426a70fb797f5f verified remote PR106 draft. C3 Context39251446669/Review39251446110 SUCCESS;106native/530assertions and5concurrency + schema parity; do not repeat C3 work. Dedicated clean branch codex/connect-actor-session-20261019 in existing isolated feature checkout. No agents/server/network/secret operations.

Pre-flight shared interfaces: entry→actor: keep onboarding authority distinct, add explicit scope/authdate/actorready and fresh ua1_ instead of upgrading ue1_ privilege. Actor→BFF: exact envelope with actor token private, public fixed profile only; idle expiry renewed only by successful canonical read. Schema→privacy/prune: reuse conn_sessions, add metadata to install/upgrade/export descriptions but not token/fingerprint exports; expiresat remains absolute bound for generic cleanup.

Ruling: execute approved RFC's provider-session portion first, not a new password endpoint or direct complete_user_login. Native MFA enabled/alternate login/nonmanual auth/forced-password-change block provider-read until separate accepted native authentication exists. Cost: these users cannot open this provider profile until that flow is supplied, even if their Telegram link is valid. This preserves existing auth policy.
Ruling: retain99tables by extending typed conn_sessions with6fields. Cost: every entry reader must explicitly enforce onboarding scope and migrations must default legacy entries to actorready0. Tests pin both boundaries.
Ruling: owner prohibits subagents; final code review will be personal. Existing authorization covers continued inline source implementation; do not add repeated permission gates or request external credentials for offline work.

Task1: tests next. Native RED/implementation/CI not yet claimed. Plan and RFC read; authoritative C3 final receipt remains PR106.

Task2 parallel boundary work while immutable native RED runs:13WSGI tests observed12missing /activate failures +1missing typed rejection error; implemented private actor_web validator/activation/rotation/status/logout, then13/13PASS and existing31webPASS. Test cookie jar corrected to remove Max-Age=0 cookies like a browser; no product cookie guard weakened. Whole674r16 OK/30environment skips. Browser test written before UI; pending expected missing profile button/heading in actual Chromium. Task1 PHP product not yet implemented, native test-only source e0399367.

Native test fixture correction: e0399367 Review39253555166/job118063982621 stopped at PHPUnit fatal: private status helper collided with final TestCase::status. No actor assertions ran. Rename helper actor_status; temporarily defer new browser step so native tests can execute and demonstrate the missing actor service. This is not an expected product RED.
