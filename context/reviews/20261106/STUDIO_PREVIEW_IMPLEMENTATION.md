# Ресурсы предпросмотра Студии —06.11.2026

STUDIO-PREVIEW-01. App2cb233d994c0e4588973cfce0577c9d466027ce2, parent baseline901ac9eb.
Owner подтвердил скрытие точек и сообщил невидимые изображения после Preview. Скриншот показывает Not allowed to load local resource blob:https://ustar.local/…. Это частичная функциональная приёмка, не подтверждение точного installed SHA; последняя полная terminal receipt719 сохраняется.

## Причина и граница исправления

app.js preview() создавал URLs в родителе и передавал их в sandbox=allow-scripts srcdoc. Opaque iframe не может загружать parent-owned blob URLs. Настоящий valid PNG: editor naturalWidth1, preview0, точная console error; загруженный TTF тоже блокировался. Регрессия воспроизведена до изменения продукта.

Новая previewResources() выполняется внутри iframe, декодирует ресурсы и создаёт URLs в его документе. CSS через textContent, COURSE через JSON.parse; payload сериализован с escaping <. Callback replacements сохраняют $&/$`/$' и literal closing-script текст. Все assets передаются по одному разу;24повтора PNG64KiB увеличили srcdoc только на2544символа. Браузер освобождает document-owned URLs при смене srcdoc; parent editor leases не затрагиваются. Старый недоступный parent onload удалён, фактический запуск с первого экрана сохранён.

course_studio.php cache identity включает hash app.js+model.js. Установщик сохраняет timestamps, поэтому нельзя полагаться на mtime нового JS. Сами parent/preview CSP, allow-scripts sandbox, server API/model/export/PDF остаются прежними.2source files,0added; local/theme0200,653files/108tables.

## Проверки до публикации

Локальный Chromium151 реальный upload PNG+DejaVu TTF, decode в editor/preview, fonts.loaded, reopening, repeated resource budget, literal lesson, parent SecurityError, PDF/Word Cyrillic roundtrip и desktop/mobile PASS. Обычный и delayed resize варианты PASS. Исходный data-URL вариант отброшен после size RED; не поставляется. Literal replacement RED (два aside) исправлен callback replacements. Npm regression suite PASS с существующим PHP runtime. JS/PHP syntax и diff whitespace PASS.

Native Moodle5.1.1/5.1.8 и CI ещё выполняются; не считать пакет квалифицированным до receipt. Operator baseline901 требует private active CSP journal и точный полный source manifest до outage; rollback только source, без DB restore. Старые f4d/45e/aa61 и frozen mechanics неизменны. Локальные16operator tests PASS с1conditional UID33 skip; actual UID33 обязателен в dedicated sudo CI.

Работа выполнена inline без субагентов, SSH, production mutations, merge и provider sends. Последующая owner проверка картинки/шрифта в своём курсе обязательна для фактической приёмки.
