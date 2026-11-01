# USTAR Connect: восстановление Telegram и обновление карточек

Комплект `ustar-connect-visual-successor-@SOURCE8@.tar.gz` предназначен для
принятого app2900/theme2700 и worker5cc. Старый visual journal допускается только
cb7128fa/5646e2de в `rolled_back`. Это состояние подтверждено вашими логами31Oct.
Гарантировать живой Telegram по локальным тестам нельзя: сначала восстановить TLS.

Выполняйте блоки отдельно, дожидаясь приглашения терминала. Текст пояснений и
приглашение `aduk@server1:~$` в терминал не вставлять. Ссылку подписки/токены не
присылать. Журналы SQLite, cursor, locks и state.json не удалять и не править.

## 1. Вернуть ответы обоих ботов

Свежие логи: службы active, но CONNECT200 → reset → HTTP000. Это обрыв пути
через общий proxy18081, до получения HTTP-ответа Telegram. Работа веб-сайтов
не доказывает исправность этого пути. Конкретный удалённый узел/фильтр не определён.

```bash
sudo systemctl restart ustar-telegram-egress.service
```

```bash
curl --proxy http://127.0.0.1:18081 --noproxy "" --connect-timeout 5 --max-time 15 -sS -o /dev/null -w 'CONNECT=%{http_connect} HTTP=%{http_code} TLS=%{ssl_verify_result}\n' https://api.telegram.org/
```

Успех: CONNECT200, HTTP200/301/302/404, TLS0. Если снова reset/HTTP000 — используйте
входящий в комплект `refresh-egress.py` (после распаковки и SHA256SUMS из раздела2):

```bash
sudo python3 -I -B /root/ustar-connect-successor-@SOURCE8@/refresh-egress.py
```

Ввести свою действующую ссылку подписки NOVAVPS в скрытое приглашение. Это прежний
проверенный инструмент с SHA4d922d29, уже использованный21Oct. Проверяет pinned
importer/unit/config/binary, пробует альтернативные узлы на отдельных loopback
портах, переключает только egress после настоящего TLS к api.telegram.org, сохраняет
прежний конфиг. URL не сохраняет. Автоматически при upgrade он не запускается.

Успех: `NODE_N=TLS_VERIFIED`, затем `egress_refreshed`. Повторить curl выше.
При `no_working_node_original_config_preserved` рабочий узел не найден: проверить
действие подписки/доступность узлов у провайдера. При `installed_helper_version_mismatch`
не обходить проверку. Прислать только короткий код отказа, без URL/config.
Сохранить путь BACKUP локально; не удалять backup. При обрыве питания/SIGKILL
автовосстановление не гарантируется; не перебирать старые конфиги вручную.

Когда curl успешен:

```bash
sudo systemctl restart ustar-connect-receiver.service ustar-connect-notify.service ustar-console-notify.service
```

Отправить НОВОЕ `Меню` каждому боту. Старые callbacks могут быть просрочены.
История до суток простоя могла быть недоступна: receiver уже сообщает
`history_gap_possible:true`. Нельзя гарантировать доставку всех старых входящих.
Restart не очищает сохранённый backoff; receiver может подождать до5минут.

```bash
sudo journalctl -u ustar-connect-receiver.service --since '10 minutes ago' -n 15 --no-pager -o short-iso
sudo python3 -I -B -c 'import json; from pathlib import Path; p=Path("/run/ustar-console-notify/status.json"); s=json.loads(p.read_text()) if p.is_file() else {}; print(json.dumps({k:s.get(k) for k in ("status","polling","pending","delivery_failures","observed_at")}))'
```

Нужен свежий `polled`/last_poll с исчезновением transport_unavailable и новые ответы
обоих ботов. У console статус должен обновляться, polling больше не no_data; pending
может временно сохраняться. После живого ответа можно переходить к обновлению.

## 2. Проверить и распаковать новый комплект

Перенесите tar.gz и одноимённый `.sha256` в `/home/aduk`. Ожидаемый внешний SHA
также приведён в сообщении передачи. Сначала проверить его:

```bash
cd /home/aduk
sha256sum -c ustar-connect-visual-successor-@SOURCE8@.tar.gz.sha256
```

Далее открыть root shell и создать НОВЫЙ каталог. `mkdir` остановится, если он уже
существует: тогда проверить существующую поставку, не распаковывать поверх неё.

```bash
sudo -i
```

```bash
umask 077
mkdir -m 700 /root/ustar-connect-successor-@SOURCE8@
```

```bash
install -o root -g root -m 600 /home/aduk/ustar-connect-visual-successor-@SOURCE8@.tar.gz /root/ustar-connect-successor-@SOURCE8@/package.tar.gz
cd /root/ustar-connect-successor-@SOURCE8@
tar --no-same-owner -xzf package.tar.gz
sha256sum --check --strict SHA256SUMS
```

Все8 внутренних проверок — OK. Не копировать predecessor.py из других каталогов:
правильный frozen9a уже внутри. В комплекте9 файлов плюс ваша копия package.tar.gz.
Если Telegram ещё недоступен — вернуться к refresh-egress из раздела1.

## 3. Проверка без изменений и установка

Из root shell:

```bash
python3 -I -B update.py --check
```

Ожидается `ready_for_visual_update`, recovery_required:false, host_changes:false,
provider_called:false. Старый journal останется неизменным. Если он уже active либо
не завершён, будет `legacy_visual_recovery_required`: нужен штатный --recover из
ТОГО старого комплекта. Для показанного cb7128 это
`/root/ustar-connect-visual-cb7128fa/update.py --recover`; не запускайте его при
уже активном новом successor. После успешного rolled_back повторить новый --check.
При другом отказе остановиться и прислать только JSON-код.

```bash
exit
```

Запуск от обычного пользователя через отдельную временную службу; время делает
имя уникальным, `--collect` позволяет не сталкиваться с прежними loaded units:

```bash
sudo systemd-run --unit="ustar-visual-successor-$(date +%s)" --collect --wait --pipe --property=Type=exec --property=UMask=0077 --property=TimeoutStartSec=20min /usr/bin/python3 -I -B -u /root/ustar-connect-successor-@SOURCE8@/update.py --upgrade
```

Сначала идёт readonly role/TLS probe. При сетевом отказе workers не останавливаются.
Затем expected: prepared → workers_stopped → pointer_switched → visuals_active,
Process exited with status0. При rolled_back/update_succeeded:false обновление НЕ
принято; прочитать reason. При recovery_required выполнить раздел5.

## 4. Проверить результат

```bash
sudo python3 -I -B /root/ustar-connect-successor-@SOURCE8@/update.py --check
sudo readlink /opt/ustar-connect/current
sudo systemctl is-active ustar-connect-receiver.service ustar-connect-notify.service ustar-console-notify.service ustar-telegram-egress.service
```

Expected visuals_ready, recovery_required:false; pointer на новый commit и четыре
active. Отправить новое Меню, открыть задачи и кадровые заявки. Проверить цветные
кнопки, PNG, совпадение номеров; «Заявка на найм» должна открывать новую заявку.
Сокращённый список имеет явное «Показаны первые N из M», скрытые строки сохраняют
описательные кнопки. Назад/Меню и обмен задачами проверить с прежними2учётками.
Старые сообщения не перерисовываются. Цвет кнопок определяет Telegram-клиент.

Приложение2900, Moodle, профили/токены/ключи/ACK/SQLite не заменяются. Пульт этим
архивом не обновляется; он использует восстановленный общий egress.
Длинный ответ/неизвестный шрифт сохраняют исходный текстовый fallback; неопределённая
ошибка отправки не запускает опасный повтор. Повтор --upgrade — verify_only.

## 5. Штатный возврат

```bash
sudo systemd-run --unit="ustar-visual-recover-$(date +%s)" --collect --wait --pipe --property=Type=exec --property=UMask=0077 --property=TimeoutStartSec=20min /usr/bin/python3 -I -B -u /root/ustar-connect-successor-@SOURCE8@/update.py --recover
```

Expected rolled_back; pointer снова5cc, приложение/бизнес-данные сохранены. Новый
журнал находится по пути из INSTALL.json, старый cb journal сохранён побайтно, его
копия legacy-state.before.json хранится рядом с новым state.json. Сеть откат не
чинит. Проверенный baseline запускается при откате без обязательного внешнего TLS:
обработчики сами повторяют соединение после восстановления сети. Если
recovery_required сохраняется — прислать короткий reason/состояния служб,
не удалять журнал и не менять symlink вручную.
