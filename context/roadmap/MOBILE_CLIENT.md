# Следующий этап: полноценное приложение Android/iOS

Запрос владельца 02.10.2026: отдельный скачиваемый, работающий и оптимизированный мобильный клиент с функциональностью USTAR. Этот документ готовит реализацию; не объявляет выбранный стек, готовый API или готовое приложение.

## Уже существует

Native Moodle UI с мобильной навигацией, manifest/icons/offline notice, личные и групповые Moodle чаты с media через File API. В `db/services.php` зарегистрированы 30 USTAR external functions и сервис `ustar_workspace`; дополнительно используются native PHP POST controllers. Полный [инвентарь](../code_map/generated/web_services.md) фиксирует зарегистрированные функции, а не проверенную мобильную совместимость.

Текущая PWA не хранит приватные страницы/offline learning. `frontend/` — Next.js web client; его наличие не означает, что он является заготовкой native приложения. В Git не найдены проекты Android/iOS, signing/distribution pipeline или законченный push/device-registration contract.

## Исходная карта покрытия для MOB-01

| Сценарий | Существующее основание | Что проверить/добавить для клиента |
|---|---|---|
| Вход, сессия, регистрация | Moodle auth, registration_service, access_context | Mobile auth/token lifecycle, revocation, pending UX, безопасное хранение; не копировать пароли в новый store |
| Главная, профиль, команда | get_workspace/get_dashboard/get_team/get_ladder/get_matrix, native pages | Сравнить поля/ACL с актуальным native UI; существующие функции могут отражать ранний web client |
| Маршруты, Quiz/SCORM, evidence | route services, Moodle activities, launch guards | Полное API покрытия, безопасный запуск, return/deep links, completion evidence; определить границу native/WebView после проверки |
| Задачи, checklist, календарь, KPI | task_workspace service/policy/worker; старые get/submit_checklist | Новый task workspace не считать покрытым старым checklist endpoint; нужны revisions, файлы, submit/review, recurrence/control |
| Чаты и media | messages_api, core_message, chat_files/chat_groups | Session POST API не равно native token API; повтор отправки, paging, membership revoke, background delivery |
| Лента и личная библиотека | feed_service/access/query/files, content, Moodle File API | Аудитория, публикация, moderation, reactions/comments, download/save API и пагинация |
| Грейды, адаптация, HR/HRD | grade services, adaptation_service, staffing_requests | Полная матрица ролей и команд, confirmation/revision; PR76 reset имеет schema prerequisite |
| Достижения, награды, соревнования | reward_control, economy, competition | Единые read models; не начислять клиентом; retry/idempotency и серверный расчёт |
| Уведомления | Moodle + workflow_notifications | Push provider/device registration пока не заданы; privacy payload, открытие конкретного объекта и revocation |
| Offline и плохая сеть | public offline notice, chat request IDs | Согласовать offline scope; хранение, очистка при logout/увольнении, conflict/retry protocol, запрет ложного completion |

## Этапы и результат

1. **MOB-01 — инвентаризация и ADR.** Составить реестр всех native страниц/команд и покрытие API, проверить существующие rights/Files/auth, описать API versioning, paging/errors/idempotency. Сравнить варианты native/cross-platform по реальным сценариям SCORM/media, доступности команды и поддержки. Утвердить стек и границы без второй бизнес-модели.
2. **MOB-02 — серверный API contract.** Расширять существующий `local_ustar` с reuse services; тестировать denial, stale revision, retry, прямые файлы и роли. Выпускать обратно совместимо, без регрессии рабочего web.
3. **MOB-03 — вертикальный клиент.** Устанавливаемые Android/iOS сборки: вход → главная → маршрут/обучение → сохранённый сервером результат; media/chat; role navigation. На реальных устройствах, с проверяемым server SHA.
4. **MOB-04 — полное покрытие и качество.** Закрыть согласованную матрицу employee/manager/HR/HRD/owner, deep links/push/offline выбранного объёма, accessibility, длительные сессии, background/foreground, сетевые ошибки и нагрузку.
5. **MOB-05 — распространение и сопровождение.** Подписанные воспроизводимые сборки, внутренний пилот, выбранный канал установки/обновления, crash/performance diagnostics без лишних персональных данных, совместимость версий клиента/сервера и rollback.

## Что выяснить перед архитектурным выбором

- Как телефоны достигают внутренней Академии: LAN/VPN, DNS, HTTPS и доверие сертификатам. Работа вне сети компании — отдельное согласованное требование; приложение само не создаёт доступ к серверу.
- Нужны публичные магазины, корпоративное распространение или иной управляемый канал; кто владеет signing accounts и устройствами. Правила площадок проверить по официальным источникам на момент реализации.
- Какая функциональность должна работать offline, а какая требует сервера; нельзя обещать offline SCORM/evidence без sync contract.
- Обязательны ли push, биометрический unlock, camera/files, deep links; какие минимальные ОС/модели устройств реально используются.
- Доступный staging, обезличенные данные и тестовые роли; текущая performance baseline и production source drift.

**Done этапа:** пользователь устанавливает подписанную сборку на Android и iPhone, входит в реальную согласованную среду и выполняет утверждённые сценарии с теми же правами/результатами, что web. Нет потерянных файлов, дублей команд/наград, утечек после смены роли или аккаунта. Скорость и нагрузка измерены; обновления и поддержка воспроизводимы. Наличие иконки, APK с WebView или демо экранов само по себе этот критерий не закрывает.
