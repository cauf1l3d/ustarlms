# SSH: отложено владельцем до подготовки внешнего доступа

**Актуальное решение25Oct:** текущий вход по логину/паролю внутри LAN работает и
принят владельцем. SSH/key/firewall hardening откладывается до подготовки открытия
Академии наружу. Windows key/agent диагностику ниже сейчас не выполнять, ключи не
создавать/не устанавливать. Не возвращать этот пункт как blocker локальной работы.
Это deferral, не успешная проверка key login или внешней безопасности. Исторический
отказ остаётся в receipt; его причину сейчас не расследуем.

Следующий локальный шаг: [Docker origin metadata](infra_docker_origin_20261025.md).
Все команды и указания «следующий шаг» ниже сохранены только как история.

**Owner25Oct: первый workstation publickey-only вызов отказал.** Windows CMD
C:\Users\User>, ответ: `aduk@192.168.1.60: Permission denied (publickey,password).`
Exit code владелец не сообщил. SSH endpoint отвечает, но offered identity/client
agent/server-side authorization из этой строки не видны. Не считать, что ключа нет,
что сервер отклонил конкретный ключ или что пароль был испробован этой командой.
Текущий server1 session сохраняется; политика сервера не менялась.

## Текущий следующий шаг — в том же Windows CMD

Первый обычный вызов ниже сохранён как история, повторять его без verbose не надо.
Выполнить две команды: первая выводит только имена файлов в клиентском .ssh,
вторая — диагностические строки одного нового key-only соединения. Ключи/config
не читать и не присылать; файлы не создаются и не перезаписываются. Если ключ
находится в другом клиенте/каталоге или имеет нестандартное имя, это ещё не ошибка
его регистрации на сервере. `-v`/выбранные строки нужны, чтобы отличить отсутствие
предложенного identity от отказа сервера или ошибки локального signing agent.

```cmd
dir /b "%USERPROFILE%\.ssh"
ssh -v -o ControlPath=none -o ControlMaster=no -o ClearAllForwardings=yes -o PreferredAuthentications=publickey -o PubkeyAuthentication=yes -o PasswordAuthentication=no -o KbdInteractiveAuthentication=no aduk@192.168.1.60 whoami 2>&1 | findstr /I /C:"identity file" /C:"Will attempt key" /C:"Offering public key" /C:"Server accepts key" /C:"Trying private key" /C:"agent" /C:"Load key" /C:"sign_and_send_pubkey" /C:"signature algorithm" /C:"no such identity" /C:"Permission denied" /C:"Authenticated to"
```

Вернуть вывод обеих команд. Full debug/keys/authorized_keys contents не запрашиваются.
Windows CMD/findstr native runtime агенту не доступен; фильтр проверен по Microsoft
syntax, OpenSSH argv/options отдельно offline. Ни одного agent SSH connection.

Primary references: [OpenSSH verbose mode](https://man.openbsd.org/ssh.1),
[Windows findstr](https://learn.microsoft.com/en-us/windows-server/administration/windows-commands/findstr),
[Windows key management](https://learn.microsoft.com/en-us/windows-server/administration/openssh/openssh_keymanagement).

Caddy metadata от владельца уже получены. На IPv4 127.0.0.1:2019 отвечает
локальный config API; отдельный HTTP server слушает :2019 (observed IPv6 wildcard),
в проекции только static_response, proxy_dials пуст. Unit caddy.service/PID862,
User/Group caddy, /etc/caddy/Caddyfile, без --resume. Moodle опубликован только
127.0.0.1:8082; PostgreSQL5432 не опубликован. Показанная wildcard запись сама
по себе не является доказательством открытого наружу admin API. Не менять Caddy
лишь по номеру порта. Источник/потребители статического endpoint и shared-host
policy отдельно не проверены; это не закрытие всей сетевой безопасности.
[Owner receipt](infra_closure_followup_owner_20261025.json#port2019).

## Действие владельца на своём компьютере

Сохранить текущую server1-сессию. В новом локальном Terminal/PowerShell выполнить
одну строку ниже. Это LAN-адрес server1 из принятой инвентаризации; если текущее
подключение идёт через другой адрес, использовать свой действующий SSH host/alias
вместо 192.168.1.60. Не выполнять эту проверку из shell aduk@server1: локальный
серверный private key или self-login не докажет доступ с рабочего компьютера.

```bash
ssh -o ControlPath=none -o ControlMaster=no -o ClearAllForwardings=yes -o PreferredAuthentications=publickey -o PubkeyAuthentication=yes -o PasswordAuthentication=no -o KbdInteractiveAuthentication=no aduk@192.168.1.60 whoami
```

ControlPath=none/ControlMaster=no исключают повторное использование уже
аутентифицированного multiplex connection; ClearAllForwardings=yes исключает
унаследованные port forwards. В новом соединении предлагается publickey, пароль
сервера и keyboard-interactive выключены только для этого вызова клиента.
Пароль локального private key может потребоваться; его не присылать.
Настройки сервера, authorized_keys, firewall и пароли эта команда не меняет.

Вернуть stdout/ошибку и указать, что запуск был со своего компьютера. Успешный
exit0 и ответ aduk засчитываются как отдельное подключение обычной учётной записи
по ключу, не как проверка emergency console/sudo/off-LAN access. Если нет подходящего
ключа, connection refused, host-key mismatch или publickey отказ, серверные пароли
не отключать; следующая операция зависит от конкретного вывода. Ключ не создавать
и не перезаписывать наугад. Agent не выполняет SSH connection за владельца.

## Границы следующего изменения

Это prerequisite для изменения SSH policy, не автоматическое разрешение выключить
password authentication во всех shared-host аккаунтах. Нужны emergency path и
потребители ISPConfig/mail/DNS/AutoReport/forwarding; blanket UFW/SSH changes не
подготовлены. Docker rotation/health остаётся отдельным coordinated frozen-ID/
recovery handoff. UPS presence принята, external restore по owner instruction
остаётся unknown/copies handed over; не переспрашивать и не повторять старые stands.

Primary reference checked25Oct: [OpenSSH client options](https://man.openbsd.org/ssh_config.5).
Проверка parser options локальная/offline; реальный workstation login ещё pending.
