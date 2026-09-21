# Stage 5 — прохождение и награды

Дата: 2026-09-21

Этап строится поверх завершённого Stage 4 в PR #7 и не изменяет production.

## Результат

Один независимо подтверждённый цикл обучения имеет immutable identity.
Повторная доставка тех же evidence возвращает прежний цикл и не может повторно
начислить награду. Новое фактическое прохождение после renewal создаёт новый
цикл и получает отдельную награду ровно один раз.

## Completion и assessment/remediation

`local_ustar_completion_cycle` хранит подтверждённые циклы. Детерминированный
`cyclekey` включает сотрудника, логическую точку, версию, фактический
`completedat` и нормализованный evidence payload.

- inherited progress не считается новым прохождением;
- override сохраняет identity исходной логической точки;
- assessment принимается только в статусе `passed`, с положительным lifecycle
  cycle и точным совпадением `verifiedcompletedat`;
- устаревшее или неподтверждённое remediation/assessment evidence отклоняется;
- существующий route progress остаётся read model и очередью reconciliation.

## Награды, USCOIN и достижения

Route reward использует ключ `route-reward-v2:<cyclekey>` и источник
`completion_cycle`. Транзакция атомарно записывает learning evidence и
начисляет USCOIN; повторный вызов безопасен. Новый подтверждённый cycle получает
новую запись. Achievement thresholds считают как legacy grants, так и новые
cycle grants, сохраняя историю перехода.

## Игры и корпоративные показатели

Game mastery уже является однократным источником XP и competition score.
Дополнительно любой score/replay теперь повторно проверяет
`accounts::participates()`, поэтому даже stale participant snapshot не
пропускает test/service identity. Те же аккаунты исключены из route reward
summary/achievements и HR product-assessment list.

## Reconciliation и проверка

Bounded reconciliation восстанавливает отсутствующий cycle из progress read
model и повторяет атомарное начисление по cycle key. Тесты покрывают:

- повторную доставку одного cycle;
- отдельную награду нового cycle;
- assessment timestamp/cycle validation;
- inherited/override semantics;
- отсутствие production reward для test learner;
- legacy и cycle achievement summary.

Условие перехода к Stage 6 выполнено: повторная доставка не дублирует награду,
новый подтверждённый цикл вознаграждается один раз, test/service accounts
исключены из корпоративных метрик.

Production deployment, review и merge остаются отдельными действиями.
