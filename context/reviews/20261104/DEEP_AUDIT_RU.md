# Углубление аудита — 04.11.2026

База: установленный app5e61c0d2, исходный аудит9504afa1. Первый исправляющий app5dd12889 пока кандидат. Это дополнение к30находкам, не декларация полного отсутствия ошибок. Агент не подключался к production; рабочая нагрузка, интервью и внешнее восстановление не измерены.

## Проверка причин, а не только симптомов

| Область | Углублённое доказательство | Вывод и действие |
|---|---|---|
| Личные материалы U02 | Реальный reader + synthetic DB:501 grants, oldest исчезает до исправления и находится после. В новом reader порции200, canonical resolve_many, ACL до счётчика/страницы, дедупликация по subject. Native regression также проверяет последнюю страницу, второй grant и draft. | Пакет01 устраняет cap500. Материалы по истории learning_events и saved feed по-прежнему имеют собственные readers; не обещаем общую SQL оптимизацию. |
| Каталог/проверки U01 | catalog_allowed(teaser=true) допускает заголовок, resolve(canopen=false) создавал shop CTA независимо от department allowlist. В checks аналогичный путь; для group/quick can_open также зависит от товара. | Скрываем закрытую карточку, если ей некуда вести; купленные/назначенные доступны прежним путём. Если проверка закрыта товаром и вообще не имеет offer, не создаём URL open=0. Direct URL guards сохраняются. |
| Навигация U05 | Настоящий Mustache/JSDOM: parent отсутствовал в GET form; previous отсутствовал; пустой поиск сообщал об отсутствии публикаций. | Пакет01 сохраняет parent, возвращает previous, разделяет отсутствие доступных проверок и пустую выдачу. |
| Studio E04 | studio_lookup_probe.php вызывает настоящий course_studio::get последнего проекта. При100/1000 проектах по64KiB читается весь массив sourcejson; отдельный JSON сохраняет точные bytes. | Это O(N×размер проекта) чтение и декодирование, хотя SQL вызов один. Не N+1; запросный бюджет сам по себе не ловит дефект. T04 должен измерять bytes/decodes и concurrency, затем indexed lookup. |
| Connect E07 | Ранее actual SQLite probe доказал10k journal_full и освобождение только старыхterminal rows. Дополнительно сопоставлены receiver runtime exit4 и RestartPreventExitStatus. | Простое увеличение cap или Restart=always не решает retention/dedup/backpressure. Отдельный пакетT03d, не трогать в Library overlay. |
| Архивы Studio | model.js проверяет вложенность3,4000файлов,200MiB распакованных данных, unsafe paths, XML declarations; server native_package ограничивает index8MiB и проверяет точное соответствие генератору. | Не заявляем отсутствие ZIP защиты. Распаковка JSZip происходит до проверки фактической длины результата: adversarial compressed-size mismatch требует отдельного memory-budget теста; эксплуатация не доказана. |

## Дополнительные подтверждённые UX-проблемы

**U09, P2 — потеря ввода при отказе сохранения.** library_manage.php ловит moodle_exception, затем заново строит форму из DB/пустого record. POST questions/title/summary не возвращаются в view model. Неверная цена, конфликт revision или ошибка банка стирают введённый текст при перерисовке. ИсправлениеT02: form state из валидированных scalar inputs, сохранение текста вопросов при отказе, field errors, отдельное решение по конфликту версии; не подменять current revision автоматически. Regression: новая проверка с шестью вопросами и неверным порогом сохраняет введённый текст после отказа.

**U10, P2 — недостоверный статус доступа в админке.** library_manage.php формирует «Действует» исключительно по revokedat, хотя active_grant проверяет validfrom и validto. Будущий/истёкший грант выглядит действующим. ИсправлениеT01/U03: единый presentation status future/active/expired/revoked/refunded; серверный entitlement не менять. Tests frozen clock на границах, открытый validto=0.

**Уточнение U03 — не только невозможность найти запись.** Селектор subject обрезан500, выбранный старый subject может отсутствовать при edit. Браузер выбирает первый option; canonical library_offer::save запрещает замену itemtype/itemid существующего предложения. Поэтому подтверждённый эффект — сохранение отказывает, а не незаметно переносит доступ на другой материал. Добавить выбранный authorized subject отдельно от страницы поиска; immutable binding показать read-only при редактировании.

## Границы и приоритеты

Первый архив закрывает U01/U02/U05 в исходниках после квалификации. U03/U04/U09/U10 — следующий пакет authoring/admin UX; затем T03 query growth/HR scope/Connect. E04 отдельный Studio reader, P03 privacy — доменная политика и handlers, P01/P02/P05 — ADR для изолированного внешнего клиента. Нельзя смешать эти изменения в один source overlay без схемы, нагрузки и миграционной приёмки.

32 находки:30 исходных + U09/U10. План T01–T10 расширен: U09 относится кT02 (сохранение вводимых данных при validation/revision error), U10 кT01/U03 (точный статус исторического доступа). Производственный rollout и внешняя продажа по-прежнему требуют ролевой приёмки/измерений и условий из T05–T10. Скрытый интерфейс не служит доказательством server ACL; синтетические call counts не являются PostgreSQL timings.

## Воспроизведение

`php context/reviews/20261104/studio_lookup_probe.php`

`php tests/release_20260912/library_discovery_contract.php`

`node tests/release_20260912/library_navigation_dom.cjs`

Native cases добавлены в `moodle/local/ustar/tests/library_access_test.php`. Проверка установленного сервера остаётся за владельцем после архива.

## Точные источники

- [Сброс формы после исключения, строки54–57](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/library_manage.php#L54).
- [Статус по revokedat, строка101](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/library_manage.php#L101).
- [Запрет смены subject, строка78](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/library_offer.php#L78).
- [Полное чтение Studio, строки15–26](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/classes/course_studio.php#L15).
- [Лимиты JSZip, строка130](https://github.com/cauf1l3d/ustarlms/blob/5e61c0d2566e8103d01121968d56852e26da99fa/moodle/local/ustar/course_studio/model.js#L130).
