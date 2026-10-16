# USTAR Server Console

Первый рабочий read-only срез INF-06 в PR103: отдельный FastAPI/API, серверные сессии Argon2id, фиксированный host collector, React/TypeScript UI и systemd. Это operations UI; `moodle/local/ustar` и `moodle/theme/ustar` не меняются, legacy `frontend/` не используется.

[Установка на server1 и приёмка](INSTALL.md) · [Архитектура](ARCHITECTURE.md) · [Health/метрики](OBSERVABILITY.md) · [UI/UX](DESIGN.md).

## Поставлено в исходниках

- Login/password, Secure/HttpOnly/SameSite cookie, CSRF/Origin, durable rate limit, idle30мин/absolute12ч, отзыв сессий при смене/локальном reset пароля. Polling не продлевает idle.
- Отдельный сборщик15s: CPU/MemAvailable/load/SSD, точный HDD mount, два контейнера, success/attempt/recovery marker, ограниченный lab receipt. Веб-пользователь не имеет Docker/root и не запускает probes по GET.
- API к fixed Prometheus queries CPU/RAM/external HTTP, bounded history, NoData/stale, четыре независимых health-домена. Неподключённые источники явно неизвестны.
- Шесть разделов UI, крупная контрастная типографика, цветовые акценты, временные ряды в духе Grafana (шкалы, легенды, last/mean/max, tooltip, CPU/RAM), детали/native dialogs, общие периоды, графики с разрывами, потеря сети/сессии, собственный пароль, мобильный интерфейс. Нет demo fallback.
- Ограниченные systemd units, timer, установка из wheel/зафиксированных зависимостей с SHA256, отдельный CI, auth/data/browser/systemd fixtures.

## Границы первого этапа

**Не установлено на server1.** Gateway/VPN/cert, сопоставление метрик с CLI и измерение overhead требуют native приёмки. Prometheus/node-exporter/blackbox, Alertmanager и правила этим комплектом не устанавливаются; интеграция истории только читает выбранные local series.

DB-monitor/cron adapter, raw host logs с redaction, Telegram/outbox/external observer, защищённый download, SFTP settings и host jobs — следующие этапы. Все роли пока читают одни данные; web-администрирования пользователей/TOTP нет. Копия сисадмина ожидает приёмки, Moodle5.2/PG17 отложены.

`prototype/index.html` сохранён как исторический автономный demo15Oct; он не является рабочим пультом или runbook переноса. [Production copy](../context/runtime/production_copy_20261015.md) — отдельная действующая инструкция передачи.

## Проверка

В отдельном workflow `USTAR console` выполняются backend/auth/data tests, UI build/dependency audit, Chromium desktop1440/mobile390/tablet768 и native systemd/install на одноразовом Ubuntu runner. Browser fixtures отделены от production wheel. Успешный CI не подтверждает настройку server1, доставки Telegram или восстановления Академии.
