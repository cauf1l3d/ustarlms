# Установка персональных моделей должностей

24.11.2026 МСК. Выпуск `2026112400` (`1.23.0-rc.1`) обновляет подтверждённый `2026112302` server1.
Файл `ustar-position-profiles-2d88d505.tar.gz`.
SHA-256 `6046f0f04bd2a29477aa5216f4ec488244bb78101a5d36297dd0032b17acc123`.
[Ревью и логика](../context/reviews/20261124/POSITION_PROFILES_RU.md) · [PR 130](https://github.com/cauf1l3d/ustarlms/pull/130)

## Подготовка

Скопируйте архив в `/home/aduk/`. Первый блок сверяет архив, журнал предыдущего выпуска и точную версию сервера. Ожидаются `phase=ready`, `local_ustar=2026112302` и `application_commit=fdb6dc414e53931d750eb63dbbedb331cee1c7d8`.

~~~bash
ustar_profiles_installer=$(
sudo bash <<'ROOT'
set -euo pipefail
umask 077
archive=ustar-position-profiles-2d88d505.tar.gz
sha=6046f0f04bd2a29477aa5216f4ec488244bb78101a5d36297dd0032b17acc123
dir=$(mktemp -d /root/ustar-position-profiles-2d88d505.XXXXXX)
install -m 600 "/home/aduk/$archive" "$dir/$archive"
printf '%s  %s\n' "$sha" "$dir/$archive" | sha256sum --check --strict >&2
tar --no-same-owner --no-same-permissions -xzf "$dir/$archive" -C "$dir"
python3 -I -B "$dir/update.py" --verify >&2
python3 -I -B "$dir/update.py" --check >&2
printf '%s\n' "$dir/update.py"
ROOT
) && printf 'INSTALLER=%s\n' "$ustar_profiles_installer"
~~~

## Установка в окно обслуживания

После `phase=ready` выполните в той же сессии.

~~~bash
sudo systemd-run --wait --collect \
  --unit=ustar-position-profiles-20261124 \
  --property=Type=oneshot --property=TimeoutStartSec=30min \
  /usr/bin/python3 -I -B "${ustar_profiles_installer:?Сначала запустите проверку архива}" \
  --upgrade --acknowledge-outage &&
sudo python3 -I -B "$ustar_profiles_installer" --check
~~~

Успех подтверждают `phase=active`, `local_ustar=2026112400`, `application_commit=2d88d505c2c0b17ad4385bef009f98740f4d23f5`. Установщик делает резервную копию кода и PostgreSQL, создаёт две таблицы и проверяет схему, страницу входа и службы.
При `phase=refused` сохраните JSON с `reason`. При `phase=recovery_required` используйте тот же проверенный `update.py` с `--recover --acknowledge-outage` в окно обслуживания. Журнал находится в `/var/lib/ustar-position-profiles-20261124/state.json`.

## Приёмка

1. Откройте «Модели должностей → IT-специалист → Обучение и проверка». Сверьте опубликованные общие шаги со Студией. В форме новой связи раскройте «Срок и варианты подтверждения».
2. Создайте две модели одной должности с разными целевыми навыками. Если опубликована лестница, задайте цели по грейдам. Добавьте каждой модели свой SCORM или тест Moodle с настроенным завершением.
3. Назначьте разные модели двум сотрудникам. Под их учётками откройте «Мой маршрут» и «Моя должность и развитие». Сверьте общие шаги, разные дополнения, цели и фактические грейды.
4. Снимите назначение у первого сотрудника. Сверьте общий маршрут и историю выполнения. Проверьте самостоятельное открытие второго дополнения после общей части.
