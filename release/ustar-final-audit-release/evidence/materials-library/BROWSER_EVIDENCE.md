# Materials / Personal Library — authenticated isolated browser evidence

Date: 2026-08-23

Environment: loopback-only isolated Moodle, `http://127.0.0.1:18080`

Classification: **CURRENT / TEST IMPLEMENTATION — NOT TARGET, NOT PRODUCTION**

## Evidence set

| File | Evidence |
|---|---|
| `01-hr-materials-before-desktop.png` | HR full workspace before a synthetic move; folder cards, editor and context actions are present. |
| `02-hr-materials-after-context-move-desktop.png` | Successful context move into Folder A, success notification, current-location breadcrumb and one-level-up action. |
| `03-hr-materials-mobile-390x844.png` | Responsive Materials workspace at the required 390×844 viewport. |
| `04-hr-materials-mobile-context-menu-390x844.png` | Mobile context menu remains reachable; destination select and disabled initial action remain visible. |
| `05-employee-library-before-desktop.png` | Synthetic employee personal Library before a route learning event: 0 items and route CTA. |
| `06-employee-library-after-desktop.png` | The same employee after an isolated route-open event: Library counters change to 1. |
| `07-employee-library-material-card-desktop.png` | Full-page desktop capture with the route material card in Personal Library. |
| `08-employee-library-after-mobile-390x844.png` | Mobile Personal Library with the unlocked material card. |

## Executed checks

- `audit_hr` could open `/local/ustar/materials.php`; `audit_employee` received the expected Moodle no-permission page for `local/ustar:hr`.
- Context move button was disabled before destination selection, enabled after Folder A selection and completed through POST/redirect with `Объект перемещён`.
- The destination view exposed the current folder in the breadcrumb and retained the explicit `На уровень выше` action.
- At 390×844, Materials search, filters, catalog, bottom navigation and the context move menu remained usable without a desktop-only control.
- Personal Library started at 0. The guarded isolated fixture then executed the same route assertion and learning-event service used by the gateway (`eventid=14`, synthetic `contentid=93`); the employee Library changed to 1 and rendered only that route material.
- Cross-user read-model check after unlock: `audit_employee=1`, `audit_hr=0`, `audit_superadmin=0`.
- HTML5 drag-and-drop could not be truthfully marked as browser-PASS: two coordinate-driven native drag attempts in the in-app browser driver produced no move. A separate no-auth control page with a standard `draggable="true"` source, `dataTransfer`, `dragover` and `drop` listeners then received **zero** `dragstart/dragover/drop` events from the same driver. This isolates the result to the automation surface rather than proving an USTAR defect. The underlying move endpoint, immutable audit, stale-write rejection and cycle rejection remain covered by the isolated 15/15 service smoke; keyboard/context move is browser-PASS.

## Cleanup and credential containment

- Only synthetic accounts were used: `audit_employee` and `audit_hr`; no real production identity was used.
- Temporary passwords were randomised immediately after capture and all three guarded synthetic sessions were killed: `users=3`, `sessions_before=1`, `sessions_after=0`.
- Browser reload after revocation returned to Moodle login.
- Fixture cleanup removed exactly `1` synthetic route point and `4` synthetic content objects.
- Final isolated counts: synthetic content `0`, synthetic route points `0`, content events `0`, Library rows `0`.
- No password, cookie, session identifier or real-person data is included in this evidence set.

Production was not changed and remains subject to separate owner approval.
