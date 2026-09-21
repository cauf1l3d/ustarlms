# Stage 2 R12 Consumer Wiring

## Goal
Move business access decisions from direct role/position checks to employee context and capability boundaries.

## Migration order

1. Team views
- Resolve visibility through organization scope.
- Preserve existing output contracts.

2. Executive/company views
- Use company scope resolver.
- Avoid direct role assumptions.

3. Remediation
- Validate manager scope before creating employee actions.

4. Assessment access
- Use capability checks instead of position names.

## Acceptance

- Employee sees own data only.
- Manager sees permitted department scope.
- HR/company roles use company scope.
- No consumer bypasses capability layer.
