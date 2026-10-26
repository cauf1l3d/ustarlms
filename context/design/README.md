# Дизайн Академии: вход в исходники

Источник действующих значений — [SCSS tokens](../../moodle/theme/ustar/scss/_tokens.scss), а не новый независимый набор в документации. [tokens.yaml](tokens.yaml) генерируется командой `python3 scripts/build_agent_inventory.py`; включает светлые/тёмные токены, SHA исходника и порядок слоёв из [theme lib](../../moodle/theme/ustar/lib.php).

- Шрифты: `--u-font-ui`, `--u-font-display`, `--u-font-mono`; точные значения в сгенерированном файле.
- Цвета, интервалы и радиусы: группы `--u-brand*`, `--u-text*`, `--u-s-*`, `--u-r-*`. Поздние слои темы и CSS страницы могут переопределять вычисленный стиль.
- Фокус и reduced-motion: [foundation](../../moodle/theme/ustar/scss/_foundation.scss). Видимый focus ring использует брендовый цвет; скрытые элементы не участвуют в взаимодействии.
- Кнопки/навигация/поверхности: [shell](../../moodle/theme/ustar/scss/_shell.scss), [fable shell](../../moodle/theme/ustar/scss/_fable_shell.scss), [polish](../../moodle/theme/ustar/scss/_polish.scss). Вся цепочка слоёв указана в tokens.yaml; Bootstrap/Boost — родительская база.
- Стили конкретных сценариев: [styles](../../moodle/local/ustar/styles), Mustache в [templates](../../moodle/local/ustar/templates). Изменять исходники целевого компонента и проверять итоговый рендер, а не только значение токена.
- Мобильные состояния: [mobile](../../moodle/theme/ustar/scss/_mobile.scss), [domain map](../domains/mobile_web.yaml). Приватные материалы не становятся offline-кэшем из-за установки PWA.

Пульт — отдельный продукт в [PR103](https://github.com/cauf1l3d/ustarlms/pull/103). Его графитовая палитра, типографика16px и графики не подменяют действующие токены Академии. Состояние «исходники подготовлены» не равно native установке.

Полная библиотека компонентов, таблица вычисленных стилей всех страниц и мобильная приёмка всей Академии ещё не завершены. Этот документ закрывает навигацию к существующему стилю и предотвращает выдумывание новой палитры по описанию.
