# Test report — final candidate checkpoint

Дата: 2026-08-27

## PASS

- `ops/route_studio_runtime_probe.php`: 10/10 checks.
- Static Mustache delimiter balance for `materials.mustache`, `positions.mustache`, `operations.mustache`.
- `git diff --check`.
- Isolated login HTTP smoke: 200 with expected Russian labels.
- Prior isolated Materials 15/15 service checks, role boundaries, schema and rollback evidence: retained and referenced, not reinterpreted.

## NOT RUN / BLOCKED

- `npm run lint` / `npm run build`: frontend dependencies are not installed in this checkout.
- Authenticated browser screenshot of tailnet-only isolated service: in-app browser is outside the tailnet; public exposure was intentionally not enabled.
- Production migration/deploy: explicitly not authorised.

