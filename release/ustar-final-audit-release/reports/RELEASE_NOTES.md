# USTAR release notes

Статус: **релиз не выполнен**  
Дата: 2026-08-23

Матрица фактической завершённости находится в `MASTER_TASK_COMPLETION_MATRIX.md`. Она отделяет PROVEN test evidence от PARTIAL business journeys, OWNER decisions и NOT AUTHORIZED production work.

Phase 0 read-only аудит согласован владельцем. Создана ветка `ustar-final-audit-release`, свежий backup, isolated test environment и выполнен разрешённый P0 containment.

Production-релиз не выполнялся. Production DB, пользователи, роли, маршруты, содержимое theme, Moodle containers, Caddy и DNS не менялись. Выполнены только два отдельно разрешённых P0 containment-блока: обратимое удаление из public web-root двух подтверждённых HR mapping files и нормализация ownership/mode для `config.php`, `local_ustar` и `theme_ustar`.

В isolated environment восстановлен свежий DB dump, созданы synthetic role identities, проверены employee/manager/HR/CEO journeys и смоделирован reset default user role к Moodle archetype. Login polish реализован и проверен только в test theme: исправлена desktop grid, одинаковая ширина полей/CTA, нативный show/hide password toggle, mobile/tablet layout и login footer noise. Нативная Moodle auth form сохранена.

До отдельного production approval остаются: production default-role remediation, dependency upgrades, production-grade offsite/RPO/RTO и rollback rehearsal, TARGET decisions и финальный release snapshot. Isolated negative capability и полный test-only DR restore уже прошли.

После отдельного точного подтверждения permission hardening применён в production: `config.php` — `root:www-data|640`; код `local_ustar` и `theme_ustar` — `adu:adu`, каталоги `0755`, файлы `0644`; writable objects для web-процесса отсутствуют. Schema check и login — PASS. Rollback manifest сохранён в `/var/backups/ustar/p0-permission-manifests/2026-08-22_23-27-14/permissions.before`.

В isolated environment capability matrix, 34 запрещённых и 18 разрешённых protected entry points прошли как на исходном, так и на восстановленном стеке. Проверены немедленное снятие роли в активной сессии и штатный forced session revoke. Полный snapshot code+moodledata+DB (445 MB) восстановлен во второй стек; checksums, schema, login и HR-404 regression — PASS. Отдельной HRD-роли в CURRENT нет, поэтому HR/HRD separation остаётся TARGET-блокером, а не автоматически исправленной настройкой.

Новая система Academy icons реализована только в isolated environment. Из 29 исходных 3D PNG отобраны 12 feature/card illustrations; функциональная навигация и action controls оставлены SVG. Achievements, Games, Knowledge и Profile проверены в браузере, включая lazy-load, тёмную тему и отсутствие horizontal overflow. Production gate для assets: подтверждение license/provenance, оптимизация веса и отдельное разрешение на релиз.

Расширенный game runtime E2E выявил production CURRENT-дефект: question image хранится как absolute URL старого host и не получает authenticated session. В isolated environment добавлен File API resolver; изображение, wrong/correct feedback, attempts, mastery, 25 XP и +5 USCOIN posting прошли. Пустая active game с 0 questions скрыта из learner catalog, но сохранена в Game Studio. Эти изменения не опубликованы в production; связь XP→USCOIN остаётся CURRENT / TEST IMPLEMENTATION, а не TARGET-решением.

Оставшиеся Moodle health issues диагностированы без изменений production. `core_publicpaths` — Caddy trailing-slash 308, после которого каждый target даёт 404; private-file disclosure не воспроизведён. `local_ai_manager` ERROR реален: кадровое поле `institution` используется как tenant identifier и конфликтует с Latin-only rule. Исправление требует TARGET-решения об AI tenancy/owner/privacy; кадровые значения автоматически не менялись.

Финальный post-change isolated snapshot `2026-08-23_01-00-56` (445 MB) прошёл все checksum checks и восстановлен в независимый RC stack на loopback `18082`. В restored stack подтверждены schema/login/HR-404, exact login/icon/game hashes, 12 Academy assets, capability matrix, 34 denial и 18 allow tests. После проверки RC containers остановлены; production не менялся.

Ветка `ustar-final-audit-release` содержит логические commits локально. GitHub push не выполнялся: `git remote -v` пуст, canonical remote не указан. Это отдельный owner/repository gate, а не доказательство опубликованного релиза.
