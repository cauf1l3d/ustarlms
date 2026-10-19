# Execution ledger — plan: USTAR_CONNECT_CON01C3_PLAN_20261019.md

Base893a2cb3 verified against PR106; main d9655074 unchanged. Clean separate feature branch codex/connect-notifications-20261019. C2 closed84native/436assertions/24link scenarios; do not redo it. User13:43 requested remaining work,14:50 requested continue. No agents or provider/production/network operations.

Pre-flight: both canonical notification writers already own their transaction and notification-key lock; enqueue must JOIN that transaction, not use identity_service::atomic inside it. Lease/begin/finish own identity lock/transaction independently. Public worker can assert only configured provider/tenant; no caller-selected employee. Recipient preferences must be read freshly from canonical storage before send, not from a cached worker snapshot.

Ruling: use existing delivery table plus Moodle user preference rather than create business queue/preferences tables — preserves canonical notification IDs and existing unique notification/channel; cost: managed delivery fields and Privacy export need explicit upgrade/parity.
Ruling: fixed generic Telegram cards, not raw canonical message/HR titles — avoids sensitive payload leaving Academy and is usable before full remote actor/step-up; cost: details require authenticated Academy access.
