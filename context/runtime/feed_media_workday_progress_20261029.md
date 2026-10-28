# SDD ledger — plan: docs/superpowers/plans/2026-10-29-feed-media-workday.md

Base: b47bf8dcb32f76880852d4f1ed7d4397e25a4a64. Linked worktree reused on
fix/feed-media-workday-20261029. Baseline CONTEXT_OK590/100/35; tracked tree clean.
Native/self implementation and author review; AGENTS forbids subagents and SSH.

Pre-flight: Task1 produces local2803, exact source delta and new native tests;
Task3 freezes that source, consumes unchanged2802 operator3c1ddd09 and includes
Task2 observer. Observer does not import application bootstrap or change business data.

Ruling: next task means current PERF-01 passive workday preparation — matches ACTIVE
and immediately preceding owner handoff; actual day/SLO acceptance stays pending.
Ruling: use exact known-CDN identity/content hashes plus one-cover card — avoids
perceptual false matches and destructive cleanup; old unidentified variants remain
available in the collapsed additional-images section.
Ruling: cloud skill workspace scripts are not local executable resources; this dated
ledger carries the same task/verification decisions without dispatching agents.

Task1: in progress. Public vc.ru RSS read29Oct confirms original and resize URLs
sharing the same Leonardo UUID; synthetic fixtures will avoid copying article text.

Task1: six actual-PHP fixture cases RED (missing methods) → GREEN. Existing query-batching9cases GREEN;400PHP/33JS/100tables/57templates source checks PASS. Native file/ACL and RSS/enrichment cases added; await actual Moodle CI.
Task2:11 observer tests RED (missing module) → GREEN; real bounded process and numeric-file fixtures included.
