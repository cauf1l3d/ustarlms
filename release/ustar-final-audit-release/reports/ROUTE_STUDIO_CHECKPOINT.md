# Route Studio checkpoint

Status: **PASS in the isolated candidate; not production-deployed.**

Changed:

- Replaced the raw-ID point form with human-readable catalogs for USTAR materials/files/videos, Moodle courses, activities/tests and skills.
- Added version-safe editing: changing a point creates a new version; published history is retained.
- Added a single primary skill, optional supporting skills, phase, completion, renewal and publish controls.
- Added stale-write protection for point edits and route reordering.
- Added a native file-upload return path from Route Studio to the existing USTAR content engine.

Tested:

- PHP lint in `ustar_audit_moodle`: PASS.
- `route_studio_runtime_probe.php`: 9/9 PASS — catalog availability, versioned requirements, primary skill, historical v1/v2, persisted order, stale reorder and stale update rejection.
- HR Route Studio rendering: PASS.
- Employee and retail manager Route Studio entry: capability denial PASS.
- Candidate → previous verified Route Studio files → candidate: PASS; HR page rendered on both states.

Known boundary:

- The browser host cannot reach the isolated loopback container yet, so visual authenticated screenshots and drag interaction remain scheduled for the integrated browser stage. No production route was opened.
