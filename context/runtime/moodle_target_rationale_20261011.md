# Почему ближайший кандидат 5.1.8, а долгосрочная цель 5.3 LTS

Проверено по официальным источникам 11.10.2026. Production остаётся Moodle
5.1.1+ / PHP8.3.33 / PostgreSQL16.15; обновление core пока не выполнялось.

Последний официальный major — **5.3 LTS**, выпущенный05Oct. Его минимальные
требования: PHP8.3, PostgreSQL17; исходный Moodle для upgrade —4.4 или новее.
Текущая версия Moodle удовлетворяет исходному требованию, PostgreSQL16.15 — нет.
[Release requirements](https://moodledev.io/general/releases/5.3),
[latest download](https://download.moodle.org/releases/latest/).

**5.1.8** — опубликованный05Oct patch внутри текущей ветки. Именно он выбран
в очереди владельца1→7→6 и закреплён exact upstream commit. Synthetic candidate
CI PR83 прошёл; реальный full-addon stage upgrade/repeat/paired rollback ещё
впереди. Такой переход сохраняет PostgreSQL16 и проверяет меньшую область
изменений. Это подготовленная ближайшая поставка, не долгосрочная платформа.
[Release5.1.8](https://moodledev.io/general/releases/5.1/5.1.8),
[текущий контракт и пределы CI](patch_runtime_20261005.md).

Общая поддержка5.1 закончилась05Oct2026; security support заканчивается
**19Apr2027**. Для5.3 LTS security support заявлена до **01Oct2029**.
Следовательно, PLAT-01 должен учитывать срок выхода с5.1 до окончания security
support. [Официальная таблица](https://moodledev.io/general/releases).

Промежуточная установка5.1.8 **не является техническим требованием** перед5.3.
Прямой переход можно готовить после проверки полного набора дополнений/темы,
нового PG17 storage и миграции базы с согласованным rollback. Совместимость
пока не доказана; переиспользовать physical PG16 data как готовый PG17 cluster
нельзя ([PostgreSQL major upgrade](https://www.postgresql.org/docs/17/upgrading.html)). Вопрос владельца «почему» не заменяет текущую очередь на major migration.
Сохранён ближайший patch5.1.8/ОС/HTTPS, PLAT-01 остаётся отдельным проектом.
