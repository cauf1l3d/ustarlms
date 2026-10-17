# USTAR Server Console

LAN выпуск INF-06 в PR103: отдельный FastAPI/API, серверные сессии Argon2id, фиксированный host collector, React/TypeScript UI и systemd. Это operations UI; `moodle/local/ustar` и `moodle/theme/ustar` не меняются, legacy `frontend/` не используется.

[Текущий LAN выпуск](LAN_ROLLOUT_20261017.md) · [Изменения по аудиту17Oct](AUDIT_FOLLOWUP_20261017.md) · [Установка на server1 и приёмка](INSTALL.md) · [Архитектура](ARCHITECTURE.md) · [Health/метрики](OBSERVABILITY.md) · [UI/UX](DESIGN.md).

## Поставлено в исходниках

- Login/password, Secure/HttpOnly/SameSite cookie, CSRF/Origin, durable rate limit, idle30мин/absolute12ч, отзыв сессий при смене/локальном reset пароля. Polling не продлевает idle.
- Отдельный сборщик15s: CPU/MemAvailable/load/SSD, SSD/HDD capacity+inodes, точный HDD mount, два контейнера, success/attempt/recovery marker, cron v3 receipt и ограниченный lab receipt. Последний наблюдавшийся успех cron сохраняется в отдельном приватном root-owned state, переживает рестарт и не стирается последующей ошибкой. Веб-пользователь не имеет Docker/root и не запускает probes по GET.
- API к fixed Prometheus queries CPU/RAM/external HTTP, bounded history, NoData/stale, четыре независимых health-домена. Неподключённые источники явно неизвестны.
- Шесть разделов UI, крупная контрастная типографика, цветовые акценты, временные ряды в духе Grafana (шкалы, легенды, last/mean/max, tooltip, CPU/RAM), детали/native dialogs, общие периоды, графики с разрывами, потеря сети/сессии, собственный пароль, мобильный интерфейс. Нет demo fallback.
- Ограниченные systemd units, timer, установка из wheel/зафиксированных зависимостей с SHA256, отдельный CI, auth/data/browser/systemd fixtures.

## LAN установка и границы

Добавлены native HTTPS/локальная CA/setup, отдельный aggregate-only PostgreSQL monitor, native история15дней, NTP/SMART adapters, durable alerts/outbox/Telegram с numeric allowlists, session-bound выдача encrypted .age через Unix FD broker и фиксированные jobs с планом/reauth/revision/locks/idempotency. TOTP и локальная CLI управления пользователями; root операции вынесены из web identity. Для другого LAN узла поставляется observer.pyz с отдельным token/outbox.

**Установлено владельцем567e90d5; limited gate пройден23:36MSK17Oct.** [Receipt](../context/runtime/console_native_install_20261017.json), [установка](INSTALL.md), [Telegram и домашний доступ](REMOTE_ACCESS.md). Пульт открывается браузером по owner report23:59MSK. HDD/SMART требуют диагностики; trust CA/login/roles/download/receiver/overhead и observer ещё не приняты. TOTP пропущен, Telegram пока не подключён; startup argument hotfix подготовлен в PR. Бот не запускает root jobs. Target design Prometheus/Alertmanager не устанавливается первым LAN комплектом: используется bounded native history/rules; fixed Prometheus adapter остаётся опциональным.

Открыты request p95/5xx, sanitized raw logs, forecast/PSI, live SFTP settings, гибкий TTL, production restore plans и web user administration. Полный INF-06 не закрыт по одному CI. Копия сисадмина ожидает native restore/RTO, Moodle5.2/PG17 отложены.

[Новый автономный прототип](prototype/index.html) отражает актуальный UI17Oct: cron/копия/диск/HTTP перед графиками, с сохранёнными исправлениями читаемости16Oct. Скачайте HTML и откройте в браузере; [подробности и пересборка](prototype/README.md). Прототип использует только демонстрационные данные. [Прежний макет15Oct](prototype/archive/index-20261015.html) сохранён в архиве; прототип не является работающим сервисом или runbook переноса. [Production copy](../context/runtime/production_copy_20261015.md) — отдельная действующая инструкция передачи.

## Проверка

В отдельном workflow `USTAR console` выполняются backend/auth/data tests, UI build/dependency audit, Chromium desktop1440/mobile390/tablet768 и native systemd/install на одноразовом Ubuntu runner. Browser fixtures отделены от production wheel. Успешный CI не подтверждает настройку server1, доставки Telegram или восстановления Академии.
