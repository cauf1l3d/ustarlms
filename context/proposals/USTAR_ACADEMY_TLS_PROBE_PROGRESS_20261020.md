# TLS probe ledger

Plan: [USTAR_ACADEMY_TLS_PROBE_PLAN_20261020.md](USTAR_ACADEMY_TLS_PROBE_PLAN_20261020.md).
Execution inline;no subagents per owner. No direct SSH or production changes.

Owner17:43 established Apache80/443,console8443;wwwroot RHS is a variable/expression
without SERVER/ENV/getenv or HTTP literals. Current config SHA96058835... remains
the immutable read-only baseline for this phase. No more general diagnostic cycles.

Ruling:use a static additive TLS probe before canonical Moodle change — owner can
verify trust for DNS leaf using the existing console CA while current HTTP stays
working. Cost if wrong is a guarded probe refusal/rollback,not an origin migration.
Permanent HTTPS/renewal/private bridge/link activation remain later operations.

Task1 RED:14new tests all failed for missing APIs before production code existed.
Initial implementation12passed/2failed:parser ignored explicit default-server
labels,and PID-change test's second loop reused the prior modeled phase. Fixed
fixture baseline reset;parser now validates default labels against first row.
Three added regressions observed RED for check-mode mkdir,missing durable receipt
before crypto,and transient reload readiness. Then17/17GREEN.
Wrong-live-leaf test observed RED,then live certificate fingerprint was pinned.
Native include-path case observed RED because Apache reports enabled symlink path,
not available target. Manager now expects the actual controlled include path.
Current19/19targeted GREEN,including real OpenSSL30day DNS/purpose verification,
wrong DNS rejection and unchanged CA/key/no serial mutation. Default plan has no
host access. TLS reload waits repeat only safe readbacks,not configuration writes.

Ruling:local Apache installation unavailable because this container cannot map
apt's service UIDs/groups. Use disposable Ubuntu CI with apache2-bin and a real
standalone Apache on18080/18443,not a claim from modeled tests. No sandbox escalation.

Task3 native fixture written:exact SNI leaf/default certificate,HTTP/config/CA
unchanged,403 other paths,prepare repeat/rollback,invalid Apache directive and
lost reload ACK recovery. Existing connect-runtime CI job gains this isolated
gate after receiver fixture;no production/systemd Apache replacement in fixture.
Native result pending. Full r16/source/context/personal review/publication pending.

Next:run full local tests,source/context;publish source for native CI and fix any
observed failure. Only after real Apache GREEN issue exact --check/--prepare/browser/
--status/--rollback commands. Never present static probe as Academy HTTPS accepted.

Final personal review found CLI umask077 could hide newly created static directories.
Added dedicated regression first: observed directory_mode_mismatch RED; only newly
created directories receive their explicit mode. Native fixture now uses CLI umask
for prepare. Full suite before this regression:790/31 skips PASS; final rerun pending.

Final local:20 targeted PASS (18.251s),791 r16 PASS/31 environment skips (62.425s).
Source checks JS24/templates57/schema99 PASS;PHP not run locally. Context344 PASS.
Personal review completed inline;no agents. Native Apache CI remains a release gate.
