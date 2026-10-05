# USTAR: вход в проект

**Приоритет владельца06.10 00:45MSK:** сначала оптимизировать хранение, обновить карту сервера и настроить автобэкапы на HDD; затем новый стенд и Moodle/ОС/HTTPS. Прежнее правило «автобэкапы в финале» заменено. PR87 `--prepare-stage` приостановлен. [Карта хранения](context/runtime/server_map_20261006.md).

Актуализировано 06.10.2026. Канонический репозиторий: https://github.com/cauf1l3d/ustarlms.

## За две минуты

- Рабочая база разработки — свежий `origin/main`. Application baseline этой консолидации: `e1d57f5bc28f6964afd20aeff7620345b34a80fe` (PR76). Последующие контекстные коммиты не меняют этот код.
- Продукт — внутренняя корпоративная академия, а не общедоступная LMS. Пользователь сам выполняет production deploy; агент готовит проверенный SHA и команды.
- Backend: Moodle/PHP, `moodle/local/ustar`. Основной UI: native Moodle pages + Mustache + `moodle/theme/ustar`, родитель темы **boost**. `frontend/` — отдельный Next.js клиент, **не используется в production по ответу владельца**.
- Реальные данные, назначения ролей, включённые службы и DNS берутся из runtime evidence. Наличие функции в Git не доказывает её настройку на сервере.
- Свежий owner manifest 05.10 02:32:35.997389 UTC: 526 файлов plugin/theme совпадают с PR76; все 468 component versions disk = readonly DB в 02:43:33. Core comparison 08:28 UTC: 24 678 matching, один changed `public/index.php`, 23 extra, ошибок/пропусков нет. Index upload проверен: redirect на главную USTAR; extras/consumers ещё проверяются. Backup/cron uploads 09:48:49 UTC полностью прочитаны, hashes совпали; [проверенный CLI и свежая ручная копия](context/runtime/recovery_contract_20261005.md). [Core result](context/runtime/core_review_20261005.md). Cron v3 success в 02:13. Свежий ручной snapshot 05.10 создан, local hash и resume PASS; cron scheduled 10:24 exit0/new failures0, root HTTP303. Вчерашний restore/manual PASS учтён; [resume helper](context/runtime/lab_resume_20261005.md) выполнен владельцем 11:30:58 UTC: lab login200 / production containers unchanged PASS. [Patch CI](context/runtime/patch_runtime_20261005.md) PR83 full RC SUCCESS. [Planning PASS и точные размеры](context/runtime/lab_planning_result_20261005.json): owner20:44:34UTC,526 USTAR/57 addon metadata, free10,51GiB. Новый SMART20:44:52UTC: extended Completed without error, pending/reallocated0, residual offlineUNC1. [Cold checkpoint + separate workspace](context/runtime/lab_cold_checkpoint_20261005.md) подготовлен/tested, fresh required budget9,80GiB/reserve4GiB; server execution pending, core upgrade/repeat/paired rollback впереди. [Baseline](context/runtime/baseline_20261005.md) и [evidence](context/runtime/evidence_20261005_baseline.yaml) имеют даты/область, это не live query агента.
- Сейчас: [аудит](context/audits/README.md) и [полный план](context/roadmap/USTAR_AUDIT_REMEDIATION_PLAN_20261005_RU.md). Открыты DB superuser, runtime-запись в code/config, HTTP, отсутствие общего пульта, старый core patch и storage/backup риски. Гипотезу о полной тестовой копии на `/` сначала измерить.
- DOC-02 завершён PR78; новая команда владельца — **1 → 7 → 6**, остальные пункты после них. INF-01 в работе, далее Moodle/CI/ОС, затем HTTPS. Продолжать после публикации, не применять старую остановку. Регулярные копии перемещены владельцем в текущий этап до стенда; HDD test-scope PASS/residual198=1 и independent-copy границы сохраняются. Mobile Android/iOS, Privacy, B2B и major/LTS остаются отдельными проектами; PWA не завершает запрос приложения.

## Обязательный порядок чтения

1. [project.yaml](context/project.yaml) и [CONTEXT_INDEX](context/CONTEXT_INDEX.md).
2. [architecture](context/architecture/overview.md), [boundaries](context/architecture/boundaries.md), [constraints](context/constraints.yaml).
3. [ADR index](context/decisions/README.md) и решения затрагиваемого домена.
4. [domains](context/domains/), [runtime](context/runtime/README.md), [code map](context/code_map/README.md).
5. [STATE](context/roadmap/STATE.yaml), [ACTIVE](context/tasks/ACTIVE.md), [BACKLOG](context/roadmap/BACKLOG.yaml), [аудит и уточнения](context/audits/README.md), [план](context/roadmap/USTAR_AUDIT_REMEDIATION_PLAN_20261005_RU.md).
6. [Agent profile](context/agents/astra.md), [protocol](context/roadmap/AGENT_PROTOCOL.md), затем фактический код сценария.

## Начать работу

```bash
git fetch origin
git switch main
git pull --ff-only origin main
git status --short
git rev-parse HEAD
git switch -c codex/<task>
```

При локальных изменениях сначала сохранить их; не применять reset/force push. Новый PR направлять в main. Не создавать новую цепочку PR поверх старых feature-веток. Release SHA фиксируется полностью; после изменения кода CI повторяется.

## Не додумывать

- `local_ustar_structure` по-прежнему хранит каталог структуры; нормализованы staff places/assignments. Полная миграция всех legacy записей не доказана.
- PRIMARY определяет должность обучения; ACTING — временное полномочие. Название должности не выдаёт HRD capabilities.
- Регистрация внутренняя, с кадровым подтверждением; SMTP не является условием регистрации.
- Нельзя считать `source/frontend=success` полным RC gate или HTTP 303 доказательством работы авторизованной страницы.
- Официальный patch Moodle и HTTPS готовятся по выбранным пунктам с recovery/stage/rollback; secrets и кадровые данные не публикуются и не меняются ради наведения порядка в Git.
- Старые release/ADR документы — свидетельства на дату. Текущие указатели только в STATE; история сохранена в [архиве](context/archive/handoff_20261002/README.md).
