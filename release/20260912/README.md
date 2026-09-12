# USTAR consolidated source — 2026-09-12

This directory records the effective source from the patches delivered on 2026-09-11/12. It is not an instruction to copy the entire repository over Moodle.

Published code: `89ba18b2130040fbdf7f0ca02bef4999d5ef1fdb` on `integration/ustar-20260912`. See [publication scope](PUBLICATION_SCOPE.md) for the exact scope and known exclusions. Delivery order:

1. USTAR_ROUTE_FLOW_20260911_RC1
2. USTAR_CAREER_20260911_RC2
3. USTAR_SCORM_BELOW_20260912 (superseded)
4. USTAR_SCORM_INLINE_20260912
5. USTAR_SCORM_FLUSH_20260912 (height defect fixed by the next patch)
6. USTAR_SCORM_HEIGHT_20260912 (effective SCORM runtime)

`effective_files.json` contains the resulting hashes for the 25 distinct files touched by that series. `patch_manifests/` and `delivery_history.json` preserve per-delivery hashes and archive provenance; their package-relative source paths refer to the original delivery archives, not to this repository directory. Original archive installers create server-side backups and guard every replacement. Historical intermediate SCORM implementations are not active source.

## Effective behavior

- One protected route continuation action for Page/native screens; course containers resolve the next outstanding activity through the published route.
- Team question people shuffled without changing identity-based scoring.
- Consultant career ladder and product-learning source compatibility; explicit evidence definitions take precedence, no staff records or progress are manufactured.
- Private profile presentation cleaned up.
- Manual grades and USTAR audit share a transaction; a specifically verified post-commit message delivery error is not reported as failed persistence. This does not repair SMTP.
- No external SCORM continuation panel. Transition follows the material's change to «Изучено» and server-confirmed completion, with launch/sesskey guards. The final player uses pixel height beneath the navbar, preserving the internal course controls.

No Moodle core modifications or schema migrations were introduced by this patch series. Existing wider feature/runtime history is retained.

## Validation

Check out `integration/ustar-20260912` first, then run from the repository root:

```sh
php tests/release_20260912/route_flow.php
php tests/release_20260912/career.php
cd tests/release_20260912
npm install
npm test
```

Executed locally: PHP 8.3.33 syntax checks on effective changed PHP files; 31 route-domain assertions, 18 evidence/career assertions, 14 career DOM assertions and 41 final SCORM DOM assertions. PHP used a WebAssembly runtime; DOM used jsdom 30.0.1/Mustache 4.2.0. Original package installers were tested with isolated filesystem fixtures for apply/reapply/rollback, drift rejection and simulated write failure. These are not Moodle/PostgreSQL integration or real-browser layout tests.

## Deployment evidence

The user explicitly reported working automatic SCORM navigation, then reported the collapsed-height defect after FLUSH, installed/checked the latest delivered variant in the conversation and replied «отлично вроде работает» before requesting GitHub synchronization. This is tentative user acceptance of the latest result, not an independently measured full production manifest. Screenshots showed the patched career/route UI during the session; full acceptance of all six original scenarios and the current server-wide file/schema hashes remain unverified.

On the server, a pre-existing route_continue.php difference (URL constructor vs params()) was reconstructed and matched exactly to SHA256 `1065265d558121ac28067ee4e4d252c07d43b672cce5b1dc4ceb3908b6699f08` before accepting it in the first installer. That predecessor is replaced by the effective source in this repository.

Do not rerun old intermediate ZIPs over this source. Roll back using the corresponding installer and exact server backup, in reverse deployment order if reverting multiple patches. Preserve business data.
