# Установка обновления брифинга и канбана

Выпуск 1.21.0-rc.2 от 18.11.2026 МСК обновляет приложение и Telegram-worker одним архивом. Установщик сверяет точное содержимое кода, worker, Moodle, схему БД, cron и состояние предыдущей попытки. [Результаты ревью](../context/reviews/20261118/BRIEFING_KANBAN_RU.md)

| Исходное приложение и worker | Версия local_ustar | Что проверяет обновление |
| --- | --- | --- |
| 422fbfb3 | 2026111402 | Создание таблиц брифинга и переход на 2026111800 |
| b1df4f2b | 2026111700 | Переход на 2026111800 с сохранением таблиц и записей |

## Подготовка на server1

Перенесите архив в /home/aduk/. Подставьте имя файла и SHA-256 из поставки. Выполните команды проверки до начала окна обслуживания.

~~~bash
sudo bash <<'ROOT'
set -euo pipefail
umask 077
archive='ИМЯ_АРХИВА_ИЗ_ПОСТАВКИ'
expected_sha='SHA256_ИЗ_ПОСТАВКИ'
dir="/root/$(basename "$archive" .tar.gz)"
test ! -L "$dir"
install -d -m 700 "$dir"
install -m 600 "/home/aduk/$archive" "$dir/$archive"
printf '%s  %s\n' "$expected_sha" "$dir/$archive" | sha256sum --check --strict -
tar --no-same-owner --no-same-permissions -xzf "$dir/$archive" -C "$dir"
cd "$dir"
sha256sum --check --strict --quiet SHA256SUMS
python3 -I -B update.py --verify
python3 -I -B update.py --check
printf 'INSTALLER=%s/update.py\n' "$dir"
ROOT
~~~

Ожидается phase=ready на одной из двух баз. Phase=active означает, что выпуск уже установлен. При recovery_required сохраните вывод проверки и журнала. Незавершённая операция старого выпуска восстанавливается его установщиком.

## Установка и приёмка

После phase=ready в окно обслуживания подставьте путь INSTALLER, полученный при проверке.

~~~bash
installer='/root/КАТАЛОГ_АРХИВА/update.py'
sudo systemd-run --wait --collect \
  --unit=ustar-briefing-review-20261118 \
  --property=Type=oneshot --property=TimeoutStartSec=30min \
  /usr/bin/python3 -I -B "$installer" --upgrade --acknowledge-outage
sudo python3 -I -B "$installer" --check
~~~

Успешный итог phase=active, local_ustar=2026111800 и одинаковые SHA приложения и worker из поставки. Путь резервной копии выводится при установке. Журнал находится в /var/lib/ustar-task-briefing-20261118/state.json. При прерывании установки сохраните вывод проверки и журнал, затем используйте --recover --acknowledge-outage для возобновления своей незавершённой операции.

1. Руководитель отправляет отчёт, затем сохраняет правку. Время первой сдачи остаётся прежним. Администратор открывает режим показа и CSV.
2. Организатор приглашает участника, переносит встречу и отзывает приглашение. Проверьте доступ участника, занятость переговорной и отсутствие пересечений с принятыми встречами.
3. В канбане откройте отчёт задачи и вернитесь к тому же поиску. У повторного курса используйте переход к материалу из карточки.
4. В привязанном Telegram откройте «Брифинг» и «Мои встречи». При наличии доверенного HTTPS-адреса проверьте кнопки перехода в Академию. При внутреннем HTTP используются текстовые карточки с маршрутом через меню.

[Настройка форм и первый запуск](BRIEFING_AND_KANBAN_RU.md)
