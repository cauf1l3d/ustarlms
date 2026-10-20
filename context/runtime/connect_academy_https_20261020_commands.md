# Переключение Академии на внутренний HTTPS

**Применено владельцем20Oct19:59МСК:** check/cutover/status active;HTTPS открывается
на компьютере/телефоне/VPN. Повторно устанавливать или переключать не нужно.
[Квитанция](connect_https_owner_20261020.json). Сейчас сначала
[диагностика stale cron receipt](cron_incident_20261020.md),затем private bridge/link/notify.
Ниже сохранены проверенные команды поставки и отката.

Готово по результатам CI source `d1e61a3b96651ef6de724e2c14ac51a73f2e3d41`.
[Квитанция](connect_academy_https_ci_20261020.json),
[план](../proposals/USTAR_ACADEMY_HTTPS_CUTOVER_PLAN_20261020.md).
Это следующий шаг после принятой владельцем проверки TLS с телефона через LAN/VPN.
Повторная установка TLS probe и новый сбор прежних серверных фактов не нужны.

Рабочий адрес станет `https://ustar.local`. Apache сохранит прокси на
`127.0.0.1:8082` и существующий observability Include; HTTP будет отвечать
редиректом 302 с сохранением пути и параметров. Moodle получит стандартные
`wwwroot`, `sslproxy`, `cookiesecure`. Сохранится прежний доверенный CA.
Сертификат выдаётся на 30 дней; постоянный systemd timer проверяет его ежедневно
и продлевает при остатке менее 10 дней. Продление делает graceful reload Apache.

## 1. Установить закреплённый инструмент и проверить условия

Выполнить на server1. `sudo -v` запрашивает пароль отдельно, до блока.
Проверка сверяет конфигурации, CA, контейнер, backend и виртуальные хосты;
затем выполняет CLI bootstrap Moodle от UID33 и PHP lint. Она не меняет
конфигурации, но bootstrap может обращаться к БД и штатным Moodle-кешам.
Весь config.php, ключи и токены присылать не нужно.

```bash
sudo -v
sudo -n bash <<'USTAR_HTTPS_INSTALL'
set -Eeuo pipefail
umask 077
ustar_https_tmp=$(mktemp /root/ustar-academy-https.XXXXXXXX.py)
trap 'rm -f -- "$ustar_https_tmp"' EXIT
ustar_https_dst=/usr/local/sbin/USTAR_ACADEMY_HTTPS_20261020.py
ustar_https_sha=42550fb1ab769dbcad7b604fe5e0619f6c7ac69fa5cbac46c624924f6895aa6e
curl -q -4 -fsSL --connect-timeout 10 --max-time 60 \
  https://raw.githubusercontent.com/cauf1l3d/ustarlms/d1e61a3b96651ef6de724e2c14ac51a73f2e3d41/scripts/infra/academy_https.py \
  -o "$ustar_https_tmp"
printf '%s  %s\n' "$ustar_https_sha" "$ustar_https_tmp" | sha256sum --check --strict -
test ! -L "$ustar_https_dst"
if [ -e "$ustar_https_dst" ]; then
  printf '%s  %s\n' "$ustar_https_sha" "$ustar_https_dst" | sha256sum --check --strict -
else
  install -o root -g root -m 600 "$ustar_https_tmp" "$ustar_https_dst"
fi
/usr/bin/python3 -I -B "$ustar_https_dst" --check
USTAR_HTTPS_INSTALL
```

Продолжать только при `"phase": "ready_for_HTTPS_cutover"`.
При `refused` остановиться и прислать этот JSON. Не снимать guards вручную.
Установленный helper первого TLS-хоста должен оставаться прежним:
`/usr/local/sbin/USTAR_ACADEMY_TLS_PROBE_20261020.py`, SHA256
`7b5fd3a62c3b9d9c72bcaf513d96777bf0a00926bcba91ec3ac1740896c78bd7`.

## 2. Переключить в короткое окно без активной работы пользователей

Команда повторяет предварительные проверки и сохраняет приватные исходные
конфигурации, права и xattrs в `/var/lib/ustar-academy-https/` (root0700).
Далее согласованно меняет config.php, HTTP/TLS vhosts и проверочную страницу,
очищает Moodle-кеши и выполняет graceful reload Apache. Проверяет настоящий
HTTPS login, Secure-cookie, HTTP redirect, прежний Include и общие службы,
устанавливает timer и запускает его service для проверки. При штатно обработанной
ошибке выполняется автоматический откат принадлежащих операции конфигураций.

```bash
sudo /usr/bin/python3 -I -B /usr/local/sbin/USTAR_ACADEMY_HTTPS_20261020.py \
  --cutover --acknowledge-outage
```

Ожидается `"phase": "active"`, `"academy_origin": "https://ustar.local"`.
Записать публичный fingerprint и срок leaf из результата.

```bash
sudo /usr/bin/python3 -I -B /usr/local/sbin/USTAR_ACADEMY_HTTPS_20261020.py --status
sudo systemctl show ustar-academy-cert-renew.timer ustar-academy-cert-renew.service \
  -p Id -p ActiveState -p SubState -p Result -p ExecMainStatus --no-pager
```

Timer должен быть active/waiting. Service после успешного oneshot обычно
inactive/dead с Result=success; это штатное состояние. Проверка `--status`
повторно проверяет приложение и может создать обычную Moodle session/access-log.

## 3. Принять в браузере

Открыть `https://ustar.local/login/index.php` с рабочего компьютера и телефона
через прежний LAN/VPN, без исключения сертификата. Проверить вход, навигацию,
открытие существующего материала/файла и возврат в кабинет; при наличии курса —
Quiz/SCORM. Проверить, что `http://ustar.local/login/index.php` переводит на HTTPS.
Прислать публичные JSON check/cutover/status, timer/service summary и результат
браузерных действий. CI не заменяет эту приёмку server1.

## Откат при проблеме приложения или незавершённой операции

```bash
sudo /usr/bin/python3 -I -B /usr/local/sbin/USTAR_ACADEMY_HTTPS_20261020.py --rollback
```

Ожидается `"phase": "rolled_back"`: Академия снова на HTTP, прежняя статическая
TLS-страница восстановлена, новый renewal timer снят. Откат восстанавливает только
четыре конфигурационных файла; бизнес-данные и БД не восстанавливаются из бэкапа.
При изменениях администратора в управляемых файлах инструмент откажется их
затирать. При `refused` прислать JSON, не заменять config.php или vhosts вручную.
Приватные before-images и state.json не публиковать и не удалять.

## Что остаётся после этого шага

Приёмка server1 ещё ожидается. Следом — private TLS bridge и scoped keys,
поставка уже проверенного chat-link candidate2026102000 с соответствующим
installer/recovery, двусторонняя привязка/revoke и opt-in уведомления.
Старый Academy installer02b0548c закреплён на payload830/local2026101905 и не
устанавливает chat-link candidate. Mini App по-прежнему требует отдельного
внешнего HTTPS. Этот LAN cutover не меняет router/DNS/routes/Caddy/Xray/receiver
и не открывает порты наружу.
