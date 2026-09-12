# USTAR agent entrypoint

Before doing work, read **[START_HERE.md](START_HERE.md)**, then:

1. `context/roadmap/STATE.yaml`
2. `context/tasks/ACTIVE.md`
3. `context/roadmap/BACKLOG.yaml`
4. `context/roadmap/AGENT_PROTOCOL.md`

The patch application code is in `integration/ustar-20260912`; canonical context and harness are in main; STATE records the exact source baseline and separate runtime evidence. Follow START_HERE links and read the existing project, architecture, ADRs, domains, runtime, code map and agent instructions at that baseline before changing code. Check newer commits and production drift rather than blindly applying an old patch.

Keep architecture decisions and historical evidence. Do not create duplicate domain models, change Moodle core, hide business logic in the theme, or remove learning/economy history. Database changes need explicit migrations and validation. User instructions take precedence over historical audit recommendations; roadmap proposals do not authorize unrelated production mutations.

Current business priority includes working route studio, reliable completion, rewards for every newly confirmed route point, gamification and achievements. Do not freeze these because an older plan suggested it.

Canonical task status is maintained in main `context/roadmap/`. Each completed step must include exact code SHA, real validation evidence and separate deployment evidence where applicable. An archive, a code file or a static check is not production acceptance. See the protocol before claiming a task done.

---

# USTAR Agent Instructions

You are working on USTAR Academy.

Before any task:

1. Read:

context/CONTEXT_INDEX.md

2. Load:

context/project.yaml
context/constraints.yaml
context/state/platform.yaml

3. For architectural changes:

Read:

context/decisions/

4. For production questions:

Read:

context/runtime/


---

# USTAR principles

## Source of truth

Git repository is canonical.

Production server is runtime evidence.

Chat history is NOT source of truth.


---

# Development rules

Never:

- overwrite working production files blindly
- create duplicate architecture
- bypass existing ADR decisions
- remove historical evidence


Always:

- create reversible changes
- update context when architecture changes
- document decisions
- keep migrations explicit


---

# Current architecture

Platform:

Moodle 5.x

USTAR layer:

local/ustar

Main domains:

- Learning
- Routes
- Organization
- Adaptation
- Evidence
- Economy


---

# Agent behavior

Before answering:

understand existing implementation.

Do not invent missing modules.

If information is missing:
request evidence.
