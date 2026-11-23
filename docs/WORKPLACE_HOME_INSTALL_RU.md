# Установка встреч и задач на главной

Приложение `24b30491`, `local_ustar=2026112301`. Архив `ustar-workplace-home-24b30491.tar.gz`.
SHA-256 `27045d226a6ad9ef11aab308fcaf2821a885414078751ebf02cfd253e7eca50c`.
Рабочий server1 подтверждён владельцем на `2026112300`, коммит `6dd6850d`. [Архив сборки](https://github.com/cauf1l3d/ustarlms/actions/runs/45147082165/artifacts/14805170549)

## Проверка архива и сервера

Скопируйте архив в `/home/aduk/` и выполните блок целиком. После проверки кода, версии, cron и Connect он выведет `phase=ready` и путь к установщику.

~~~bash
ustar_home_installer=$(
sudo bash <<'ROOT'
set -euo pipefail
umask 077
archive=ustar-workplace-home-24b30491.tar.gz
sha=27045d226a6ad9ef11aab308fcaf2821a885414078751ebf02cfd253e7eca50c
dir=$(mktemp -d /root/ustar-workplace-home-24b30491.XXXXXX)
install -m 600 "/home/aduk/$archive" "$dir/$archive"
printf '%s  %s\n' "$sha" "$dir/$archive" | sha256sum --check --strict >&2
tar --no-same-owner --no-same-permissions -xzf "$dir/$archive" -C "$dir"
python3 -I -B "$dir/update.py" --verify >&2
python3 -I -B "$dir/update.py" --check >&2
printf '%s\n' "$dir/update.py"
ROOT
) && printf 'INSTALLER=%s\n' "$ustar_home_installer"
~~~

В JSON проверки архива `baseline_commit` обозначает базовый выпуск накопительного пакета. Для server1 используйте `local_ustar` из строки `phase=ready`.

## Обновление в окно обслуживания

После `phase=ready` выполните блок в той же сессии. При `phase=refused` сохраните JSON с `reason`.

~~~bash
sudo systemd-run --wait --collect \
  --unit=ustar-workplace-home-20261123 \
  --property=Type=oneshot --property=TimeoutStartSec=30min \
  /usr/bin/python3 -I -B "${ustar_home_installer:?Сначала запустите блок проверки}" \
  --upgrade --acknowledge-outage &&
sudo python3 -I -B "$ustar_home_installer" --check
~~~

Успех подтверждают `phase=active`, `local_ustar=2026112301`, код `24b30491`.
Во время установки оператор сохраняет код и PostgreSQL, включает обслуживание, обновляет приложение и возвращает службы Connect к исходному состоянию.

При `phase=recovery_required` используйте тот же проверенный `update.py` с аргументами `--recover --acknowledge-outage` в окно обслуживания. Журнал находится в `/var/lib/ustar-workplace-home-20261123/state.json`.

## Проверка в Академии

1. Организатор приглашает сотрудника на встречу с переговорной. Под второй учёткой открывается «Мои встречи». Карточка показывает организатора, отдел при наличии, время, переговорную и кнопки ответа.
2. Сотрудник принимает встречу с главной. На главной, в списке и деталях отображается «Принято». Под «Изменить ответ» доступно «Не смогу».
3. Сотрудник без приглашения не видит карточку. Организатор видит ответ в списке приглашённых. «Занятость переговорных» показывает этот интервал.
4. На главной работают «Мой план», «Чаты задач» и быстрый отчёт задачи. В «Настроить главную» можно переместить, изменить ширину или скрыть встречи.

[Инженерное ревью](../context/reviews/20261123/WORKPLACE_HOME_RU.md)
