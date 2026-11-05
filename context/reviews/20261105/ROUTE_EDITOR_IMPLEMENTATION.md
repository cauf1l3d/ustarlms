# Реализация редактора точки05Nov

Авторизовано owner: «Давай следующий пакет и редактор». Source baseline719bf47c, документацияb5cb201b.

Implemented candidate: отдельная visibility-команда с real actor/capability/view-as/scope/revision/route+row lock, без новых версий; авторский includeinactive отдельный opt-in; lossless rows codec с exact base coverage и baseversion check под writer lock; отдельные draft/publish; inline errors, scalar input retention; primary/required/completionmode per row; optional advanced controls; no-JS add/search rows; bounded200 displayed choices with retained selection.

Module names batch hydrated by type instead of per-module get_field. Full metadata source hydration and per-point admin-history reads remain O(N); no production latency/SLO claim. Separate comprehensive route reader optimisation remains in T04, not falsely closed.

Ruling: incomplete draft may keep a structurally valid SCORM policy; publish validates compatibility in both direct create and draft publication paths. Draft never becomes active published runtime implicitly.
Ruling: per-row source is server-loaded immutable base; submitted new choice resolves canonical allowed metadata. Truncated/missing base rows refuse, explicit remove retains history.
Ruling: primary source/module chooser uses at most200 matches in rendered rows with server-side filter; selected value retained. Full metadata batch remains bounded only by current installation size; broader query budget remains unclaimed.

Final verification: qualified1c43377b, all4workflows/9FullRC jobs PASS. PHP8.2/8.3 source1619tests each (272environment skips), native Moodle5.1.1/5.1.8 PostgreSQL430tests/1988assertions each. Authenticated real GET/POST draft→hide→restore→invalid input PASS on both runtimes, early5.1.8 job PASS. Operator13tests including actual PHP/UID33 PASS. Actual-template DOM/browser1440/390 PASS. No subagents/independent reviewer per owner; author trace review only.

Ruling: disabling a position override would reveal the common point under ADR0004, not hide that common point. UI therefore uses existing «Вернуть общий вариант» for overrides; separate hide applies to common/local points. Restoring an already-hidden legacy override remains supported. No new per-position exclusion model introduced.

Final review: valid60-second SCORM review interval rejected by old HTML min/step; DOM RED→GREEN with step=any/server min enforcement. Keyboard reordering across hidden cards RED→GREEN with active-only targets. Summary of current policy is visible outside collapsed details; hidden point publication notice explicit.

Final review regression: retained the existing direct file/video upload link after extracting shared fields; actual template test RED→GREEN.

Native qualification first run40c5b4a0 rejected: tests resumed writes after expected delegated rollback, compared associative key order before canonical normalization, and Python discarded curl's Netscape session expiry0 as expired. Reproduced CookieJar failure locally; added session-cookie/expired-cookie regression tests, canonicalized fixtures, moved expected stale rollback last. Product runtime code unchanged in this correction. Native HTTP now explicitly refuses login redirects and retains its last synthetic HTML for diagnosis. Requalification required; no failed candidate is delivered.

R09 native HTTP blocker: DB suite430tests/1988assertions passed on both runtimes at0c2421a2, but actual GET returned500. Fast isolated HTTP job f433ae20/42006317436/127173843494 retained the body: E_USER_NOTICE PAGE context missing in development_assessment::catalog→format_string (route_studio.php185). [Sanitized trace](page_context_http_failure.json) excludes cookies/session/environment. Fixed in app6c4f30b6 by setting system PAGE context before catalogue formatting, after authorization. Early mandatory route-editor-http job now gives feedback before full RC; full gate also requires it. Requalified at1c43377b; owner archive45e9d6b7 only, no earlier failed archive delivered. Exact owner invalidparameter still distinct.

## Карта финального исправления

Application6c4f30b6, оператор45e9d6b7, qualification1c43377b; источники закреплены SHA, не плавающей веткой.

| Находка | Источник исправления | Контракт проверки |
|---|---|---|
| R01/R02 | [decode](https://github.com/cauf1l3d/ustarlms/blob/6c4f30b600e6353aad10e112e94f7db7930e0e48/moodle/local/ustar/classes/route_studio_form.php#L29), server base + exact coverage | route_editor_form_contract.php, test_editor_roundtrip_preserves_multiple_activities_and_mixed_required_flags; DOM mixed required |
| R03 | [set_point_active](https://github.com/cauf1l3d/ustarlms/blob/6c4f30b600e6353aad10e112e94f7db7930e0e48/moodle/local/ustar/classes/route_commands.php#L51) | native visibility/version/stale/actor tests; HTTP hide/restore |
| R04 | [includeinactive opt-in](https://github.com/cauf1l3d/ustarlms/blob/6c4f30b600e6353aad10e112e94f7db7930e0e48/moodle/local/ustar/classes/route_model.php#L2975) | actual controller GET after hide; default remains false |
| R05 | [draft create guard](https://github.com/cauf1l3d/ustarlms/blob/6c4f30b600e6353aad10e112e94f7db7930e0e48/moodle/local/ustar/classes/route_model.php#L1062), [publication validation](https://github.com/cauf1l3d/ustarlms/blob/6c4f30b600e6353aad10e112e94f7db7930e0e48/moodle/local/ustar/classes/route_model.php#L672) | incomplete_scorm_draft native test; HTTP draft retains published |
| R06 | [controller recovery](https://github.com/cauf1l3d/ustarlms/blob/6c4f30b600e6353aad10e112e94f7db7930e0e48/moodle/local/ustar/route_studio.php#L646), scalar-only posted input | HTTP invalid timed0 retains title and unchanged versions; FormData/DOM |
| R07 | [shared form](https://github.com/cauf1l3d/ustarlms/blob/6c4f30b600e6353aad10e112e94f7db7930e0e48/moodle/local/ustar/templates/route_studio_fields.mustache), [presenter](https://github.com/cauf1l3d/ustarlms/blob/6c4f30b600e6353aad10e112e94f7db7930e0e48/moodle/local/ustar/classes/route_studio_presenter.php#L7) | actual-template DOM/browser1440/390; direct upload/minute interval/hidden reorder regression |
| R08 partial | batch module-name hydration,200-match displayed picker, one editing form | module_names_probe100/1000 →2API calls for2types; full reader cost still open |

Счётчики вызовов в probe и DOM screenshots не являются измерением production latency, всех ролей или полной доступности. Финальная CI-квитанция и owner deployment должны читаться отдельно.

[Final qualification](../../runtime/route_editor_qualification_20261105.json), [installation and owner acceptance](../../../docs/ROUTE_EDITOR_INSTALL_RU.md). Historical5.1.1 image lacked Python3 for the new HTTP harness; fixed in qualification commit1c43377b without changing application6c4f30b6 or operator45e9d6b7. CI selects the last immutable operator/builder commit, not documentation HEAD. Manually inspected template/CSS bytes match final application exactly; final browser rerun PASS.
