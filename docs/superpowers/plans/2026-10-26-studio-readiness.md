# Studio readiness implementation plan

> **For agentic workers:** Use superpowers:executing-plans for inline execution.

**Goal:** исправить подготовку материала, улучшить интерфейс и добавить текстовый импорт/печатный экспорт PDF.
**Architecture:** существующий Moodle API; отдельный клиентский pdf.js adapter, локальная закреплённая библиотека; общая модель курса неизменна.
**Tech Stack:** PHP8.2/8.3, plain JS, PDF.js, HTML/CSS, Moodle5.1.
**Spec:** docs/superpowers/specs/2026-10-26-studio-readiness-design.md

## Global constraints
- Не изменять production/core, роли и существующую историю попыток.
- PDF25МБ/200страниц/2млн символов; отдельный проект после предпросмотра.
- Без внешних сервисов и новых серверных зависимостей.

## Review focus
- Смешанные PDF: пустые страницы сохраняют нумерацию, полный скан отклоняется.
- Враждебный текст: только текст, HTML не исполняется.
- Печать длинного курса и кириллицы: без обрезания и панелей редактора.
- Узкий экран/клавиатура: действия доступны, нет перекрытия панелей.
- SCORM HTTP отличается от PHPUnit: настоящая сессия, PAGE и повторная подготовка.

## Tasks
- [ ] API: получить exception class/code/location из CI; воспроизвести дефект в controller/HTTP тесте, исправить root cause, повторить обе Moodle DB матрицы.
- [x] PDF: tests/release_20260912/course_studio_pdf.cjs проверяет page-to-text, escaping, лимиты и print HTML; затем course_studio/pdf.js с importFile(file), textLines(items), printHTML(course,assetURL). Реальный PDF browser test проверяет кириллицу/пустые/повреждённые файлы и экспорт.
- [x] UI: editor.html/style.css/app.js — академическая оболочка, PDF dialog, проверка готовности, статистика и focus mode. DOM тесты проверяют переходы/ошибки/сохранение; browser desktop/mobile screenshots и PDF render.
- [ ] Проверки: npm test --prefix tests/release_20260912, PHP regressions, scripts/ci/source_checks.py, контекст. Независимое ревью, исправления, push и полный CI.
- [ ] Поставка: обновить COURSE_STUDIO_RU/STATE/ACTIVE/BACKLOG/SESSION_LOG, построить inventory от фактического application commit, PR107 с инструкцией внедрения и границами приёмки.
