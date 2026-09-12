Current source: `48326c6a011f58b985d251cb0462835bc1d37a16`. Read [STATE](roadmap/STATE.yaml), [ACTIVE](tasks/ACTIVE.md), and [dated snapshot](runtime/snapshots/20260912T204824Z/README.md).

# Current entrypoint — 2026-09-12

Read [START_HERE](../START_HERE.md), [STATE](roadmap/STATE.yaml) and [ACTIVE](tasks/ACTIVE.md). Main contains canonical context and harness; current patch code is on integration/ustar-20260912. [Latest source/deployment evidence](runtime/20260912_release.md). Historical runtime snapshots are not a current server manifest.

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

Production runtime:
Docker Moodle deployment

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

Current production facts:

- server
- docker
- moodle
- git state

---

## 6. Code Map

context/code_map/

Generated map of:

- PHP classes
- database tables
- frontend
- dependencies

---

## 7. State

context/state/

Current migration and platform state.

---

# Agent rules

Before changing code:

1. Read AGENTS.md
2. Read context/project.yaml
3. Read context/constraints.yaml
4. Read relevant ADR
5. Check runtime state

Never modify production directly.
Never remove historical decisions.
