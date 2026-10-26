# Docker lifecycle — deferred by owner26Oct2026

No rollout or package is authorized in the current task. Experimental source and
failed native evidence remain at branch `infra/docker-successor-frozen`, commit
80870f7dd0e6b37d994f4af10ea5c53e7b1da64a. It was never applied on server1.

Logging/healthcheck and container replacement belong to the next planned Moodle
upgrade window, with a reproducible image, recurring-consumer compatibility and
recovery proof. See [ADR0018](../decisions/0018-container-runtime.md).
Do not run dated successor commands or resume the old transition plan.

Owner26Oct network metadata: Docker29.9.0, Compose5.6.0, ustar_network bridge,
172.18.0.0/16, gateway172.18.0.1, exactly2 attached containers. PostgreSQL172.18.0.2,
Moodle172.18.0.3; IPAMConfig empty. This is read-only evidence, not a migration result.
