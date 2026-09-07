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
