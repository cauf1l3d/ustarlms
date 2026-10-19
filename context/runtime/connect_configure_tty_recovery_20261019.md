# USTAR Connect: восстановление интерактивной настройки,19Oct18:41МСК

Владелец подтвердил на server1: archive b01bd942 SHA проверен, install вернул installed_services_not_started с exact source_commit. Затем configure вернул operation_failed до запроса токена. Настройка, запуск и live /start ACK ещё не приняты.

Причина воспроизведена: open('/dev/tty','r+') пытается открыть не поддерживающий seek терминал как BufferedRandom и выбрасывает io.UnsupportedOperation: File or stream is not seekable. Исключение безопасно скрывалось общим operation_failed. Исправление — output stream '/dev/tty','w'; getpass сам открывает controlling tty для скрытого чтения. Установленные zipapps и manifest не переписываются. Отдельный исправленный root admin использует те же validation/lock/private-file routines и создаёт только конфигурацию уже установленного receiver.

Регрессия проверяет реальный controlling PTY и CLI configure: до исправления FAIL с тем же operation_failed, после — prompt, ECHO off, exact synthetic token доставлен в configure, token отсутствует в output. Root/systemd/install-host guards в этом тесте моделируются; real /dev/tty/getpass не подменяются.11deploy tests PASS; full691r16 OK/30environment skips. Native host CI b01 не покрывал интерактивный шаг (configure вызывался напрямую); прежняя квитанция остаётся исторической и не доказывает working interactive configure. Новый PR CI отдельно, whole PR green не заявлен.

Не повторять install и не изменять admin.pyz в immutable release: это нарушит manifest. Токен уже перевыпущен, его не отправлять в чат. Фикс не меняет Moodle/БД/console/Xray/службы/пользователей или настройки сети.

Сначала sudo -v отдельно. Затем получить исправленный admin (branch URL + exact content SHA; последующие изменения branch откажут по hash, а не исполнят другой код):

```bash
sudo -n bash <<'USTAR_CONNECT_TTY_FIX'
set -Eeuo pipefail
umask 077
USTAR_CONNECT_FIX=$(mktemp /root/ustar-connect-admin.XXXXXXXX.py)
trap 'rm -f -- "$USTAR_CONNECT_FIX"' EXIT
curl -q -4 -fsS --connect-timeout 10 --max-time 60 \
  'https://raw.githubusercontent.com/cauf1l3d/ustarlms/design/ustar-connect-20261018/connect-worker/deploy/install.py' \
  -o "$USTAR_CONNECT_FIX"
printf '%s  %s\n' \
  216107c3b570b66a4c41704c750e02531cb390169c3a639f2546fdd3f2993d53 "$USTAR_CONNECT_FIX" |
  sha256sum --check --strict -
install -m 600 -o root -g root -- \
  "$USTAR_CONNECT_FIX" /root/ustar-connect-admin-tty.py
USTAR_CONNECT_TTY_FIX
```

Затем выполнить ОТДЕЛЬНО в интерактивном SSH:

```bash
sudo /usr/bin/python3 -I /root/ustar-connect-admin-tty.py configure
```

На Token @ustarcorp_bot (hidden): ввести перевыпущенный token и Enter. Ввод не отображается. Ожидается status=configured, onboarding_enabled=false, proxy=http://127.0.0.1:18081.

Только после configured:

```bash
sudo /usr/bin/python3 -I /opt/ustar-connect/current/admin.pyz start
sudo /usr/bin/python3 -I /opt/ustar-connect/current/admin.pyz status
```

Личный /start в @ustarcorp_bot завершает live acceptance. Оригинальные start/status не используют дефектный tty branch. Для будущего повторного configure этого архива также использовать исправленный root admin до следующего проверенного обновления пакета. Перезапуск сервера для исправления не нужен.
