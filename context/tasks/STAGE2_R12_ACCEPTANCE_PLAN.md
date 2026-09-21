# Stage 2-R12 Acceptance Plan

## Consumer migration acceptance

This document defines the validation sequence before closing Stage 2.

### Team scope

- employee: self scope only
- manager: department scope
- HR/director: company scope

### Capability checks

Required capabilities:

- learning.self
- team.read
- team.remediation
- company.read

### Consumers to migrate

- team views
- executive organization view
- assessment/remediation access
- employee profile context
- route visibility

### Completion criteria

Stage 2 can be closed only after:

- no business access decisions depend directly on position names;
- consumers use employee context and capability resolver;
- scope isolation tests pass;
- migration dry-run produces expected mappings.
