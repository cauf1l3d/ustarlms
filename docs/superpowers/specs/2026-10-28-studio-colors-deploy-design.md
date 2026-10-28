# Установка цветов и компактной студии2800

Владелец спросил, как установить уже квалифицированные изменения. Подготовить
проверенный архив и команды для самостоятельной установки на server1; без SSH,
автоматического merge и изменения приложения4c48dce7.

Выбор исполнителя: отдельный фиксированный2700→2800 оператор поверх существующих
Application/Journal/Locks. Старые2600/2700 файлы и архивы не переписывать. Прямая
замена файлов обходит paired snapshot, cron и recovery; повторная упаковка старого
оператора меняет смысл принятой поставки. Поэтому используется отдельный successor.

Точный predecessor: operator01e1cef2393c78cb9bb2f3221e739ffeda236180,
app20ed966e146378a21016c085d31ce4defaa809a8/local2700/theme2700.
Target: app4c48dce7dadd4da8f054f2acf0d6974bdaeecebc/local2800/theme2700,
589files/100tables, без schema delta; worker6c56 без rollout. На2600 или drift
read-only --check отказывает; владелец сначала завершает ранее выданную2700.
Скриншот не доказывает новую точную установленную версию.

Новый журнал /var/lib/ustar-studio-colors-update. Существующие shared locks,
paired source/database/data snapshot, current task policy, HTTPS и права сохраняются.
Cron2700 выводится из pinned2000 через прежнюю цепочку, а не принимается по live hash.
Проверяются принятый2700 журнал, исходники, DB, cron и действующий worker/runtime.
До public release failure восстанавливает2700 пару; после release --recover
завершает2800, сохраняя новые записи. Полный поздний restore требует обоих флагов.

Standalone root-owned update.py: offline PLAN_ONLY, --check, --upgrade
--acknowledge-outage, --status, --recover. Deterministic tar: update.py,
INSTALL.json, README.txt, SHA256SUMS. Внешний SHA проверяется до распаковки.
До выдачи: RED/GREEN reference/cron/package/real journal+pair fixtures, process loss,
UID33, полный R16 и применимый CI; скачанный CI архив сверяется с независимой сборкой.
Владелец получает архив и systemd-run команды; host acceptance остаётся отдельной.
