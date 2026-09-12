# Publication scope — 2026-09-12

User approved public publication only of delivered patches, context and harness tools.

- Canonical roadmap/context: `main`. Application files on main are unchanged by this publication.
- Effective patch code: [`integration/ustar-20260912`](https://github.com/cauf1l3d/ustarlms/tree/integration/ustar-20260912), code commit `89ba18b2130040fbdf7f0ca02bef4999d5ef1fdb`.
- Parent working feature: `f4961bc3207eeb2bff8b28a3cc84dffa7e5b5df4`. Existing feature history is retained on the integration branch, not merged into main.
- The source delta contains 88 files matched to supplied patch payloads, including the final 25-file overlay in `effective_files.json`.
- Unverified source-only changes to `classes/competition.php` and `styles/team_hierarchy.css` were excluded. `styles/executive_2713.css` and `theme/ustar/scss/_login.scss` use the supplied patch versions, excluding additional unverified runtime edits. These differences must be considered during production drift checks.
- Tests under `tests/release_20260912` must run on the integration branch. Main's older application code is not the test baseline. Release metadata in main describes the integration branch.
- The local reconstruction SHA mentioned in historical working notes is provenance only; use the published code SHA above for checkout and review.

No deployment, database migration or production mutation was performed by this GitHub publication. Full production equivalence remains unverified; R00 remains review.
