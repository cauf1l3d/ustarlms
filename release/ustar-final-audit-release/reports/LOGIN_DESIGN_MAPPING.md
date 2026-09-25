# USTAR Login — Canva → production mapping

Дата: 2026-08-23  
Canva evidence: design `DAHTBqJuKtA`, «Страница логина Устар»  
Статус: **design direction, не готовая спецификация**

## 1. Вывод

Canva-макет задаёт брендовый образ: USTAR, дружелюбный mascot, белое поле, контрастные иллюстрации и короткий эмоциональный текст. Он не содержит полноценной модели входа и не может быть перенесён «как есть». Connector определяет документ как **one-page whiteboard** и сообщает `is_responsive=false`.

Требование master task о специально выделенном месте `[ НАТИВНАЯ ФОРМА ВХОДА MOODLE ]` является обязательным, хотя literal-текст этого placeholder не возвращён Canva content API. Поэтому форма не перерисовывается вручную: сохраняется `core/loginform` со стандартными `username`, `password`, `logintoken`, errors, forgot-password, identity providers, guest/cookie controls по принятой конфигурации. Меняется только layout shell темы.

Production сейчас имеет два разных входа:

1. Moodle native login на `https://158-160-29-94.nip.io/login/index.php` — функциональный, но форма находится ниже слишком крупного hero; рядом конкурируют guest login и cookie banner.
2. Next frontend login на `https://academy.158-160-29-94.nip.io/login` — отдельная авторизация через получение Moodle token; это дублирует auth surface.

До визуального объединения необходимо принять архитектурное решение: один канонический login/session flow. Полировать два параллельных входа нельзя считать завершением задачи.

## 2. Mapping

| Canva element | Смысл | Production mapping | Gap | Решение для будущей фазы |
|---|---|---|---|---|
| USTAR wordmark | Идентичность продукта | Логотип/заголовок темы | В двух интерфейсах выглядит по-разному | Один brand component и asset source |
| Mascot / 3D illustration | Тепло, мотивация | Большой Moodle hero и отдельные декоративные элементы | Hero вытесняет форму ниже fold | Mascot вторичен; форма и status первичны |
| Белое пространство | Спокойная иерархия | Moodle hero + карточка; Next two-panel | Левая панель Next имеет низкий contrast | Единая сетка и WCAG contrast |
| Слоган/описание | Объяснение продукта | Дублирующиеся заголовки и copy | В Canva опечатка `MODLE`; «ХОЗМАГиЯ» не объяснена | Один проверенный текст без технического жаргона |
| Иллюстративные иконки | Навигация/эмоция | 29 локальных 3D PNG assets | Нет правил семантики, размера и состояния | Icon registry: name, meaning, context, alt text |
| Footer | Юридическая/служебная зона | Cookie/guest/recovery элементы | Конкурируют с primary CTA | Разделить legal, help и auth actions |

## 3. Геометрия Canva и правила переноса

Transaction API вернул рабочую композицию примерно `1590 × 904` условных единиц: от `x≈50` до `x≈1640`, от `y≈1361` до `y≈2264`. Это почти desktop 16:9, но не HTML viewport.

| Canva element | Absolute geometry | Нормализованный смысл | CSS mapping |
|---|---:|---|---|
| Левая основная shape | x 49.9, y 1427.6, w 903.6, h 769.4 | Основная login/brand зона, ≈57% ширины | `.u-login__primary`; max-width/grid column, не absolute px |
| `USTAR` | x 117.1, y 1450.7, w 754.0, h 189.6 | Доминирующий wordmark | `.u-login__brand`; responsive clamp typography/image |
| Главный tagline | x 187.4, y 1630.2, w 652.5, h 46.0 | Короткое обещание | `.u-login__tagline`; max 2–3 lines |
| Центральная иллюстрация | x 358.0, y 1680.7, w 280.1, h 336.4 | Mascot/эмоциональный anchor | `.u-login__mascot`; decorative/meaningful alt по asset |
| Правая большая image | x 887.1, y 1360.5, w 752.5, h 903.6 | Визуальная половина, ≈47% | `.u-login__visual`; hidden/reordered on small screens |
| Product copy справа | x 1037.4, y 2063.0, w 553.5, h 43.0 | Вторичное объяснение | `.u-login__description` |
| Footer | x 1063.9, y 2230.0, w 452.9, h 15 | Legal строка | `.u-login__legal` |

В Canva элементы частично перекрываются примерно на 66 единиц. В HTML это не должно превращаться в отрицательные margins вокруг формы: использовать grid `minmax(0, 1fr)` и controlled visual overlap только для декоративных assets.

### Цвет, шрифт, градиенты

Canva API не вернул надёжные font-family, font-size и color attributes. Поэтому точные hex/font из изображения не объявляются фактом. В thumbnail наблюдаются светлая/белая основа, графитовый текст и жёлтый USTAR accent. Ближайшие уже существующие implementation tokens:

| Purpose | Existing theme token | Value | Status |
|---|---|---:|---|
| Brand yellow | `--u-brand` | `#FBC502` | Кандидат; проверить визуально рядом с экспортом Canva |
| Strong accent | `--u-brand-strong` | `#EBC500` | Кандидат для CTA/focus |
| Primary ink | `--u-text-primary` | `#282727` | Кандидат |
| Canvas | `--u-canvas` | `#F6F5F1` | Кандидат |
| Surface | `--u-surface` | `#FFFFFF` | Кандидат |
| UI font | `--u-font-ui` | Inter/system | Уже принят в theme baseline, не извлечён из Canva |
| Display font | `--u-font-display` | Inter Tight/Inter | Уже принят baseline, не извлечён из Canva |

Свойства градиентов connector не вернул. Не копировать визуально предполагаемый gradient как точный; сначала получить export/brand token approval.

### Adaptivity mapping

- Desktop ≥1024: две зоны, форма полностью above the fold; visual занимает не более 45–48%.
- Tablet 768–1023: форма 55–60%, иллюстрация обрезается безопасно; legal остаётся читаемым.
- Mobile ≤767: одна колонка; native form первой, wordmark/короткий tagline над ней, mascot после form или скрыт как decorative.
- 320 px/200% zoom: никакого абсолютного позиционирования функциональных controls.
- `prefers-reduced-motion`: статичная иллюстрация, без обязательных entrance animations.

## 4. Canva → HTML/CSS → файл реализации

| Canva element / requirement | HTML/CSS component | Planned implementation file | Current evidence |
|---|---|---|---|
| Page shell | `body.pagelayout-login`, `.u-login` | `theme_ustar/layout/login.php` | Файла нет; `theme_ustar/config.php` не объявляет layout `login`, поэтому используется parent Boost |
| Shell template | `.u-login__primary`, `.u-login__visual`, slots | `theme_ustar/templates/login.mustache` | Файла нет |
| Native form slot | `{{{ output.main_content }}}` | `theme_ustar/templates/login.mustache` | Должен рендерить core output без копирования auth logic |
| Username/password/errors/recovery | `.loginform`, `#login`, `#username`, `#password`, `#loginerrormessage` | Moodle `core/loginform` — **не изменять** | Содержит logintoken, recovery, errors, autofocus, toggle password, submit guard |
| Login styles | `.u-login*` + narrow native selectors | `theme_ustar/scss/_login.scss` | Файла нет; добавить import в `theme_ustar/scss/main.scss` только после approval |
| Brand tokens | CSS custom properties | `theme_ustar/scss/_tokens.scss` | Tokens существуют |
| Logo/mascot | `<img>` / decorative background | `theme_ustar/pix/brand/` или Moodle File API | Текущие `logo-onlight.png`, `logo-ondark.png`; Canva assets не импортированы в code |
| Russian copy | Moodle strings | `theme_ustar/lang/ru/theme_ustar.php` | Добавлять как локализованные строки, не hardcode в template |
| Parent layout fallback | Theme layout map | `theme_ustar/config.php` | Добавить `login` layout pointing to `login.php` |
| Separate Next login | Не новый visual target | `frontend/src/app/login/page.tsx` | Сейчас кастомно получает Moodle token; после canonical Moodle login решения — redirect/decommission, не третий form |

Implementation rule: не редактировать Moodle core/Boost files. Точки расширения принадлежат `theme_ustar`; native `core/loginform` остаётся upstream-owned.

## 5. Чего нет в Canva, но обязательно для login

- Username/email и password поля с labels.
- Show/hide password.
- Forgot password.
- Ошибки: неверные данные, заблокирован, требует approval, сеть недоступна.
- Loading и защита от повторной отправки.
- Состояния prehire / pending HRD / disabled / deleted.
- Язык, клавиатура, focus, screen reader и autofill.
- Mobile 320–430 px, landscape, zoom 200%.
- Privacy/cookie объяснение.
- Support/contact и correlation ID для ошибки.
- Политика guest login: нужен ли он вообще.
- Session expiry и re-authentication.

## 6. Предлагаемая информационная иерархия

```text
USTAR logo
«Вход в USTAR Academy»
Коротко: обучение, развитие и рабочие допуски

[ Логин или рабочая почта ]
[ Пароль                     ] [показать]
[ Войти ]
Забыли пароль?

Status / error с понятным действием
Служба поддержки · Политика конфиденциальности

Mascot/illustration — рядом на desktop, после формы на mobile
```

## 7. Acceptance criteria

- Форма и кнопка входа полностью видимы при 1366×768 без прокрутки.
- Рендерится нативный Moodle `core/loginform`; POST, `logintoken`, errors и recovery сохраняются.
- Первый focus — логин; tab order логичен; Enter отправляет форму один раз.
- Контраст текста/controls соответствует WCAG AA.
- Ошибка не раскрывает существование конкретного аккаунта.
- Token отсутствует в URL, HTML и client-readable storage.
- Одна сессия используется Moodle и USTAR либо применён документированный SSO flow.
- Для pending approval показано, что доступ ограничен и кто должен действовать.
- Guest login скрыт, если не подтверждён как TARGET scenario.
- Icon/mascot не ухудшает LCP, CLS и доступность.

## 8. Контент-корректировки Canva

- Исправить `MODLE` на `Moodle` только если Moodle вообще должен называться пользователю; предпочтительно «платформа обучения».
- Объяснить или убрать «ХОЗМАГиЯ»: сейчас это выглядит как внутреннее слово без контекста.
- Не обещать функции, не закреплённые Конституцией и TARGET.

## 9. Приоритеты design feedback

1. **High** — макет не является responsive page и не содержит функциональных auth states. Fix: превратить композицию в responsive shell вокруг native Moodle form.
2. **High** — USTAR wordmark/иллюстрации доминируют над задачей входа. Fix: на реальном viewport форма и CTA должны быть первым functional focus.
3. **High** — текст `MODLE` содержит опечатку и открывает внутреннюю технологию без пользы пользователю. Fix: «платформа обучения» или корректное `Moodle`.
4. **Medium** — два длинных объясняющих абзаца находятся далеко друг от друга. Fix: оставить один короткий value proposition рядом с формой.
5. **Medium** — `ХОЗМАГиЯ` не объяснена. Fix: дать контекст или убрать из login.
6. **Low** — legal строка очень мала в масштабе композиции. Fix: не менее 12–14 CSS px и достаточный contrast.

Что работает: чёткая брендовая доминанта, запоминающийся mascot, сильное разделение светлой основы и графитово-жёлтых акцентов.

## 10. Ограничение аудита

Canva изучен read-only; transaction `4065012194050340900` отменён, изменения не сохранялись. Production не изменялся.

## 11. Isolated implementation result — 2026-08-23

Реализация выполнена только в isolated theme copy:

| Layer | File | Result |
|---|---|---|
| Moodle layout | `theme_ustar/layout/login.php` | Unchanged; native `output.main_content` retained |
| Moodle template | `theme_ustar/templates/login.mustache` | Unchanged; native form slot retained |
| SCSS registration | `theme_ustar/lib.php` | `_login.scss` appended as the final visual layer |
| Login polish | `theme_ustar/scss/_login.scss` | New isolated/test file |

Проверено: desktop grid больше не перекрывается Moodle `#page` flex rule; native username/password/error/recovery/policy flows работают; password toggle видим и переключает тип поля; 1440×900, 768×1024 и 390×844 не имеют horizontal overflow. Production theme не изменён.
