# USTAR — UX/UI audit

Дата: 2026-08-23  
Scope: Moodle theme, отдельный USTAR frontend, Architecture Studio, Canva login direction.

## 1. Итог

Система содержит несколько качественных визуальных наработок, но пользователь воспринимает не один продукт, а минимум три: Moodle, Next workspace и Architecture Studio. Главный UX-долг — не цвет/иконки, а несовпадающие понятия, навигация, доступы и состояния.

## 2. Системные проблемы

| Problem | Impact | Priority |
|---|---|---|
| Два login flow | Разные сессии, поведение и риски | P0 architecture |
| Role ≠ Position ≠ interface | Пользователь не понимает, почему видит функцию | P1 |
| Course/enrolment и route/progress параллельны | Дублируются назначения и прогресс | P1 |
| Нет manager hierarchy | Manager/HR/CEO screens не могут быть достоверны | P1 |
| Технические коды видны как первичный язык | Интерфейс требует знания Moodle/БД | P1 |
| Неконсистентные hero/panels/cards | Слабая иерархия и много декоративного шума | P2 |
| Нет унифицированных empty/error/loading states | Пользователь не знает, что делать | P1 |
| Role-based disclosure не доказан | Privacy и access uncertainty | P0/P1 |

## 3. Запрошенная пользовательская оценка

### Что удобно

- В current Moodle shell есть короткая основная навигация «Главная / Обучение / Знания / Развитие».
- На Home реализована кнопка/ссылка «Продолжить» к следующей активности и несколько содержательных empty states.
- Knowledge уже имеет карточки, type/category filters, query parameter и отдельный блок материалов, требующих ознакомления.
- Нативный Moodle login сохраняет recovery, errors, CSRF-like `logintoken`, autofocus, password toggle и submit guard.
- Architecture Studio объясняет сущности в бизнесовом порядке и хранит revisions/backups.

### Что ломает сценарий

- Live search dialog уже работает внутри shell, но TARGET sources, ACL, relevance и privacy contract не утверждены; UI-наличие не делает search governance завершённым.
- «Команда» не имеет достоверной manager relation: API группирует через текущие department/role assumptions, reporting table пуста.
- Два login/session flows создают разные точки отказа.
- Route, enrolment, course progress и evidence не образуют одну понятную цепочку.
- Role access и data scope невозможно объяснить пользователю из должности/ответственности.

### Что выглядит дешево

- Чрезмерно крупные hero/brand blocks, из-за которых полезное действие уходит ниже fold.
- Декоративные баннеры/mascots без связи с текущей задачей.
- Смешение русских названий и вторичных англоязычных labels (`Learning`, `Knowledge Market`, `Skills · Career`) без системной причины.
- Разные визуальные языки Moodle theme, Next workspace и Studio.
- Глобальные technical codes и legacy/Moodle понятия в пользовательском контексте.

### Что требует полировки

- Контраст вторичного текста, focus/hover/disabled states.
- Единые loading, no data, no access, not configured, stale и error states.
- Реальная responsive проверка таблиц, оргструктуры, маршрута и activity runtime.
- Тексты, pluralisation, локализация дат и объяснение источника/freshness.
- Иконки: разделить UI-action line icons и крупные 3D illustrations.

### Что мешает вау-эффекту

- Пользователь не видит единую причинно-следственную историю «моя должность → требования → маршрут → evidence → допуск → развитие».
- Руководитель не может доверять экрану команды без canonical hierarchy.
- CEO не может доверять показателям без source/freshness/drill-down policy.
- Награды и leaderboard выглядят декоративно, пока нет утверждённых сезонов, правил честности и приватности.
- Красивый UI не компенсирует дублирующую auth/learning архитектуру.

## 4. Moodle native login

Наблюдения:

- hero слишком высокий, поэтому основная форма может оказаться ниже первого экрана;
- заголовки повторяются и конкурируют с задачей входа;
- guest login и cookie panel забирают внимание у primary action;
- интерфейс сообщает Moodle-термины, а не задачу сотрудника;
- positive: стандартные username/password/recovery механики уже устойчивее кастомного token login.

Recommendation: сохранить зрелую серверную auth-механику или заменить её формально принятым SSO, а visual shell привести к Canva direction. Не создавать третий auth flow.

## 5. Next workspace login и оболочка

Наблюдения:

- левая текстовая панель имеет недостаточный contrast;
- баннер, mascot и декоративные элементы конкурируют с рабочей задачей;
- login напрямую получает Moodle token, хранит его внутри подписанной, но не зашифрованной cookie;
- URL скачивания содержит token query;
- отсутствует middleware, доступ защищается на API/cookie уровне;
- navigation и workspace routes шире, чем доказанные role contracts.

Recommendation: сначала session/auth hardening и role contract, затем визуальная унификация.

## 6. Architecture Studio

Сильные стороны:

- бизнесовый порядок интервью вместо порядка таблиц;
- current/probem/target/action/rationale/dependencies;
- autosave, version history, backup/restore, import/export и generation;
- conflict center, source map и final review как правильная концепция.

Разрывы:

- «resolved» допускает текст «нужно определиться»;
- 4 overrides undecided, но 30/30 верхнеуровневых решений выглядят завершёнными;
- Final Review не подтверждён;
- generated TARGET имеет 0 roles;
- owner/sourceOfTruth пусты;
- нет links B001–B109;
- нет first-class KPI, mentor, notification/task, staff place/vacancy, seasons;
- provenance указывает на файлы, но не даёт пользователю компактного evidence drill-down.

UX requirement: прогресс должен считаться не по заполненности формы, а по валидности и закрытым зависимостям.

## 7. Статус ранее запланированных изменений

| План | CURRENT evidence | Статус | Следующий gate |
|---|---|---|---|
| Убрать старый красный баннер | Authenticated employee/manager/HR/CEO shell проверен; старого красного баннера нет | Реализовано в current Moodle shell | Не переносить legacy banner обратно при cutover |
| Чистый USTAR brand | `theme_ustar` tokens/logo и единый light/dark shell проверены; Next/Studio остаются отдельными visual systems | Частично | Свести Moodle/Next/Studio в один system после TARGET cutover |
| Масштаб центрального блока | Authenticated cards проверены на desktop/mobile browser viewports; horizontal overflow отсутствует | Частично | Добавить formal 1366×768/320 px visual baseline |
| Исправить «Продолжить» | `home.php` вычисляет next activity; template содержит CTA | Реализовано статически | E2E: правильный route step, no dead link |
| Заполнить пустоты/заглушки | `u-empty-state` есть для learning/position/evidence | Частично | Проверить все empty/no access/error classes |
| Живой search dropdown | Topbar button opens in-page dialog; two-character live result, focus return and Escape close проверены | Реализовано технически | Утвердить TARGET sources/ACL и relevance contract; не возвращать отдельную search page |
| Раздел «Команда» | Employee/manager/HR/CEO entry points PASS; employee видит peers, но reporting hierarchy=0 и manager chain отсутствует | Конфликт | Canonical manager relation; затем CEO→director→manager→me journey |
| Материалы как marketplace | Knowledge search, type/category chips, cards и empty state проверены; synthetic employee получает 0 доступных материалов | Частично | Subcategories, ownership/access fixtures и непустой role E2E |
| Игры всем ролям | Common `local/ustar:use` access, employee/manager browser smoke; из 2 active games одна имела 0 active questions | Частично | Test every approved TARGET role/pending state; editor publication validation |
| DGMJS/runtime | Реальная игра открыта, изображение и answer flow проверены; absolute-host media bug исправлен only in isolated | Isolated PASS | Production approval; content/editorial QA |
| Сохранение game progress | Wrong + correct attempts, mastery, XP and USCOIN ledger verified; unique mastery/ledger indexes present | Isolated PASS / business conflict | Concurrency/load test; approve or remove XP→USCOIN TARGET rule |
| USCOIN balance/journal/store | First mastery posted one idempotent +5 ledger event; balance screen exists | Частично | Separate economy policy, store, reversal/audit and abuse E2E |
| Leaderboard seasons/fairness/teams | Global ranking; month filter; names/positions disclosed | Не соответствует TARGET | Season/league/privacy/ranking policy |
| Theme colors/presets для пользователей | Light/dark toggle and Academy cards проверены; selected theme persists across pages | Частично | Preset access policy, contrast matrix and preference ownership |

## 8. Набор иконок

Фактическая папка называется `C:\Users\User\Desktop\release\иконки академия` и содержит 29 PNG. В master task использовано другое имя `академия иконки`; фактический путь зафиксирован, файлы не перемещались.

| Группа | Файлы | Рекомендуемые места | Ограничение |
|---|---|---|---|
| Notifications/tasks | bell, calender, chat-bubble, tick | Пустые состояния уведомлений/задач, крупные cards | Не заменять компактные toolbar icons PNG-картинками |
| Learning/content | bookmark, file-new, folder-fav, notebook, link, bulb | Материалы, библиотека, learning empty states | Проверить семантику и alt text |
| Growth/mastery | puzzle, target, rocket, star, medal, trophy, crown | Навыки, маршрут, достижения, leaderboard | Не использовать reward icon для gate/admission |
| Economy | credit-card, gift, gift-box, eth | USCOIN/store/reward | `eth` не использовать как USCOIN без явного business approval |
| Preference/admin | sun, moon, setting, tools | Theme/settings illustration | Для кнопок оставить line icons |
| Social/feedback | heart, thumb-up, hash, next | Feedback/community/onboarding | `next` может иллюстрировать шаг, но CTA должен иметь text label |

До изменения необходимо создать asset manifest: исходный filename, license/provenance, semantic name, intended surfaces, alt/decorative flag, rendered sizes, WebP/AVIF derivatives и checksum. 3D PNG — emotional illustration layer; navigation/actions остаются simple vector icons для читаемости и размера.

### Isolated implementation result — 2026-08-23

Создан строгий semantic registry из 12 отобранных PNG и отдельный SCSS layer. 3D assets используются только как декоративные feature/card illustrations; left rail, mobile bottom navigation, topbar, search, profile, message, arrow и остальные action glyphs остаются inline SVG. USCOIN не сопоставлен с `eth` или payment imagery без TARGET-решения.

Фактически проверены Achievements, Games, Knowledge и Profile в isolated environment. Все видимые и lazy-loaded изображения загрузились (`naturalWidth=500`), горизонтального overflow нет. В тёмной теме cards и illustrations сохраняют читаемость. На проверенных экранах navigation содержит 14 SVG и 0 raster Academy icons. Полная карта и production gates находятся в `ICON_DESIGN_MAPPING.md`; license/provenance и оптимизация веса остаются обязательными до production.

## 9. Design system direction

| Token | Direction |
|---|---|
| Primary | Один утверждённый USTAR blue, не несколько близких оттенков |
| Accent | Тёплый highlight для reward/mascot, не для critical actions |
| Success/warning/error | Семантические цвета + icon + текст, не только цвет |
| Typography | Один UI sans; mono только для кодов/provenance |
| Radius/shadow | Два уровня, без «карточки внутри карточки» |
| Spacing | 4/8 px scale |
| Icons | 29 3D assets только для крупных эмоциональных состояний; UI actions — простые line icons |
| Technical labels | Вторичный mono-текст; человеческое название — primary |

## 10. Core role navigation hypothesis

Не утверждать до role testing.

| Role | Primary jobs | Не показывать по умолчанию |
|---|---|---|
| Сотрудник | Сегодня, маршрут, допуски, цели, развитие, награды | Admin/Moodle settings |
| Новый сотрудник | Статус approval, адаптация, первый маршрут, наставник | Соревнования до допуска, если не разрешено |
| Руководитель | Команда, риски/просрочки, допуски, feedback, цели | HR-wide data |
| HR | Люди/назначения, адаптация, контент/маршруты по полномочиям | HRD-only access control |
| HRD | Оргмодель, access governance, lifecycle, reports | Superadmin technical settings |
| CEO | Executive indicators, exceptions, decisions | Персональные learning details без необходимости |
| Superadmin | Health, integrations, audit, configuration | Бизнес-решения вместо владельцев |

## 11. Accessibility and responsiveness checklist

- [ ] Keyboard-only completion of every primary scenario.
- [ ] Visible focus and skip link.
- [ ] Labels and accessible names for all controls.
- [ ] Contrast AA for normal/disabled/error states.
- [ ] Zoom 200% without loss of action.
- [ ] 320 px mobile without horizontal scroll.
- [ ] Tables convert to cards or scroll with context retained.
- [ ] Charts have text alternative.
- [ ] 3D icons have meaningful alt or are decorative.
- [ ] Russian pluralisation and date/timezones consistent.
- [ ] Empty states distinguish «нет данных», «нет доступа», «не настроено», «ошибка».

## 12. Performance/quality budgets for change phase

- LCP ≤ 2.5 s p75 on representative mobile profile.
- CLS ≤ 0.1; INP ≤ 200 ms p75.
- No token/PII in URLs or client logs.
- No unhandled console errors.
- Every API error shows a recoverable user action and correlation ID.
- Visual regression at 360×800, 768×1024, 1366×768, 1920×1080.

## 13. UX release blockers

1. Canonical login/session not chosen.
2. Role contracts and manager hierarchy not established.
3. Pending/disabled/prehire states not defined.
4. Route/enrolment and completion/evidence semantics unresolved.
5. Privacy scope for leaderboards, boards and executive screens unresolved.
6. Synthetic authenticated role scenarios проверены, но реальные production identities и полная business-data correctness не использовались и не доказаны.

## 14. Login checklist audit after isolated polish — 2026-08-23

Проверена реально отрисованная isolated page `http://127.0.0.1:18080/login/index.php` по [Login checklist (Web app)](https://www.checklist.design/web-app/login). Moodle-native `core/loginform` сохранён.

| | Item | Why |
|---|---|---|
| 🟡 | **Email and password fields — The two standard authentication inputs: an email address field and a password field.** | Password есть; первый field — корпоративный Moodle username, а не email. Это допустимо только если TARGET сохраняет username identity. После polish оба поля одинаковой ширины. |
| 🟢 | **Show/hide password toggle — A button alongside the password field that reveals or conceals what the user has typed** | Нативная кнопка Moodle видима на desktop/tablet/mobile; проверено `password → text → password`. |
| 🟢 | **Forgot password — A link that begins the password reset flow for users who cannot remember their credentials** | Ссылка «Забыли пароль?» присутствует и ведёт в native Moodle recovery. |
| 🔴 | **Remember me — A checkbox that persists the user's session across browser closures** | На странице отсутствует. До добавления требуется security/session decision для корпоративных и общих рабочих устройств. |
| ⚪ | **SSO or social login — Alternative authentication via a third-party identity provider (Google, Microsoft, GitHub) that bypasses the email/password form.** | CURRENT IdP не подтверждён; не следует добавлять social login без TARGET identity decision. |
| ⚪ | **Sign up link — A link to the account creation screen for users who do not yet have an account** | Корпоративные accounts должны создаваться управляемым HR/admin lifecycle, self-signup не требуется. |
| 🟢 | **Error messages — Feedback shown when authentication fails, indicating what the user should try next** | Неверная synthetic пара показывает generic alert «Неверный логин или пароль» без раскрытия существования account. |

Дополнительные фактические исправления в test theme:

- Moodle `#page` flex specificity больше не ломает двухколоночную desktop grid;
- username/password/submit имеют согласованную ширину;
- desktop 1440×900: no horizontal/vertical overflow;
- tablet 768×1024: one-column responsive layout, no horizontal overflow;
- mobile 390×844: no horizontal overflow, hero compact, footer noise hidden;
- focusable native form controls и skip link сохранены;
- policy consent flow и forgot-password route не заменялись.

Нерешённое CURRENT-содержание: mandatory policy содержит кадровые/дисциплинарные и KPI-утверждения. Оно зафиксировано как CURRENT / TEST IMPLEMENTATION и требует отдельного business/legal approval; UI-аудит не делает его TARGET-политикой.

## 15. Checklist Design — дополнительные контрольные области

Проверка выполнена по сохранённым isolated browser evidence, исходникам release candidate и фактическим отчётам E2E. Новый вход в production во время checklist review не выполнялся.

### Searchbar

Проверено по [Searchbar checklist (Design system)](https://www.checklist.design/design-system/searchbar).

| | Item | Why |
|---|---|---|
| 🟢 | **Input field — A clear container for a user to start typing in** | Topbar открывает in-page search dialog с явным текстовым полем; ввод фактически проверен. |
| 🟢 | **Label or placeholder text — Identify the purpose of the field is for them to search** | Поле объясняет назначение поиска, а dialog не выглядит общим command input. |
| 🟢 | **Quick links, autocomplete and suggestions — As the user is typing, offer available links and phrases based on what they have entered so far** | После двух символов появляются быстрые результаты в текущем dialog. |
| ⚪ | **Submit search button — A visible link to submit search and view results** | Принятый паттерн — live results без перехода на отдельную страницу; отдельная submit-кнопка дублировала бы тот же результат. |
| ⚪ | **Previous searches — Showing what a user has searched before can speed up their experience if they frequently search the same queries** | История не входит в CURRENT или согласованный TARGET и создала бы отдельный privacy/storage contract. |
| 🟡 | **Appropriate visibility — Search should be directly linked to what you are looking for, whether it's searching across the entire platform or in a specific area** | Search доступен глобально и заметен, но TARGET sources, ACL, relevance и privacy scope ещё не утверждены. |

### Empty State

Проверено по [Empty State checklist (Web app)](https://www.checklist.design/web-app/empty-state).

| | Item | Why |
|---|---|---|
| 🟢 | **Illustration or icon — A visual that signals the empty state and gives the screen some personality, rather than feeling broken** | Learning, position, evidence и games используют контекстный `u-empty-state`, включая Academy illustration там, где она уместна. |
| 🟢 | **Clear heading — A short, plain-language title naming what's missing** | Например, learner catalog сообщает «Игры ещё не опубликованы», а не показывает пустую оболочку `0 / 0`. |
| 🟢 | **Supporting description — A brief explanation of what belongs in this space, most useful for first-time users** | Games state объясняет, что задания появятся после публикации администратором USTAR. |
| 🟡 | **Primary action — A CTA pointing toward the next step: creating, importing, connecting etc** | Для learner states действие часто зависит от другого владельца; ссылка/ответственный actor присутствуют не во всех состояниях. |
| 🟡 | **Zero state vs. no-results state — A distinction between a screen that is empty because nothing has been created versus one that returned no search or filter results** | Нулевые catalog states различаются, но единое правило reset/broaden для всех filtered no-results не доказано. |
| 🔴 | **Error state variant — A separate variant for when content failed to load, as opposed to genuinely being empty** | Унифицированный load-failure variant не подтверждён; без него ошибка API может выглядеть как отсутствие данных. |

### Icon

Проверено по [Icon checklist (Design system)](https://www.checklist.design/design-system/icon).

| | Item | Why |
|---|---|---|
| 🟢 | **Responsiveness — The flexibility in detail of the icons at varying sizes** | 3D PNG используются только в крупных feature/card slots; компактные navigation/actions сохранены как простые SVG. |
| 🟡 | **Visual style consistency — All icons share the same stroke weight, corner radius, and optical sizing approach** | 12 illustrations взяты из одного 3D-набора, но полный набор 29 assets ещё не имеет утверждённых optical-size rules и оптимизированных derivatives. |
| 🟢 | **Color — Black and white, flat colors or gradients** | Цветные 3D illustrations отведены эмоциональному слою, а функциональные glyphs наследуют контрастный theme color. |
| 🟢 | **Naming — Name an icon by what it literally is so it can be used flexibly** | Semantic registry отделяет исходное имя файла от surface/meaning и не приравнивает `eth` к USCOIN. |

### Accessibility

Проверено по [Accessibility checklist (Design system)](https://www.checklist.design/design-system/accessibility).

| | Item | Why |
|---|---|---|
| 🔴 | **Target conformance level — The WCAG conformance target the team has committed to documented and referenced in contribution guidelines. AA as a baseline for most products, with AAA achievable for specific criteria such as text contrast.** | WCAG AA указан как release direction, но формального design-system commitment/contribution contract нет. |
| 🟡 | **Colour contrast standards — The contrast ratios verified across all text and interactive element colour combinations (4.5:1 for normal text, 3:1 for large text and UI components)** | Light/dark readability визуально проверена, но вычисленная contrast matrix для normal/disabled/error states отсутствует. |
| 🟡 | **Focus indicator design — A visible, high-contrast focus indicator designed for every interactive component** | Focus и возврат focus после search dialog проверены на ключевых элементах; полное покрытие всех компонентов не доказано. |
| 🟡 | **Keyboard navigation patterns — Standard keyboard interaction patterns documented and applied consistently e.g. arrow keys for menus and listboxes, Enter and Space for activation, Escape for dismissal** | Escape dismissal и login tab flow проверены, но keyboard-only проход каждого primary journey не выполнен. |
| 🟡 | **ARIA pattern library — Attributes that make web content accessible to those who use assistive technologies with roles, states, and properties defined for every interactive component pattern** | В исходниках есть `aria-hidden` для decorative glyphs и native Moodle semantics, но общей библиотеки ARIA patterns нет. |
| ❔ | **Screen reader testing — Components tested with at least VoiceOver on Safari and NVDA on Chrome before shipping** | Тесты NVDA/VoiceOver не выполнялись; отдельный assistive-technology pass закроет этот пункт. |
| 🔴 | **Accessibility annotations in design — A shared annotation kit used in design files to specify ARIA labels, roles, reading order, and focus behaviour** | Canva-макет не содержит подтверждённого annotation kit и не является responsive specification. |
| 🔴 | **Accessibility in contribution guidelines — Ensuring accessibility requirements are part of the component contribution checklist** | В локальном control-repo не найден обязательный contribution checklist для accessibility. |

Главный вывод Checklist Design: isolated polish устраняет конкретные login/grid/icon/game-empty дефекты, но production release нельзя считать визуально завершённым до формальной accessibility baseline, error-state system, asset licence и полных business journeys.
