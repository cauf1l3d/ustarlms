# Execution ledger — plan: USTAR_CONNECT_CON01C3_PLAN_20261019.md

Base893a2cb3 verified against PR106; main d9655074 unchanged. Clean separate feature branch codex/connect-notifications-20261019. C2 closed84native/436assertions/24link scenarios; do not redo it. User13:43 requested remaining work,14:50 requested continue. No agents or provider/production/network operations.

Pre-flight: both canonical notification writers already own their transaction and notification-key lock; enqueue must JOIN that transaction, not use identity_service::atomic inside it. Lease/begin/finish own identity lock/transaction independently. Public worker can assert only configured provider/tenant; no caller-selected employee. Recipient preferences must be read freshly from canonical storage before send, not from a cached worker snapshot.

Ruling: use existing delivery table plus Moodle user preference rather than create business queue/preferences tables — preserves canonical notification IDs and existing unique notification/channel; cost: managed delivery fields and Privacy export need explicit upgrade/parity.
Ruling: fixed generic Telegram cards, not raw canonical message/HR titles — avoids sensitive payload leaving Academy and is usable before full remote actor/step-up; cost: details require authenticated Academy access.


## Реализация / до финальной CI приёмки

Test-only source f42c1555652fd66ad9d01fd61c4649bbbba0d079, treee060a5b6bf97d4e7917b22a6292549c200f42361. Context39248117658 SUCCESS; Review39248117367 source8.2/8.3 SUCCESS; native118045737886 expected RED:96tests/436assertions,12missing notification_preferences errors,84предыдущих теста PASS. Decoded log прочитан. Test-only artifact12184303083 — evidence отказа, не готовности.

Worker missing-module RED10tests →13tests PASS после реализации; review обнаружил отличие реального bridge-envelope от test double, контракт и тесты приведены к exact {ok:true,result:{...}}. Никаких provider вызовов. Native suite расширен до16notification tests с owner/CSRF/privacy/atomic rollback/DST. Добавлена независимая multi-process lease/begin/receipt гонка. Кандидат2026101904/550addonfiles/99tables; новый worker вне addoncount. Нативный UI содержит личные настройки и20безопасных delivery summaries. Публичные сырые messages/HR reasons/URL не добавлены.

Локальный первый full r16:660tests/30skips,3environment import errors (mcp отсутствует). Это не PASS; declared pinned dependencies устанавливаются отдельно. PHP и Chromium локально не заявляются. Новая exact implementation CI ещё не завершена; итоговая квитанция будет в PR106.

Команды разработки (продублированы в чате):
```bash
python -m unittest discover -s tests/r16 -p test_connect_notifications.py -v
python -m unittest discover -s tests/r16 -v
node tests/mobile/connect-link.cjs
PYTHONPATH=connect-worker python -m ustar_connect.notification_runtime --help
python scripts/check_context.py
```

C3 не закрывает remote actor/step-up/полный ролевой контур. Они следуют за доставкой; внешнийorigin/заменённыйtoken нужны для liveactivation, не для sourceразработки.


Личный review: provider-wide Retry-After теперь блокирует следующие managed deliveries того же tenant после restart, не только исходную строку. Startup getMe401 останавливает,429 выдерживает паузу (workerRED→GREEN14tests). Напоминание/эскалация проверяют canonical cyclekey/trigger/lane/level/current recipients через read-only helper существующего task worker, не только view permission. Добавлены actual registration writer/HRD capability revocation, feed audience и own grade request guards; notification suite21tests +1bridge exact payload/scope. Нативные дополнительные проверки пока pending.

Implementation86f47bab Context39249334337 SUCCESS; Review39249334425 source8.2/8.3 SUCCESS (actual Chromium native controls included), native118049834476 FAILED на XMLDB dependency status_next_idx при расширении varchar status16→32 до PHPUnit. Это реальная ошибка миграции кандидата, не отказ тестовой среды. Миграция исправлена: снимает зависимые status_next_idx/connect_ready_idx, меняет precision и восстанавливает оба индекса из install schema. Повторная exact native/fresh/upgrade/repeat проверка обязательна. Локальный full661r16 OK/30environment skips после pinned requirements; PHP/Chromium локально не выполнялись.
