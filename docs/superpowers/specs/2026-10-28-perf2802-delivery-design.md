# PERF2802 delivery —28.10.2026

Owner agreed to the immediately preceding next stage of source integration and a separate server delivery: «ок давай к следующему этапу». PR107 is merged as84a078ef, preserving the tested tree. Continue in PR109; server execution remains with the owner, no agent SSH or provider sends.

Goal: ship the already qualified RSS/operator-name batching over accepted Studio2801 as local2026102802/theme2026102700. Runtime baseline is the exact a3df5783 application and81118e39 operator/journal, not the old main2300. Existing Studio assets, all100 tables, task policy and Connect worker6c56 stay unchanged.

Approach: extend the existing frozen delivery chain with perf_query_update.py and its exact-Git builder. A small adapter retains shared locks, paired source/DB copy, two upgrades, publication boundary, recovery and explicit late restore. The alternative of reusing an old updater would replace its frozen identity; a generic unpinned installer cannot attest the accepted2801 journal. Neither fits the established delivery contract.

Payload: four changed baseline files (competition.php, feed_article_resolver.php, feed_admin.php, version.php) and one added native test. Namespace new2801 baseline bytes separately from the embedded2800 baseline to avoid the version.php collision. Baseline589/target590 files, identical schema and both accepted index profiles. A missing2801 receipt, foreign source/cron/worker or partial index pair must refuse without host writes. No attempt to adopt arbitrary live hashes.

Qualification: local meaningful adapter/package/transaction tests, full R16 and all applicable CI; mandatory actual UID33 read/write denial and actual PG16 fresh/legacy metadata probes. Merge PR109 after green exact-head checks; reconcile canonical main context separately. Compare the independently built archive byte-for-byte with CI, verify inner hashes/ZIP CRC and the downloaded saved copy. Supply a new root-owned install directory and bounded check/status commands.

Broader workday/SLO performance acceptance remains open. No production speed percentage is inferred from the old aggregate Apache p95.
