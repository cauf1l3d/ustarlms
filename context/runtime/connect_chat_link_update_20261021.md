# Следующая поставка: application1905→2000 вместе с cron

[План](../proposals/USTAR_CONNECT_COORDINATED_UPDATE_PLAN_20261021.md),
[принятая cron-only квитанция](connect_cron_contract_owner_20261021.json).
HTTPS/cron/source baseline уже принят. Старый installer02, HTTPS cutover и
полный диагностический preflight не повторять.

Проверенный tool source:**6aa83a951b3d8119db8ef2d5d5e2484383fcbce1**.
[CI receipt](connect_chat_link_update_ci_20261021.json):Context39500936977 и
Review39500936916 SUCCESS,обе PHP869r16/136skips,actualTLS/UID33 и native1905→2000.
Файл:ustar-chat-link-update-6aa83a95.pyz.
SHA256:e0d364ab33fb01482c0bedc73833562a567dc221c0682c85009759bca58d82c3.
[Artifact](https://github.com/cauf1l3d/ustarlms/actions/runs/39500936916/artifacts/12300525979).
Private root copy проверяется этим hash перед исполнением. Последующие docs heads
не подменяют tool source. Нативное применение owner ещё pending.

Новый отдельный pyz: `build_connect_chat_link_update.py --commit <exactSHA>`.
Payload фиксирован1e8a8819,baseline830; tool SHA отличается от payload SHA.
Без аргументов — PLAN_ONLY, без host access. `--check` — read-only SQL/source/
metadata и существующие locks,без bootstrap/backup/операций. Может отказать
при занятом backup/renewal lock; не удалять locks и не менять права ради PASS.

Сначала отдельная подготовка HTTPS backup,без остановки Академии:

```bash
sudo /usr/bin/python3 -I -B /root/ustar-chat-link-update-6aa83a95.pyz --check-backup-https
sudo /usr/bin/python3 -I -B /root/ustar-chat-link-update-6aa83a95.pyz --prepare-backup-https
sudo /usr/bin/python3 -I -B /root/ustar-chat-link-update-6aa83a95.pyz --check
```

Первый результат ready_for_backup_HTTPS_compatibility,второй
backup_HTTPS_compatibility_prepared или already_applied. Это только два связанных
root-owned helper script с сохранением before-images/metadata;без backup/upgrade/
unit/calendar изменений. Публичный HTTPS/readiness становится доверенным TLS,
а расписание сохраняется. При отказе не менять hashes/права ради PASS.

После успешного `ready_for_chat_link_update` выбрать короткое окно без активной
работы пользователей и вызвать тот же root-owned/hash-verified pyz:

```bash
sudo /usr/bin/python3 -I -B /root/ustar-chat-link-update-6aa83a95.pyz --upgrade --acknowledge-outage
sudo /usr/bin/python3 -I -B /root/ustar-chat-link-update-6aa83a95.pyz --status
```

Только после CLI `upgrade_complete`: штатный cron на следующей минуте должен
закончить scheduled run с version2000 и exit0. Проверка:

```bash
sudo -n timeout --kill-after=2s 40s /usr/bin/python3 -I -B -u /usr/local/sbin/USTAR_CRON_SETUP_20261005.py --status
```

Нужен новый finished timestamp и отсутствие новых failures;18overdue/2historical
failed не обнуляются. Вход/материал по прежнему https://ustar.local проверяются
владельцем после поставки. Bridge/link/notify не включаются этой командой.

При незавершённой до release операции использовать **тот же pyz**:

```bash
sudo /usr/bin/python3 -I -B /root/ustar-chat-link-update-6aa83a95.pyz --restore --acknowledge-outage
```

После public_released=true restore требует дополнительного явного
`--discard-writes-since-snapshot`: он возвращает данные snapshot и теряет новые
записи. Не применять автоматически. Поддерживается только matching tool/payload/
transaction и verified paired baseline; нельзя подменять marker/hash/state.
