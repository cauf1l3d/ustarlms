# USTAR Server Console

Первый интерактивный UI-прототип; на сервер не установлен. [Scope](../context/runtime/control_panel_scope_20261015.md).

Открыть `prototype/index.html` локально в браузере. Нет build step, CDN, внешних ресурсов или runtime dependencies. Данные помечены как демонстрационные. Навигация, фильтры, копирование путей и предпросмотр операций работают локально. Результаты выполнения/метрики не имитируют живое подключение. Поля логина/пароля disabled; это layout, не механизм авторизации.

Следующая поставка: серверная аутентификация и сессии, read-only collector/API, защищённый адрес на server1, затем фиксированные audit/job adapters существующих backup/restore scripts. Отдельные proxy/auth/network решения требуют фактического listener/network inventory. Прямого доступа к server1 у агента нет. Прототип не подключать к root/Docker socket и не публиковать как защищённую админку.

Manual/browser QA: desktop/mobile, navigation, backup filter and empty state, SSD/Moodle outage distinction, restore production/test target distinction, dialog keyboard close, disabled login inputs; exact results captured in the current handoff.

Validation performed15Oct: JS syntax PASS; jsdom DOM checks PASS for all views, filters/empty, SSD impact, restore target separation and disabled login fields. Dialog API modeled in jsdom. Native Playwright could not launch because Chromium was absent; browser download failed. Desktop/mobile screenshots and real browser visual/keyboard/layout acceptance remain PENDING; no screenshot success is claimed.

15Oct13:32MSK owner styling update: Linear-inspired dark graphite UI, compact navigation/tables, subtle borders and violet actions. All views/dialog/login styled; JS bytes unchanged. Prototype status and demo/no-live-backend boundaries retained.
