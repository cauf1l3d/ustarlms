# USTAR Academy — icon system mapping

Дата: 2026-08-23  
Статус: **isolated implementation / production not deployed**

## Source pack

Проверен каталог `C:\\Users\\User\\Desktop\\release\\иконки академия`: 29 transparent PNG assets в едином 3D-стиле — тёмный graphite, gold accents, фронтальный ракурс. Исходные файлы имеют размер 61–214 KB; весь каталог не должен загружаться на каждой странице.

Лицензионный provenance в архиве не найден. Суффикс `premium` требует подтверждения права production-использования до релиза. В test bundle включены только 12 выбранных assets.

## Design rule

| UI layer | Decision | Reason |
|-|-|-|
| Left rail, mobile bottom navigation, topbar actions | KEEP current inline SVG | 20–22 px, high contrast, no network request, currentColor and dark-theme support; detailed 3D raster becomes blurry here |
| Feature headers and large cards | USE 3D Academy asset | 38–58 px gives the new visual identity without weakening navigation |
| Achievement/game cards | USE 3D Academy asset | Trophy/puzzle/tick remain recognisable and add the intended premium tone |
| Empty states | USE lazily loaded decorative asset | Helps remove visual emptiness; empty `alt` avoids duplicate screen-reader content |
| Form controls, search, arrows, status/action glyphs | KEEP SVG | These are functional controls, not decoration |
| USCOIN | UNDECIDED | `eth` and credit-card imagery would imply a crypto/payment model that TARGET has not approved |

## Semantic mapping

| USTAR semantic key | Academy asset | Primary use |
|-|-|-|
| `trophy` | `3dicons-trophy-front-premium.png` | achievements, badges |
| `game` | `3dicons-puzzle-front-premium.png` | games and mastery |
| `check` | `3dicons-tick-front-premium.png` | checklists/completion |
| `executive` | `3dicons-crown-front-premium.png` | executive analytics |
| `palette`, `settings` | `3dicons-setting-front-premium.png` | UI/configuration feature cards |
| `route` | `3dicons-rocket-front-premium.png` | learning route |
| `knowledge`, `book` | `3dicons-notebook-front-premium.png` | knowledge/materials |
| `spark` | `3dicons-bulb-front-premium.png` | ideas/boards |
| `workspace` | `3dicons-tools-front-premium.png` | HR workspace/tools |
| `star` | `3dicons-star-front-premium.png` | mastery/recognition |
| `clock` | `3dicons-calender-front-premium.png` | activity/recency |
| `bell` | `3dicons-bell-front-premium.png` | notification feature header |

Keys without a genuinely matching 3D asset (`profile`, `team`, `search`, `message`, `arrow`) retain the existing SVG. No misleading substitution is made merely to use more files.

## Implementation mapping

| Layer | File | Change |
|-|-|-|
| Semantic renderer | `local/ustar/classes/ui.php` | Feature-class calls return lazy decorative `<img>` from a strict allowlist; default/action calls remain SVG |
| Assets | `local/ustar/pix/academy/` | 12 selected source PNGs, unchanged |
| Theme | `theme/ustar/scss/_academy_icons.scss` | object-fit, specificity-safe reset, restrained shadows and reduced-motion-safe hover |
| Theme loader | `theme/ustar/lib.php` | Adds the Academy icon layer after login/feature SCSS |
| Deployment | `ops/deploy_academy_icons_to_test.sh` | Exact baseline hashes, test-only path guard, backup, lint, cache purge and HTTP check |

## Release gates

- Confirm production licence/provenance for the `premium` asset pack.
- Optimise selected originals for rendered sizes and compare visual quality/file weight before production; the isolated bundle intentionally preserves source bytes for provenance.
- Production deployment still requires separate owner confirmation.

## Isolated validation — 2026-08-23

Deployment target: `/opt/ustar/test-env/ustar-final-audit-release`, loopback-only `127.0.0.1:18080`. Production theme/plugin files were not changed.

| Check | Result |
|-|-|
| Guarded deployment from exact baseline hashes | PASS |
| PHP lint for `classes/ui.php` and `theme/ustar/lib.php` | PASS |
| Moodle cache purge and login HTTP check | PASS |
| Selected assets present | PASS — 12/12 |
| Achievements page | PASS — trophy/puzzle/star loaded at 23–58 px |
| Games page | PASS — trophy and puzzle loaded; mobile card layout has no horizontal overflow |
| Knowledge page | PASS — notebook/tick/calendar loaded after intentional lazy-load |
| Profile page | PASS — notebook/trophy/settings loaded after intentional lazy-load |
| Dark theme | PASS — assets loaded and remained legible; no horizontal overflow |
| Functional navigation | PASS — 14 SVG navigation icons, 0 raster Academy navigation icons on checked screens |
| Decorative accessibility | PASS — empty `alt`, fixed dimensions and `loading="lazy"` prevent duplicate announcements and unnecessary eager loading |

The browser initially reported unloaded images below the viewport on Knowledge/Profile. After scrolling them into view, every file completed with `naturalWidth=500`; this is expected lazy-loading behaviour, not a broken asset.
