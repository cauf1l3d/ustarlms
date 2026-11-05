# Студия: CSP/YUI,05Nov2026

Owner trace: EvalError in YUI._afterConfig, Function constructor blocked by script-src self unsafe-inline; subsequent javascript-static.js setup reading one. Owner confirmed45e9d6b7 not installed. Production719bf47c receipt unchanged.

Confirmed source: course_studio.php18 supplies that exact restrictive CSP. Pinned Moodle5.1.8 public/lib/yuilib/3.18.1/yui/yui-min.js _afterConfig calls Function("return this") only when config.global is absent. global is also consumed by JSON feature detection, so null is not a valid substitute.

Core output order: page_requirements_manager::get_head_code emits YUI_config; core_renderer::standard_head_html then appends additionalhtmlhead; body loads yui-moodlesimple and javascript-static. Proposed request-local additionalhtmlhead append supplies YUI_config.global=window without modifying CSP or core. Preserve any existing custom head.

Why previous tests missed it: course_studio_http_smoke.sh checks HTML/API but never runs browser JS; course_studio_browser.cjs wraps editor.html in synthetic shell/API, bypassing native YUI bootstrap and actual response CSP. New native browser matrix must fail before source fix and pass after it. Exact second-error causality awaits this browser reproduction.

[Design](../../../docs/superpowers/specs/2026-11-05-studio-csp-design.md), [plan](../../../docs/superpowers/plans/2026-11-05-studio-csp-remediation.md).45e source and operators remain frozen; cumulative successor will include uninstalled Route Studio fix and CSP compatibility from719bf47c.

Primary source: [current Studio header](https://github.com/cauf1l3d/ustarlms/blob/5c0e6c5977f6ba63273c3f11a46f441d93bcb470/moodle/local/ustar/course_studio.php#L18), [pinned Moodle YUI](https://github.com/moodle/moodle/blob/cc0bf17da8b5fbd290faf95b2b14a03d64331923/public/lib/yuilib/3.18.1/yui/yui-min.js), [Moodle page requirements](https://github.com/moodle/moodle/blob/cc0bf17da8b5fbd290faf95b2b14a03d64331923/public/lib/classes/output/requirements/page_requirements_manager.php), [renderer head hook](https://github.com/moodle/moodle/blob/cc0bf17da8b5fbd290faf95b2b14a03d64331923/public/lib/classes/output/core_renderer.php).

## Native RED evidence

Commit eb00cd1229cd3d80fd726a419bac560f5dc3a2e0, review run42041487667, jobs127293512865 (Moodle5.1.8) and127293513317 (5.1.1): both captured the exact owner EvalError in YUI._afterConfig followed by javascript-static.js87 setup reading one; assertion Native Moodle JS must initialise without CSP/YUI errors failed. Product source still unchanged at this commit. Initial277 harness port failure was corrected separately; it is not counted as product RED. Minimal product fix now appends supported YUI global through the request-local head hook; policy/sandbox/core remain unchanged.

Первый прогон исправления f4d81940: оба native runtime прошли проверку отсутствия исходных ошибок, Y.one, global=window и сохранения custom head. Отрицательный CSP probe исправлен: синхронная вставка script из CDP evaluation наследовала allowUnsafeEvalBlockedByCSP. Теперь Function вызывается настоящим click-событием после возврата из evaluation; требование EvalError сохранено. Продукт/политика/оператор неизменны. [CDP primary contract](https://chromedevtools.github.io/devtools-protocol/tot/Runtime/#method-evaluate).
