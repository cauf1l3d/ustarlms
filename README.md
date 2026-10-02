# USTAR Academy

Корпоративная академия розничной сети «Хозмагия», работающая во внутренней сети компании. Moodle отвечает за LMS, `local_ustar` — за бизнес-процессы, `theme_ustar` — за интерфейс.

**Код и текущая документация находятся вместе в `main`. Начните с [START_HERE.md](START_HERE.md).**

| Что нужно | Куда идти |
|---|---|
| Состояние и следующий шаг | [STATE](context/roadmap/STATE.yaml), [ACTIVE](context/tasks/ACTIVE.md) |
| Архитектура и ограничения | [Обзор](context/architecture/overview.md), [ADR](context/decisions/README.md) |
| Найти реализацию | [Карта модулей](context/code_map/README.md) |
| Установленный код, CI, последние SHA | [Реестр релизов](context/runtime/RELEASE_LEDGER.md) |
| Эксплуатация, проверка prod, выпуск | [Runbook](context/runtime/OPERATIONS.md) |
| Следующий этап Android/iOS | [План мобильного клиента](context/roadmap/MOBILE_CLIENT.md) |

На 02.10.2026 владелец сообщает о стабильной работе без выявленных в текущей работе багов. Это пользовательское наблюдение; актуальный серверный manifest и полная приёмка всех сценариев — отдельные свидетельства. Последний проверенный application baseline — PR76 `e1d57f5bc28f6964afd20aeff7620345b34a80fe`, версии `2026100204 / 2026100202`, полный CI #456. Слияние в main само по себе не устанавливает обновление на сервер.

Исторические RC, аудиты и копии исходников в `release/` сохранены для трассировки; новый код меняется в `moodle/`. Секреты, production DB, moodledata и recovery archives в Git не хранятся.
