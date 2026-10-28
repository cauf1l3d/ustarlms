# Materials and Studio Polish Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans; AGENTS.md prohibits subagents. Steps use checkbox syntax for tracking.

**Goal:** Единый контур Материалы→редактируемая Студия→подготовленный материал, с Word и удобным каталогом.

**Architecture:** Существующий редактор в штатной оболочке. Серверный адаптер источников создаёт или возвращает канонический черновик; Word работает локально. Каталог получает пакетные метаданные и переиспользует один DOM для трёх видов.

**Tech Stack:** Moodle5.1.8/PHP8.3/PG16, PHP/Mustache/CSS, JS/JSZip, DOM/XML API.

**Spec:** docs/superpowers/specs/2026-10-27-materials-studio-polish-design.md

## Global Constraints

- local_ustar2026102700, theme2026102700; текущая production2600 не подменяется candidate.
- Только существующие author capabilities, actor/view-as/sesskey/revision/lock guards.
- Опубликованный источник, scope, route snapshots и попытки не меняются при открытии.
- Word .docx:25МБ/1000entries/100МБ inflated/10МБ XML/200экранов; общие project limits.
- Без внешних сервисов, схемы БД, инфраструктурных изменений и subagents.

## Review Focus

- Двойной POST/два автора открывают один материал: один связанный черновик, без потери правок.
- Источник с локальными картинками и опубликованными попытками: ресурсы доступны, оригинал прежний.
- Плохой DOCX, ZIP bomb, XML entity или внешний relationship: отказ до отображения, текущий курс прежний.
- Маленький экран/клавиатура/длинное название: действия и меню видимы, фокус возвращается.
- Большой каталог/фильтр/переход по папкам: ограниченная выдача и число SQL-запросов без N+1.

### Task 1: Word codec and editor import/export

**Files:** Create course_studio/word.js and tests/release_20260912/course_studio_word.cjs; modify editor.html/app.js and browser/DOM tests.

**Interfaces:** StudioWord.importFile(file) → normalized local course; exportCourse(course) → Uint8Array DOCX; inspectZip(bytes) validates before inflate.

- [x] RED: Cyrillic/headings/bold/list/table/image roundtrip; malformed/traversal/oversized/entity/external object rejection; assert failure before codec exists.
- [x] Implement bounded OOXML parser/writer using existing JSZip; no untrusted execution/network.
- [x] Add Word file actions and import preview/confirmation; keep current course until confirmation.
- [x] GREEN: node Word/DOM tests; browser imports actual DOCX, edits, exports and re-imports.
- [x] Commit codec and UI with evidence in progress ledger.

### Task 2: Material source bridge

**Files:** Create classes/course_studio_material.php; modify course_studio.php/API/service, materials.php/templates and native tests.

**Interfaces:** course_studio_material::open(int $contentid,int $actorid): array returns Studio metadata; complete(int $contentid,stdClass $project,string $sourceToken,int $actorid): array binds a converted file; file(int $contentid,int $actorid): stored_file serves only its authorized source.

- [x] RED: existing visual draft opens same id; published/native source opens reusable draft; retry/actor/view-as/CSRF; original status/scope/cmid unchanged; assets survive.
- [x] Implement source adapters for native blueprints, Moodle page/book, compatible USTAR SCORM and authorized PDF/DOCX/text/image sources; unsupported package reports reason.
- [x] Add materialid to Studio config, POST open route, hydrate assets and select exact returned course after sync.
- [x] Add actions to catalog and detail; no new per-row database lookup.
- [x] GREEN: native Moodle tests + authenticated HTTP open/retry/guard smoke + DOM direct-open selection.
- [x] Commit bridge and tests.

### Task 3: Academy shell and editor usability

**Files:** course_studio.php, editor.html/style.css/app.js, tests for native shell and responsive editor.

**Interfaces:** Scoped .u-course-studio under native Moodle page; existing element IDs and sync/project APIs retained.

- [x] RED: native shell output; scoped CSS; accessible outline/properties controls and accurate local/server save status.
- [x] Render fragment inside ustar layout; use current Academy tokens; remove separate navy branding and isolate editor selectors.
- [x] Adapt three panels, focus mode and dialogs to desktop/tablet/mobile; preserve course content/theme.
- [x] GREEN: DOM regression and real browser at390/768/1440px, keyboard/focus/no clipping; inspect screenshots.
- [x] Commit shell and usability changes.

### Task 4: Materials catalogue views and bounded reader

**Files:** Create classes/material_catalog.php and tests; modify materials.php, templates/materials.mustache, materials_fullwidth.css, AMD source/build.

**Interfaces:** material_catalog::file_labels(array $records,int $contextid): array; views list/blocks/cards on data-materials-view; page60 rows with preserved filters.

- [x] RED: selected view persists for reader and manager; invalid storage safe; menu keyboard/Escape; drag works all views; batch metadata query count independent of row count.
- [x] Batch current USTAR/Moodle resource filenames; factor visual classification; paginate60 with deterministic order and query count.
- [x] Add accessible view group, readable summary/status, consistent creation/open/edit actions and page navigation.
- [x] GREEN: PHP/native reader tests, DOM interactions and real browser catalogue at all three widths/views; inspect screenshots.
- [x] Commit catalogue changes.

### Task 5: Integration, production receipt and qualification

**Files:** version.php, context/STATE/ACTIVE/BACKLOG/evidence/source candidate inventories, runbook and CI tests.

- [x] Record owner post-deploy status/generation and fresh scheduled cron; mark historical wrapper error correctly; record actual manual observations only.
- [x] Bump plugin to2700; generate separate candidate manifest from exact source tree; production stays2600.
- [x] Run source/context/secrets, all applicable JS/PHP/Python suites and browser acceptance. Review full diff for scope, source loss, access, race and performance errors.
- [x] Push qualified implementation to PR107 preserving accepted installer62b0/app2d identities. Run full RC/native Moodle matrices; repair any regression and record exact CI receipts.
- [x] Deliver result, limits, screenshots and next deployment instructions; no agent production access or automatic merge.
