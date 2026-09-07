# Current roadmap — 2026-09-07

Canonical plan and task status are maintained in main:

- [START_HERE](https://github.com/cauf1l3d/ustarlms/blob/main/START_HERE.md)
- [STATE](https://github.com/cauf1l3d/ustarlms/blob/main/context/roadmap/STATE.yaml)
- [BACKLOG](https://github.com/cauf1l3d/ustarlms/blob/main/context/roadmap/BACKLOG.yaml)
- [Agent protocol](https://github.com/cauf1l3d/ustarlms/blob/main/context/roadmap/AGENT_PROTOCOL.md)

Read these first. This branch contains the audited application/context baseline; its historical task list is not the current roadmap. Follow existing ADRs below and distinguish source code, tested runtime and deployed release. Do not create a second independent task-status copy.

---

# USTAR 1.5.1 production baseline

Canonical source repository for USTAR.

Production Moodle custom source was captured from:
/opt/ustar/apps/moodle/moodle/public/local/ustar
/opt/ustar/apps/moodle/moodle/public/theme/ustar

Frontend source:
/opt/ustar/source/frontend

DGMJS is maintained as an external pinned dependency.

IMPORTANT:
bitrix_bot_handler.php is intentionally excluded from this baseline commit.
The live handler contains credential-related configuration that must first be moved to protected environment configuration.
The live handler remains protected by the production snapshot/recovery system.
