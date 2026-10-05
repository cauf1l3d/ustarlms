# INF-01: результат сверки ядра 05.10.2026

Измерение владельца: **2026-10-05 08:28:00.664160 UTC / 11:28 MSK**. Получен архив `ustar-core-rHoI7j.zip`, SHA-256 `4f9b66da218d3a10f3278ed1b7054d16be2d8c28763cd9b5da50a0f0cfb5c379`. Исходный архив не изменён и не публикуется. Обезличенная сводка — [core_comparison_20261005.json](core_comparison_20261005.json).

| Контроль | Результат |
|---|---|
| Collector SHA-256 | Совпадает с проверенным скриптом PR79: `898667ddd621f2254b2d1db533fedf9f2edc0d07cb82a83ba02c6926df745c9c` |
| Official reference | `moodle/moodle`, weekly commit `1cd17816c56a7df7ee796892efccaf1ed5347340` |
| Reference files в объявленной области | 24 679 |
| Matching / changed / missing | 24 678 / **1** / 0 |
| Extra / errors / unsupported reference | **23** / 0 / 0 |
| complete / match / exit | true / false / 3 |

Проверены полный JSON, уникальность matching paths и равенство union matching/changed/missing точному reference tree. Expected OID изменённого файла также соответствует upstream tree. Это независимая проверка структуры и reference отчёта; самостоятельно production files агент не читал.

Один изменённый файл — **`public/index.php`**, главная страница Moodle. Expected Git blob `801ffcf1dd31e6356932a20a6433743323d828c6`, observed `529f83bf0735719dfb5240496e9c8eba376e944a`. В архиве есть collector, отчёт и upstream metadata repo; **текущего содержимого production index.php нет**. Нельзя утверждать, что это redirect, ошибка или безопасная косметическая правка, пока файл не изучен.

## Дополнительные файлы

Группировка по путям/именам; содержимое и фактическое использование пока не проверены.

| Группа | Количество | Пути / назначение по имени |
|---|---:|---|
| Копии config.php | 6 | Пять в parent core directory, одна `public/config.php.before_ip_change_20260830T203238Z` |
| Вспомогательные PHP entrypoints | 7 | `githash.php`, две parent gradehelper scripts; в public — Bitrix handler, course_dashboard, два gradehelper scripts |
| PDF files | 7 | `public/reports/course_10_*.pdf` |
| Другие | 3 | `bx_grade_tokens.txt`, `public/index.php.backup_ustar`, `wwwroot` |

13 extra paths находятся под public webroot. В том числе config backup и PDF: необходимо отдельно проверить доступ/содержимое/consumers, не объявлять их защищёнными или доступными по HTTP без проверки действующих rules. `bx_grade_tokens.txt` рассматривается как потенциально секретный файл по имени, значения не читались. Не публиковать эти файлы/конфигурацию/токены в Git. Не обращаться к неизвестным PHP helpers через HTTP/CLI для диагностики: их write impact неизвестен.

Результат не разрешает удаление или слепое копирование extras в новый release. Upgrade должен сохранить recovery комплект и учесть реально используемые entrypoints/данные. Hardening INF-02/03 и cleanup остаются отложенными после выбранных владельцем 1 → 7 → 6; конкретные dependencies сохранности при upgrade входят в подготовку выбранного пункта.

## Следующий шаг

1. Получить private copy **только production public/index.php**, проверить observed blob OID и статически сравнить с upstream. Выяснить поведение главной страницы и способ сохранить его при official patch. PHP не запускать.
2. Статически получить argument/subparser names и SHA-256 существующих backup/cron wrappers, без их запуска и без defaults/secret values. Не придумывать create/restore/rebind flags.
3. По проверенному CLI подготовить fresh согласованную ручную recovery copy после записей cron и изолированную upgrade/repeat/rollback репетицию. Копия 04.10 остаётся исторической; регулярного расписания нет.

INF-01 остаётся **in_progress**: core comparison result получен, review local index/extras и recovery/stage readiness ещё не приняты. Сохранён порядок baseline → Moodle/CI/ОС → HTTPS. Конфигурация, core, данные, cron и AppArmor не исправлялись этой GitHub-поставкой; application PR76, original audit и runtime fixtures unchanged. Проверки текущей документации и exact implementation SHA/CI — в PR/Git history; старый full gate не является приёмкой нового runtime candidate.
