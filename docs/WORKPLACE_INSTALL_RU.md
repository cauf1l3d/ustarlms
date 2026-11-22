# Установка рабочего контура Академии 1.22.0-rc.1

22.11.2026 МСК. Архив **ustar-workplace-33189142.tar.gz**.
SHA-256 **4dfb36f02c6f611683c8a52aa95f292d54054efe3c9c986e227b543883ce7c15**.
[Архив из GitHub Actions](https://github.com/cauf1l3d/ustarlms/actions/runs/45031747297/artifacts/14757826856).

База — приложение и Connect `17b61410`, `local_ustar=2026111800`, `theme_ustar=2026110200`.
На `server1` владелец подтвердил обе версии в БД. Предварительная проверка дополнительно
сверяет файлы приложения и темы, рабочую сборку Connect, схему брифинга и планировщик.

## Проверка до окна обслуживания

Положите `ustar-workplace-33189142.tar.gz` в `/home/aduk/`. Из артефакта GitHub Actions
сначала извлеките сам `.tar.gz`. Команды ниже проверяют архив и состояние сервера.

~~~bash
ustar_workplace_installer=$(
sudo bash <<'ROOT'
set -euo pipefail
umask 077
archive=ustar-workplace-33189142.tar.gz
sha=4dfb36f02c6f611683c8a52aa95f292d54054efe3c9c986e227b543883ce7c15
dir=$(mktemp -d /root/ustar-workplace-33189142.XXXXXX)
install -m 600 "/home/aduk/$archive" "$dir/$archive"
printf '%s  %s\n' "$sha" "$dir/$archive" | sha256sum --check --strict - >&2
tar --no-same-owner --no-same-permissions -xzf "$dir/$archive" -C "$dir"
python3 -I -B "$dir/update.py" --verify >&2
python3 -I -B "$dir/update.py" --check >&2
printf '%s\n' "$dir/update.py"
ROOT
) && printf 'INSTALLER=%s\n' "$ustar_workplace_installer"
~~~

`phase=ready` подтверждает готовность к обновлению. `phase=active` относится к уже
установленному кандидату. При `phase=refused` сохраните строку с `reason`.

## Обновление в окно обслуживания

После `phase=ready` выполните команды в той же сессии терминала.

~~~bash
test -n "${ustar_workplace_installer:-}" &&
sudo systemd-run --wait --collect \
  --unit=ustar-workplace-20261122 \
  --property=Type=oneshot --property=TimeoutStartSec=30min \
  /usr/bin/python3 -I -B "$ustar_workplace_installer" --upgrade --acknowledge-outage &&
sudo python3 -I -B "$ustar_workplace_installer" --check
~~~

Ожидаемый результат — `phase=active`, приложение `33189142`, `local_ustar=2026112200`
и Connect `17b61410`. Установщик создаёт копии исходного кода и PostgreSQL, обновляет
версию приложения и её строку в планировщике. Connect приостанавливается на время
обновления и возвращается к прежнему состоянию запущенных служб.

Журнал находится в `/var/lib/ustar-workplace-20261122/state.json`. Если операция
прервана и проверка показывает `phase=recovery_required`, используйте тот же `update.py`
с `--recover --acknowledge-outage`. На этапе `prepared` восстановление возвращает
исходные файлы. На этапах `migration` и `app_ready` оно завершает обновление по журналу.

[Сценарии пилота и проверки переноса из Битрикс24](WORKPLACE_MIGRATION_RU.md).
