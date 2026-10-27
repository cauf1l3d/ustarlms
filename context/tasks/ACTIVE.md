# Активная работа —28.10.2026

## STUDIO-01 / PR107

Поручение: штатный дизайн Академии для студии, «Открыть в студии» из материалов,
редактируемый импорт, Word .docx, каталог список/блоки/карточки и исправления UX.
Реализовано в отдельном worktree без субагентов. Локальные codec/DOM/SQL/Chromium
проверки и full RC2700 пройдены: обе Moodle347тестов/1671проверка, HTTP PASS. [Квалификация](../runtime/studio_materials_qualification_20261028.json). Кандидат не установлен, PR107
не сливать автоматически. [План](../../docs/superpowers/plans/2026-10-27-materials-studio-polish.md),
[прогресс](../runtime/studio_materials_polish_progress_20261027.md),
[инструкция автора](../../docs/COURSE_STUDIO_RU.md).

Production остаётся2d95137a/local2600/theme0202, установленным владельцем через
operator62b0da20. [Квитанция установки](../runtime/course_studio_owner_deploy_20261027.json).
Владелец подтвердил app_ready/DB/services и scheduled cron27Oct20:22UTC exit0 без
новых ошибок; студия открывается/работает и импортирует другую студию.
[Post-deploy](../runtime/course_studio_post_deploy_owner_20261028.json).
Отдельные PDF/conflict/employee кейсы не заявлены. Старую ошибку cron20Oct и
установку2600 не повторять. Агент не подключается к серверу.

## Принятая основа и очередь

Main содержит принятую основу6c56/local2300: PR105/103/106 интегрированы через
PR108. [Сверка и CI](../runtime/repository_reconciliation_20261026.json).
Production2600 и неустановленный кандидат2700 учитываются отдельно в
[STATE](../roadmap/STATE.yaml).

После студии: PERF-01 — пассивный baseline и подтверждённые N+1; INF-12 —
отрицательные actor/scope/upload/CSRF проверки и реестр входов; UX-путь сотрудника
от «Мой день» до результата на телефоне. Не создавать второй каталог сотрудников,
прогресса или evidence. Извлекать readers/presenters по изменяемому домену,
сохраняя канонические сервисы и API.

Docker rotation/health/recreate отложены до обновления Moodle/PHP. SSH/firewall —
перед внешним доступом по поручению владельца. Копии переданы; внешний restore
остаётся unknown, повторного запроса не нужно. Прочие риски — в ACCEPTED_RISKS.

Код, CI, квитанция владельца и свежая проверка сервера — разные основания.
