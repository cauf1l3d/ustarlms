# Установка назначения задач отделам и должностям

23.11.2026 МСК. Выпуск `2026112302` (`1.22.0-rc.4`) поверх подтверждённого `2026112301`.
Архив `ustar-workplace-assignment-fdb6dc41.tar.gz`.
SHA-256 `ada75f355a481eca54c25bbc7090a67d4bdbd392e9fa809b4a3290a43025c103`.
[Сборка архива](https://github.com/cauf1l3d/ustarlms/actions/runs/45163762558) · [Ревью](../context/reviews/20261123/TASK_ASSIGNMENT_RU.md)

## Подготовка

Скопируйте архив в `/home/aduk/`. Блок сверяет архив и читает состояние сервера. Для server1 ожидаются `phase=ready`, `local_ustar=2026112301`, `application_commit=24b304914d51e2cb7d11f76305aa3734a635bfa6`.

~~~bash
ustar_assignment_installer=$(
sudo bash <<'ROOT'
set -euo pipefail
umask 077
archive=ustar-workplace-assignment-fdb6dc41.tar.gz
sha=ada75f355a481eca54c25bbc7090a67d4bdbd392e9fa809b4a3290a43025c103
dir=$(mktemp -d /root/ustar-workplace-assignment-fdb6dc41.XXXXXX)
install -m 600 "/home/aduk/$archive" "$dir/$archive"
printf '%s  %s\n' "$sha" "$dir/$archive" | sha256sum --check --strict >&2
tar --no-same-owner --no-same-permissions -xzf "$dir/$archive" -C "$dir"
python3 -I -B "$dir/update.py" --verify >&2
python3 -I -B "$dir/update.py" --check >&2
printf '%s\n' "$dir/update.py"
ROOT
) && printf 'INSTALLER=%s\n' "$ustar_assignment_installer"
~~~

## Установка в окно обслуживания

После `phase=ready` выполните в той же сессии.

~~~bash
sudo systemd-run --wait --collect \
  --unit=ustar-workplace-assignment-20261123 \
  --property=Type=oneshot --property=TimeoutStartSec=30min \
  /usr/bin/python3 -I -B "${ustar_assignment_installer:?Сначала запустите блок проверки}" \
  --upgrade --acknowledge-outage &&
sudo python3 -I -B "$ustar_assignment_installer" --check
~~~

Ожидаются `phase=active`, `local_ustar=2026112302`, `application_commit=fdb6dc414e53931d750eb63dbbedb331cee1c7d8`.
Установщик сохраняет код и PostgreSQL, выполняет Moodle upgrade, проверяет страницу входа и восстанавливает исходное состояние служб Connect. Версия схемы XMLDB и код Connect остаются прежними.

При `phase=refused` сохраните JSON с `reason`. При `phase=recovery_required` используйте тот же проверенный `update.py` с `--recover --acknowledge-outage` в окно обслуживания. Журнал находится в `/var/lib/ustar-workplace-assignment-20261123/state.json`.

## Быстрая приёмка

1. С учётки директора откройте «Задачи → Создать задачу → Отдел». Для тестового отдела проверьте имя руководителя. Назначьте одно тестовое поручение и проверьте его в «Мой день» руководителя.
2. С учётки руководителя выберите «Должность». Сверьте состав с отделом. Назначьте одно поручение двум сотрудникам и проверьте счётчик в «Моих поручениях». Под каждым сотрудником откройте собственную карточку и вложение.
3. Проверьте назначение одному сотруднику через поиск. У этой задачи доступны обычный отчёт, чат и переход на канбан.
4. Проверьте, что руководитель не видит получателей из чужого подразделения, а рядовой сотрудник не получает кнопки «Создать задачу».
