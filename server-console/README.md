# USTAR Server Console

LAN выпуск INF-06 в PR103: отдельный FastAPI/API, серверные сессии Argon2id, фиксированный host collector, React/TypeScript UI и systemd. Это operations UI; `moodle/local/ustar` и `moodle/theme/ustar` не меняются, legacy `frontend/` не используется.

**18Oct17:03МСК: пульт возвращён владельцем на доработку.** Прежнее закрытие14:42 — история. [Объём и проверки](REVISIONS_20261018.md), [HTTPS и Mini App](ACCESS_FIX_20261018.md), [request metrics и журналы](REQUEST_LOGS_20261018.md). Тема/zoom и вкладки «Скрипты»/«Документация» входят в новую поставку; native trust/route/install ещё не приняты.

[Текущий LAN выпуск](LAN_ROLLOUT_20261017.md) · [Изменения по аудиту17Oct](AUDIT_FOLLOWUP_20261017.md) · [Установка на server1 и приёмка](INSTALL.md) · [Архитектура](ARCHITECTURE.md) · [Health/метрики](OBSERVABILITY.md) · [UI/UX](DESIGN.md).

## Поставлено в исходниках

- Login/password, Secure/HttpOnly/SameSite cookie, CSRF/Origin, durable rate limit, idle30мин/absolute12ч, отзыв сессий при смене/локальном reset пароля. Polling не продлевает idle.
- Отдельный сборщик15s: CPU/MemAvailable/load/SSD, SSD/HDD capacity+inodes, точный HDD mount, два контейнера, success/attempt/recovery marker, cron v3 receipt и ограниченный lab receipt. Последний наблюдавшийся успех cron сохраняется в отдельном приватном root-owned state, переживает рестарт и не стирается последующей ошибкой. Веб-пользователь не имеет Docker/root и не запускает probes по GET.
- API к fixed Prometheus queries CPU/RAM/external HTTP, bounded history, NoData/stale, четыре независимых health-домена. Неподключённые источники явно неизвестны.
- Шесть разделов UI, крупная контрастная типографика, цветовые акценты, временные ряды в духе Grafana (шкалы, легенды, last/mean/max, tooltip, CPU/RAM), детали/native dialogs, общие периоды, графики с разрывами, потеря сети/сессии, собственный пароль, мобильный интерфейс. Нет demo fallback.
- Ограниченные systemd units, timer, установка из wheel/зафиксированных зависимостей с SHA256, отдельный CI, auth/data/browser/systemd fixtures.
- Telegram PNG-карточки с реальной CPU/RAM/HTTP историей, полным текстом и durable retries; ёмкость HDD/inode, свежие правила и сохранённые инциденты в UI. Sourceb93e16af:312 backend/72 Chromium/native CI, проверенный пакет; [результат поставки](../context/runtime/console_telegram_graphics_20261018.json).

## LAN установка и границы

Добавлены native HTTPS/локальная CA/setup, отдельный aggregate-only PostgreSQL monitor, native история15дней, NTP/SMART adapters, durable alerts/outbox/Telegram с numeric allowlists, session-bound выдача encrypted .age через Unix FD broker и фиксированные jobs с планом/reauth/revision/locks/idempotency. TOTP и локальная CLI управления пользователями; root операции вынесены из web identity. Для другого LAN узла поставляется observer.pyz с отдельным token/outbox.

Первый native gate567e90d5 от17Oct и последующие исправления сохранены в датированных receipts. После выдачи пакетаb93e16af владелец18Oct сообщил, что работает, и закрыл этап; exact installed hash и отдельная полная native матрица не присланы. Этот [receipt](../context/runtime/console_owner_acceptance_20261018.json) сохранён как история и отменён новым запросом17:03; token/setup повторять не требуется. TOTP остаётся пропущенным владельцем. Бот не запускает root jobs. Для LAN используются bounded native history/rules; fixed Prometheus adapter опционален.

В отдельной INF-06-OPS сохранены неподтверждённые role/download/job/overhead-проверки, независимый observer и возможный private home access. Новые p95/5xx и очищенные журналы входят в текущую доработку; forecast/PSI, live SFTP settings, гибкий TTL, production restore plans и web user administration остаются возможными отдельными работами; они не объявляются реализованными этой доработкой. Копия сисадмина ожидает native restore/RTO (OPS-02), Moodle5.2/PG17 отложены.

[Автономный прототип](prototype/index.html) отражает UI18Oct, включая HDD, правила и историю инцидентов. Скачайте HTML и откройте в браузере; [подробности и пересборка](prototype/README.md). Прототип использует только демонстрационные данные. [Прежний макет15Oct](prototype/archive/index-20261015.html) сохранён в архиве; прототип не является работающим сервисом или runbook переноса. [Production copy](../context/runtime/production_copy_20261015.md) — отдельная действующая инструкция передачи.

## Проверка

В отдельном workflow `USTAR console` выполняются backend/auth/data tests, UI build/dependency audit, Chromium desktop1440/mobile390/tablet768 и native systemd/install на одноразовом Ubuntu runner. Browser fixtures отделены от production wheel. Успешный CI не подтверждает настройку server1, доставки Telegram или восстановления Академии.
