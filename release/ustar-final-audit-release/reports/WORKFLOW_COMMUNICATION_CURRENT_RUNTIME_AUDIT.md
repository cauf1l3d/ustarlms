# Workflow and Communication — CURRENT isolated runtime audit

Date: 2026-08-23

Status: **CURRENT / TEST IMPLEMENTATION — isolated technical guard proven; NOT TARGET; NOT PRODUCTION**

Constitution scope: B069–B073, B086–B091, B102–B103 and B107–B109. The Constitution explicitly separates a notification, an official task, a personal task, a goal, a review and an HR decision. Existing Moodle rows do not become those TARGET entities implicitly.

## Human-language map

| CURRENT mechanism | What it actually does | What it is not |
|---|---|---|
| Moodle `notifications` | Stores system/plugin alerts addressed to one Moodle user | USTAR official task, personal task, escalation or acknowledged obligation |
| Moodle messaging | Stores conversations and messages; USTAR renders a custom shell over Moodle core APIs | A task workflow, formal feedback process or Bitrix delivery log |
| `local_ustar_goals` | Stores a private user-owned title, optional due date and completed flag | Official task, team goal, development plan or immutable goal history |
| `local_ustar_reviews` | Appends one HR score/period/summary row | Approval workflow, calibrated review cycle or HRD decision |
| `local_ustar_hr_actions` | Generic technical audit rows emitted by several HR/content operations | A task queue, notification outbox or complete domain-event ledger |
| Moodle scheduled task | Runs enrolment reconciliation every 30 minutes | Employee-facing task/reminder/escalation scheduler |

This distinction is the central CURRENT conflict. `Notification ≠ Task`, `Goal ≠ Personal task`, `Checklist ≠ Official task`, and an `HR action log ≠ HR workflow`.

## Isolated aggregate snapshot

The read-only probe used the loopback Moodle at `http://127.0.0.1:18080`. It emitted counts and technical categories only; no names, message text, review text, URL values or identifiers were included.

### Notifications

| Fact | Value |
|---|---:|
| Moodle notification rows | 70 |
| Unread | 48 |
| Distinct recipients | 13 |
| Created by component `local_ustar` | 0 |
| Moodle core / `tool_monitor` | 68 / 2 |
| `newlogin` / `availableupdate` / `insights` | 34 / 22 / 10 |
| Other event types | 4 |
| Context URL present / absent | 11 / 59 |
| Relative context URLs | 0 |
| Read timestamp before created timestamp | 0 |

The table has no priority/severity, deadline or acknowledgement field. The configured Moodle processors are `popup`, `email` and `airnotifier` enabled; `sms` is disabled. Configuration alone does not prove actual delivery, retry or receipt. There is no USTAR message-provider declaration and no Bitrix processor/integration in the checked source or database configuration.

USTAR `communication::notifications()` reads Moodle core rows and renders their raw `component` and `eventtype` labels. It is a presentation layer, not a USTAR-owned notification domain or outbox.

### Messaging

| Fact | Value |
|---|---:|
| Messages | 11 |
| Conversations | 20 |
| Conversation memberships | 25 |
| User actions | 6 |
| Popup-notification rows | 63 |

Membership, visibility, read state and sending are delegated to Moodle core. The runtime probe proved that a synthetic employee cannot open a conversation to which the employee does not belong. USTAR does not add task origin, due date, completion condition, escalation or HR consequence to a chat message.

### Tasks, rules and delivery lifecycle

All of the following CURRENT tables are absent:

```text
local_ustar_tasks
local_ustar_personal_tasks
local_ustar_notification_rules
local_ustar_notification_deliveries
local_ustar_escalations
```

USTAR has one enabled scheduled task, `sync_enrolments`, and zero USTAR ad-hoc tasks. No job creates reminders, repeats critical notices until acknowledgement, sends action-required/critical notices to Bitrix or records delivery attempts.

Therefore the B086–B091 workflow does not exist as a first-class CURRENT mechanism. The Constitution text is a TARGET requirement, not evidence of implementation.

### Goals

| Fact | Value |
|---|---:|
| Goal rows / distinct owners | 2 / 2 |
| Completed / open | 2 / 0 |
| Without due date | 2 |
| Target type | `custom` for both |
| Status / completion timestamp / explicit owner-assignee fields | absent |
| Goal event/history table | absent |

The checked Moodle plugin has an AJAX service and dashboard payload for goals but no dedicated goal page/template. A user can create, complete and hard-delete only their own row. Cross-user completion is denied. Hard deletion and the lack of `completedat` mean CURRENT cannot reconstruct when a goal was completed or deleted.

The old API also returned `status=ok` for any alphabetic action other than `create`, `complete` or `delete`, while performing no mutation. This is a technical contract defect, independent of TARGET goal policy.

### HR reviews and audit actions

| Fact | Value |
|---|---:|
| Review rows / targets / reviewers | 1 / 1 / 1 |
| Existing score range | 4…4 |
| Review status/version/approval fields | absent |
| HR action rows / actors / targets | 176 / 3 / 62 |
| HR actions without target user | 101 |
| Invalid `detailsjson` | 0 |

The action distribution is dominated by staff-map sync (`55`), content update/import/create (`30/29/28`) and assignment sync (`8`). There is one existing `review_created` action. A null target is legitimate for global content/structure operations but confirms that this generic table is not a person-task queue.

Runtime boundaries passed: employee review creation denied, score outside `1..5` denied, HR review creation allowed, and exactly one matching `review_created` audit row was emitted. The fixture review and audit row were then deleted by exact synthetic identifiers.

## Self-cleaning runtime matrix

| Boundary | Result |
|---|---|
| Employee notification list excludes peer notification | PASS |
| Employee cannot mark peer notification read | PASS |
| Employee can mark own notification read | PASS |
| Foreign Moodle conversation denied | PASS |
| Goal create/complete/delete for owner | PASS |
| Peer cannot complete owner's goal | PASS |
| Employee cannot create HR review | PASS |
| Invalid review score denied | PASS |
| HR can create review and matching audit row | PASS |
| Exact cleanup restores all five counters | PASS |

```text
notifications / local_ustar notifications / goals / reviews / HR actions
70 / 0 / 2 / 1 / 176
→ synthetic probe
→ 70 / 0 / 2 / 1 / 176
```

## Isolated API containment — PASS

The review candidate adds one strict allowlist to `save_goal`: `create | complete | delete`. No schema, goal ownership, completion, deletion or business semantics changed.

```text
old class SHA-256:       a5a14fc9e4a76cb6efaf22ae9b51c9dbfc44ef5ad65b311801eb5aa7925e4459
candidate SHA-256:       8075218ea6d1b1316de6761660f674ce348f2c696628dd86ec3a51ed1928859d
old unknown action:      accepted with status=ok
candidate unknown action: invalid_parameter_exception
```

Rollback was exercised: restoring the old SHA reproduced the false success; reapplying the candidate SHA restored strict rejection. Both legs passed PHP lint, the full notification/conversation/goal/review boundary matrix and exact baseline restoration.

Final isolated health: installed SHA matched the candidate, login returned HTTP `200`, the 15-minute scan contained `0` new critical log lines, and exact host/container temporary probe and backup files were removed (`TEMP_CLEANUP=PASS`).

Production remains on the old class. The guard is installed only in the isolated release candidate and is not a production-release approval.

## Checklist Design source audit

This is a source-only audit of `notifications.mustache`, `messages.mustache`, the page controllers and `communication.php`. Authenticated visual evidence was not recreated because the previously used synthetic passwords and sessions were intentionally revoked. Layout, contrast, focus and mobile rendering are therefore not claimed here.

### Notifications — Web app

| | Item | Why |
|---|---|---|
| 🟡 | **Notification list — A chronological feed of alerts, messages, and activity updates for the user.** | Rows are newest-first, but there is no today/yesterday/week grouping and the feed mixes unrelated Moodle technical events. |
| 🟢 | **Read and unread states — A clear visual distinction between notifications the user has and hasn't seen** | The template has unread card state, dot, count, individual read action and shell count. Visual contrast still needs a rendered capture. |
| 🟡 | **Notification type — A visual or label indicating type e.g. mention, system alert, billing event** | Raw `component · eventtype` is shown, but there is no human type, importance or action-required/critical category. |
| 🟡 | **Timestamp — A relative time for recent items e.g. 2 mins ago, a complete date and time for older ones e.g. May 14 7:08pm** | Every row uses one absolute date/time format; recent relative time is absent. |
| 🟢 | **Actions (if applicable) — An action related to the notification event e.g. a notification that involves a direct message could have a 'reply' button** | Context link and per-row mark-read action are present when applicable. |
| 🟢 | **Mark all as read — A single action to clear all unread indicators at once** | A session-key-protected “Прочитать всё” action is present. |
| 🟢 | **Empty state — A clear message when there are no notifications** | “Пока тихо” explains that new notifications will appear here. |

### Chat — Web app

| | Item | Why |
|---|---|---|
| 🟡 | **Message thread — A chronological display of messages in the conversation, with the most recent at the bottom** | Server output is oldest-first, but the checked source has no auto-scroll/latest-message behaviour. |
| 🟢 | **Message input — A text field for composing and sending messages, with support for multi-line input** | A required two-row textarea with 4,000-character limit and explicit send button is present. |
| 🟡 | **Sender identification — The sender's name and avatar displayed alongside each message, making the conversation easy to follow** | Sender name is present; avatar and consecutive-message grouping are absent. |
| 🟡 | **Timestamps — When each message was sent, using relative time for recent messages and a full timestamp for older messages** | Full date/time is present on every message; relative recent time is absent. |
| 🔴 | **Read receipts — An indicator showing whether the other participant has seen a message.** | Moodle read state is not exposed in the USTAR thread, so a sender cannot tell whether a message was seen. |
| 🔴 | **File and media sharing — The ability to attach images, files, or links within the conversation, and show those attachments within the conversation** | The USTAR composer is plain text only. |
| 🔴 | **Reactions — Emoji reactions on individual messages as a lightweight way to respond without sending a full reply** | No reaction action or rendering exists. |

Read receipts, attachments and reactions are not automatically TARGET requirements; the owner must decide whether Moodle chat is part of USTAR at all. The release-critical issue is semantic: chat and notifications must not be presented as official tasks.

## CURRENT conflicts and missing lifecycle

1. No official-task versus personal-task separation.
2. No approved task source, assignee/owner, reason, due date, completion condition or evidence link.
3. No notification severity, action-required state, deadline or acknowledgement.
4. No USTAR outbox/delivery-attempt record, retry state, dead-letter state or channel receipt.
5. No Bitrix delivery or critical repeat/escalation mechanism.
6. No versioned rules controlling which business events create a notification.
7. No canonical reporting relation, so manager task authority cannot be scoped.
8. Goals have no lifecycle/history and can be hard-deleted.
9. Reviews have no draft/review/approval/publication/version/archive lifecycle.
10. The notification UI exposes raw Moodle component/event codes and can link to absolute URLs, but has no domain-level explanation of importance or required action.

## TARGET decisions required

- Is Moodle messaging retained as an employee communication channel, linked out, or removed from the USTAR shell?
- What exact event types create normal, action-required and critical USTAR notifications?
- Which channel combinations are allowed; what is delivered to Bitrix; what retry, receipt, escalation and dead-letter rules apply?
- What is the official Task entity: source, assignee, owner, scope, reason, due date, completion condition, evidence, acknowledgement, escalation, correction and archive?
- What is the separate Personal Task entity, and is voluntary sharing supported?
- How do Goal, Development Plan, Review, Checklist and Task reference one another without duplicating facts?
- Which manager/HR/HRD/CEO roles can create, view, reassign, close, correct or archive official tasks and reviews within which organisational scope?
- Which events and task states appear on employee/manager/HR/CEO home screens and reports?

Until these decisions are approved and implemented, the checked screens remain Moodle-backed CURRENT utilities. They must not be described as the constitutional USTAR task and notification workflow.
