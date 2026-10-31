# USTAR Connect: графические карточки и цветные кнопки

Обновлено 31.10.2026 (МСК). Это worker-only обновление оформления; версия приложения
остаётся `local_ustar=2026102900`, `theme_ustar=2026102700`. Это отдельная поставка оформления, не обновление Moodle-плагина.

**Статус: исходники опубликованы в PR111. Графика и пакет прошли CI; архив побайтно
совпал с артефактом GitHub. Остальные обязательные gates ещё выполняются.**
Перед production-установкой дождаться итоговой квалификации. Агент сервер не менял.

## Что меняется

- Сводки и обычные карточки выводятся PNG: крупные значения, полосы прогресса,
  названия, сотрудники и статусы без моноширинных таблиц.
- Списки задач/заявок получают нумерацию и короткие кнопки «1 · Открыть задачу».
- Синий — открыть/перейти, зелёный — подтвердить/принять, красный — отклонить/вернуть;
  нейтральный — назад/меню. Точные оттенки и цвет текста выбирает Telegram-клиент.
- Длинные ответы и неподдерживаемые символы сохраняют прежний текстовый вариант.
  При однозначном отказе медиа отправляется текст; при timeout нет автоматического повтора.
- Старые сообщения не перерисовываются массово: после установки нажать «Меню».

Привязки, права, кадровые решения, уведомления, ACK, курсор SQLite и бизнес-история
остаются прежними. Mini App и внешний HTTPS не включаются.

Исходник worker/оператора: `5646e2de78b798056168169249c2ed764062d756`.
Архив: `ustar-connect-visual-install-5646e2de.tar.gz`, 21 382 136 байт.
SHA256: `9c9ac15c1867b56baba40e5c737755ff1982f641f7260751791ba6019ea354c1`.
Старый локальный архивcb7128fa заменён этим комплектом: используется опубликованный SHA.
Прежний контроллер9a в архиве побайтно взят из ранее проверенного комплекта
`0860a86786a6db2c3b88cc940203a4ed756b8ac372d9b21c69db53c4e03cfd4e`;
проверены его внутренние SHA256. Новые профили/ключи не генерируются.

## Проверка поставки

Локально87 тестов: 83 прошли, 4 PHP-проверки пропущены из-за отсутствия PHP в среде.
CI графики/доставки/возврата и package acceptance: PASS, job124644249261/run41237501077.
Артефакт13055504167 скачан, ZIP CRC и побайтное совпадение архива подтверждены.
PHP и код Академии в этом изменении не меняются. Проверены PNG, короткие кнопки,
цветовые стили, multipart, переходы текст/медиа, отказ/неоднозначность Telegram,
replay, read-only preflight, неизвестный drift, восстановление и process-loss.

`tests/stage/connect_visual_package.py`: PASS; SHA256, состав, ZIP CRC и реальная
кириллическая PNG из receiver.pyz при `python -I -S` без site-packages/Pillow.
Systemd/probe в локальном тесте моделируются; новая production-приёмка впереди.

## После прохождения CI: подготовка на сервере

Скопировать архив в `/home/aduk/`. Выполнять блоки по очереди.

```bash
sudo -v
sudo bash <<'ROOT'
set -euo pipefail
umask 077
install -d -o root -g root -m 700 /root/ustar-connect-visual-5646e2de
install -o root -g root -m 600 \
  /home/aduk/ustar-connect-visual-install-5646e2de.tar.gz \
  /root/ustar-connect-visual-5646e2de/package.tar.gz
cd /root/ustar-connect-visual-5646e2de
printf '%s  %s\n' \
  9c9ac15c1867b56baba40e5c737755ff1982f641f7260751791ba6019ea354c1 \
  package.tar.gz | sha256sum --check --strict -
tar --extract --gzip --file package.tar.gz --no-same-owner --no-same-permissions
sha256sum --check --strict SHA256SUMS
/usr/bin/python3 -I -B update.py --check
ROOT
```

Ожидается `ready_for_visual_update`; `host_changes:false`, `provider_called:false`.
При отказе прислать только публичный код причины; не править журналы и lock-файлы.

## Обновление worker

Кратко останавливает receiver/notify и восстанавливает прежние active/enabled-состояния.
Академия и база не останавливаются и не обновляются.

```bash
sudo systemd-run --no-block \
  --unit=ustar-connect-visual-5646e2de \
  --property=Type=oneshot --property=TimeoutStartSec=180 \
  /usr/bin/python3 -I -B -u \
  /root/ustar-connect-visual-5646e2de/update.py --upgrade
```

```bash
sudo journalctl -fu ustar-connect-visual-5646e2de.service -o cat
```

Дождаться `visuals_active` и штатного завершения службы; затем Ctrl+C прекращает только просмотр.

```bash
sudo /usr/bin/python3 -I -B \
  /root/ustar-connect-visual-5646e2de/update.py --check
```

Ожидается `visuals_ready`, `recovery_required:false`. В боте нажать «Меню», открыть
сводку задач и кадровые заявки; проверить совпадение номера на картинке и кнопке.
Длинный отчёт должен остаться доступен текстом, а старые кнопки — заново проверять права.

## Возврат и прерывание

```bash
sudo systemd-run --no-block \
  --unit=ustar-connect-visual-recover-5646e2de \
  --property=Type=oneshot --property=TimeoutStartSec=180 \
  /usr/bin/python3 -I -B -u \
  /root/ustar-connect-visual-5646e2de/update.py --recover
```

Ожидается `rolled_back`: возвращается worker5cc02944 с действующим ролевым ботом.
Только возврат оформления; приложение/БД/новые отчёты/привязки не откатываются.
После возврата возможен повторный `--upgrade`. При `recovery_required` прислать
публичный вывод и ничего не удалять вручную.

Журнал: `/var/lib/ustar-connect-bot-visual-update`. Журнал прежнего2900 не переписывается.
После `visuals_active` использовать новый `update.py --check/--recover`. Старый
контроллер9a принимает только старый worker и откажет на новом; после возврата его
исходные проверки снова применимы. Для будущего обновления учитывать обе квитанции.
