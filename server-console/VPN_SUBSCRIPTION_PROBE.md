# Проверка формата подписки NOVAVPS

Владелец сообщил о VPN-подписке в Happ и показал только замаскированную HTTPS-ссылку на novavps.app с путём /s/. Полная ссылка не получена агентом. Формат HTTPS не определяет содержимое ответа: это может быть список URI, Base64, JSON, HTML или зашифрованные данные. Публичная документация NOVAVPS по этому контракту не подтверждена.

`tools/subscription_probe.py` — отдельная проверка на стандартной библиотеке Python3.12. Её можно запустить обычным пользователем aduk до обновления установленного wheel. Команда с exact published commit и SHA256 публикуется в PR103 и диалоге. После проверки checksum:

```bash
timeout --kill-after=2s 40s python3 -B /verified/path/subscription_probe.py </dev/tty
```

На `Subscription URL (hidden):` владелец вводит полную ссылку локально. URL не передаётся через argv, shell history или environment. Не присылать ссылку/QR, raw subscription body или server profile в чат/GitHub.

Проверка выполняет только HTTPS GET к novavps.app, по умолчанию напрямую с проверкой системных CA. Начальная ссылка обязана иметь HTTPS/443 и путь /s/; пользователь/пароль в authority, fragment, control characters и другой host отклоняются. Максимум три запроса с redirect только внутри того же HTTPS origin, восемь секунд на сетевую операцию, общий CLI deadline30s, максимум1MiB тела и512 просмотренных узлов. Ответы не исполняются и не сохраняются. VPN-клиент, службы, маршруты, DNS, firewall, настройки пульта и Telegram не устанавливаются этой проверкой.

JSON-вывод содержит только фиксированные метки формата, количество просмотренных узлов, протоколы/транспорт/TLS из allowlist и счётчик обнаруженных insecure TLS flags. Адреса/имена узлов, UUID, ключи, query values и response/error bodies не выводятся; неизвестные значения заменяются меткой other. Неожиданные исключения тоже подавляют секретный контекст. Скрипт распознаёт plain/Base64 URI, VMess payload metadata и JSON outbounds, отдельно HTML/Happ encrypted/invalid JSON.

`result=inspected` означает завершение классификации ответа, не валидность конфигурации, успешное VPN-соединение или Telegram delivery. `node_values_validated=false` и `configuration_installed=false` сохраняют эту границу. Ответ относится к User-Agent USTAR-Subscription-Probe/1.0; провайдер может выдавать разные форматы разным клиентам. HTML_page/JSON_other/Happ_encrypted потребуют штатного экспорта или инструкции провайдера, а не автоматической дешифровки/исполнения. По безопасной сводке отдельно выбирается server client и локальный proxy только для Bot API. Приватный доступ к пульту из дома остаётся отдельной задачей.

Проверки:22 сценария secret-redaction/origin/redirect/error/size/node-limit и всего147 backend tests локально PASS. Реальная подписка/её ответ агентом не получены; native запуск и настройка VPN ожидаются. Поставка console3305ecc9 остаётся последним проверенным исполняемым пакетом для owner update; этот standalone tool не требует повторного полного setup.

Источники по форматам и планируемому локальному proxy: [Happ](https://www.happ.su/main/ru/faq/adding-configuration-subscription), [Xray HTTP inbound](https://xtls.github.io/en/config/inbounds/http.html). Они не доказывают конкретный контракт NOVAVPS или работоспособность подписки владельца.
