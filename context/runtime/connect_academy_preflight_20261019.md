# USTAR Connect: следующий этап после первого запуска бота

Владелец19Oct18:59МСК подтвердил работающий @ustarcorp_bot. [Квитанция](connect_receiver_server1_20261019.json). Первый receiver остаётся sourceb01bd942; Academy main/production пока e1d57f5b526files/93tables. Следующий этап — подготовка отдельного обновления Академии и приватной связи Connect. Mini App по решению владельца откладывается до HTTPS.

## Что подготовлено

Source **ef9911f31a57dd30554eb841bfa1bc601443dfd6**, отдельный read-only bundle **ustar-connect-preflight-ef9911f3.pyz**,66561байт, SHA256 **da6a992d3cdfe60b294df0452c0fa8c1eff7ae9eaedeaf8151289f857c65becb**. [План/ограничения](../proposals/USTAR_CONNECT_ACADEMY_PREPARATION_20261019.md). Это сборщик фактов, не installer или разрешение на обновление.

Он статически читает текущие версии и hashes local_ustar/theme_ustar; определяет единственный web root из /opt/ustar/data/moodle/public и вложенного public; проверяет fixed container bind mounts и running-state; выполняет ограниченный PostgreSQL SELECT версий/каталога таблиц в BEGIN READ ONLY с timeouts и ROLLBACK. Содержимое config.php не выводится; PHP/Moodle bootstrap отсутствует. Нет user records/паролей/токенов или Config.Env. Читается только безопасная выжимка existing backup success receipt: это не проверка самого архива/восстановления/RTO.

В bundle зафиксированы actual Git fingerprints: accepted526/93/local2026100204/theme2026100202 и candidate552/99/local2026101905. Между ними515matching/11changed/26added/0removed. Сам сборщик не меняет код, конфигурацию, БД, роли, службы, Xray, DNS или маршруты. Plan mode не запускает host commands; --check явный root/Python312, deadline90секунд. Снимок не атомарный; drift/unknown требует рассмотрения, не автоматического repair.

## Команды владельцу

Скачать переданный bundle и положить WinSCP в /home/aduk/ustar-connect-preflight-ef9911f3.pyz. Не распаковывать. Сначала отдельно:

```bash
sudo -v
```

Затем вставить один блок и дождаться полного JSON. Он копирует bundle в root-private временный файл, проверяет checksum ДО выполнения и удаляет эту временную копию при завершении. stdout остаётся в терминале; приложение и служба бота не останавливаются.

```bash
sudo -n bash <<'USTAR_CONNECT_PREFLIGHT'
set -Eeuo pipefail
umask 077
USTAR_CONNECT_CHECK=$(mktemp /root/ustar-connect-preflight.XXXXXXXX.pyz)
trap 'rm -f -- "$USTAR_CONNECT_CHECK"' EXIT
install -m 600 -o root -g root -- \
  /home/aduk/ustar-connect-preflight-ef9911f3.pyz "$USTAR_CONNECT_CHECK"
printf '%s  %s\n' \
  da6a992d3cdfe60b294df0452c0fa8c1eff7ae9eaedeaf8151289f857c65becb \
  "$USTAR_CONNECT_CHECK" | sha256sum --check --strict -
/usr/bin/python3 -I "$USTAR_CONNECT_CHECK" --check
USTAR_CONNECT_PREFLIGHT
```

Передать полный JSON. baseline_measured означает только совпадение измеренных source/DB с прежним baseline; deployment_approved всегда false. review_required/refused не исправлять командами chmod/chown/reset или повторной установкой: сначала разобрать полученный reason/section. DB/containers/backup unavailable сохраняются unknown. Если общее --check заняло90секунд и refused probe_deadline — зафиксировать это, не увеличивать автоматически нагрузку.

## Порядок продолжения

1. По свежему отчёту подготовить exact candidate release и reviewed installer для фактического Moodle518 layout; текущие старые PR71/Moodle core patch installers не использовать как универсальную установку Connect.
2. Закрыть полный RC/schema fresh/upgrade/repeat и paired rollback; перед production mutation нужен свежий согласованный backup/восстановимый комплект. Передача архива сисадмину и синтетические CI не доказывают clean-host restore.
3. После отдельной приёмки обновить plugin/theme без смены Moodle/PG platform; bridge/provider-read/notification flags остаются явно управляемыми. Приватный HTTPS listener и scoped service keys отдельны от внешнего origin, console identity/token не Academy link.
4. После получения HTTPS принять Mini App/BFF и корректный signed provider link; затем одна личная owner account link и ограниченная проверка notifications. В текущем сценарии первая настоящая привязка через Mini App ещё ждёт HTTPS; массовой отправки накопленного backlog нет. Native registration использует canonical signup/HRD approval, никакого пароля в Telegram.
5. Полный native Academy handoff/recovery/MFA/step-up, object role screens и дополнительные каналы — следующие самостоятельные реализации/приёмки. Работающий notice bot их не включает.

## Проверено / что ещё не проверено

13targeted real-files/parser/CLI/bundle tests PASS, full708r16 OK/30environment skips. Тестовый Git repository доказал игнорирование dirty source и byte determinism; actual production-sized zip CRC и3Python sources byte-exact Git PASS;1078file references совпали с accepted/candidate manifests. Packaged --help/plan_only PASS. Общий CI исходного commit: Context39283463591/Review39283463554 последним наблюдался queued; whole PR green/merge не заявлен. Native Docker/SQL на server1 данным collector ещё не запускался. Личный review автора,0subagents по требованию владельца. Никаких native изменений или реальных Bot API сообщений агентом.
