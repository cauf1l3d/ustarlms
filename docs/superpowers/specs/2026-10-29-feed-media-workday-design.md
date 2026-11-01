# RSS media and workday measurement —29Oct2026

Owner accepted Materials/feed settings/seasons/Studio on2802 and requested the
pre-existing repeated RSS photographs be fixed together with the next PERF-01 step.
Implementation remains native/self-driven, no subagents, SSH or provider sends.

## Intent and scope

One RSS card should read as one article: one cover, with additional illustrations
available through an accessible collapsed section. Existing files, saves, post IDs,
reactions, audiences and history survive. Non-RSS employee attachments retain their
current presentation. A separate bounded local observer prepares workday evidence;
collecting an actual workday and agreeing concurrency/SLO remain owner operations.

## Diagnosis and approaches

The parser only removes identical URL strings. The importer names files by URL
hash, not content, and feed.php renders every attachment as a full-size block.
The current public vc.ru feed also supplies the same leonardo.osnova.io asset UUID
at original and resize URLs. Article enrichment can add another URL variant.

Considered: globally stripping image URL parameters (can merge distinct images);
perceptual image comparison (cost and false matches); exact provider asset identity
plus byte identity and compact presentation. The last is the agent's implementation
choice under the owner's concrete fix request, not a new owner architecture decision.
No destructive media cleanup or perceptual matching.

## Application behavior

- Deduplicate only an exact known Leonardo host/UUID path identity; preserve the
  first URL. Unknown hosts and meaningful query strings remain distinct.
- Apply the same identity to RSS parsing and enrichment. Before storage, reject
  an already stored SHA1 content hash; record the original source URL for new files.
- Display-time dedup applies only to external images, scoped to each post, using
  content hash or known provider identity. It also improves old stored posts without
  rewriting them. Unidentifiable old resized variants remain available in the
  additional-images section; only one cover occupies the feed preview.
- Preserve private Moodle pluginfile/download/library actions and current ACL SQL.
- Release local2803/theme2700; unchanged schema and worker. Fixed2802 predecessor
  operator3c1ddd09 and a separate successor journal; old operators stay byte-identical.

## Passive observer

One standard-library Python program, offline PLAN_ONLY by default. Explicit root
collection for a bounded duration (default8h, maximum24h), interval at least60s,
private new report directory and bounded JSONL/summary. No arbitrary commands or
SQL, Moodle bootstrap, application HTTP, credentials, user data or raw logs exported.
Fixed PostgreSQL READ ONLY transaction/statement and lock timeouts collect aggregate
database/cron counters. Read existing console numeric metrics and /proc host counters.
Unavailable/stale data is NoData, never zero. Version changes, statistics resets,
restarts and gaps split rate comparisons. Observer DB transactions are included.
No SQL/page, authenticated route timings, Web-SAPI OPcache or SLO claimed by this tool.

## Delivery and acceptance

One verified archive: fixed updater plus observer and checksums/instructions. Native
Moodle8.3/PG16 and historical RC, source/context/console/infra CI, actual UID33 source
write denial and strict legacy-index profile checks. Deterministic local build must
match CI and its saved downloaded copy. Publish a PR; no automatic merge of this
new stage. Owner applies and starts a normal workday capture with explicit commands.
