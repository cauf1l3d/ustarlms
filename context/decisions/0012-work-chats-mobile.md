# ADR 0012 — Native work chats and mobile web application

2026-10-01. Review candidate; extends the existing core_message presentation.

Moodle remains the owner of messages, conversation membership, privacy, per-user
message deletion and notifications. Work groups are standalone native GROUP
conversations: component is null, the reserved itemtype local_ustar_workchat marks
their origin and itemid identifies their creator. Moodle 5.1's linked-conversation
presenter assumes core_group/groups and warns for other components; standalone work
groups do not enter that course-link path. No core patch or warning suppression.
Only that active
session actor, while still a member, manages the group. Course-linked groups are
readable through core messaging but cannot be managed by this controller. Multiple
work groups may share one creator. Invitations check current employment, actual
Moodle send permission and recipient privacy. New members see existing history;
removal revokes access to that history and every attachment URL immediately.

Attachments live in Moodle File API under system/local_ustar/message_attachment,
itemid = native message ID. Server multipart validation checks real uploaded files,
size, type and image integrity; only safe raster/media types render inline. Other
files download as attachments. Every direct URL checks current membership, enabled
conversation, employment and native per-user deletion. Administrator authority
alone does not expose another conversation. No parallel message/employee database.

Sending and member changes share a conversation lock. A per-actor send lock and
bounded user preference receipts deduplicate the last 30 request IDs, detecting a
changed payload. Message, attachment records and receipt commit together. Receipts
are retry metadata; native messages remain the source. A request ID is retained
across uncertain delivery and changed after success or draft edits. History reads
50 latest messages with bounded pagination; polling pauses on hidden tabs, older
history and media playback. Private views and mutations bind the real session actor
and reject view-as. All HTTP API actions require POST and sesskey.

The theme provides responsive presentation and complete mobile navigation from
existing capability-filtered navitems. Installation metadata is provided by a
Moodle head hook. Service worker scope follows wwwroot, including subdirectory
installations. Only public offline notice and public icon responses are cached;
Moodle pages, chat files, APIs and POST requests stay on the network. Offline mode
explains the missing company connection rather than presenting stale private data.

Home presentation controls are outside the reordered widget container, after all
personal blocks. Original login artwork and mascot are preserved, with a native
Moodle form and matching panel geometry. Authentication and the corrected layout
lifecycle from PR71 hotfix remain unchanged.

No Moodle core edits, new schema or role reassignment. Internal HTTPS and client
trust are runtime prerequisites for install promotion on production. The release
installer does not overwrite an unobserved reverse-proxy configuration or claim
that code installation configures corporate DNS and certificate trust. See the
release runbook for a concrete internal Caddy configuration and acceptance steps.
