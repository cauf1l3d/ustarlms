# Studio CSP compatibility implementation plan

> Исполнение одним агентом; subagents/SSH/provider sends/merge запрещены. Продолжение авторизованных исправлений и пакетов, без повторного согласования routine выбора.

**Goal:** запуск native Course Studio без EvalError и без unsafe-eval.
**Architecture:** request-local bootstrap через существующий Moodle head hook; canonical Studio/route writers не меняются.
**Tech Stack:** Moodle5.1.1/5.1.8, PHP8.2/8.3, PostgreSQL16, Chromium/Playwright.
**Spec:** ../specs/2026-11-05-studio-csp-design.md.

## Global constraints / review focus

- Исходная CSP остаётся неизменной; тест реально выполняет запрещённый Function через inline script и ожидает EvalError.
- Существующий additionalhtmlhead сохраняется; bootstrap действует только на course_studio request.
- Preview sandbox allow-scripts без allow-same-origin; parent доступ и eval запрещены.
- Настоящий Moodle/YUI и реальный API save/fresh-browser reload, без synthetic API transport.
- Owner45e не устанавливал: один новый cumulative пакет от719bf47c; старые операторы frozen, production не трогать.

## Task1 — native browser regression and minimal fix

Files: tests/stage/course_studio_native_browser.cjs; existing authenticated_shell_smoke.sh/route_editor_native.sh; CI review gate; moodle/local/ustar/course_studio.php.

- [x] Добавить отдельный isolated native browser job; действующий stage boot/auth переиспользовать с ограниченным test-only browser mode и localhost port. Проверять оба pinned Moodle runtime.
- [x] RED: открыть настоящую Студию, сохранить browser errors; ожидать готовый Y.one и отсутствие bootstrap errors. Проверить actual response CSP, сохранение custom head, реальные save/reload, preview isolation и eval denial.
- [x] Запустить CI RED на старом course_studio.php, прочитать точную причину.
- [x] Добавить request-local YUI_config.global=window через additionalhtmlhead до header; не менять CSP или core. GREEN тот же тест.
- [x] Source/DOM/native regression suite и отдельное авторское trace review.

## Task2 — immutable cumulative owner package

Files: new scripts/infra/studio_csp_patch.py, build_studio_csp_patch.py; new operator tests; context/runtime receipt and owner runbook.

- [x] Новый qualified frozen successor по существующему source-only transaction contract, baseline719bf47c,10changed+4added addon files,653files/108tables.45e/aa61 не менять.
- [x] Offline plan/check, drift/locks/UID33/PHP lint/cache purge/interruption/recovery tests; root preflight refuses wrong source before outage.
- [x] Exact app/operator pins, fullRC + native browser, deterministic build, CI byte match and downloaded retained copy match.
- [x] STATE/BACKLOG/ACTIVE/code map/PR114 и краткие owner команды; owner install остаётся отдельным receipt.

Qualified05Nov2026: app901ac9eb/toolf4d81940, exact2984b066,4CI/11FullRC PASS. Owner deployment remains separate and pending; original route invalidparameter must be retried.
