# Реализация редактора точки05Nov

Авторизовано owner: «Давай следующий пакет и редактор». Source baseline719bf47c, документацияb5cb201b.

Implemented candidate: отдельная visibility-команда с real actor/capability/view-as/scope/revision/route+row lock, без новых версий; авторский includeinactive отдельный opt-in; lossless rows codec с exact base coverage и baseversion check под writer lock; отдельные draft/publish; inline errors, scalar input retention; primary/required/completionmode per row; optional advanced controls; no-JS add/search rows; bounded200 displayed choices with retained selection.

Module names batch hydrated by type instead of per-module get_field. Full metadata source hydration and per-point admin-history reads remain O(N); no production latency/SLO claim. Separate comprehensive route reader optimisation remains in T04, not falsely closed.

Ruling: incomplete draft may keep a structurally valid SCORM policy; publish validates compatibility in both direct create and draft publication paths. Draft never becomes active published runtime implicitly.
Ruling: per-row source is server-loaded immutable base; submitted new choice resolves canonical allowed metadata. Truncated/missing base rows refuse, explicit remove retains history.
Ruling: primary source/module chooser uses at most200 matches in rendered rows with server-side filter; selected value retained. Full metadata batch remains bounded only by current installation size; broader query budget remains unclaimed.

Verification so far: PHP codec RED missing class→GREEN, real Mustache/DOM PASS, full npm PASS before final refinements; native tests queued for visibility/version preservation/stale actor/base/mixed SCORM+Quiz/incomplete draft rejection on publish. No subagents per owner; final review by author. Screenshot and Moodle docs identify generic invalidparameter, not unique debug detail; no claim HTTP501 measured.
