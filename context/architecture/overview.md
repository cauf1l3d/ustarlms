# USTAR Architecture Overview

## Core

USTAR is a corporate learning platform based on Moodle.

Main layers:

- Moodle core
- local/ustar business plugin
- USTAR theme
- frontend components
- external services

---

## Business domains

- Organization
- Routes
- Learning
- Adaptation
- Evidence
- Economy

---

## Architecture principle

Business rules must live in USTAR domain layer.

UI must not become source of business logic.

Database changes require migration tracking.
