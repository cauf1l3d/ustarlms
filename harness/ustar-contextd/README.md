# USTAR context harness

Canonical context lives on main; current patch application code lives on integration/ustar-20260912. Read ../../START_HERE.md and ../../release/20260912/PUBLICATION_SCOPE.md first.

The existing server and MCP configuration are retained from the working feature branch. They use the deployment-specific /opt/ustar/git/ustarlms path and require the dependencies in requirements.txt. They were not live-tested against MCP or production during this publication. R16 remains open; do not treat synchronization as completion of that task.

scripts/build_context_index.py and scripts/build_code_map.sh regenerate local context. scripts/refresh_context.sh also queries the local Docker host; run only on the intended host and review generated runtime metadata before publishing. Do not overwrite canonical roadmap status with a generated index. Historical runtime files are observations at their recorded dates, not proof of current deployment.
