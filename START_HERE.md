# USTAR: вход в проект

Актуализировано 02.10.2026. Канонический репозиторий: https://github.com/cauf1l3d/ustarlms.

## За две минуты

- Рабочая база разработки — свежий `origin/main`. Application baseline этой консолидации: `e1d57f5bc28f6964afd20aeff7620345b34a80fe` (PR76). Последующие контекстные коммиты не меняют этот код.
- Продукт — внутренняя корпоративная академия, а не общедоступная LMS. Пользователь сам выполняет production deploy; агент готовит проверенный SHA и команды.
- Backend: Moodle/PHP, `moodle/local/ustar`. Основной UI: native Moodle pages + Mustache + `moodle/theme/ustar`, родитель темы **boost**. `frontend/` — отдельный Next.js клиент в репозитории, его production-использование не подтверждено.
- Реальные данные, назначения ролей, включённые службы и DNS берутся из runtime evidence. Наличие функции в Git не доказывает её настройку на сервере.
- Пользователь сообщает о стабильной работе. Последний доступный точный серверный manifest относится к раннему PR71 `4cbb8ac37ef99051b9432e1e5c7c462f1472ad36`; позднее есть подтверждения поведения PR72/иконки PR75. Точный текущий production SHA пока не измерен заново.
- Следующий приоритет — полноценное скачиваемое приложение Android/iOS. Сейчас есть мобильный web/PWA; native client, push и полноценный offline ещё не реализованы.

## Обязательный порядок чтения

1. [project.yaml](context/project.yaml) и [CONTEXT_INDEX](context/CONTEXT_INDEX.md).
2. [architecture](context/architecture/overview.md), [boundaries](context/architecture/boundaries.md), [constraints](context/constraints.yaml).
3. [ADR index](context/decisions/README.md) и решения затрагиваемого домена.
4. [domains](context/domains/), [runtime](context/runtime/README.md), [code map](context/code_map/README.md).
5. [STATE](context/roadmap/STATE.yaml), [ACTIVE](context/tasks/ACTIVE.md), [BACKLOG](context/roadmap/BACKLOG.yaml).
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
- Moodle core, secrets, live config и кадровые данные не редактируются ради наведения порядка в Git.
- Старые release/ADR документы — свидетельства на дату. Текущие указатели только в STATE; история сохранена в [архиве](context/archive/handoff_20261002/README.md).
