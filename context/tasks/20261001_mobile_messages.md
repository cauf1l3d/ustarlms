# Continuation — mobile shell, work chats and original login

Status: implementation under review. Branch: codex/ustar-mobile-messages-20261001.
Base: c77ba822423855ea822be461efc91793e3189afe (PR71 hotfix).
User's installed PR71 evidence: 4cbb8ac37ef99051b9432e1e5c7c462f1472ad36;
installation of c77ba822 is not inferred from the issued command.

Recovered the stopped agent's untracked chat/PWA classes and scripts from the
existing mobile-messages checkout. Tracked changes there were predominantly line
ending rewrites, with no connected chat controller/template. Source checkout kept
untouched; work proceeds on a separate branch over the exact GitHub hotfix.

Requirements: preserve original artwork/mascot login, home controls last, wider
chat, inline media/downloads, work-group create/rename/member management, mobile
navigation/forms/local table scrolling, installation manifest/icons/worker.
Performance investigation is explicitly deferred; no team/evidence queries or
business architecture are changed for the measurements in the prior chat.

Implementation: ADR0012 and context/releases/MOBILE_MESSAGES_20261001_RU.md.
Validation implemented: source/DOM, native group/ACL/retry/pagination DB tests,
actual HTTP login+multipart upload+direct-file ACL, Chromium fixtures on five widths.
No production acceptance is claimed by isolated fixtures or public redirects.
Canonical roadmap stays in main; this task is dated continuation evidence.

Local validation: source checks (354 PHP, 24 JS, 93 tables, 55 templates), 33 Python
tests, existing route/career/DOM tests and the new chat retry/worker cache tests
passed. Frontend's four tests, production build and built HTTP smoke passed; dependency audit reported zero vulnerabilities. Chromium uses real USTAR
templates, all theme partials and synthetic Moodle-like data at 320/390/768/1366/1920,
46 scenarios including dark theme and a reduced visual viewport. It is not a full Moodle render.
Native API signatures and ownership were checked against pinned Moodle v5.1.1
a3a086d670fc08507853c594042f266b2dd6f613.

Full PHP8.2/PostgreSQL16 HTTP/DB and rollback gates remain pending. Docker is absent
locally. A scratch PHP8.3/PGlite18 attempt did not pass Moodle's installer Unicode
probe and is not accepted as runtime evidence; product/core files were not changed
to accommodate that temporary engine. New native/HTTP tests must pass on the normal
GitHub isolated runtime before recommending deployment.

GitHub push was rejected by automatic approval review, which requires explicit
authorization to publish the candidate to cauf1l3d/ustarlms. The original request's
destination was verified from the supplied GitHub reference and existing PR71.
No connector workaround, PR creation or production deploy was attempted before
authorization. On 2026-10-01 the user explicitly authorized publication, PR creation
and full CI. Direct git push has no configured credentials in this execution
workspace; publication proceeds through the connected GitHub Git-data API, with
its resulting complete source tree compared to the local candidate before CI.
