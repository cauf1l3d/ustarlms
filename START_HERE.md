> Обновление 24.09: PR #14 (`codex/ustar-complete-rc-20260923`) — черновой кандидат поверх PR #13. Ни один из предшествующих PR не принят автоматически; Moodle DB, браузерная приёмка и восстановление полного backup остаются открытыми. Указанные ниже SHA и проверки описывают историческую базу 19–20.09, а не состояние этого чернового кандидата.

> Обновление 19.09: фактический source production сведен в GitHub и проверен побайтно для publishable source. Канонический код: `integration/ustar-20260919@378d397152a8c83f8b0d046e2e561ab2732d6b02`. Полный recovery snapshot сохранён отдельно и не публикуется в Git.

# USTAR — начать здесь

Обновлено: **2026-09-20**.

Начат [рефакторинг в шесть этапов](context/architecture/refactor_20260920.md).
Первый кодовый пакет: [PR #2](https://github.com/cauf1l3d/ustarlms/pull/2).
Второй этап начат: [PR #4](https://github.com/cauf1l3d/ustarlms/pull/4), оргданные и границы команды; R10/R12 остаются in progress.
[Проверки и ограничения](context/runtime/20260920_stage1_ci.md) относятся к изолированному CI;
production source ниже сохраняется до отдельного принятия и выпуска.

**Текущий канонический application source находится в `integration/ustar-20260919`, каталог `moodle/`.**

Точный commit:

`378d397152a8c83f8b0d046e2e561ab2732d6b02`

Этот commit создан из фактически работающего production source:

- `/opt/ustar/data/moodle/public/public/local/ustar`
- `/opt/ustar/data/moodle/public/public/theme/ustar`

Publishable source был проверен byte-for-byte перед commit:

- `local/ustar`: 328 файлов
- `theme/ustar`: 53 файла
- всего: 381 Git-source файл
- 33 production backup/runtime remnants классифицированы существующим `.gitignore` и не включены как активный source

1. [STATE.yaml](context/roadmap/STATE.yaml) — текущая кодовая база и границы подтверждения.
2. [ACTIVE.md](context/tasks/ACTIVE.md) — ближайшие действия.
3. [BACKLOG.yaml](context/roadmap/BACKLOG.yaml) и [протокол](context/roadmap/AGENT_PROTOCOL.md).
4. [Production sync 2026-09-19](release/20260919/README.md) и [runtime evidence](context/runtime/20260919_prod_sync.md).
5. [Индекс контекста](context/CONTEXT_INDEX.md), [project](context/project.yaml), [constraints](context/constraints.yaml), [architecture](context/architecture/), [ADR](context/decisions/), [domains](context/domains/), [code map](context/code_map/), [agent](context/agents/astra.md).

## Что считается текущей базой

Для любых последующих изменений сначала использовать:

`integration/ustar-20260919@378d397152a8c83f8b0d046e2e561ab2732d6b02`

Не брать `integration/ustar-20260912` как текущий application baseline и не накатывать поверх production старые ZIP/RC-пакеты.

Commit 19.09 включает фактические изменения production после 12.09, в том числе текущие версии route flow, assessment lifecycle, career/grades, organization/team presentation, forced retraining и login theme source.

## Recovery

Полный disaster-recovery snapshot:

`USTAR_FULL_CURRENT_20260919_113924.tar.gz`

SHA256:

`598177f211333d7d036abc025b9d21c3c7c3eb4cfb0bc10d004ff0fcff473f41`

Recovery archive содержит runtime state, PostgreSQL, moodledata и Docker images и **не хранится в GitHub**.

## Границы подтверждения

Source synchronization подтверждена на уровне Git source и manifest. Это не означает автоматическую полную приёмку:

- database/schema upgrade path;
- всех browser-сценариев;
- native tour DB records;
- полного clean-server DR restore.

Поэтому R00 остаётся review до закрытия этих проверок.

## История

Предыдущая кодовая база:

`integration/ustar-20260912@48326c6a011f58b985d251cb0462835bc1d37a16`

сохраняется как историческая точка. Датированные runtime snapshots и старые release manifests не являются текущим source baseline.

Не создавать второй Org/Evidence/Economy-домен, не менять Moodle core без отдельного решения, не удалять исторические ADR и не подделывать completion/evidence.
