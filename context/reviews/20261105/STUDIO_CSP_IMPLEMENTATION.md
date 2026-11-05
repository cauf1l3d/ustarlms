# Source review05Nov2026 — cumulative Studio CSP successor

Author-only trace review, no subagents/independent reviewer or CodeRabbit run claimed.

Application901ac9eb: one4-line addition to course_studio.php. Existing authentication, require_author, view-as refusal, PAGE setup, CSP/cache/nosniff headers, assets, source sanitizers, preview policies and iframe sandbox remain byte-identical. Static trusted html_writer::script has no user interpolation; request-local CFG append preserves admin head and performs no set_config/DB write. Pinned core emits YUI_config before additionalhtmlhead and YUI afterwards. Actual browser must prove this order, Y.one, strict Function denial and real API lifecycle.

RED eb00cd12/42041487667 on both5.1.1/5.1.8 reproduced BOTH exact owner errors; initial277 network harness failure is separately documented and never counted as product RED. CI browser-only overlay permits published host loopback on disposable synthetic site, without changing production or existing isolated native test networks.

Operatorf4d81940 pins application901 and installed baseline719. CHANGED10+ADDED4, full source653/108tables/version0200, preserves worker267 and every prior journal/control. Frozen45e/aa61/scorm operators unchanged. Private adapter avoids mutating old module globals. Check guards exact full source before outage; root-only private artifact, common locks, metadata/xattrs, actual7-file PHP lint, UID33 cache purge, interruption recovery retained. Dedicated CI127296886010:14tests passed including actual UID33. Local UID33 fixture unavailable due chown EINVAL; not masked/skipped in mandatory CI. Archive194854bytes SHA33e0f16b9043bc79beddcb06ea6476b13be1323147cde956cc7e5e01f2d6f87a equals deterministic build and CIartifact13423366044.

Scope limits: no production/merge/SSH/provider action; no claim this JS trace explains the earlier server-side route invalidparameter. Route reader/query budget and field-level UX follow-ups remain in audit plan. Full qualification and owner install remain separate evidence.

Qualification2984b066: all4workflows and11FullRC jobs PASS. Native Moodle5.1.1/5.1.8 each430tests/1988assertions; native Connect172/875; source PHP8.2/8.3 each1633tests/273conditional skips. Both real browsers pass original-error absence, Y.one/global, existing head, actual API save/fresh390reload, parent/preview eval and parent-access denial. Archive equals final CIartifact13425294665 and retained copy. Source CI budget increased15→25 after evidenced font-download stalls; one renderer screenshot protocol failure was retried on the same commit. All11 release jobs executed successfully.

[Receipt](../../runtime/studio_csp_qualification_20261105.json), [owner runbook](../../../docs/STUDIO_CSP_INSTALL_RU.md). Owner45e not installed, production719 unchanged.
