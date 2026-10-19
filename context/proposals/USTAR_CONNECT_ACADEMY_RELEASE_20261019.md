# Connect Academy: source RC and paired recovery

Completed on830d3703:Context39305805813 and full RC39305805820 SUCCESS,all9jobs. [Exact evidence](../runtime/connect_academy_rc_ci_20261019.json),[source package and remaining operator gates](../runtime/connect_academy_rc_20261019.md). This closes source RC verification,not production installation or the complete chatbot RFC.

Continuation of the approved Connect RFC and completed receiver preparation. Owner19Oct21:35MSK authorizes code/tests publication without the full server report. That report and its private local commits are excluded from this branch and its ancestry. No subagents.

## Release scope

Freeze the existing Git candidate local2026101905/theme2026100202,552addon files/99tables. Verify migration from the accepted Git baseline e1d57f5b/local2026100204/theme2026100202,526files/93tables, then restore the baseline DB/source/moodledata/config together in an isolated fixture. These are source/fixture references,not new public measurements of a private host.

The deterministic package contains plugin/theme sources, reference hashes/versions/schema, a read-only preflight, SOURCE_COMMIT and checksums. It includes no installer, production configuration, server report, database dump, credentials or activation flags. Production maintenance/cron fencing,fresh paired backup and a reviewed addon upgrade/recovery procedure remain a separate operator gate. Existing core-upgrade tooling is not reused as an addon installer.

## Execution and verification

1. Source builder tests cover exact Git contents,deterministic bytes despite dirty/untracked files,archive paths/modes,checksums,packaged default-plan preflight,invalid refs,symlinks and sensitive files. RED missing builder and GREEN7tests were observed locally;full local715r16 passed with30explicit environment skips.
2. Native Connect fixture captures accepted baseline DB/source/data/config,then checks upgrade/repeat/fresh parity,Connect unit/concurrency scenarios and candidate sentinels. Restore all four snapshot components;compare versions/schema/source/data/config with the captured baseline and repeat upgrade with baseline source. Private fixture dumps/config stay in tmpfs,not uploaded as artifacts.
3. Publish into existing draft PR106 with a normal fast-forward from the current remote head. Run complete RC on the finished source commit;require PHP8.2/8.3/browser,Connect DB/recovery,receiver host,legacy rollback,DB matrix,packaged candidate and aggregate gate. Prior green runs do not verify this new fixture.
4. Record the tested commit,CI jobs and archive hash. Mini App waits for external HTTPS;private bridge,scoped keys,real links and controlled notifications follow a separately reviewed server procedure. Production deployment is not inferred from CI.

## Review

Reviewed archive path safety/exclusions,source vs dirty worktree,manifest consistency,accepted-baseline identity,paired restore order,artifact scope and default-off activation. Source review is personal under owner's no-subagent instruction. Full native result and exact tested references are recorded in the CI receipt;downloaded recovery artifact was checked,including exclusion of private snapshot files.
