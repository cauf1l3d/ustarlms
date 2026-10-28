# RSS media and workday measurement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: use superpowers:executing-plans.
> Native self implementation and author review; AGENTS forbids subagents.

**Goal:** Fix repeated RSS photographs and provide the next passive PERF-01 capture
in one owner-installable release2803.

**Architecture:** Reuse feed_rss/feed_files and the existing private renderer. Reuse
the immutable2802 deployment engine through a guarded successor. The passive observer
is a standalone standard-library program, independent of application business writes.

**Tech Stack:** PHP8.3/Moodle5.1.8/PostgreSQL16, Python3 standard library.

**Spec:** docs/superpowers/specs/2026-10-29-feed-media-workday-design.md

## Global Constraints

- Fixed predecessor app22607c69/tool3c1ddd09; worker6c56/theme2700 unchanged.
- No schema delta, destructive media deletion, source writable by UID33, or old operator edits.
- No subagents/SSH/provider sends/automatic merge; production evidence remains owner supplied.
- Default observer offline; explicit collection bounded60s minimum interval/24h maximum duration.

## Review Focus

- Same-content files with different names: one external image, original files/saves retained.
- Different signed URLs and lookalike hosts: never collapsed by global URL rewriting.
- Multiple genuine photos, files and employee posts: accessible extras and normal files survive.
- Clock/restart/statistics reset, stale metrics and missing measurements: gaps/NoData, no false rates.
- Symlinks, command errors and unexpected producer fields: no private content or arbitrary execution.

### Task1: RSS identity, storage and compact cards

**Files:** feed_rss.php, feed_article_resolver.php, feed_files.php, feed.php, styles/feed.css;
tests/release_20260912/feed_media.php, tests/r16/test_feed_media.py and native feed_rss_test.php.
**Interfaces:** feed_rss::unique_media_urls(array):array; feed_files::for_display(array,bool):array;
feed_files::card_media(array,bool):array with summary/additional lists.

- [x] Write fixtures for original/resize/unknown/signed URLs and exact image duplicates; run RED.
- [x] Implement identity/content dedup and one-cover/additional-images presentation, including reposts.
- [x] Run local actual PHP fixtures and native file/ACL checks; verify employee files and saves unchanged.
- [x] Record owner2802 acceptance, freeze2803 application commit and explicit undeployed manifest.

### Task2: Bounded passive workday observer

**Files:** scripts/infra/workday_observer.py, tests/r16/test_workday_observer.py.
**Interfaces:** numeric sanitized snapshot; fixed read-only SQL; JSONL plus honest summary.

- [x] Write RED cases for rates across resets/gaps, stale sources, denied paths and command failure redaction.
- [x] Implement fixed aggregate observation, safe private outputs, duration/output bounds and offline default.
- [x] Exercise real temporary files/subprocess boundaries; validate fixed SQL against native PG fixture.

### Task3: Guarded successor and one archive

**Files:** scripts/infra/feed_media_update.py, build_feed_media_update.py;
tests/r16/test_feed_media_update.py, test_feed_media_indexes.py; native probe and CI references.
**Interfaces:** fixed2802→2803, --check/upgrade/status/recover/restore, observer separate explicit command.

- [x] Add RED contract/envelope/recovery/old-source refusal tests and actual UID33 check.
- [x] Implement successor retaining paired snapshot/publication-aware recovery and exact task/cron/source guards.
- [x] Freeze operator; run applicable full CI including native PG legacy profiles and observer SQL.
- [x] Build deterministic archive locally; compare every file with CI, save and verify downloaded copy.

### Task4: Handoff

- [x] Author review, focused and full required checks; current state/backlog/generated views accurate.
- [x] Push branch and open PR with final scope/validation; no automatic merge.
- [x] Deliver archive/hash and concrete install/capture/report commands; actual workday remains pending.

Owner server apply and real workday/concurrency/SLO remain pending; these implementation checkboxes do not certify production acceptance.
