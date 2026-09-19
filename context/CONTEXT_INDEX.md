Current source: `378d397152a8c83f8b0d046e2e561ab2732d6b02` on `integration/ustar-20260919`.

Read [STATE](roadmap/STATE.yaml), [ACTIVE](tasks/ACTIVE.md), [production sync release](../release/20260919/README.md), and [runtime evidence](runtime/20260919_prod_sync.md).

# Current entrypoint — 2026-09-19

Main contains canonical context/roadmap. Current application source is on `integration/ustar-20260919`.

The 2026-09-19 source baseline was reconstructed directly from running production and verified byte-for-byte for the publishable Git scope before push.

Previous `integration/ustar-20260912@48326c6a...` is historical, not the current development baseline.

# USTAR Dynamic AI Context Index

## Purpose

This repository contains the machine canonical context
for USTAR Academy.

AI agents MUST read this context before making changes.

---

# Source of Truth

## Code

Git repository:
ustarlms

Current application branch:
integration/ustar-20260919

Current application commit:
378d397152a8c83f8b0d046e2e561ab2732d6b02

Production runtime:
Docker Moodle deployment

Recovery snapshot is separate from Git and contains DB/moodledata/runtime state.

---

# Context hierarchy

## 1. Project

context/project.yaml

Business and product definition.

---

## 2. Architecture

context/architecture/

System boundaries and design principles.

---

## 3. Decisions

context/decisions/

Architecture Decision Records.

These are immutable unless explicitly replaced.

---

## 4. Domains

context/domains/

Business domains:

- learning
- routes
- organization
- adaptation
- evidence
- economy

---

## 5. Runtime

context/runtime/

Current production facts and dated evidence.

Latest reconciliation:
context/runtime/20260919_prod_sync.md

---

## 6. Code Map

context/code_map/

Generated map of:

- PHP classes
- database tables
- frontend
- dependencies

Generated maps have their own dates and must not override the exact current source commit.

---

## 7. State

context/roadmap/STATE.yaml

Current roadmap and release state.

---

# Agent rules

Before changing code:

1. Read START_HERE.md
2. Read context/project.yaml
3. Read context/constraints.yaml
4. Read relevant ADR
5. Read context/roadmap/STATE.yaml and context/tasks/ACTIVE.md
6. Check current runtime evidence
7. Use integration/ustar-20260919 as the source baseline unless STATE explicitly supersedes it

Never modify Moodle core without an explicit decision.
Never remove historical decisions.
Never treat an old ZIP/RC package as newer than the exact current source baseline.
Never commit production database, moodledata, credentials or recovery archives to Git.
