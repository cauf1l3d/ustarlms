# Установка брифинга и канбана

Проверено 18.11.2026 МСК. Выпуск приложения и Connect b1df4f2b234b6b79260eb3bbd2423fc0daf7ef79 обновляет установленную операторскую базу 422fbfb3, local_ustar 2026111402 до 2026111700. Theme 2026110200.

[Проверки выпуска](../context/runtime/briefing_qualification_20261118.json) и [успешный прогон GitHub](https://github.com/cauf1l3d/ustarlms/actions/runs/44127104864). Сохранённый архив совпал с CI. На обоих профилях Moodle прошли 66 тестов и 295 утверждений Connect, сценарий брифинга, установка, обновление и повторное обновление со сравнением схемы.

Архив ustar-briefing-kanban-b1df4f2b.tar.gz имеет SHA-256 1cd5bf68325739912fdb3a23709610026b5e992621763b3482971125da3100e7.

## Подготовка

Поместите архив в /home/aduk/ на server1 и выполните проверку.

~~~bash
sudo bash <<'ROOT'
set -euo pipefail
umask 077
archive=ustar-briefing-kanban-b1df4f2b.tar.gz
dir=/root/ustar-briefing-kanban-b1df4f2b
expected_worker=/opt/ustar-connect/releases/422fbfb324db3b18447b61bc1c86a8a5481fe3e0
actual_worker=$(readlink -e /opt/ustar-connect/current)
printf 'WORKER=%s\n' "$actual_worker"
test "$actual_worker" = "$expected_worker"
test ! -L "$dir"
install -d -m 700 "$dir"
install -m 600 "/home/aduk/$archive" "$dir/$archive"
printf '%s  %s\n' \
  1cd5bf68325739912fdb3a23709610026b5e992621763b3482971125da3100e7 \
  "$dir/$archive" | sha256sum --check --strict -
tar --no-same-owner -xzf "$dir/$archive" -C "$dir"
cd "$dir"
sha256sum --check --strict --quiet SHA256SUMS
python3 -I -B update.py --verify
python3 -I -B update.py --check
ROOT
~~~

Ожидается phase=ready, local_ustar 2026111402, worker 422fbfb3. При отказе сохраните полный вывод для разбора.

## Установка и проверка

После phase=ready выполните установку в окно обслуживания.

~~~bash
sudo systemd-run --wait --collect \
  --unit=ustar-briefing-kanban-20261117-v2-01 \
  --property=Type=oneshot --property=TimeoutStartSec=30min \
  /usr/bin/python3 -I -B \
  /root/ustar-briefing-kanban-b1df4f2b/update.py \
  --upgrade --acknowledge-outage
~~~

~~~bash
sudo python3 -I -B \
  /root/ustar-briefing-kanban-b1df4f2b/update.py --check
~~~

Ожидается phase=active, local_ustar 2026111700, приложение и worker b1df4f2b.

Предыдущая установка b264aeb4 завершилась откатом. В этом выпуске исправлен режим каталога classes/briefing при umask 077. Прежний журнал сохраняется в /var/lib/ustar-task-briefing-20261117/state.json. Перед использованием нового журнала /var/lib/ustar-task-briefing-20261117-v2/state.json установщик проверяет, что предыдущая известная транзакция завершена откатом. При ошибке установки сохраните вывод systemd и новый журнал для разбора до следующего запуска.

## Рабочая приёмка

Следуйте [первому запуску](BRIEFING_AND_KANBAN_RU.md). Настройте переговорные и формы отделов. Проверьте отчёт руководителя и сводку администратора, личные заметки, поручение из отчёта, карточку канбана, приглашение участника, перенос и отмену встречи. Затем откройте разделы «Брифинг» и «Мои встречи» в Telegram с действующей привязки.

По умолчанию приём отчётов запланирован до 09:30 МСК, брифинг проходит в понедельник с 10:00 до 11:00. Срок сдачи и переговорная задаются администратором. Отчёты и календарь встреч находятся в Академии. Прежние формы Google переносятся через редактор по содержанию.

Приёмка на рабочем сервере ожидается.
