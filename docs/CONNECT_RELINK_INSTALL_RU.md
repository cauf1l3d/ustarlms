# Повторная привязка Telegram · 11.11.2026

Пакет `ustar-connect-relink-dc3a3c54.tar.gz` исправляет повторную привязку после отвязки в Академии и в Telegram. Он проверяет исходники Preview `2cb233d9` и активный журнал Preview. Установка кратко перезапускает веб-контейнер и очищает кэши Moodle.

Перенесите архив в `/home/aduk/` и выполните на server1.

```bash
sudo bash <<'ROOT'
set -euo pipefail
umask 077
ustar_relink_archive=/home/aduk/ustar-connect-relink-dc3a3c54.tar.gz
ustar_relink_dir=/root/ustar-connect-relink-dc3a3c54

printf '%s  %s\n' \
  d8e03129ba8b9864534b6c6f75fbb12432997b49281a3aa5864a21b09ca1f784 \
  "$ustar_relink_archive" | sha256sum --check --strict -
test ! -L "$ustar_relink_dir"
install -d -o root -g root -m 700 "$ustar_relink_dir"
tar --no-same-owner -xzf "$ustar_relink_archive" -C "$ustar_relink_dir"
cd "$ustar_relink_dir"
sha256sum --check --strict SHA256SUMS
python3 -I -B update.py --check

systemd-run --no-block --unit=ustar-connect-relink-20261111-01 \
  --property=Type=oneshot --property=TimeoutStartSec=240 \
  /usr/bin/python3 -I -B "$ustar_relink_dir/update.py" \
  --upgrade --acknowledge-outage
ROOT
```

После завершения проверьте результат.

```bash
sudo systemctl show ustar-connect-relink-20261111-01.service -p ActiveState -p SubState -p Result -p ExecMainStatus
sudo journalctl -u ustar-connect-relink-20261111-01.service -n 40 --no-pager
sudo python3 -I -B /root/ustar-connect-relink-dc3a3c54/update.py --check
```

Ожидаются `Result=success`, `ExecMainStatus=0` и `phase=active`. У завершённого oneshot состояние `inactive/dead` штатно. При предварительном `refused` блок остановится до перезапуска. Сохраните причину отказа из вывода.

В «Личный кабинет → Мессенджеры → Мои подключения» нажмите «Отвязать Telegram», если старая связь активна. Создайте новую персональную ссылку и откройте её в нужном Telegram. В том же браузере Академии сверьте Telegram ID и подтвердите связь. В новом Telegram отправьте боту `/start` и откройте профиль. Прежняя запись останется со статусом «Связь отозвана».

Если установщик после прерывания сообщает `recovery_required`, используйте восстановление исходников этого пакета.

```bash
sudo python3 -I -B /root/ustar-connect-relink-dc3a3c54/update.py --recover --acknowledge-outage
```

Пакет Библиотеки `f374d2d9` рассчитан на исходники до Telegram-исправления. После этого обновления потребуется новый пакет Библиотеки с учётом Connect.
