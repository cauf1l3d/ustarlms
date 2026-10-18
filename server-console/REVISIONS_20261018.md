# Доработка пульта после повторной проверки владельцем

Запрос:18 октября2026,17:03МСК. Исходная ветка PR103,7f8a614a. Предыдущее сообщение14:42 «работает» не является окончательной приёмкой: владелец явно вернул HTTPS/Mini App/UI/источники на доработку. Датированный receipt сохраняется как история и больше не задаёт следующий шаг.

## Согласованный результат

1. Светлая/тёмная/системная тема с сохранением выбора и читаемостью всех полей/диалогов/графиков; графики раскрываются крупнее.
2. «Скрипты»: каталог управляемых серверных helper, назначение/эффект/успех/source SHA; отдельно измеренный installed inventory. Кнопки только для существующих четырёх broker actions с прежними password/TOTP-if-enrolled/role/CSRF/idempotency checks. Остальные скрипты не превращаются в root shell.
3. «Документация»: упорядоченные проверенные документы GitHub в составе того же выпуска, полнотекстовый поиск и безопасное чтение. Source commit и время сборки видимы; это versioned copy, не обещание live GitHub sync.
4. Реальные request p95/RPS/4xx/5xx за5мин из отдельного минимального Apache log, исключая собственный HEAD probe. Только timestamp/status/duration; ни URL/IP/query/cookie. Root-owned bounded reader, конфигурация только явной owner-командой с backup/configtest/reload/rollback.
5. Очищенные события фиксированных служб: сервер классифицирует локально, в web попадают только время/служба/категория/количество. Сырые строки и секреты не передаются.
6. HTTPS: локальная проверка chain/SAN/срока/live fingerprint, корректная публичная копия CA и инструкции Windows/iOS. На скриншоте обход предупреждения Chrome, а не доказательство client trust. CA не меняется автоматически. Доступ через телефонный VPN проверяется отдельно от сертификата; private192.168.1.60 не доступен из домашней/мобильной сети без собственного маршрута/relay.

## Выполнение и доказательства

- [x] Backend baseline и новые regression RED→GREEN.
- [x] Theme/zoom и knowledge browser scenarios desktop/tablet/mobile, contrast>=4.5.
- [x] Request/log reader malformed/stale/rotation/partial/missing/privacy/size tests; installer fixture rollback.
- [x] TLS diagnostic tests; без native server доступа результат server1 остаётся pending.
- [ ] Full build/tests/CI/native fixture/artifact SHA и сохранение существующих boundaries.
- [ ] GitHub STATE/ACTIVE/BACKLOG/SESSION_LOG и generated context актуальны; команды выдаются также в чате.

## Границы

Никаких изменений Academy platform, default routes, DNS, firewall, VPN, CA root, пользователей/паролей/TOTP и production данных. Установка сертификата на клиенте и маршрут с телефона не могут быть выполнены серверным wheel. Не объявлять Mini App исправленным до проверки на самом устройстве. Прямого SSH к server1 у агента нет. Независимый observer и restore/RTO остаются отдельными evidence.

Локальная проверка:355 backend и114 Chromium PASS. Fresh review: Critical0; Important2 исправлены RED→GREEN (старый журнал/усечённый хвост); время server inventory теперь явно датировано, доступно обновление. Axis14px — сохранённое исключение для подписей графика, обычный текст/контролы/документы16px. Native и CI выпуск ещё отдельно.
