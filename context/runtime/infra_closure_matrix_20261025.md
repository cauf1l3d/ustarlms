# Остаток инфраструктуры по фактическому отчёту25Oct

Отчёт01:50:08–01:50:21UTC принят; collector SHA0d5d05fc совпал. Его exact-source
Review40151717494/Context40151717558 SUCCESS, existing PG16 native121045127545
INFRA_REMAINING_SURVEY_NATIVE=PASS. Агент не подключался к серверу. Предыдущие
SSD/Moodle518/ОС/reboot/HTTPS/DB-role/code/Connect проверки засчитываются.

| Этап | Что подтверждено | Что осталось |
|---|---|---|
| Права | config/ключи/новые private state защищены; data root0770 | Видны как минимум63 обычных экспорта с group/other доступом. Новый пакет закрывает доступ к подходящим файлам на глубине2. Общая группа www-data содержит caddy: это отдельная граница доверия, не независимая изоляция служб. |
| Docker/SSD | Оба контейнера running, restarts0/OOM0; root≈47.5GiB/HDD≈378.7GiB available; PG не опубликован, Moodle localhost8082 | json-file без ротации, Moodle≈62MiB; healthcheck отсутствует. Нужен согласованный lifecycle с переносом frozen ID guards; daemon defaults не исправят существующие контейнеры. Лаборатория не удаляется. |
| Доступ | SSH key и пароль включены; root без пароля; UFW inactive/INPUT ACCEPT | Нужны проверенный отдельный key login, аварийный доступ и allowlist общего хоста/внешнего маршрутизатора. До этого blind firewall/password disable не выполняется. |
| Копии/DR | HDD success00:36:21Z, encrypted SHA39c0986f/readback; timer active/enabled | Новые role/code journals пока вне стандартного архива. Пакет добавляет отдельный companion. Clean-host restore у сисадмина, независимый экземпляр, отдельное хранение ключа, RPO/RTO/retention требуют реальных подтверждений. |
| Наблюдение | Numeric HTTP log работает; свежий snapshot | Journal root direct measured, service no_data. Пакет диагностирует в sandbox и сохраняет только проверенное изменение. Внешний host-loss observer/проверка доставки не подтверждены. |
| Железо/питание | Свежий SMART health PASSED; HDD5/197/198/199=0 | Это не long-test или подтверждение замены диска, не отмена исторического риска HDD и не UPS/shutdown acceptance. Нужны физические сведения. |
| Регламент | Daily Academy cert-renew timer уже установлен, success; adhoc0/retry_enabled0 |19overdue не доказывают новый cron сбой. Исправить чтение квитанции, подтвердить актуальный scheduled tick/active cert, SMTP delivery с согласованным адресатом. Учесть qualified recovery после законного TLS renewal. |
| Приёмка | Вход/открытие работают по сообщению владельца, предыдущие профильные стенды приняты | Один HTTP request/p95 не является нагрузочным SLO. Финальный статус возможен только по строкам выше; все8 сейчас не закрыты. |

Частичный survey: du restore-lab timeout, cron helper не дал JSON; home scan capped,
listeners capped, active leaf не выбран. Эти ограничения не маскируются как PASS.
Первая home выборка содержит и исходники/установщики, и настоящие plaintext dumps;
не каждый архив объявляется утечкой БД. Ничего не удаляется и не отправляется.

## Единый локальный пакет

`scripts/infra/infra_one_pass.py` standalone, default PLAN_ONLY. `--apply` сам
выполняет preflight, фиксирует metadata-only undo, сохраняет две проверенные
encrypted control snapshots (до и после), закрывает group/other доступ к
выбранным старым файлам, ставит companion timer01:00UTC+0–120s (04:00MSK), возвращает
санитизированный итог. DB/code/data/CA/shared firewall/SSH/container IDs не меняет.
Остановки Академии нет; collector timer кратко приостанавливается для диагностики.
Quota5GiB и reserve8GiB у companion; при заполнении отказ без удаления. Нужна
отдельная политика retention; прежние backup archives не ротируются.

Companion содержит root controllers, новые journals/private legacy archive,
существующие deployment/HTTPS control roots и USTAR `/etc` configs/keys.
`recovery.key` исключён. Crypto authentication и manifest/file hashes проверяются
потоковым расшифрованием в памяти; plaintext на диск не распаковывается. Это не
whole-host backup: console auth/notify SQLite, ОС, DNS/mail data и бизнес-данные
полностью сюда не входят. Стандартный согласованный DB/CODE/DATA архив обязателен.
Архив00:35:20Z-b6dfed1e сохранить: в нём ещё есть оригинальные legacy bytes.

Journal: временный ExecStartPost в реальной collect.service возвращает только
reason/elapsed/exit metadata. Только timeout допускает CPUQuota100%; только
permission_denied — SupplementaryGroups=systemd-journal. Без measured fresh
service snapshot изменение убирается, journal остаётся unresolved. Сырая journal
выдача, токены, provider сообщения и широкие capabilities не добавляются.

`--check` читает текущие guards/status; `--undo` и `--recover` восстанавливают
metadata/свои units, не бизнес-строки; encrypted copies сохраняются. Unknown bytes,
inode/size/mtime drift или foreign unit запрещают перезапись/откат. После успешного
шага нельзя молча принимать before/probe metadata вместо конечного состояния.
Свежий upload этого пакета — не доказательство production apply: нужен итог JSON.

Для полного закрытия нужны одним ответом: подтверждение отдельного SSH key login и
сетевого allowlist; результат restore от сисадмина и место независимой копии/ключа;
модель/подключение ИБП; выбранный независимый observer и адресат проверки доставки;
бизнес-требования к RPO/RTO/SLO. Секреты, приватные ключи и токены не присылать.

Companion и DB/CODE/DATA архив имеют независимые capture times. Они не объявляются
атомарной recovery pair. При восстановлении сверить release/generation, ownership/
code modes, phase и container identities; новая машина требует квалифицированного
profile. Извлечение двух архивов поверх live production не является поддержанной
процедурой. Generation pairing/clean-host restore остаются частью открытого DR.
[Docker: существующим контейнерам требуется recreation для нового log config](https://docs.docker.com/engine/logging/configure/).
