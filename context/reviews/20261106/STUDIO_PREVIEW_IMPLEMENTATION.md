# Ресурсы предпросмотра Студии —06.11.2026

STUDIO-PREVIEW-01. App2cb233d994c0e4588973cfce0577c9d466027ce2, parent baseline901ac9eb.
Owner подтвердил скрытие точек и сообщил невидимые изображения после Preview. Скриншот показывает Not allowed to load local resource blob:https://ustar.local/…. Это частичная функциональная приёмка, не подтверждение точного installed SHA; последняя полная terminal receipt719 сохраняется.

## Причина и граница исправления

app.js preview() создавал URLs в родителе и передавал их в sandbox=allow-scripts srcdoc. Opaque iframe не может загружать parent-owned blob URLs. Настоящий valid PNG: editor naturalWidth1, preview0, точная console error; загруженный TTF тоже блокировался. Регрессия воспроизведена до изменения продукта.

Новая previewResources() выполняется внутри iframe, декодирует ресурсы и создаёт URLs в его документе. CSS через textContent, COURSE через JSON.parse; payload сериализован с escaping <. Callback replacements сохраняют $&/$`/$' и literal closing-script текст. Все assets передаются по одному разу;24повтора PNG64KiB увеличили srcdoc только на2544символа. Браузер освобождает document-owned URLs при смене srcdoc; parent editor leases не затрагиваются. Старый недоступный parent onload удалён, фактический запуск с первого экрана сохранён.

course_studio.php cache identity включает hash app.js+model.js. Установщик сохраняет timestamps, поэтому нельзя полагаться на mtime нового JS. Сами parent/preview CSP, allow-scripts sandbox, server API/model/export/PDF остаются прежними.2source files,0added; local/theme0200,653files/108tables.

## Проверки до публикации

Локальный Chromium151 реальный upload PNG+DejaVu TTF, decode в editor/preview, fonts.loaded, reopening, repeated resource budget, literal lesson, parent SecurityError, PDF/Word Cyrillic roundtrip и desktop/mobile PASS. Обычный и delayed resize варианты PASS. Исходный data-URL вариант отброшен после size RED; не поставляется. Literal replacement RED (два aside) исправлен callback replacements. Npm regression suite PASS с существующим PHP runtime. JS/PHP syntax и diff whitespace PASS.

Квалификация exacted292313 завершена:4CI/11FullRC PASS. Native Moodle5.1.1/5.1.8 —430tests/1988assertions каждый; Connect172/875. Обе source PHP8.2/8.3 —1649tests/274conditional skips; настоящий API/PNG/font/fresh mobile reload/eval/parent denial PASS в обоих браузерах. Долгая установка Chromium dependencies в8.3 прошла с первой попытки в существующем25min бюджете, без изменения проверок. Operator baseline901 требует private active CSP journal и точный полный source manifest до outage; rollback только source, без DB restore. Старые f4d/45e/aa61 и frozen mechanics неизменны. Operator16actualPHP/UID33 tests PASS в dedicated CI; локально15PASS/1conditional skip. Локальный широкий r16 не является qualification PASS:13fail из-за отсутствия PDO в малом PHP runtime,3errors из-за отсутствия Python mcp; оба CI с объявленными dependencies прошли полностью, без ослабления тестов.

Работа выполнена inline без субагентов, SSH, production mutations, merge и provider sends. Последующая owner проверка картинки/шрифта в своём курсе обязательна для фактической приёмки.

Архив ustar-studio-preview-ed292313.tar.gz,136074bytes, SHA256 cf8c9b0b0f5fd6217cda3ed699fa8ea8f256e28c58764d7acb7fd87b7e1e5fb7. Детерминированная сборка, CIartifact13499364633 и сохранённая скачанная копия побайтно совпали. [Квитанция](../../runtime/studio_preview_qualification_20261106.json), [команды/приёмка](../../../docs/STUDIO_PREVIEW_INSTALL_RU.md). Квалификация не deployment receipt; exact901 predecessor проверяет installer до outage.
