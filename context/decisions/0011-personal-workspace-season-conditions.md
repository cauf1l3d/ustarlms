# ADR 0011 — Personal layouts, conditional rewards and learning seasons

2026-09-30. Review candidate; extends ADR 0010 and the PR70 notebook/competition.

Notebook cards remain owner-private learning_tasks and Moodle File API attachments.
Presentation metadata extends `ustar_notebook_layout`: bounded card geometry,
frames, links and named backgrounds. Commands bind the logged-in owner, sesskey,
lock and revision. Frame moves include members outside the currently visible page.

Home settings use `ustar_home_layout`. Available widgets are derived from existing
capabilities; showing a widget cannot grant its underlying authority. Width/order/
style/visibility are personal presentation, not a second source of task facts.

Reward conditions are snapshots within `reward_rules_v1`, effective in the future.
The last matching condition replaces the event's base amounts. Predicates select
existing verified events and source IDs, current/dated organization identity or a
specific business account. Source filters for tasks select canonical templates.
New course/activity rewards freeze in the existing reward_grants table; old read
models skip frozen events, preventing double XP. Existing historical completion
XP stays under its historical base policy. Game mastery freezes its XP as before.
USCOIN remains available only for route confirmations and accepted work and uses
the existing wallet ledger. Disabling a condition creates a new policy version.

Season mode extends immutable rule JSON in the existing competition tables.
Legacy seasons stay game seasons. A learning season selects route completion
cycles or Moodle course completions, avoiding double counting the same learning
as both a course and route point. Publication freezes the current audience from
organization_identity. Score writes verify durable completion rows and remain
idempotent under the season row lock. Scheduled reconciliation repairs missed
learning deliveries; closing reconciles all eligible learning facts before final
results. Private employee rankings retain pseudonyms; authorized operators see
actual names and the pre-publication audience.
