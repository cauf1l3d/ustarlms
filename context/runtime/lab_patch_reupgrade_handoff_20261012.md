# Возврат проверенного стенда на5.1.8 перед ручной приёмкой

Получен [owner real paired rollback PASS](lab_patch_restore_result_20261012.json):
12Oct08:51:07..09:01:36UTC/11:51:07..12:01:36MSK,629s/10min29s. Code/config,
Moodledata и physicalPG восстановлены вместе из согласованной копии; исходный
coreDB2025100601.03, login200 и4910 file contenthashes PASS. Предыдущие
[stage upgrade/repeat5.1.8](lab_patch_upgrade_result_20261012.json) и этот restore
подтверждают реальный stage upgrade/repeat/paired rollback cycle. Это owner
stdout, а не независимое SSH наблюдение агента или production rollback test.

Текущий stage снова на исходной версии. Следующий шаг — вернуть его на5.1.8
для authenticated USTAR/browser/addon QA. Установленный helper59b88e1/SHA452d8fd6
не меняется; новый download/install/prepare-stage/prepare-candidate не требуется.
В `upgrade()` проверено: существующий marker допускает новый запуск только при
`phase=baseline_restored`, сохраняется в private history, затем повторяются
accepted candidate/platform readonly guards и budget. Создаётся **новый** run и
fresh paired baseline текущего stage. Старые manifest156e4688/runfylkhzxl,
retained upgraded files, frozen original checkpoint и accepted candidate остаются.

Операция снова останавливает только stage relay/web/PG, активирует code в тот же
bind-root inode, запускает official root admin/cli/upgrade.php дважды с explicit
CLI mail/memory policy, проверяет preserved addon versions, tasks0/filedir hashes/
bootstrap/loopback/login200 и source/production identities. При ошибке восстанавливает
новую согласованную baseline. Внешний короткий timeout не используется. Полный
[transaction contract](lab_patch_upgrade_20261012.md) остаётся тем же.

В SSH-окне `aduk@server1:~$`, две команды по очереди, каждая одной строкой:

```bash
sudo -v
sudo -n bash -c 'set -euo pipefail; umask 077; printf "%s  %s\n" 452d8fd682bc2878fee66aaa9c121528e707d944b943d90264c2d220a48133f4 /usr/local/sbin/USTAR_LAB_PATCH_UPGRADE_20261012.py | sha256sum --check --strict -; printf "STAGE_REUPGRADE_START=%s\n" "$(date -u +%FT%TZ)"; python3 -I -B -u /usr/local/sbin/USTAR_LAB_PATCH_UPGRADE_20261012.py --upgrade-stage; printf "STAGE_REUPGRADE_FINISH=%s\n" "$(date -u +%FT%TZ)"'
```

Ожидается LAB_PATCH_UPGRADE=PASS, core2025100608.00, repeat PASS, новый directory и
manifest/hash, login200, actual filehash count, source/production unchanged.
Получить и проверить owner output до browser QA. Новый report по-прежнему выводит
`PAIRED_ROLLBACK_TEST_PENDING` для **новой transaction**: это не отменяет уже
принятый restore предыдущего run. Без новых ошибок/изменений не назначать тот же
цикл отката бесконечно; следующий этап — authenticated manual acceptance.

## Ручная приёмка после нового upgrade PASS

Использовать offline stage18085 и его новый7200s relay, сохраняя cron/mail isolation.
Доступ с рабочего Windows ПК требует SSH forwarding к серверному127.0.0.1:18085;
публичный LAN endpoint или изменения firewall не создаются. Пути и фактический
способ входа проверяются с владельцем при открытии стенда; пароли не публикуются.

| Область | Что проверить на клоне | Что фиксировать |
|---|---|---|
| Вход и USTAR home | Реальный вход, главная USTAR, меню, ссылки и оформление темы | HTTP/login form не заменяет authenticated result; ошибки/ожидаемое поведение |
| Роли | Существующие admin/HR/manager/employee accounts, доступные страницы и запрещённые действия | Real actor/capability/scope, без выдачи production ролей ради теста |
| SCORM и файлы | Открытие существующего курса, текст/изображения, продолжение попытки, сохранение прогресса и completion | Сохранность содержимого и состояния после refresh/relogin, ошибки JS/network |
| USTAR сценарии | Материалы/маршруты/задачи/чаты/лента/аттестации в уже существующем разрешённом контуре | Ошибки после core patch отдельно от ранее открытых product задач |
| Сохранённые addons | Runtime smoke всех19 addon roots/57 metadata, их реально используемые страницы и операции | Composer/vendor metadata и CLI versions не означают full runtime compatibility |

Тестовые записи выполняются только на отдельном клоне. Production patch, full CI
gate, OS/AppArmor/required service reboot и HTTPS остаются следующими отдельными
этапами. Source59b88e1 Context58/PHP8.2/8.3 SUCCESS, frontend audit FAILURE/full gate
не PASS, PR99 draft/unmerged; пороги не ослабляются. Apache PHP settings и независимое
восстановление свежего HDD archive остаются открыты в своих областях.
