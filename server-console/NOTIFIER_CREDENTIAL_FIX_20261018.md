# Исправление notifier LoadCredential — 18 октября 2026

Owner03:46/03:49MSK подтвердил update1f605 PASS, рабочий Xray/TLS, private challenge ID и proxy-aware setup. Бот не отвечает: notifier9 restarts/auto-restart, ValueError credential_guard alerts225/262; proxytrue/allowlist1/matching. [Receipt](../context/runtime/console_telegram_native_20261018.json). Это отказ локальной проверки credential до Bot API, не свидетельство неправильного token/ID или неисправного Xray.

Systemd254+ может выдавать root-owned credential0440 с ACL `user:service:r--`, `group::---`, `mask::r--`, `other::---`. Биты group показывают ACL mask, не разрешение чтения всей группе. Старый `mode&077` отвергал такой private файл. Поведение подтверждено [самим systemd](https://github.com/systemd/systemd/issues/29435) и воспроизведено с настоящим POSIX ACL локально. Точные mode/ACL inode на server1 ещё не присланы; они не приписываются owner measurement.

[Исправленный исходникfd46c383](https://github.com/cauf1l3d/ustarlms/commit/fd46c383c9cfba3d8155275ad1e04cae423aa082) принимает owner0400/0600 либо только narrow0440 ACL с root/currentUID read-only, нулевыми group/other и maskr--. Нет общего разрешения0440/0640. Проверка и bounded read идут через один O_NOFOLLOW/NONBLOCK FD; regular/singlelink/owner/8192 ограничения сохранены или усилены. Private source `/etc/ustar-console/telegram.json`0600 и systemd unit не меняются.

## Проверки

17 credential tests,77 focused и192 backend локально PASS. Positive actual ACL и hardlink cases были RED до fix, GREEN после. Foreign UID fixture локально ограничен UID namespace и инъецирует только отрицательный xattr; native CI использует настоящий service UID. Fresh reviewer Critical/Important0. Новый CI fixture использует installed wheel, настоящий PID1 LoadCredential/static ustar-notify/shared console group и первый worker cycle; только Bot API ответы подменены, без внешних сообщений. Exact fd46c383: все3 CI SUCCESS; console39084647257/job117548560984 дал192 backend/45 Chromium и CONSOLE_NOTIFIER_LOADCREDENTIAL=PASS с mode0o440. Логи просмотрены. [Receipt](../context/runtime/console_notifier_credential_fix_20261018.json).

## Точечная установка

Один блок под aduk; token/ID/subscription повторно не вводятся. Он скачивает только `alerts.py` из закреплённого code commit и проверяет SHA256; установленный модуль должен иметь исходный SHA release1f605 либо уже исправленный SHA. Root ownership/права всех родителей, regular/singlelink и syntax проверяются до остановки. Неизвестный локальный patch автоматически не затирается.

Прежний модуль сохраняется root-private. Замена атомарная в том же каталоге; останавливается/запускается только ustar-console-notify. При ошибке установки/команды start возвращается прежний модуль. Изменение файла другим root процессом после preflight сохраняется, исправление отказывает. Возврат systemctl start не равен доставке; остаются наблюдение за worker и ответ в Telegram.

```bash
sudo -v
sudo -n bash <<'USTAR_NOTIFY_FIX'
set -Eeuo pipefail
umask 077
USTAR_FIX_DIR=$(mktemp -d /root/ustar-notify-credential-fix.XXXXXXXX)
curl -q -fsSL --connect-timeout 10 --max-time 60 \
  https://raw.githubusercontent.com/cauf1l3d/ustarlms/fd46c383c9cfba3d8155275ad1e04cae423aa082/server-console/backend/ustar_console/alerts.py \
  -o "$USTAR_FIX_DIR/alerts.py"
printf '%s  %s\n' \
  33c706c01e70eae40fff864c95b4becfe3b88a11d63e6e3888093fe9812ed4a2 \
  "$USTAR_FIX_DIR/alerts.py" | sha256sum --check --strict -
/opt/ustar-console/venv/bin/python - "$USTAR_FIX_DIR" <<'PY'
import ast,hashlib,os,stat,subprocess,sys,tempfile
from pathlib import Path
stage=Path(sys.argv[1])
target=Path('/opt/ustar-console/venv/lib/python3.12/site-packages/ustar_console/alerts.py')
old_sha='a8d83b498afd4faae9e3aee598249aa233ea603359c27f8feba14da47c8b0227'
new_sha='33c706c01e70eae40fff864c95b4becfe3b88a11d63e6e3888093fe9812ed4a2'
for path in (target,*target.parents):
    info=path.lstat()
    expected=stat.S_ISREG if path==target else stat.S_ISDIR
    if info.st_uid!=0 or info.st_mode&0o022 or not expected(info.st_mode):
        raise SystemExit('REFUSED: untrusted installed module path')
if target.stat().st_nlink!=1:
    raise SystemExit('REFUSED: linked installed module')
previous=target.read_bytes()
if hashlib.sha256(previous).hexdigest() not in (old_sha,new_sha):
    raise SystemExit('REFUSED: installed module differs from expected release')
updated=(stage/'alerts.py').read_bytes()
assert hashlib.sha256(updated).hexdigest()==new_sha
ast.parse(updated)
backup=stage/'alerts.previous.py'
backup.write_bytes(previous)
backup.chmod(0o600)
def replace(content):
    fd,name=tempfile.mkstemp(prefix='.alerts-credential-',dir=target.parent)
    try:
        with os.fdopen(fd,'wb') as stream:
            os.fchmod(stream.fileno(),0o644)
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(name,target)
    finally:
        if os.path.exists(name): os.unlink(name)
subprocess.run(['systemctl','stop','ustar-console-notify.service'],check=True)
if target.read_bytes()!=previous:
    subprocess.run(['systemctl','start','ustar-console-notify.service'],check=False)
    raise SystemExit('REFUSED: module changed concurrently; current file preserved')
try:
    replace(updated)
    assert hashlib.sha256(target.read_bytes()).hexdigest()==new_sha
    subprocess.run(['systemctl','reset-failed','ustar-console-notify.service'],check=False)
    subprocess.run(['systemctl','start','ustar-console-notify.service'],check=True)
except Exception:
    replace(previous)
    subprocess.run(['systemctl','start','ustar-console-notify.service'],check=False)
    raise SystemExit('NOTIFIER_PATCH=FAILED; previous module restored') from None
print('NOTIFIER_PATCH=INSTALLED; receiver confirmation pending')
print('BACKUP='+str(backup))
PY
USTAR_NOTIFY_FIX
```

После `NOTIFIER_PATCH=INSTALLED` подождать15секунд и выполнить:

```bash
sudo systemctl show ustar-console-notify.service --no-pager \
  -p ActiveState -p SubState -p Result -p NRestarts
```

Затем отправить своему боту `/status` и ждать ответ. При успешном запуске ожидаются active/running и NRestarts0 после reset-failed; если уже появился restart, пришлите состояние и повторите санитизированную диагностику из чата, без raw journal/token/config. Не переустанавливать Xray/пульт и не менять права credential ради обхода guard.

Установленный bundle после этого остаётся1f605 плюс single-module alerts hotfixfd46c383, пока новый полный artifact не применён. Native получение сообщения, роли/download hash/observer/restore и домашний доступ принимаются отдельно. В начале бот может прислать накопившиеся алерты; очередь не очищается. Для `/start` и следующей команды выдержать10секунд.
