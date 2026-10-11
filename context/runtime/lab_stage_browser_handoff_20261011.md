# Новый стенд: результат и проверка через браузер — 11.10.2026

**Owner screenshot19:53:07UTC:** [authenticated SCORM на18085, section6/20/text/images](lab_stage_browser_result_20261011.json) принят в видимой области. Tunnel/browser access уже работает; эту настройку повторять не нужно. Next [скачать и проверить official package5.1.8](lab_patch_package_handoff_20261011.md). Full role/home/completion/persisted progress acceptance не объявлены по одному изображению.

Владелец выполнил exact PR87 helper `--prepare-stage` в18:55:53..19:17:02UTC
(21:55:53..22:17:02MSK). [Полный обезличенный receipt](lab_stage_prepare_result_20261011.json).
Wrapper21min9s; helper1267.054s. Установка, --check и --prepare-stage завершены;
команды не повторять. Старый lab снова работает, production containers unchanged.

Frozen checkpoint:63431 entries, полный inventory/file hashes PASS, manifest SHA-256
`77672fd0b5b7bc5a27d1bd71d536f2bf47449455623589b53e0605005584b4c0`.
Отдельная mutable copy запущена с исходным coreDB2025100601.03; bootstrap PASS,
HTTP login200,526 USTAR file hashes/57 addon metadata совпали,4910 Moodle file
contenthashes проверены. Cron выключен, email отключён, сеть loopback only.

Это **успешный baseline исходной версии**. Core upgrade=false, full-addon
compatibility=false, patch upgrade/rollback=false, manual roles/SCORM=NOT_TESTED.
Короткий smoke ниже проверяет новую workspace, а не production или прежний lab.
storage_plan в report измерен перед копированием; он не сообщает остаток места
после операции. Relay живёт7200s; точные start/expiry в данном stdout не напечатаны.

## Следующий шаг оператора

В новом окне CMD/PowerShell **на Windows-компьютере**, вне текущей SSH-сессии:

```powershell
ssh -N -o ExitOnForwardFailure=yes -L 127.0.0.1:18085:127.0.0.1:18085 aduk@192.168.1.60
```

После пароля окно обычно не выводит prompt — оставить его открытым. Если SSH
сообщит ошибку привязки локального порта, передать текст; неизвестный процесс не
завершать. Сам туннель forwarding only: не выполняет remote command.

Открыть на том же компьютере
[страницу входа](http://127.0.0.1:18085/login/index.php), войти существующим
аккаунтом из восстановленной копии. Пароль агенту не передавать. Проверить:

1. Вход и главную USTAR: нет PHP/SQL ошибки, привычные блоки доступны.
2. Открытие одного существующего курса/SCORM и переход между несколькими экранами.
3. Открытие одного известного вложения, если оно есть в выбранном курсе.

Передать итог каждого пункта и текст/скрин ошибки без личных данных. Это короткий
manual baseline smoke; полные роли/SCORM completion/история будут приниматься
отдельно после candidate upgrade и paired restore. Если relay истёк и браузер
не отвечает, --prepare-stage не повторять: копии уже существуют; сначала установить
фактический статус и подготовить ограниченное возобновление доступа.

## Следующая работа агента по Moodle

Для full-addon server upgrade/repeat/paired rollback нет готового reviewed helper.
Существующий [PR83 patch runtime](patch_runtime_20261005.md) проверяет synthetic CI;
его commands не являются командой обновления этой real workspace. Подготовить
candidate5.1.8 exact upstreamcc0bf17da8b5fbd290faf95b2b14a03d64331923/vendor,
сохранить19 addon roots/23 extra paths/USTAR homepage contract, проверять guard
идентичностей/права/место, затем workspace-only upgrade и repeat с exact evidence.
Обязательное испытание согласованного восстановления code/config/Moodledata/PG
из frozen checkpoint — после clean stop только новых owned stage containers.
Checkpoint и старый lab не менять. Production upgrade пока не выполнять.
