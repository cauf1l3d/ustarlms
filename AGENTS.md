# USTAR agent entrypoint

Before doing work, read **[START_HERE.md](START_HERE.md)**, then:

1. `context/roadmap/STATE.yaml`
2. `context/tasks/ACTIVE.md`
3. `context/roadmap/BACKLOG.yaml`
4. `context/roadmap/AGENT_PROTOCOL.md`

The audited modern code/context is on the branch and commit stated in STATE, not automatically the application files in this main checkout. Follow START_HERE links and read the existing project, architecture, ADRs, domains, runtime, code map and agent instructions at that baseline before changing code. Check newer commits and production drift rather than blindly applying an old patch.

Keep architecture decisions and historical evidence. Do not create duplicate domain models, change Moodle core, hide business logic in the theme, or remove learning/economy history. Database changes need explicit migrations and validation. User instructions take precedence over historical audit recommendations; roadmap proposals do not authorize unrelated production mutations.

Current business priority includes working route studio, reliable completion, rewards for every newly confirmed route point, gamification and achievements. Do not freeze these because an older plan suggested it.

Canonical task status is maintained in main `context/roadmap/`. Each completed step must include exact code SHA, real validation evidence and separate deployment evidence where applicable. An archive, a code file or a static check is not production acceptance. See the protocol before claiming a task done.
