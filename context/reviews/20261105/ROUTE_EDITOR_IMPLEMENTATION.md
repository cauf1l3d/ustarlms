# Реализация редактора точки05Nov

Авторизовано owner: «Давай следующий пакет и редактор». Source baseline719bf47c, документацияb5cb201b.

Implemented candidate: отдельная visibility-команда с real actor/capability/view-as/scope/revision/route+row lock, без новых версий; авторский includeinactive отдельный opt-in; lossless rows codec с exact base coverage и baseversion check под writer lock; отдельные draft/publish; inline errors, scalar input retention; primary/required/completionmode per row; optional advanced controls; no-JS add/search rows; bounded200 displayed choices with retained selection.

Module names batch hydrated by type instead of per-module get_field. Full metadata source hydration and per-point admin-history reads remain O(N); no production latency/SLO claim. Separate comprehensive route reader optimisation remains in T04, not falsely closed.

Ruling: incomplete draft may keep a structurally valid SCORM policy; publish validates compatibility in both direct create and draft publication paths. Draft never becomes active published runtime implicitly.
Ruling: per-row source is server-loaded immutable base; submitted new choice resolves canonical allowed metadata. Truncated/missing base rows refuse, explicit remove retains history.
Ruling: primary source/module chooser uses at most200 matches in rendered rows with server-side filter; selected value retained. Full metadata batch remains bounded only by current installation size; broader query budget remains unclaimed.

Verification so far: PHP codec RED missing class→GREEN, real Mustache/DOM PASS, full npm PASS before final refinements; native tests queued for visibility/version preservation/stale actor/base/mixed SCORM+Quiz/incomplete draft rejection on publish. No subagents per owner; final review by author. Screenshot and Moodle docs identify generic invalidparameter, not unique debug detail; no claim HTTP501 measured.

Ruling: disabling a position override would reveal the common point under ADR0004, not hide that common point. UI therefore uses existing «Вернуть общий вариант» for overrides; separate hide applies to common/local points. Restoring an already-hidden legacy override remains supported. No new per-position exclusion model introduced.

Final review: valid60-second SCORM review interval rejected by old HTML min/step; DOM RED→GREEN with step=any/server min enforcement. Keyboard reordering across hidden cards RED→GREEN with active-only targets. Summary of current policy is visible outside collapsed details; hidden point publication notice explicit.

Final review regression: retained the existing direct file/video upload link after extracting shared fields; actual template test RED→GREEN.

Native qualification first run40c5b4a0 rejected: tests resumed writes after expected delegated rollback, compared associative key order before canonical normalization, and Python discarded curl's Netscape session expiry0 as expired. Reproduced CookieJar failure locally; added session-cookie/expired-cookie regression tests, canonicalized fixtures, moved expected stale rollback last. Product runtime code unchanged in this correction. Native HTTP now explicitly refuses login redirects and retains its last synthetic HTML for diagnosis. Requalification required; no failed candidate is delivered.

R09 native HTTP blocker: DB suite430tests/1988assertions passed on both runtimes at0c2421a2, but actual GET returned500. Fast isolated HTTP job f433ae20/42006317436/127173843494 retained the body: E_USER_NOTICE PAGE context missing in development_assessment::catalog→format_string (route_studio.php185). [Sanitized trace](page_context_http_failure.json) excludes cookies/session/environment. Fixed in app6c4f30b6 by setting system PAGE context before catalogue formatting, after authorization. Early mandatory route-editor-http job now gives feedback before full RC; full gate also requires it. Requalification pending; old archives not delivered. Exact owner invalidparameter still distinct.
