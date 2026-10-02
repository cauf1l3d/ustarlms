# Реестр архитектурных решений

Исторические ADR сохранены с исходной датой и статусом. Наличие в main означает включение исходника, а не автоматическую production-приёмку. Для фактической реализации смотреть код и [release ledger](../runtime/RELEASE_LEDGER.md).

| Документ | Область |
|---|---|
| [0009-task-workspace](0009-task-workspace.md) | ADR 0009: Native work task workspace |
| [0010-workspace-hr-reward-control](0010-workspace-hr-reward-control.md) | ADR 0010 — Work previews, HR operations and reward controls |
| [0011-personal-workspace-season-conditions](0011-personal-workspace-season-conditions.md) | ADR 0011 — Personal layouts, conditional rewards and learning seasons |
| [0012-work-chats-mobile](0012-work-chats-mobile.md) | ADR 0012 — Native work chats and mobile web application |
| [0013-named-seasons-team-quiz](0013-named-seasons-team-quiz.md) | ADR 0013 — Named season rankings and distinct team-quiz answers |
| [0014-adaptation-assignment-reset](0014-adaptation-assignment-reset.md) | ADR 0014 — Adaptation assignment cancellation and retained cycles |
| [ADR-0001-git-source-of-truth](ADR-0001-git-source-of-truth.md) | ADR-0001 Git Source of Truth |
| [ADR-0002-unified-org-domain](ADR-0002-unified-org-domain.md) | ADR-0002 Unified Organization Domain |
| [ADR-0003-evidence-canonical-model](ADR-0003-evidence-canonical-model.md) | ADR-0003 Evidence Canonical Model |
| [ADR-0004-route-position-overrides](ADR-0004-route-position-overrides.md) | ADR-0004 Route Position Override Model |
| [ADR-0005-native-registration-hrd](ADR-0005-native-registration-hrd.md) | ADR-0005 · Внутренняя регистрация и подтверждение HRD |
| [ADR-0006-adaptation-decision-access](ADR-0006-adaptation-decision-access.md) | ADR-0006 · Явное полномочие решения HRD по адаптации |
| [ADR-0007-versioned-grade-ladders](ADR-0007-versioned-grade-ladders.md) | ADR-0007 — Версии грейдовой лестницы и личный грейд |
| [ADR-0008-feed-audience-and-authorship](ADR-0008-feed-audience-and-authorship.md) | ADR-0008: аудитория и авторство ленты (черновик G06) |

## Уточнения актуального поведения

- ADR0013 явно заменяет pseudonymous employee ranking из ADR0011 именами участников текущего сезона.
- ADR0014 расширяет ADR-0006 отменой назначения адаптации с сохранением истории и повторным назначением новым циклом.
- ADR-0007 описывает первоначальную политику грейдов. Последующая PR59 реализация `grade_promotion::assign_initial_if_bound()` вызывается из подтверждённой HR staffing-команды; автоматическое начальное назначение при наличии привязки разрешено владельцем 29.09. Это не назначение на GET и не автоматическое повышение.
- ADR-0008 содержит раннее условие о Privacy API перед RC. Владелец позднее отложил полный Privacy API как неблокирующий; текущий inventory и PRIVACY-01 сохраняют этот долг.
- ADR-0002 — целевая организация; текущий каталог по-прежнему в structure, staff place/assignment в отдельных таблицах. Не создавать вымышленные SQL departments/positions по одному тексту ADR.
- Новый мобильный стек ещё не утверждён. MOB-01 готовит отдельный ADR и опирается на существующие границы.
