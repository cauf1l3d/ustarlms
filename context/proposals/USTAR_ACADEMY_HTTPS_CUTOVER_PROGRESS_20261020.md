# Academy HTTPS cutover — execution record

Plan: [USTAR_ACADEMY_HTTPS_CUTOVER_PLAN_20261020.md](USTAR_ACADEMY_HTTPS_CUTOVER_PLAN_20261020.md).

Continuation resumed after source `d1e61a3b`, not after the older Academy install
preflight. The supplied shared conversation ends with the read-only directory
mount/container-recreation fixes and their CI running. Owner had already accepted
the static TLS page on phone over office WiFi/VPN and supplied the filtered HTTP
vhost with the exact observability Include. Neither action needs repeating.

Tasks1/2 implementation exists in `8649a1f` and `d1e61a3b`; this continuation
reviewed it without rewriting it or claiming a new RED-first cycle. Reviewed
boundaries: indirect wwwroot/bootstrap, retained opaque Include, directory versus
file bind, private before-images/mode/xattrs, exact configuration guards,
configuration-only recovery, current-container rollback, pending renewal recovery,
unchanged CA and persistent timer. No confirmed code defect found in this pass.
Owner forbids delegation; 0 subagents. This was self-review, not independent review.

Verification: 15 targeted tests PASS locally. Exact-source Context39459332144 and
Review39459332116 SUCCESS. PHP8.2/8.3 source jobs run811 tests/134 environment skips.
Downloaded native artifacts match GitHub digests. Real Apache/OpenSSL/PHP proxy,
redirect, renewal/recovery PASS; Docker/systemd boundaries are explicitly modeled
in that fixture. Separate actual Moodle5.1.8/PostgreSQL16 gate confirms canonical
wwwroot, sslproxy, cookiesecure, HTTPS server flag and native Secure session header.
The native Connect regression also passes124tests/629assertions, six concurrency
scenarios and paired93→99→93 recovery. This run skips full-RC jobs; it is not a
new full release or production browser acceptance.

Task3 handoff: [exact CI receipt](../runtime/connect_academy_https_ci_20261020.json)
and [pinned install/check/cutover/status/rollback](../runtime/connect_academy_https_20261020_commands.md).
Code source remains d1e61a3b; the handoff update changes context/docs only.
The agent has not accessed or changed server1. Owner execution and application
browser acceptance are pending; then private bridge/scoped keys, chat-link
candidate delivery and opt-in notification acceptance. Public Mini App remains a
separate external HTTPS gate. No new product scope or permission request.
