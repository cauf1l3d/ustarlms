# Current roadmap — 2026-09-07

Canonical plan and task status are maintained in main:

- [START_HERE](https://github.com/cauf1l3d/ustarlms/blob/main/START_HERE.md)
- [STATE](https://github.com/cauf1l3d/ustarlms/blob/main/context/roadmap/STATE.yaml)
- [BACKLOG](https://github.com/cauf1l3d/ustarlms/blob/main/context/roadmap/BACKLOG.yaml)
- [Agent protocol](https://github.com/cauf1l3d/ustarlms/blob/main/context/roadmap/AGENT_PROTOCOL.md)

Read these first. This branch contains the audited application/context baseline; its historical task list is not the current roadmap. Follow existing ADRs below and distinguish source code, tested runtime and deployed release. Do not create a second independent task-status copy.

---

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
