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
| Leaderboard seasons/fairness/teams | Isolated: 90 participants; employee payload exposes 89 others/29 positions/coin fields; 87 tied people get distinct ranks; team card can show global `#10` instead of local `#2`; reporting and season tables empty | Не соответствует TARGET | Separate score; season/rule version; comparable audience; privacy fields; tie/team/newcomer/transfer/close policy |
| Boards collaboration | 7 current boards are private; page says “Мои и командные”, but no share/read-only audience/delete/rename/history controls. Production CURRENT loses 23/24 concurrent writes; isolated containment now returns 23 conflicts correctly | Production FAIL / isolated invariant PASS / TARGET undecided | Conflict UX; board type/owner/audience/editor; version recovery; archive/transfer/quota/import policy |
| Notifications / Messages / Tasks | Notification list/read states/actions/empty state and Moodle chat shell exist. Labels are raw component/event codes; no severity/deadline/ack; no USTAR notifications or task entities; chat lacks receipts/attachments/reactions | CURRENT utility / B086–B091 absent | Separate official/personal task UX; human notification types and required action; delivery/escalation state; decide whether Moodle chat belongs in USTAR |
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

## 16. Materials / Personal Library — source audit после owner supplement

Проверены `materials.mustache`, `knowledge.mustache`, `materials.js`, `_materials.scss`, server-rendered POST/notification flow и authenticated synthetic rendering в loopback isolated Moodle. Desktop и 390×844 screenshots подтверждают визуальный размер, контекстное меню, breadcrumb, empty state и Personal Library before/after.

### Breadcrumb

Проверено по [Breadcrumb checklist (Design system)](https://www.checklist.design/design-system/breadcrumb).

| | Item | Why |
|---|---|---|
| 🟢 | **Current location — The current page shown as the final item in the trail, visually distinct from the preceding levels, typically not a link** | Текущая папка теперь выводится как `span[aria-current=page]`, а не как повторная ссылка; для неё есть отдельный visual state. |
| 🟢 | **Level links — All preceding levels rendered as active links, each one navigating directly to that level rather than requiring back-button presses** | Корень и все предыдущие папки остаются прямыми ссылками; отдельная кнопка «На уровень выше» сохраняет привычный Explorer-путь. |
| 🟢 | **Separator character — A consistent visual separator between levels (a slash, chevron, or arrow) distinguishing hierarchy from a list of links** | Между элементами CSS добавляет единый chevron `›`. |
| 🟡 | **Truncation for long paths — Deep hierarchies collapsed with an ellipsis, preserving the root and current page while hiding intermediate levels behind a toggle** | Trail не переносится и прокручивается горизонтально, но автоматического ellipsis/toggle для глубокой структуры ещё нет. Проверить реальную глубину каталогов и добавить collapse при необходимости. |

### Context action

Проверено по [Dropdown Menu checklist (Design system)](https://www.checklist.design/design-system/dropdown-menu).

| | Item | Why |
|---|---|---|
| 🟢 | **Trigger affordance — The element that opens the menu: an overflow icon, chevron button, or right-click target** | У каждой перемещаемой строки есть overflow-trigger `•••` с accessible name, поэтому drag & drop не является единственным способом. |
| 🟡 | **Menu item anatomy — The structure of each action: label, optional leading icon, optional trailing shortcut, optional destructive styling** | Единственная операция подписана «Переместить в» и заканчивается явной кнопкой, но это компактная form внутри menu, а не унифицированный список action items. |
| ⚪ | **Section dividers and groups — Dividers and/or labels that break related actions into sections once the menu gets large** | В текущем menu ровно одна операция; группировка добавила бы шум. |
| ⚪ | **Destructive item styling — Visual differentiation — typically the danger colour — for actions that cannot be undone** | Перемещение обратимо; delete/archive actions в этот context menu не включены. |
| ⚪ | **Nested submenu — A secondary menu triggered by a parent item, used for grouping related but distinct actions** | Для одной операции вложенность не нужна. |
| 🟡 | **Disabled items — Menu items that are visible but cannot be triggered, indicating an unavailable action** | Текущая папка и сам перемещаемый folder исключены из списка, placeholder disabled; descendant-cycle destinations пока отклоняются сервером после submit, а не объясняются заранее. |
| 🟡 | **Positioning and overflow — The menu's placement relative to its trigger, repositioning automatically to stay within the viewport** | Menu привязан справа, ограничен `80vw` и получил vertical max-height/scroll; автоматический flip у нижней границы viewport визуально не доказан. |
| ⚪ | **Keyboard shortcut display — Shortcut hints aligned to the trailing edge of the item label** | Продукт не устанавливал keyboard shortcuts для файловых операций; keyboard fallback — native details/select/button. |

### Empty State

Проверено по [Empty State checklist (Web app)](https://www.checklist.design/web-app/empty-state).

| | Item | Why |
|---|---|---|
| 🟢 | **Illustration or icon — A visual that signals the empty state and gives the screen some personality, rather than feeling broken** | Materials использует contextual folder icon, Personal Library — semantic Knowledge illustration. |
| 🟢 | **Clear heading — A short, plain-language title naming what's missing** | Раздельные headings: «Папка пока пуста», «Ничего не найдено» и «В личной библиотеке пока ничего нет». |
| 🟢 | **Supporting description — A brief explanation of what belongs in this space, most useful for first-time users** | Описания объясняют, что появится в папке и почему Library пополняется только после route learning event. |
| 🟢 | **Primary action — A CTA pointing toward the next step: creating, importing, connecting etc** | Filtered state сбрасывает фильтры; manager создаёт material; сотрудник переходит к своему маршруту. |
| 🟢 | **Zero state vs. no-results state — A distinction between a screen that is empty because nothing has been created versus one that returned no search or filter results** | Server context теперь явно разделяет pristine zero state и active search/type/status/category filters. |
| 🟡 | **Error state variant — A separate variant for when content failed to load, as opposed to genuinely being empty** | POST errors идут через Moodle error notification и не маскируются под empty state; отдельный full-page content-load error/retry state не реализован. |

### Saving changes

Проверено по [Saving changes checklist (Flows)](https://www.checklist.design/flows/saving-changes).

| | Item | Why |
|---|---|---|
| 🟢 | **Show action that enables change — There should be an action to enable information to be updated. It may be automatically editable, but that can be riskier for some software. If it is read-only by default, then a button can trigger the editable version to then update and save.** | Материал открывается read-only; context menu и editor дают явные change actions, drag дополнительно ускоряет move. |
| 🟢 | **Disable save action until changes are made — An action should be visible as a source of confirming changes to be saved - this is usually a button. Initially, the action can be disabled. It indicates no changes have been made, and there is nothing to save. A common location for this action is in the navigation above the fold, so it's always visible over the content. Another option is after all the content that's editable.** | Context Move открывается с disabled button и обязательным placeholder; текущий parent из destinations исключён. |
| 🟢 | **State changes to active once a change is made — In the example, we've changed the email address, which means a change is waiting to be saved. Changing the button state to active brings the user's attention to the action.** | Выбор новой destination включает Move button; drag target получает отдельный active outline. |
| 🟢 | **Action changes to loading state when pressed — Now that the changes are being saved, you want to show that action is in progress. You can do so with a loading spinner in the action, as the user's view will be on that element.** | Context button блокируется и меняет текст на «Перемещаем…»; drag move ставит workspace в `is-moving`, `aria-busy` и live-status. |
| 🟢 | **Notify changes have been saved — The page will reload or update, and this is the critical part. The user should now be informed that their changes have been saved. They can now safely leave the page, knowing the details are locked in until they choose to change them again.** | Успешный move завершает POST/redirect и показывает Moodle success notification «Объект перемещён»; ошибки выводятся отдельно. |

Source-аудит повлиял на код review-ветки: workspace снял ограничение `1480px`, breadcrumb получил корректную current-location семантику, empty/no-results разделены, появились actionable CTA и assistive move status. Authenticated desktop/mobile capture подтверждён восемью PNG в `evidence/materials-library/`. Это всё ещё **CURRENT / TEST IMPLEMENTATION**, не автоматически принятый TARGET.

## 17. Materials / Personal Library — authenticated rendered result

| Check | Result | Evidence |
|---|---|---|
| HR editor allowed; employee denied | PASS | HR workspace renders; employee direct URL returns Moodle no-permission page. |
| Context move state and result | PASS | Disabled before target, enabled after selection, success notification and destination breadcrumb after POST/redirect. |
| Mobile context action 390×844 | PASS | Context menu, destination select and bottom navigation remain available. |
| Personal Library rule | PASS in isolated fixture | Employee Library `0 → 1` only after guarded route-open learning event. |
| Cross-user Library isolation | PASS | Employee `1`; HR `0`; superadmin `0`. |
| Native HTML5 drag | NOT PROVEN by browser driver | Two USTAR attempts and a minimal standard HTML5 control produced no drag events; this isolates a driver limitation. Server move/audit/stale/cycle verifier remains 15/15 PASS. |
| Cleanup | PASS | Passwords randomised, sessions `1 → 0`, fixture point/content removed, final synthetic counts `0/0/0/0`. |

The evidence folder is intentionally synthetic and contains no real employee data or credentials.
