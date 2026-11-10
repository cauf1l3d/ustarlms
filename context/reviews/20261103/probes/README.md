# Воспроизведение диагностик аудита 03–04.11.2026

Запускать из корня checkout с application5e61c0d2 и worker267e5587. Только scratch/CI/staging: эти scripts не являются Moodle CLI и не нуждаются в config.php или production данных. Временная SQLite создаётся и удаляется локально. Отправок и сетевых обращений нет.

Требования: PHP8.3, Python3 с SQLite, Node.js с зависимостями `tests/release_20260912/package-lock.json` (`npm ci --prefix tests/release_20260912`).

```bash
php context/reviews/20261103/probes/library_reads.php
python3 context/reviews/20261103/probes/connect_capacity.py
node context/reviews/20261103/probes/ui_contracts.cjs
```

Ожидаемое поведение среза:24/240/2400offers отображают24карточки и101/965/9605DB API calls; старейший из501grants не находится; закрытая витрина остаётся целью CTA;10000свежих journal events вызывают journal_full; новая форма имеет5question fields. Это подтверждение находок, **не исправлений**. При изменении приложения probes могут перестать совпадать с датированными JSON; не переписывать старые результаты под новую версию.

DB/ACL для Library заменены явными synthetic dependencies, а production reader methods настоящие. Число calls не является числом SQL на PostgreSQL. UI probe использует реальные Mustache и synthetic view data, controller limits сверены в отчёте. Он не измеряет CSS,contrast,keyboard traversal или screen reader. Connect использует actual Journal/SQLite; unit exit policy проверена статически.

Квалификация/ограничения среды: [verification.json](../verification.json). Для исправлений нужны отдельные regression/native tests из плана.
