<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Checklist definitions live in versioned USTAR JSON; executions live in relational audit tables. */
class checklists {
    public const NAME = 'checklists';

    public static function defaults(): array {
        return [
            'version' => 1,
            'items' => [
                [
                    'id' => 'technical_daily',
                    'title' => 'Ежедневные обязанности технического персонала',
                    'description' => 'Оцифровано из файла «Техничка чек лист xlsx.xlsx». HR может изменить назначение и формулировки.',
                    'active' => true,
                    'recurrence' => 'daily',
                    'positionIds' => ['retail_cleaner', 'dc_cleaner'],
                    'sections' => [
                        ['id' => 'entrance', 'title' => 'Входная зона', 'items' => [
                            ['id' => 'entrance_area', 'title' => 'Прилегающая к магазину территория убрана'],
                            ['id' => 'entrance_wet', 'title' => 'Подмести входную зону, лестницу и двор; провести влажную уборку'],
                            ['id' => 'mat', 'title' => 'Вытряхнуть коврик'],
                            ['id' => 'doors', 'title' => 'Протереть входные двери и стекла от пятен; контроль 2 раза в день'],
                            ['id' => 'meeting', 'title' => 'Помыть пол и протереть пыль в переговорной и прилегающих кабинетах до 09:00'],
                        ]],
                        ['id' => 'hall', 'title' => 'Зал', 'items' => [
                            ['id' => 'hall_floor', 'title' => 'Подмести зал и помыть полы чистой водой со средством; сменить воду 2 раза'],
                            ['id' => 'hall_spots', 'title' => 'Удалить доступные для удаления пятна с пола'],
                            ['id' => 'sofa', 'title' => 'Протереть поверхность кожаного дивана и маленький стол влажной тряпкой'],
                            ['id' => 'section_clean', 'title' => 'Ежедневно выбирать один отсек/отдел и убирать с перемещением доступных предметов'],
                            ['id' => 'trash', 'title' => 'Вынести мусор'],
                        ]],
                        ['id' => 'sanitary', 'title' => 'Туалеты и молельная', 'items' => [
                            ['id' => 'toilet', 'title' => 'Промыть унитаз внутри и снаружи дезинфицирующим средством'],
                            ['id' => 'sink', 'title' => 'Вымыть раковину внутри и снаружи'],
                            ['id' => 'bin', 'title' => 'Почистить и продезинфицировать мусорку; вынести мусор'],
                            ['id' => 'soap', 'title' => 'Проверить наличие жидкого мыла и заменить при необходимости'],
                            ['id' => 'walls', 'title' => 'Протереть стены и удалить потеки'],
                            ['id' => 'ablution', 'title' => 'Помыть ванну/зону омовения'],
                            ['id' => 'carpets', 'title' => 'Подмести ковры; два раза в неделю мыть под ними'],
                            ['id' => 'mirror', 'title' => 'Протирать зеркало средством для зеркал ежедневно'],
                            ['id' => 'freshener', 'title' => 'Проверить наличие освежителя'],
                            ['id' => 'frames', 'title' => 'Протереть двери и рамы от пятен и грязи'],
                            ['id' => 'inner_yard', 'title' => 'Проверить прилегающую территорию во внутреннем дворе'],
                        ]],
                        ['id' => 'weekly', 'title' => 'Периодические задачи', 'items' => [
                            ['id' => 'warehouse', 'title' => 'Прибрать на складе (2 раза в неделю)'],
                        ]],
                    ],
                ],
                [
                    'id' => 'retail_morning',
                    'title' => 'Утренний контроль торгового зала',
                    'description' => 'Оцифровано из файла «Чек лист утренний.xlsx». По умолчанию назначено администратору торгового зала; HR может переназначить.',
                    'active' => true,
                    'recurrence' => 'daily',
                    'positionIds' => ['retail_admin'],
                    'sections' => [[
                        'id' => 'checks', 'title' => 'Ежедневные проверки', 'items' => [
                            ['id' => 'biotime', 'title' => 'Сотрудники отметились в BioTime'],
                            ['id' => 'appearance', 'title' => 'Внешний вид соответствует правилам'],
                            ['id' => 'badges', 'title' => 'У всех сотрудников есть бейджи'],
                            ['id' => 'order', 'title' => 'Чистота и порядок в зоне каждого сотрудника'],
                            ['id' => 'shelves', 'title' => 'Нет пустых полок'],
                        ],
                    ]],
                ],
            ],
        ];
    }

    public static function get(): array {
        global $DB;
        $rec = $DB->get_record('local_ustar_structure', ['name' => self::NAME]);
        if (!$rec) {
            return self::defaults();
        }
        $data = json_decode($rec->jsondata, true);
        return is_array($data) ? $data : self::defaults();
    }

    public static function save(array $data, ?int $expectedversion = null): void {
        global $DB, $USER;
        if (!isset($data['items']) || !is_array($data['items'])) {
            throw new \invalid_parameter_exception('Checklist catalogue items are required');
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')
            ->get_lock('checklist-publish', 10);
        if (!$lock) {
            throw new \moodle_exception('Checklist publication is busy');
        }
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $previous = self::get();
                if ($expectedversion !== null && (int)($previous['version'] ?? 1) !== $expectedversion) {
                    throw new \invalid_parameter_exception('Каталог изменился. Обновите редактор.');
                }
                $byid = array_column($previous['items'] ?? [], null, 'id');
                $now = time();
                foreach ($byid as $key => $old) {
                    if (!$DB->record_exists('local_ustar_check_def_ver',
                            ['checklistkey' => (string)$key, 'version' => 1])) {
                        $DB->insert_record('local_ustar_check_def_ver', (object)[
                            'checklistkey' => (string)$key, 'version' => 1, 'status' => 'published',
                            'definitionjson' => json_encode($old + ['version' => 1], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                            'createdby' => 0, 'timecreated' => $now, 'publishedat' => $now,
                        ]);
                    }
                }
                $seen = [];
                foreach ($data['items'] as &$definition) {
                    $key = (string)($definition['id'] ?? '');
                    if ($key === '' || isset($seen[$key]) || strlen($key) > 64) {
                        throw new \invalid_parameter_exception('Checklist IDs must be unique and non-empty');
                    }
                    $seen[$key] = true;
                    $old = $byid[$key] ?? null;
                    $before = $old;
                    $after = $definition;
                    if ($before) {
                        unset($before['version']);
                    }
                    unset($after['version']);
                    if ($old && $before == $after) {
                        $definition['version'] = max(1, (int)($old['version'] ?? 1));
                        continue;
                    }
                    if ($DB->record_exists('local_ustar_check_def_ver',
                            ['checklistkey' => $key, 'status' => 'draft'])) {
                        throw new \invalid_parameter_exception('Сначала опубликуйте черновик чек-листа.');
                    }
                    $version = (int)$DB->get_field_sql(
                        'SELECT MAX(version) FROM {local_ustar_check_def_ver} WHERE checklistkey = :key',
                        ['key' => $key]) + 1;
                    $definition['version'] = $version;
                    $DB->insert_record('local_ustar_check_def_ver', (object)[
                        'checklistkey' => $key, 'version' => $version, 'status' => 'published',
                        'definitionjson' => json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        'createdby' => (int)$USER->id, 'timecreated' => $now, 'publishedat' => $now,
                    ]);
                }
                unset($definition);
                $data['version'] = max((int)($data['version'] ?? 1), (int)($previous['version'] ?? 0) + 1);
                structure::save(self::NAME, $data);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
        } finally {
            $lock->release();
        }
    }

    public static function published_version(string $id, int $version): ?array {
        global $DB;
        $record = $DB->get_record('local_ustar_check_def_ver',
            ['checklistkey' => $id, 'version' => $version, 'status' => 'published']);
        if ($record) {
            $definition = json_decode((string)$record->definitionjson, true);
            return is_array($definition) ? $definition : null;
        }
        // Fresh installation has no initial snapshot until its first write.
        $current = self::find($id);
        return $version === 1 && $current && (int)($current['version'] ?? 1) === 1
            ? $current : null;
    }

    public static function draft_for(string $id): ?array {
        global $DB;
        $record = $DB->get_record('local_ustar_check_def_ver',
            ['checklistkey' => $id, 'status' => 'draft']);
        if (!$record) {
            return null;
        }
        $data = json_decode((string)$record->definitionjson, true);
        return is_array($data) ? $data + ['version' => (int)$record->version,
            'draftrevision' => (int)$record->revision] : null;
    }

    /** Store one mutable draft without changing the published catalogue. */
    public static function save_draft(array $definition, int $expectedrevision = 0): int {
        global $DB, $USER;
        $id = (string)($definition['id'] ?? '');
        if ($id === '' || strlen($id) > 64 || $id !== clean_param($id, PARAM_ALPHANUMEXT)) {
            throw new \invalid_parameter_exception('Invalid checklist id');
        }
        if (trim((string)($definition['title'] ?? '')) === ''
                || !is_array($definition['sections'] ?? null)
                || count($definition['sections']) > 50) {
            throw new \invalid_parameter_exception('Добавьте название и не более 50 разделов.');
        }
        $itemids = [];
        foreach ($definition['sections'] as $section) {
            if (!is_array($section) || !is_array($section['items'] ?? null)) {
                throw new \invalid_parameter_exception('Некорректный раздел чек-листа.');
            }
            foreach ($section['items'] as $item) {
                $itemid = (string)($item['id'] ?? '');
                if ($itemid === '' || strlen($itemid) > 64 || isset($itemids[$itemid])
                        || trim((string)($item['title'] ?? '')) === '') {
                    throw new \invalid_parameter_exception('Пункты должны иметь уникальные ID и название.');
                }
                $itemids[$itemid] = true;
            }
        }
        if (!$itemids || count($itemids) > 150) {
            throw new \invalid_parameter_exception('Добавьте от 1 до 150 пунктов.');
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')
            ->get_lock('checklist-publish', 10);
        if (!$lock) { throw new \moodle_exception('Checklist editor is busy'); }
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $draft = $DB->get_record('local_ustar_check_def_ver',
                    ['checklistkey' => $id, 'status' => 'draft']);
                if (($draft ? (int)$draft->revision : 0) !== $expectedrevision) {
                    throw new \invalid_parameter_exception('Черновик изменился. Перезагрузите редактор.');
                }
                if ($draft) {
                    $version = (int)$draft->version;
                    $definition['version'] = $version;
                    $draft->definitionjson = json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    $draft->revision++;
                    $DB->update_record('local_ustar_check_def_ver', $draft);
                } else {
                    $current = self::find($id);
                    if ($current && !$DB->record_exists('local_ustar_check_def_ver',
                            ['checklistkey' => $id, 'version' => 1])) {
                        $DB->insert_record('local_ustar_check_def_ver', (object)[
                            'checklistkey' => $id, 'version' => 1, 'status' => 'published',
                            'definitionjson' => json_encode($current + ['version' => 1], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                            'createdby' => 0, 'timecreated' => time(), 'publishedat' => time(),
                        ]);
                    }
                    $version = (int)$DB->get_field_sql(
                        'SELECT MAX(version) FROM {local_ustar_check_def_ver} WHERE checklistkey = :key',
                        ['key' => $id]) + 1;
                    $definition['version'] = $version;
                    $DB->insert_record('local_ustar_check_def_ver', (object)[
                        'checklistkey' => $id, 'version' => $version, 'status' => 'draft',
                        'definitionjson' => json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        'revision' => 1,
                        'createdby' => (int)$USER->id, 'timecreated' => time(), 'publishedat' => null,
                    ]);
                }
                $transaction->allow_commit();
                return $version;
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
        } finally {
            $lock->release();
        }
    }

    public static function publish_draft(string $id, int $expectedversion, int $expectedrevision): int {
        global $DB;
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')
            ->get_lock('checklist-publish', 10);
        if (!$lock) { throw new \moodle_exception('Checklist publication is busy'); }
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $draft = $DB->get_record('local_ustar_check_def_ver',
                    ['checklistkey' => $id, 'status' => 'draft'], '*', MUST_EXIST);
                if ((int)$draft->version !== $expectedversion
                        || (int)$draft->revision !== $expectedrevision) {
                    throw new \invalid_parameter_exception('Черновик изменился. Перезагрузите редактор.');
                }
                $definition = json_decode((string)$draft->definitionjson, true);
                if (!is_array($definition)) {
                    throw new \coding_exception('Invalid checklist draft');
                }
                $catalogue = self::get();
                $current = self::find($id);
                if ($current && (int)($current['version'] ?? 1) >= (int)$draft->version) {
                    throw new \invalid_parameter_exception('Опубликованная версия изменилась. Создайте новый черновик.');
                }
                $replaced = false;
                foreach ($catalogue['items'] as &$item) {
                    if ((string)$item['id'] === $id) {
                        $item = $definition;
                        $replaced = true;
                        break;
                    }
                }
                unset($item);
                if (!$replaced) { $catalogue['items'][] = $definition; }
                $catalogue['version'] = (int)($catalogue['version'] ?? 1) + 1;
                $draft->status = 'published';
                $draft->publishedat = time();
                $DB->update_record('local_ustar_check_def_ver', $draft);
                structure::save(self::NAME, $catalogue);
                $transaction->allow_commit();
                return (int)$draft->version;
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
        } finally {
            $lock->release();
        }
    }

    public static function flat_items(array $checklist): array {
        $items = [];
        foreach (($checklist['sections'] ?? []) as $section) {
            foreach (($section['items'] ?? []) as $item) {
                if (!empty($item['id']) && !empty($item['title'])) {
                    $items[$item['id']] = $item + ['section' => $section['title'] ?? ''];
                }
            }
        }
        return $items;
    }

    public static function find(string $id): ?array {
        foreach ((self::get()['items'] ?? []) as $checklist) {
            if (($checklist['id'] ?? '') === $id) {
                return $checklist;
            }
        }
        return null;
    }

    public static function applies_to(array $checklist, string $positionid): bool {
        if (empty($checklist['active'])) {
            return false;
        }
        $positions = array_values(array_filter(array_map('strval', $checklist['positionIds'] ?? [])));
        return !$positions || in_array($positionid, $positions, true);
    }
}
