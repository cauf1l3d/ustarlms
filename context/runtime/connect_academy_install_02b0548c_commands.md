# Точные команды выпуска02b0548c

Архив `ustar-connect-install-02b0548c.tar.gz`,17978054bytes.
SHA256 `53d9983b9e437bc9b4623bac83c72564c35a772ce3cc292b55efe8a986be1d13`.
Controller02b0548c,immutable Academy payload830d3703. [Проверки](connect_academy_installer_ci_20261020.json),[процедура/восстановление](connect_academy_install_20261020.md).

## 1. Загрузить архив и проверить

Положить этот новый архив через WinSCP в `/home/aduk/`. Выполнить на server1:

```bash
sudo bash <<'ROOT'
set -euo pipefail
umask 077
install -d -o root -g root -m 700 /root/ustar-connect-install
install -o root -g root -m 600 /home/aduk/ustar-connect-install-02b0548c.tar.gz /root/ustar-connect-install/package.tar.gz
cd /root/ustar-connect-install
printf '%s  %s\n' 53d9983b9e437bc9b4623bac83c72564c35a772ce3cc292b55efe8a986be1d13 package.tar.gz | sha256sum --check --strict -
tar --extract --gzip --file package.tar.gz --no-same-owner --no-same-permissions
sha256sum --check --strict SHA256SUMS
python3 -I -B update.pyz --check
ROOT
```

Этап только размещает комплект и читает состояние. Академия продолжает работу.
Нужны все строки `OK` и `ready_for_maintenance_window`. При отказе остановиться;
не запускать следующий этап. Проверяется уже root-owned копия до распаковки;
`set -euo pipefail` останавливает блок при ошибке SHA или проверки.

## 2. В выбранное окно обслуживания запустить обновление

Академия временно недоступна: свежий бэкап,холодная пара,миграция и проверки.
Запуск в отдельной systemd-службе сохраняет работу при разрыве SSH.

```bash
sudo systemd-run --unit=ustar-connect-academy-update --property=Type=oneshot --property=TimeoutStartSec=infinity /usr/bin/python3 -I -B -u /root/ustar-connect-install/update.pyz --upgrade --acknowledge-outage
sudo journalctl -fu ustar-connect-academy-update.service -o cat
```

Ctrl+C завершает только просмотр журнала. Статус транзакции:

```bash
sudo python3 -I -B /root/ustar-connect-install/update.pyz --status
```

Успех: `CONNECT_ACADEMY_UPDATE=PASS; UPGRADE_REPEAT=PASS; BOT_ACTIVATION=PENDING`
и `phase=upgrade_complete`. При автоматическом откате `CONNECT_PAIRED_RECOVERY=PASS`
означает возврат прежнего состояния;это не успешное обновление. Проверить вход,
тему USTAR и обычную работу пользователя. Каналы и ключи бота ещё не активируются;
следующим шагом остаются private bridge и проверенная привязка/доставка.

Если systemd уже хранит службу с таким именем, не останавливать её ради нового
запуска: сначала посмотреть журнал и `--status`. При потере питания/ошибке
восстановления использовать раздел recovery основной инструкции. После открытия
пользовательских записей возврат старой пары требует решения о потере новых данных.
