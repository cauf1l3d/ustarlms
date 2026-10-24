# Execution ledger — plan: USTAR_CONNECT_CON01B4_PLAN_20261019.md

Owner19Oct09:55MSK requested roadmap and next slice. Personally,0subagents, existing feature checkout reused; remote PR106 head d4f7c1bd matches clean local. Prior78native/399 and full schema/concurrency PASS read from exact CI logs, not inferred from PR claim.

Pre-flight: Task1 produces typed sanitized bridge rejection consumed by Task2; old client mapped canonical422 to transport unavailable, so add fixed safe envelope, no arbitrary exception text. Task2 session state consumed by Task3 must distinguish registration uncertainty from rejection; stable server command/no automatic retry.

Ruling: use volatile single-process bounded session store, not new SQL/encrypted credential database — only onboarding10min, restart fail-closed; cost: process restart/replica lacks session and requires new bot launch. Moodle remains durable idempotency owner.
Ruling: plain JS for six-field shell instead of proposed React/Vite — no new dependencies/framework required for this small UI; cost: wider future client may migrate rendering, API remains unchanged.
Ruling: no real edge/relay deployment until placement/domain approved — user wants no inbound office ports or route/DNS changes; cost: code/browser fixtures do not yet mean externally reachable Mini App.

Baseline local575:3errors solely missing mcp test dependency (test_all_tools_use_reader, test_audit_pointer_cannot_escape_boundary, test_stdio);30skips including PHP unavailable. Prior temporary test venv/PHP gone. Restoring test dependencies in /tmp, not changing product or hiding failures. Native implementation gates remain exact prior78/399 until new CI.

Task1 complete: safe422/403 envelope tests observed RED (validation/conflict mapped bridge_unavailable) → GREEN. All unknown/raw/oversize errors still unavailable, no exception chaining/body exposure. Existing bridge API/HTTPS/scopes unchanged.

Task2:11WSGI tests first RED missing app, then GREEN actual app. Added concurrency/capacity/current flags delegation/restart/retention/forged launch cases. Two explicit regressions RED→GREEN: restart could strand old cookie until TTL (fresh bootstrap now creates preauth only); Unicode CSRF caused503 instead of403 (strict ASCII token format before compare). RAM store never contains form/password/initData or cookie plaintext; lock and4slots tests verify no extra remote dispatch. Duplicate Cookie and JSON rejected.

Task3: browser test RED on absent UI before assets; now assets implemented. Local Playwright browser download failed (truncated CDN ZIP, no installed Chromium); do not claim local browser PASS. Existing pinned CI browser runtime will execute same test. Full Python after test-dependency restoration594/30skips PASS before4extra cases; latest exact count recorded at publication. Missing PHP remains explicit local skip, native prior78/399 separate.

Ruling: retain Strict cookie for initial native WebView slice — do not weaken to SameSite=None without end-to-end iframe acceptance; cost: Web Telegram third-party iframe can fail, documented activation gate. No claim all Telegram clients supported yet.

Final personal review (no independent reviewer, user prohibits agents): read fixed route/field map, rotation/expiry/restart path, one-session nonblocking lock+global slots, output whitelist, signed start_param only, canonical stable command and uncertainty UI; no Critical/Important outstanding in this development slice. Operational telemetry/server/relay/multi-client cookie acceptance deliberately activation gates, not silently accepted. Tests do not prove live Telegram/provider/network. Local full598tests OK/30skips after23web tests, JS syntax+source structural/context PASS. Browser acceptance remains pending CI (local no Chromium), exact final receipt in PR106 to avoid context-only CI loop. No source manifest regeneration needed: addon component trees/542files/99tables unchanged.
