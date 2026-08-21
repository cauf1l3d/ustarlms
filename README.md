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
