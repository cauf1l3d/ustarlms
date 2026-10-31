# USTAR Connect: графические карточки и цветные кнопки

Подготовлено 30.10.2026 (МСК). Это worker-only обновление оформления; версия приложения
остаётся `local_ustar=2026102900`, `theme_ustar=2026102700`. Это отдельная поставка оформления, не обновление Moodle-плагина.

**Статус: локально проверенный кандидат. CI нового оформления ещё не запускался:**
автоматическая проверка отклонила push в GitHub. Перед production-установкой требуется
публикация ветки и проверка CI согласно AGENTS.md. Production не изменён агентом.

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

Исходник worker/оператора: `cb7128faa07629182aa5f1ada3a11299ac36cf24`.
Архив: `ustar-connect-visual-install-cb7128fa.tar.gz`, 21 382 136 байт.
SHA256: `d10b39e36fbf8ff79e56cf0ddb4f344cd9ce7f7bac39b521098f1dd867ce3cb4`.
Прежний контроллер9a в архиве побайтно взят из ранее проверенного комплекта
`0860a86786a6db2c3b88cc940203a4ed756b8ac372d9b21c69db53c4e03cfd4e`;
проверены его внутренние SHA256. Новые профили/ключи не генерируются.

## Проверено локально

87 тестов: 83 прошли, 4 PHP-проверки пропущены из-за отсутствия PHP в среде.
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
install -d -o root -g root -m 700 /root/ustar-connect-visual-cb7128fa
install -o root -g root -m 600 \
  /home/aduk/ustar-connect-visual-install-cb7128fa.tar.gz \
  /root/ustar-connect-visual-cb7128fa/package.tar.gz
cd /root/ustar-connect-visual-cb7128fa
printf '%s  %s\n' \
  d10b39e36fbf8ff79e56cf0ddb4f344cd9ce7f7bac39b521098f1dd867ce3cb4 \
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
  --unit=ustar-connect-visual-cb7128fa \
  --property=Type=oneshot --property=TimeoutStartSec=180 \
  /usr/bin/python3 -I -B -u \
  /root/ustar-connect-visual-cb7128fa/update.py --upgrade
```

```bash
sudo journalctl -fu ustar-connect-visual-cb7128fa.service -o cat
```

Дождаться `visuals_active` и штатного завершения службы; затем Ctrl+C прекращает только просмотр.

```bash
sudo /usr/bin/python3 -I -B \
  /root/ustar-connect-visual-cb7128fa/update.py --check
```

Ожидается `visuals_ready`, `recovery_required:false`. В боте нажать «Меню», открыть
сводку задач и кадровые заявки; проверить совпадение номера на картинке и кнопке.
Длинный отчёт должен остаться доступен текстом, а старые кнопки — заново проверять права.

## Возврат и прерывание

```bash
sudo systemd-run --no-block \
  --unit=ustar-connect-visual-recover-cb7128fa \
  --property=Type=oneshot --property=TimeoutStartSec=180 \
  /usr/bin/python3 -I -B -u \
  /root/ustar-connect-visual-cb7128fa/update.py --recover
```

Ожидается `rolled_back`: возвращается worker5cc02944 с действующим ролевым ботом.
Только возврат оформления; приложение/БД/новые отчёты/привязки не откатываются.
После возврата возможен повторный `--upgrade`. При `recovery_required` прислать
публичный вывод и ничего не удалять вручную.

Журнал: `/var/lib/ustar-connect-bot-visual-update`. Журнал прежнего2900 не переписывается.
После `visuals_active` использовать новый `update.py --check/--recover`. Старый
контроллер9a принимает только старый worker и откажет на новом; после возврата его
исходные проверки снова применимы. Для будущего обновления учитывать обе квитанции.
