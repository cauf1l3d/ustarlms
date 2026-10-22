# CON-02 deployment implementation progress — 22Oct2026

Owner: «ок приступай к реализации план потверждаю». Approved plan:
[9 tasks/45 steps](../proposals/USTAR_CONNECT_CON02_DEPLOYMENT_PLAN_20261022.md).
Self implementation and author review,0subagents. PR106 remains unmerged.
No SSH, production changes or provider messages. Production2000+overlay/a86,
UID130 last revoked; accepted pilot is not repeated.

| Task | Observed state |
|---|---|
| 1 Fixed profile/contracts | Initial contracts/reference export implemented;7 focused tests PASS. Host integration and native gates still pending. |
| 2 Images/locks/journal | Initial implementation;6 real filesystem/lock tests PASS,1 UID33 environment skip. |
| 3 App+cron transaction | Initial implementation;9 real filesystem/cron tests PASS with Docker/SQL modeled. Native direct upgrade still pending. |
| 4 v2/ACK profiles | Pending |
| 5 Runtime transitions | Pending |
| 6 Plain.py/CLI | Pending |
| 7 Native direct upgrade/recovery | Pending |
| 8 Actual systemd/HTTPS | Pending |
| 9 Regression/artifact/handoff | Pending |

TDD: contract pattern initially7 assertion failures for missing interface, then7 PASS.
State pattern initially7 assertion failures for missing interface; now6 PASS/1skip.
Combined `python3 -m unittest discover -s tests/r16 -p 'test_connect_con02_*.py' -v`:
14 tests,1 skip. Local root user namespace cannot map GID33 (`chown` EINVAL);
strict UID33 CI is mandatory and is not claimed passed. Baseline cron10/10 PASS.
Exact a86 missing objects restored from GitHub and verified by Git blob/tree/commit SHA.
No installer artifact or owner server commands are ready yet. Source534 product remains unchanged.

Task3 TDD:8 missing-interface assertion failures,then8 PASS. Added actual xattr recovery test:
old copy lost user.con02 (ENODATA),then9 PASS after a private xattr-aware adapter.
Original primitives/modules remain unchanged. Combined23 tests/1 UID33 environment skip PASS.
Direct/repeat preserves customised task [1,1]; partial cron pair restores; post-public
recovery preserves a new write. Fresh child trace confirms only first4 locks held.
