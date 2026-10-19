# Connect Academy RC: комплект и следующий этап

Источник:830d3703e2ffa2f9864900c0d0c58f60049cb0c2,tree08a7bfdcdfd7b762b7f7d60a21007c9fdc0ba737. [Полный CI](https://github.com/cauf1l3d/ustarlms/actions/runs/39305805820) завершился SUCCESS,все9jobs;[context](https://github.com/cauf1l3d/ustarlms/actions/runs/39305805813) SUCCESS. [Точная квитанция](connect_academy_rc_ci_20261019.json). Native Connect121/611+6concurrency и paired93→99→93;две Moodle suites288/1266 PASS. Проверен скачанный recovery artifact:26файлов,baseline/restored metadata и schema совпадают,private snapshots отсутствуют. Локальный archive hash совпадает с CI.

## Состав

`ustar-connect-academy-830d3703.tar.gz`,17744353bytes. SHA256:a67a08d8e5509c580043a6752e73964cb8aed72c5d1732647a9d3c2e2379113e.

- `payload/local/ustar` и `payload/theme/ustar`:552точных Git-файла. Local2026101905/theme2026100202;XMLDB99таблиц.
- `manifest.json`:source commit,версии,схема и hashes accepted/candidate references. Accepted Git referencee1d57f5b526/93;это reference исходников,не новый отчёт о сервере.
- `preflight.pyz`:проверенный сборщик с теми же references;без параметров выводит план без обращения к хосту.
- `SOURCE_COMMIT` и `SHA256SUMS`:идентификация выпуска. Всего556regular archive members,из них552payload;`SHA256SUMS` проверяет555остальных файлов и не включает себя.

Production config,данные БД,ключи,токены,полный private server report и installer отсутствуют. Полный server report и его локальные коммиты не входят в опубликованную историю. Для дальнейшей проверки используется этот публичный source RC.

## Проверка скачанного файла

В каталоге,куда сохранён архив,на Linux:

```bash
printf '%s  %s\n' \
  a67a08d8e5509c580043a6752e73964cb8aed72c5d1732647a9d3c2e2379113e \
  ustar-connect-academy-830d3703.tar.gz | sha256sum --check --strict -
```

Ожидается `ustar-connect-academy-830d3703.tar.gz: OK`. Эта команда только читает архив. Сборка исходников не устанавливает обновление;копировать payload поверх работающей Академии вручную не следует. Старый production_patch_upgrade.py предназначен для другого core-upgrade и не является installer этого RC.

## Следующий операторский выпуск

Подготовить отдельную проверяемую процедуру addon update:согласованное окно обслуживания,блокировка конкурирующих cron/backup writers,свежий согласованный и проверенный backup исходников/config/БД/moodledata,проверка свободного места и неизменности целевых mounts/versions. Применять только два USTAR addon;core,другие плагины и сеть не входят в этот пакет.

В процедуре должны быть native Moodle upgrade/repeat,проверки versions/schema/hashes,возврат служб в исходные режимы и явный парный recovery при сбое до открытия пользовательских записей. Поздний rollback после новых записей требует отдельного решения о потере этих записей. CI-восстановление тестовой БД не подтверждает свежий backup или RTO конкретного production.

После обновления:private HTTPS bridge и отдельные ограниченные ключи,проверенная привязка аккаунта,опциональная подписка пользователя и контролируемая первая доставка. Notifications включаются с новым cutover time,без массовой отправки старых сообщений. Работающий receiver сейчас принят отдельно;этот RC не меняет его установку.

## Зависимость от Mini App

Текущий пользовательский сценарий привязки использует `startapp` и проверку Telegram initData через Mini App. Receiver с `onboarding_enabled=false` продолжает информационный режим. Поэтому наличие новых таблиц/API само по себе ещё не включает полноценную привязку или уведомления. Mini App и этот путь подтверждения требуют доступного доверенного HTTPS;включать onboarding заранее нельзя. Независимый путь привязки непосредственно через личный чат,если он нужен раньше HTTPS,потребует отдельной реализации с тем же canonical identity и вторым подтверждением в Академии;ручное заполнение таблиц привязок не заменяет проверку владельца.

Native login handoff/MFA/recovery/step-up,расширенные ролевые действия и Bitrix/WhatsApp остаются последующими gates утверждённого RFC. Этот source RC не означает завершение всего контура чат-ботов.
