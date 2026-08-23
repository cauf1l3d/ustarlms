# USTAR — GitHub publication result

Дата: 2026-08-23

## Result

| Поле | Значение |
|---|---|
| Repository | `https://github.com/cauf1l3d/ustarlms` |
| Visibility | Private |
| Remote branch | `ustar-final-audit-release` |
| Base `main` | `5443bf5ab2c000a0fc019d3b0b30dcff0a60a7ff` |
| Initial published payload | `c30a2aa496bd51cac3ca827208b24527e80a7376` |
| Verified Materials UX code commit | `db6095cc9ce78fbaea60dd25b26ae77a809a4361` |
| Authenticated role/mobile evidence commit | `112fa3f16c287184bd67d3277193c2edfa54dfbf` |
| USCOIN CURRENT abuse-boundary audit commit | `3c8f8846278206230563c2049e108faa84a5ec78` |
| Leaderboard CURRENT privacy/fairness audit commit | `0c757f41872d8c14510a4a328d2ad3212fdae37f` |
| Boards CURRENT lost-update audit commit | `7c9d15437dde87163d4e8ac9338699ce345a88dd` |
| GitHub review gate for Boards audit payload | `success` — run `32643164685` |
| Bundle manifest | `99` files — `PASS` |
| Push mode | New branch, non-force |
| Production release | Not authorized / not performed |

Remote verification after the Boards audit push, before this record-only annotation:

```text
refs/heads/main                       5443bf5ab2c000a0fc019d3b0b30dcff0a60a7ff
refs/heads/ustar-final-audit-release  7c9d15437dde87163d4e8ac9338699ce345a88dd
```

The review branch is based directly on canonical `main` and includes these major logical payloads:

1. `3c748cd` — login shell around native Moodle authentication;
2. `e32cd05` — Academy feature asset system;
3. `9cb0f6e` — game same-origin media and empty-draft catalog fix;
4. `c30a2aa` — audit evidence, test/rollback helpers and release reports.
5. publication evidence and refreshed bundle manifests;
6. `01bff25` — mandatory HR/Materials owner supplement integrated into the release gate;
7. `4c84b52` — route-gated Personal Library and audited Explorer-style Materials workflow;
8. `db6095c` — final full-width Materials UX, breadcrumb, empty-state and move-feedback polish;
9. `112fa3f` — eight authenticated synthetic desktop/mobile screenshots, role boundaries, Personal Library `0 → 1` and cleanup evidence.
10. `3c8f884` — isolated USCOIN runtime/abuse audit: 12 concurrent duplicate submissions persisted once, while an overspend probe proved the missing non-negative balance invariant; all synthetic ledger rows were removed.
11. `0c757f4` — read-only isolated Leaderboard privacy/fairness audit: 90 participants, 89 other identities/positions/coin fields visible to employee scope, 87 tied people split into distinct ranks, team-rank mismatch and no season entities; probe mutations zero.
12. `7c9d154` — isolated Boards ACL/lifecycle audit and deterministic 24-worker race: all 24 expected-version saves returned success, final version remained 2 and 23 documents were silently lost; exact two-row fixture cleanup restored the seven-board baseline.

Static GitHub gate: [USTAR review gate run 32643164685](https://github.com/cauf1l3d/ustarlms/actions/runs/32643164685) — `success` for exact audit head `7c9d15437dde87163d4e8ac9338699ce345a88dd`.

The browser evidence also includes a same-driver minimal native HTML5 control. It emitted zero `dragstart`, `dragover` and `drop` events, isolating the automated native-drag gap to the browser driver rather than the USTAR application. Context-menu move, permission boundaries and the audited server-side move path remain proven.

The final remote branch ref is also recorded in the external handoff copy of this report; embedding a commit's own hash inside that same commit is intentionally avoided.

The owner explicitly approved transfer of the complete private source/audit bundle and 12 supplied `premium` PNG files. Asset licence/provenance remains a production/public-use gate.

No pull request was created automatically. No production server, database, user, role, route, theme, container, Caddy or DNS change was made by this GitHub publication.
