# Test report — final candidate checkpoint

Дата: 2026-08-27

## PASS

- `ops/route_studio_runtime_probe.php`: 10/10 checks.
- Static Mustache delimiter balance for `materials.mustache`, `positions.mustache`, `operations.mustache`.
- `git diff --check`.
- Isolated login HTTP smoke: 200 with expected Russian labels.
- Prior isolated Materials 15/15 service checks, role boundaries, schema and rollback evidence: retained and referenced, not reinterpreted.
- Isolated Moodle plugin upgrade to `2026082702`: **PASS**.
- `cli/check_development_assessment.php`: **PASS** — three tables, published original profile, 12 questions, four styles, HRD allowed / HR denied.
- `cli/probe_development_assessment.php`: **PASS** — idempotency, historical attempts, route-completion lookup and cleanup of probe rows.
- `cli/bootstrap_trading_floor_route.php --apply` and `cli/check_learning_route_v2.php`: **PASS** — required profile is the first route step; real tracked Moodle content and attestation remain connected.
- Isolated loopback access smoke: **PASS** — protected Development Center, HRD analytics, career and Route Studio endpoints return the expected authentication redirect, not an application error.
- Source and test-DB rollback: **PASS** — the isolated database was restored from the just-created pre-upgrade dump after a rejected migration run, then migration was re-run successfully.
- Integrated TARGET Core runtime probes: **PASS** — idempotent evidence, self-gate denial, manager decision, immutable checklist, private personal data, notification/outbox and append-only evidence-revocation boundaries; probe cleanup restored the baseline.
- Organization / reporting runtime probe: **PASS** — invalid reporting links (self, cycle and dangling manager) are rejected; retail manager sees direct reports, HR does not receive team scope, CEO retains executive scope; probe cleanup restored the baseline.
- Evidence / checklist / gate runtime probe: **PASS** — no pre-completion admission, duplicate/stale checklist decisions rejected, employee cannot publish HR evidence and unassigned employee cannot complete a protected requirement; probe cleanup restored the baseline.
- Workflow / communication runtime probe: **PASS** — updated to exercise the canonical `local_ustar_notifications` path, including owner-only listing/mark-read, cross-user denial, goal and HR-review boundaries; probe cleanup restored the baseline.
- Employee lifecycle runtime probe: **PASS** — HR cannot self-import; a position change atomically adds/removes the manager access projection; isolated roles, position and audit rows restored after the scenario.

## NOT RUN / BLOCKED

- `npm run lint` / `npm run build`: frontend dependencies are not installed in this checkout.
- Authenticated browser screenshot of tailnet-only isolated service: in-app browser is outside the tailnet; public exposure was intentionally not enabled.
- Production migration/deploy: explicitly not authorised.
- Two post-attestation trading-floor videos: blocked pending owner-provided approved files; intentionally not represented as employee-visible learning.
