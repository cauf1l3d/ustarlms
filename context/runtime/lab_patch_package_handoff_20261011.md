# Пакет Moodle 5.1.8: следующий ограниченный шаг — 11.10.2026

[Owner browser screenshot](lab_stage_browser_result_20261011.json) получен19:53:07UTC:
новый loopback18085 доступен, авторизованный administrator session открывает SCORM
«Товароведение», раздел6/20,30percent, текст/изображения отображаются. Screenshot
не доказывает свежий login, homepage redirect, completion, сохранение прогресса,
все роли или отдельное скачивание вложения. Raw image не опубликован.

## Проверенный источник пакета

Official[SHA-256](https://packaging.moodle.org/stable501/moodle-5.1.8.tgz.sha256):
`0e8e2cdeecf24512b75e24158fa224ed08a20c608b4e1065cb2068832467665c`.
Агент скачал отдельный официальный tgz и проверил его локально:75466974bytes,
39376entries/29409regular files/9967directories,313711622logical file bytes,
необычных entry types нет. [Receipt](moodle_518_package_verification_20261011.json).
Version/index/composer.json/lock Git blob OIDs совпали с exact upstream
`cc0bf17da8b5fbd290faf95b2b14a03d64331923`; это four-file metadata verification,
не full package equality to Git tree или addon compatibility.

В tgz нет root vendor. Composer lock содержит0 production/68 dev packages; core
runtime library sources входят в public/. Не добавлять dev-only Composer install
лишь по аналогии с synthetic CI Dockerfile. Root vendor/config/addon dependencies
existing clone требуют отдельной проверки перед candidate activation; их нельзя
объявить совместимыми по одному official checksum. Stock Moodle function
get_default_home_page_url и index поддерживают local path defaulthomepage; применение
`/local/ustar/home.php` ещё не проверено в runtime этого full-addon stage.

## Команда оператору: скачать и проверить, без замены code/DB

На сервере после отдельного `sudo -v` выполнить **одну physical line** ниже.
Она проверяет private parent metadata, точный manifest digest уже принятого
checkpoint и существующий coordination lock (fd8 открыт read-only, новый lock не
создаётся). Checkpoint file contents копированию не подвергаются, inventory повторно
не сканируется. Требуется4GiB reserve+128MiB download allowance. Exclusive flock
защищает от параллельных reviewed lab операций; download не останавливает их
containers и не исполняет PHP/SQL. Создаётся новый root0700 directory рядом с lab,
в него скачивается лишь один HTTPS tgz с120s curl timeout/180s outer timeout и
100000000bytes size limit. Expected SHA проверяется strict, файл fsync.

```bash
sudo -n timeout 180s bash -c 'set -euo pipefail; umask 077; test -f /run/ustar-restore-lab-20261004.lock && test ! -L /run/ustar-restore-lab-20261004.lock; exec 8</run/ustar-restore-lab-20261004.lock; flock --exclusive --nonblock 8; ustar_package=$(python3 -I -B -c "import os,stat,tempfile,hashlib,shutil; from pathlib import Path; p=Path(\"/var/lib/ustar-restore-lab/20261004T123406Z-741e3f72\"); items=[(q,q.lstat()) for q in (p,*p.parents)]; assert all(stat.S_ISDIR(i.st_mode) and i.st_uid==0 and not i.st_mode&0o022 for q,i in items),\"STOP: private parent identity\"; assert p.stat().st_gid==0 and stat.S_IMODE(p.stat().st_mode)==0o700,\"STOP: source root permissions\"; c=p/\"patch-rollback-20261005\"; ci=c.lstat(); assert stat.S_ISDIR(ci.st_mode) and ci.st_uid==ci.st_gid==0 and stat.S_IMODE(ci.st_mode)==0o700,\"STOP: checkpoint directory\"; m=c/\"manifest.json\"; mi=m.lstat(); assert stat.S_ISREG(mi.st_mode) and mi.st_uid==mi.st_gid==0 and stat.S_IMODE(mi.st_mode)==0o600 and mi.st_nlink==1 and mi.st_size<=33554432,\"STOP: manifest permissions\"; assert hashlib.sha256(m.read_bytes()).hexdigest()==\"77672fd0b5b7bc5a27d1bd71d536f2bf47449455623589b53e0605005584b4c0\",\"STOP: checkpoint identity\"; l=Path(\"/run/ustar-restore-lab-20261004.lock\"); li=l.lstat(); fi=os.fstat(8); assert stat.S_ISREG(li.st_mode) and li.st_uid==0 and not li.st_mode&0o022 and li.st_nlink==1 and (li.st_dev,li.st_ino)==(fi.st_dev,fi.st_ino),\"STOP: coordination lock identity\"; assert shutil.disk_usage(p).free>=4*1024**3+128*1024**2,\"STOP: space reserve\"; print(tempfile.mkdtemp(prefix=\"moodle-518-package-20261011-\",dir=p),flush=True)"); printf "PACKAGE_DOWNLOAD_START=%s; DIRECTORY=%s\n" "$(date -u +%FT%TZ)" "$ustar_package"; curl -fsSL --proto "=https" --proto-redir "=https" --connect-timeout 10 --max-time 120 --max-filesize 100000000 https://packaging.moodle.org/stable501/moodle-5.1.8.tgz -o "$ustar_package/moodle-5.1.8.tgz"; printf "%s  %s\n" 0e8e2cdeecf24512b75e24158fa224ed08a20c608b4e1065cb2068832467665c "$ustar_package/moodle-5.1.8.tgz" | sha256sum --check --strict -; sync -f "$ustar_package/moodle-5.1.8.tgz"; printf "MOODLE_518_PACKAGE=PASS; NO_CORE_UPGRADE=YES; DIRECTORY=%s\n" "$ustar_package"'
```

Передать весь stdout/error, включая DIRECTORY. Ожидаемый marker:
`MOODLE_518_PACKAGE=PASS; NO_CORE_UPGRADE=YES`. Данная команда не извлекает архив,
не переносит addons, не монтирует candidate в containers и не обновляет Moodle.
При failure new partial directory сохраняется; retry/cleanup до разбора не нужен.
Это размер официального tgz, не full candidate/workspace storage budget.

## Продолжение после принятого download

Собрать отдельный candidate tree из stock5.1.8 с full19 addon roots и23 reviewed
extras/config с manifest hash verification и safe paths; сохранить homepage USTAR
через поддержанный config contract, проверить runtime dependencies. Не смешивать
новый core поверх старого: deleted upstream paths и unknown helpers сохранять
отдельно/в явно проверенных границах, неизвестный PHP не запускать. Frozen checkpoint,
source lab и текущая baseline workspace сохраняются до tested paired restore.
Подготовить workspace-only candidate switch/upgrade/repeat и согласованный rollback
code/config/Moodledata/physicalPG после clean stop только owned stage containers.
Ни новый full-addon helper, ни server upgrade в этой docs поставке не выполнены.
Browser acceptance после candidate и после rollback остаётся обязательной.
