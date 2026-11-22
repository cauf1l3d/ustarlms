# Установка рабочего контура Академии 1.22.0-rc.2

23.11.2026 МСК. Архив **ustar-workplace-review-6dd6850d.tar.gz**.
SHA-256 **4a9abd0155e89dda0a823db7b09af5d7402b360af9e0351da99448135be09624**.
[Архив из GitHub Actions](https://github.com/cauf1l3d/ustarlms/actions/runs/45075609359/artifacts/14774548158).

Один архив принимает две исходные версии. Подходящий переход определяется по установленным файлам и версии БД.

| Исходный local_ustar | Исходные файлы приложения | После установки |
|---|---|---|
| `2026111800` | Брифинг `17b61410` | `2026112300`, включая личный канбан и чаты задач |
| `2026112200` | Рабочий контур `33189142` | `2026112300` с исправлениями ревью |

Для обоих переходов ожидаются тема `2026110200` и Connect `17b61410`. Если существует журнал предыдущей установки, проверяется его завершённость. На server1 владелец подтвердил в БД `2026111800` и `2026110200`.

## Проверка до окна обслуживания

Положите `ustar-workplace-review-6dd6850d.tar.gz` в `/home/aduk/`. Из артефакта GitHub Actions
сначала извлеките сам `.tar.gz`. Команды ниже проверяют архив и состояние сервера.

~~~bash
ustar_review_installer=$(
sudo bash <<'ROOT'
set -euo pipefail
umask 077
archive=ustar-workplace-review-6dd6850d.tar.gz
sha=4a9abd0155e89dda0a823db7b09af5d7402b360af9e0351da99448135be09624
dir=$(mktemp -d /root/ustar-workplace-review-6dd6850d.XXXXXX)
install -m 600 "/home/aduk/$archive" "$dir/$archive"
printf '%s  %s\n' "$sha" "$dir/$archive" | sha256sum --check --strict - >&2
tar --no-same-owner --no-same-permissions -xzf "$dir/$archive" -C "$dir"
python3 -I -B "$dir/update.py" --verify >&2
python3 -I -B "$dir/update.py" --check >&2
printf '%s\n' "$dir/update.py"
ROOT
) && printf 'INSTALLER=%s\n' "$ustar_review_installer"
~~~

`phase=ready` подтверждает готовность к обновлению. `phase=active` относится к уже
установленному кандидату. При `phase=refused` сохраните строку с `reason`.

## Обновление в окно обслуживания

После `phase=ready` выполните команды в той же сессии терминала.

~~~bash
test -n "${ustar_review_installer:-}" &&
sudo systemd-run --wait --collect \
  --unit=ustar-workplace-20261123 \
  --property=Type=oneshot --property=TimeoutStartSec=30min \
  /usr/bin/python3 -I -B "$ustar_review_installer" --upgrade --acknowledge-outage &&
sudo python3 -I -B "$ustar_review_installer" --check
~~~

Ожидаемый результат — `phase=active`, приложение `6dd6850d`, `local_ustar=2026112300`
и Connect `17b61410`. Установщик создаёт копии исходного кода и PostgreSQL, обновляет
версию приложения и её строку в планировщике. Connect приостанавливается на время
обновления и возвращается к прежнему состоянию запущенных служб.

Журнал находится в `/var/lib/ustar-workplace-20261123/state.json`. Если операция
прервана и проверка показывает `phase=recovery_required`, используйте тот же `update.py`
с `--recover --acknowledge-outage`. На этапе `prepared` восстановление возвращает
исходные файлы. На этапах `migration` и `app_ready` оно завершает обновление по журналу.

[Сценарии пилота и проверки переноса из Битрикс24](WORKPLACE_MIGRATION_RU.md).

[Результаты инженерного ревью](../context/reviews/20261123/WORKPLACE_REVIEW_RU.md).
