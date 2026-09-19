# Активная работа — 2026-09-19

Канонический application source:

`integration/ustar-20260919@378d397152a8c83f8b0d046e2e561ab2732d6b02`

Канонический статус:

`main/context/roadmap/STATE.yaml`

## R00 — review

Production source reconciliation выполнена.

Подтверждено пользовательским запуском reconciliation script:

- 328 файлов `moodle/local/ustar`;
- 53 файла `moodle/theme/ustar`;
- 381 publishable source файл проверен byte-for-byte перед commit;
- `INDEX_SOURCE_BYTE_MATCH_OK`;
- remote branch и local commit совпали;
- 33 runtime/developer backup artifact классифицированы существующим `.gitignore` и не опубликованы как активный source;
- полный inventory после cleanup pass: 414 файлов.

Recovery snapshot:

`USTAR_FULL_CURRENT_20260919_113924.tar.gz`

SHA256:

`598177f211333d7d036abc025b9d21c3c7c3eb4cfb0bc10d004ff0fcff473f41`

Следующее действие R00:

1. read-only проверка DB schema / plugin versions / upgrade-path против exact SHA `378d397...`;
2. browser smoke текущего production по критическим маршрутам;
3. native tour records/visual acceptance;
4. отдельный clean-server DR restore test созданного recovery snapshot;
5. после приёмки решить, удалять ли 33 ignored runtime remnants с production.

R01/R04/R17 не считать автоматически завершёнными из-за source synchronization.

Старую `integration/ustar-20260912` и старые ZIP/RC использовать только как исторический provenance, не как текущую базу.
