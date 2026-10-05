# Мониторинг: требование и проект, ещё не внедрён

05.10.2026: владелец требует **один пульт** с важными метриками и уведомлениями, желательно Telegram. Общей установленной системы и проверенной доставки пока нет. Локальные private logs/status cron уже работают. Источник — [dated evidence](evidence_20261005.yaml), работа — INF-06 в [полном плане](../roadmap/USTAR_AUDIT_REMEDIATION_PLAN_20261005_RU.md).

Предложение аудита: Grafana/Prometheus и ограниченные сборщики. Размещение, retention, роли, receiver/bot/chat и независимый наблюдатель ещё выбираются. Дашборд показывает свежесть/NoData, компоненты хоста, контейнеров, HTTP/PHP, PostgreSQL, cron/USTAR, нужной почты/DNS/ISPConfig и backup. Без personal labels, full Docker socket, cluster superuser и произвольного shell.

| Сигнал | Значение / правило |
|---|---|
| Cron process | Last start/finish, exit, duration; exit 0 не заменяет task outcome |
| Cron freshness | Предложение: >5 мин без успеха вне согласованного окна; порог ещё не утверждён |
| Enabled task overdue | Учитывать effective-enabled/overrides/timezone и индивидуальное расписание. 17 COMPONENT_DISABLED отдельно |
| Task failure | Новые ошибки отличаются от исторического failed24h и disabled H5P/registration; incident grouping/recovery |
| Adhoc | Queue age/size/failed и динамика; не только total |
| Backup pause | Причина/время начала/допустимое окно и завершение. Backup-lock не бесконечное подавление алерта |
| Storage | Место, inode, абсолютный остаток, growth, SMART; предложения 80%/90% требуют согласования |
| HTTP/PHP/DB | Ready/latency/error/locks/worker saturation; latency thresholds после PERF-01 |
| Host/dashboard lost | Отдельный наблюдатель вне этого хоста и heartbeat. Same-host пульт не обнаруживает собственную полную потерю |
| Backup | Сейчас `not_installed` для регулярности; позднее success/transfer/checksum/restore freshness по принятой политике |

## Расписания USTAR для правильного смысла метрик

Defaults PR76; production overrides и timezone проверяются отдельно.

| Класс задачи | Период |
|---|---|
| reconcile_competitions, process_work_tasks, submit_grade_requests | Каждые 5 мин |
| enrich_feed_sources | Минуты 2, 7, …, 57 |
| import_feed_sources | Каждые 15 мин |
| sync_enrolments | Каждые 30 мин |
| reconcile_reporting | Минута 5 часа |
| reconcile_rewards | Минуты 10 и 40 |
| renew_acting_assignments | 03:20 по применяемой Moodle timezone |

Telegram сообщение: среда, компонент, проблема, время, ссылка на закрытый пульт. Tokens/PII/полные request URLs не отправляются. Получатели, резервный канал и подтверждение инцидента выбираются при внедрении. Наличие contact point не является доказательством доставки из корпоративной сети.

**Закрывающий evidence:** controlled failure/NoData, фактическое получение, dedup и recovery; проверка независимого observer и сбоя самой доставки; бюджет хранения. Ни auto-restart, ни auto-cleanup этим проектом не разрешаются.
