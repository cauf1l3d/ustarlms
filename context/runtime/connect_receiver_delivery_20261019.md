# USTAR Connect: первый запуск Telegram-бота без Mini App

Source b01bd942f7f748d9510618a79489643b42cbd690. Комплект предназначен для Ubuntu24.04/Python3.12/systemd на server1. Он устанавливает отдельный receiver @ustarcorp_bot через существующий HTTP proxy127.0.0.1:18081. Mini App отключён до HTTPS; обновления Moodle, bridge и миграции БД здесь нет. Notify-служба подготовлена и выключена. C4fd9eea80 остаётся завершённой разработкой, но не native deployment.

Проверено на GitHub Ubuntu24.04: actual systemd/UID/LoadCredential/SQLite и install/reinstall/update/rollback, job118145618545 PASS. Контекст39277324228 SUCCESS. Source8.2/8.3 logs подтверждают690r16/133skips и60mobile/20onboarding/24link/100actor scenarios PASS. Общий review39277324207 ждёт очереди native Moodle regression; полного PR green/merge не заявляем. Этот standalone receiver не устанавливает Academy/schema; его acceptance закрыта отдельным host job. Локально690r16 OK/30environment skips. [Artifact12197369243](https://github.com/cauf1l3d/ustarlms/actions/runs/39277324207/artifacts/12197369243) скачан: ZIP38429байт/SHA6804d5a23f946124574beff8db6fac54fc13fd0af4e854d49d392163cc42a880/CRC, tar32786байт/SHA674b05ae4f418ed5caa3582c6c071a036c77d647667fce6a7f90e6b232a72332,15Python modules в обоих zipapps +3units/admin source byte-exact Git PASS. Local и CI tar совпадают побайтно. Это синтетический provider и реальный disposable host, не приёмка server1.

1. Скачать `ustar-connect-receiver-b01bd942.tar.gz` из предоставленного комплекта/проверенного artifact, загрузить через WinSCP в `/home/aduk/`. Не распаковывать вручную. Секретов в архиве нет.
2. В SSH server1 под aduk сначала отдельно обновить sudo:

```bash
sudo -v
```

Затем вставить весь блок. Он копирует архив в root-private каталог, проверяет полный SHA256 и извлекает только встроенный admin.pyz из уже проверенного архива. Установка не отправляет сообщения и не запускает службы.

```bash
sudo -n bash <<'USTAR_CONNECT_INSTALL'
set -Eeuo pipefail
umask 077
USTAR_CONNECT_DIR=$(mktemp -d /root/ustar-connect-setup.XXXXXXXX)
install -m 600 -o root -g root \
  /home/aduk/ustar-connect-receiver-b01bd942.tar.gz \
  "$USTAR_CONNECT_DIR/receiver.tar.gz"
printf '%s  %s\n' \
  674b05ae4f418ed5caa3582c6c071a036c77d647667fce6a7f90e6b232a72332 "$USTAR_CONNECT_DIR/receiver.tar.gz" |
  sha256sum --check --strict -
tar --extract --gzip --no-same-owner --no-same-permissions \
  --file "$USTAR_CONNECT_DIR/receiver.tar.gz" \
  --directory "$USTAR_CONNECT_DIR" admin.pyz
/usr/bin/python3 -I "$USTAR_CONNECT_DIR/admin.pyz" install \
  --archive "$USTAR_CONNECT_DIR/receiver.tar.gz" \
  --sha256 674b05ae4f418ed5caa3582c6c071a036c77d647667fce6a7f90e6b232a72332
USTAR_CONNECT_INSTALL
```

Ожидается `installed_services_not_started`, source_commit как выше. Никаких pip/apt, остановки Academy/console или смены сети этот installer не делает. Чужие units/drop-ins и недоверенные владельцы/права не исправляются молча.

3. Приватно ввести уже перевыпущенный токен. Команда выполняется отдельно в интерактивном SSH, без heredoc. Ввод скрыт, токен не передаётся аргументом или переменной окружения.

```bash
sudo /usr/bin/python3 -I /opt/ustar-connect/current/admin.pyz configure
```

На `Token @ustarcorp_bot (hidden):` вставить новый токен и Enter. Ожидается `configured`, onboarding_enabled=false, proxy127.0.0.1:18081. Токен не присылать в чат. Файлы /etc/ustar-connect root-only0700/0600; systemd передаёт их service через LoadCredential с проверенными root-owned read-only ACL.

4. Проверить Telegram и запустить только receiver с автозапуском:

```bash
sudo /usr/bin/python3 -I /opt/ustar-connect/current/admin.pyz start
sudo /usr/bin/python3 -I /opt/ustar-connect/current/admin.pyz status
```

Start сначала делает read-only getMe/getWebhookInfo, проверяет точное имя/ID и отсутствие webhook, затем enable/start receiver. Проверка может занять до45секунд. getUpdates/отправка сообщений начинают работать только в receiver. Existing webhook/неверный bot/proxy/TLS отказ не удаляется/не обходится автоматически.

Ожидается receiver ActiveState=active/SubState=running. Probe после успешного завершения inactive/dead/Result=success — это штатно. Notify inactive — это штатно, его не включать этим этапом.

5. Открыть https://t.me/ustarcorp_bot и отправить `/start` в личный чат. Ожидаемый ответ: «Бот Академии USTAR подключён. Mini App пока не подключён…». Это первый receiver launch, а не регистрация/учебный вход. `/academy` — тот же режим; повторные /start,/academy ограничены одним ответом в минуту на пользователя. `/help` доступен отдельно. Пароли в бот не отправлять.

При отказе сохранить статус и последние безопасные журналы:

```bash
sudo journalctl -u ustar-connect-probe.service -u ustar-connect-receiver.service \
  -n 50 --no-pager -o cat
```

Дополнительная read-only проверка без polling/send:

```bash
sudo /usr/bin/python3 -I /opt/ustar-connect/current/admin.pyz probe
```

Остановка с отключением автозапуска:

```bash
sudo /usr/bin/python3 -I /opt/ustar-connect/current/admin.pyz stop
```

Возврат предыдущего worker возможен только после последующего обновления, когда сохранена previous release. При первой установке предыдущей версии нет — используется stop. Credentials и SQLite cursor не откатываются, чтобы не проигрывать сообщения повторно.

```bash
sudo /usr/bin/python3 -I /opt/ustar-connect/current/admin.pyz rollback
```

Пути: immutable code /opt/ustar-connect/releases/SOURCE; current и previous.json под root; private config /etc/ustar-connect; receiver transport receipts/cursor /var/lib/ustar-connect-receiver/receiver.sqlite3. Записи не содержат текстов сообщений и паролей. Старый operational console bot и его token/account bindings не импортируются.

После HTTPS: отдельно обновить/принять Academy candidate, настроить private bridge и keys с explicit operation allowlists, Mini App/BFF и затем notification sender. Existing notification key допускает только notification.lease, notification.begin, notification.finish; credentials/CA и schema acceptance отдельные. Привязки аккаунтов пульта не являются Academy links. Включение уведомлений не должно воспроизводить прежние disabled/pending доставки без приёмки.

Native server1 install, исходящий Telegram ACK и boot/restart владельцем ещё предстоят. CI подтверждает synthetic transport и изоляцию/установку на disposable Ubuntu, не сетевую доступность server1 или доставку вашему аккаунту.
