# Registration and HR workflow: stage 3

Stage 3 reuses the existing Moodle account, USTAR employment lifecycle,
StaffPlace/Assignment model and staffing request queue. It does not create a
second employee directory or trust a position selected by the applicant.

## State flow

1. Moodle email self-registration creates the account.
2. The `user_created` observer creates an explicit `pending` employment record.
3. The employee profile remains available and accepts one requested position.
4. An idempotent `registration` staffing request appears in the responsible
   manager's existing “Моя команда” / staffing queue.
5. A manager may approve only a position present in their explicit valid scope.
6. Approval atomically creates the primary assignment, activates employment and
   synchronizes the learning route. Rejection leaves employment pending and allows
   a corrected request.

Pending employees cannot learn, receive rewards or acquire team/company authority.
The selected position is only request data until approval creates a canonical
StaffPlace assignment.

## Idempotency and notifications

- Repeating the same submission returns the existing pending request.
- A second pending request for another position is rejected.
- Repeating the same review decision returns the existing result without another
  assignment, lifecycle transition or notification.
- Opposite decisions after completion are rejected.
- Notifications use deterministic idempotency keys for each request and recipient.

## Operational boundary

Moodle email self-registration must be deliberately enabled and configured by the
site administrator; this source change does not enable public signup automatically.
Manual HR creation, manager hire requests and termination remain supported by the
same staffing screen. Production activation and email delivery require separate
release/runtime acceptance.
