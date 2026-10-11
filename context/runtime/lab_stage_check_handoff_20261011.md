# Свежая проверка перед отдельным стендом — 11.10.2026

Post-resize owner checks получены; следующий шаг текущей очереди — PR87 read-only
`--check`. Helper deployment/check на сервере пока не подтверждены.

Слитый [PR87](https://github.com/cauf1l3d/ustarlms/pull/87), implementation
`40e7d13e197cd59321e5e89e3f477539eb265b19`. Helper SHA-256
`9c81abb6b30bea611f99fb7a3e336e12d0a7827d4ea462a4d3d63e6f8aec9012`;
remote blob56122e1090d66e30ef9cd9054e9c2da2899c9fe9 совпал с local.
[Context CI](https://github.com/cauf1l3d/ustarlms/actions/runs/37375193850) и
[Review CI](https://github.com/cauf1l3d/ustarlms/actions/runs/37375193949) SUCCESS.
Это проверенный historical helper, не CI новой docs поставки или server acceptance.

Команды выполнять по одной; каждая занимает одну physical line. Download/install
создают только файл инструмента; `--check` не создаёт copies, не останавливает
containers и не запускает Moodle upgrade/tasks. Он проверяет pinned dependencies,
source hashes/config/readonly lab SQL, exact container identities, stage namespace,
порт18085, RAM≥6GiB и fresh budget для двух отдельных copies с4GiB reserve.
Оценка места пересчитывается по текущему source, не переносится со05Oct.

1. Authenticate отдельно.

```bash
sudo -v
```

2. Скачать immutable reviewed helper.

```bash
curl -fsSL --connect-timeout 10 --max-time 60 https://raw.githubusercontent.com/cauf1l3d/ustarlms/40e7d13e197cd59321e5e89e3f477539eb265b19/scripts/infra/lab_cold_checkpoint.py -o /home/aduk/USTAR_LAB_COLD_CHECKPOINT_20261005.py
```

3. Проверить SHA и установить root:root0600.

```bash
printf '%s  %s\n' 9c81abb6b30bea611f99fb7a3e336e12d0a7827d4ea462a4d3d63e6f8aec9012 /home/aduk/USTAR_LAB_COLD_CHECKPOINT_20261005.py | sha256sum --check --strict - && sudo -n install -m 600 -o root -g root /home/aduk/USTAR_LAB_COLD_CHECKPOINT_20261005.py /usr/local/sbin/USTAR_LAB_COLD_CHECKPOINT_20261005.py
```

4. Повторно проверить установленный SHA и выполнить только --check; лимит180s.

```bash
sudo -n timeout 180s bash -c 'set -eu; printf "%s  %s\n" 9c81abb6b30bea611f99fb7a3e336e12d0a7827d4ea462a4d3d63e6f8aec9012 /usr/local/sbin/USTAR_LAB_COLD_CHECKPOINT_20261005.py | sha256sum --check --strict -; python3 -I -B /usr/local/sbin/USTAR_LAB_COLD_CHECKPOINT_20261005.py --check'
```

Ожидаемый marker: `LAB_COLD_CHECKPOINT_PREFLIGHT=PASS; read-only; no container/file changes`
и JSON budget. Owner должен передать полный вывод. При отказе сначала разобрать
его категорию; в частности, existing checkpoint/workspace не удалять и не обходить
guards. `--prepare-stage` не включён в эту проверку.

После принятого fresh result — отдельный cold checkpoint и mutable workspace по
[reviewed contract](lab_cold_checkpoint_20261005.md). В этот следующий изменяющий
шаг source lab временно остановится для согласованного copy и возобновится с теми
же IDs; production containers проверяются через inspect и продолжают работать.
Далее full-addon candidate5.1.8 upgrade/repeat/paired rollback. AppArmor diagnosis
остаётся в выбранном OS scope; functional mail/DNS/ISPConfig acceptance и свежий
archive restore не объявлены по status/listener smoke.

PR98 predecessorc13ab254f90dff6ac96a3269ebb3e38ff4dac9ce: Context#49 SUCCESS;
Review#485 FAILURE на frontend npm audit, sourcePHP8.2/8.3 SUCCESS, downstream
DB/rollback/prepare/gate skipped. Это отдельно от SUCCESS инструментаPR87.
