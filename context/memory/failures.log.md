# Engineering Failures Log


## 2026-09-06

Issue:
Route override patch v1 failed.

Cause:
Patch was created against outdated code structure.

Lesson:

Do not use large blind string replacement scripts
against production PHP files.

Preferred:

- git diff patches
- exact context matching
- smaller atomic changes
