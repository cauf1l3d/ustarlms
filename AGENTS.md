# USTAR Academy — AI Agent Instructions

Before any change read:

- context/project.yaml
- context/architecture.yaml
- context/migration_state.yaml
- context/constraints.yaml
- context/decisions/

Git is the source of truth.

Do not:
- edit production directly
- modify Moodle core
- create *.bak *.new *.final files
- create parallel models

USTAR stack:

- Moodle 5.1
- PHP 8.2
- PostgreSQL 16
- Docker
- local/ustar domain plugin

Current phase:

migration + refactoring

Canonical domains:

Organization:
- departments
- positions
- skills
- staff_places
- assignments

Learning:
- routes
- route_points
- route_versions
- route_progress

Evidence:
- evidence_rec

Legacy systems must be migrated gradually.
