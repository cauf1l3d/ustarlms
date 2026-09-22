<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * USTAR product-knowledge catalog.
 *
 * The table is intentionally generic: group -> subgroup -> detail card.
 * Detail cards may be product, material or assessment records.  Binary
 * assets are stored in Moodle File API, never under the public web root.
 */
final class catalog {
    public const TYPE_GROUP = 'group';
    public const TYPE_SUBGROUP = 'subgroup';
    public const TYPE_PRODUCT = 'product';
    public const TYPE_MATERIAL = 'material';
    public const TYPE_ASSESSMENT = 'assessment';

    public const FILEAREA_IMAGE = 'catalog_image';
    public const FILEAREA_SOURCE = 'catalog_source';

    public static function available(): bool {
        global $DB;
        return $DB->get_manager()->table_exists(new \xmldb_table('local_ustar_catalog'));
    }

    public static function browse(?int $parentid = null, string $q = ''): array {
        global $DB;
        if (!self::available()) {
            return [];
        }

        $params = ['active' => 1];
        $where = 'active = :active';
        $q = trim($q);
        if ($q !== '') {
            $like = '%' . $DB->sql_like_escape($q) . '%';
            $where .= ' AND ('
                . $DB->sql_like('title', ':q1', false) . ' OR '
                . $DB->sql_like('sku', ':q2', false) . ' OR '
                . $DB->sql_like('summary', ':q3', false) . ' OR '
                . $DB->sql_like('description', ':q4', false) . ' OR '
                . $DB->sql_like('attributesjson', ':q5', false)
                . ')';
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like];
        } else if ($parentid === null) {
            $where .= ' AND parentid IS NULL';
        } else {
            $where .= ' AND parentid = :parentid';
            $params['parentid'] = $parentid;
        }

        $rows = [];
        foreach ($DB->get_records_select('local_ustar_catalog', $where, $params, 'sortorder ASC, title ASC') as $record) {
            $rows[] = self::view_record($record);
        }
        return $rows;
    }

    public static function get(int $id): ?\stdClass {
        global $DB;
        if ($id <= 0 || !self::available()) {
            return null;
        }
        return $DB->get_record('local_ustar_catalog', ['id' => $id, 'active' => 1]) ?: null;
    }

    public static function view(int $id): ?array {
        $record = self::get($id);
        return $record ? self::view_record($record) : null;
    }

    public static function ancestors(int $id): array {
        $result = [];
        $seen = [];
        $current = self::get($id);
        while ($current && !empty($current->parentid)) {
            $parentid = (int)$current->parentid;
            if ($parentid <= 0 || isset($seen[$parentid])) {
                break;
            }
            $seen[$parentid] = true;
            $parent = self::get($parentid);
            if (!$parent) {
                break;
            }
            array_unshift($result, [
                'id' => (int)$parent->id,
                'title' => format_string($parent->title),
                'url' => (new \moodle_url('/local/ustar/catalog.php', ['parent' => (int)$parent->id]))->out(false),
            ]);
            $current = $parent;
        }
        return $result;
    }

    public static function stats(): array {
        global $DB;
        if (!self::available()) {
            return ['groups' => 0, 'subgroups' => 0, 'cards' => 0, 'products' => 0, 'assessments' => 0];
        }
        $active = ['active' => 1];
        return [
            'groups' => (int)$DB->count_records('local_ustar_catalog', ['active' => 1, 'itemtype' => self::TYPE_GROUP]),
            'subgroups' => (int)$DB->count_records('local_ustar_catalog', ['active' => 1, 'itemtype' => self::TYPE_SUBGROUP]),
            'cards' => (int)$DB->count_records_select('local_ustar_catalog', 'active = :active AND itemtype <> :g AND itemtype <> :s', $active + ['g' => self::TYPE_GROUP, 's' => self::TYPE_SUBGROUP]),
            'products' => (int)$DB->count_records('local_ustar_catalog', ['active' => 1, 'itemtype' => self::TYPE_PRODUCT]),
            'assessments' => (int)$DB->count_records('local_ustar_catalog', ['active' => 1, 'itemtype' => self::TYPE_ASSESSMENT]),
        ];
    }

    public static function file_url(int $itemid, string $filearea, bool $download = false): string {
        if (!in_array($filearea, [self::FILEAREA_IMAGE, self::FILEAREA_SOURCE], true) || $itemid <= 0) {
            return '';
        }
        $context = \context_system::instance();
        $files = get_file_storage()->get_area_files(
            $context->id,
            'local_ustar',
            $filearea,
            $itemid,
            'filename ASC, id ASC',
            false
        );
        if (!$files) {
            return '';
        }
        $file = reset($files);
        return \moodle_url::make_pluginfile_url(
            $context->id,
            'local_ustar',
            $filearea,
            $itemid,
            $file->get_filepath(),
            $file->get_filename(),
            $download
        )->out(false);
    }

    public static function source_filename(int $itemid): string {
        $context = \context_system::instance();
        $files = get_file_storage()->get_area_files(
            $context->id,
            'local_ustar',
            self::FILEAREA_SOURCE,
            $itemid,
            'filename ASC, id ASC',
            false
        );
        if (!$files) {
            return '';
        }
        return (string)reset($files)->get_filename();
    }

    /** HR authors may edit the catalog even before learner mastery is granted. */
    public static function can_manage(int $userid): bool {
        return capabilities::has($userid, capabilities::CATALOG_WRITE);
    }

    /** @return array<int,\stdClass> */
    public static function editor_records(int $userid): array {
        global $DB;
        self::assert_manage($userid);
        if (!self::available()) { return []; }
        return array_values($DB->get_records('local_ustar_catalog', [], 'parentid ASC, sortorder ASC, title ASC'));
    }

    /** @param array<string,mixed> $input */
    public static function save(int $id, array $input, int $actorid): \stdClass {
        global $DB;
        self::assert_manage($actorid);
        if (!self::available()) {
            throw new \moodle_exception('Каталог будет доступен после обновления базы данных.');
        }
        $type = clean_param((string)($input['itemtype'] ?? self::TYPE_PRODUCT), PARAM_ALPHA);
        $types = [self::TYPE_GROUP, self::TYPE_SUBGROUP, self::TYPE_PRODUCT, self::TYPE_MATERIAL, self::TYPE_ASSESSMENT];
        if (!in_array($type, $types, true)) {
            throw new \invalid_parameter_exception('Неизвестный тип карточки каталога.');
        }
        $title = trim(clean_param((string)($input['title'] ?? ''), PARAM_TEXT));
        if ($title === '') {
            throw new \invalid_parameter_exception('Укажите название карточки.');
        }
        $parentid = (int)($input['parentid'] ?? 0);
        $expected = (int)($input['expectedmodified'] ?? 0);
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')->get_lock('catalog:structure', 10);
        if (!$lock) {
            throw new \moodle_exception('Карточка каталога редактируется в другой сессии. Повторите попытку.');
        }
        try {
            $tx = $DB->start_delegated_transaction();
            self::assert_parent($parentid, $type, $id);
            $now = time();
            $record = null;
            if ($id > 0) {
                $record = $DB->get_record_sql(
                    'SELECT * FROM {local_ustar_catalog} WHERE id = :id FOR UPDATE', ['id' => $id], MUST_EXIST
                );
                if ($expected <= 0 || (int)$record->timemodified !== $expected) {
                    throw new \moodle_exception('Карточка уже изменилась. Обновите страницу.');
                }
                if ($record->itemtype !== $type
                        && $DB->record_exists('local_ustar_catalog', ['parentid' => $id])) {
                    throw new \moodle_exception('Перед изменением типа перенесите вложенные карточки.');
                }
                self::snapshot($record, $actorid);
            } else {
                $record = (object)['timecreated' => $now, 'active' => 1];
            }
            $record->parentid = $parentid > 0 ? $parentid : null;
            $record->itemtype = $type;
            $record->title = $title;
            $record->slug = self::slug((string)($input['slug'] ?? ''), $title);
            $record->sku = trim(clean_param((string)($input['sku'] ?? ''), PARAM_TEXT)) ?: null;
            $record->summary = trim(clean_param((string)($input['summary'] ?? ''), PARAM_TEXT)) ?: null;
            $record->description = clean_text((string)($input['description'] ?? ''), FORMAT_HTML) ?: null;
            $record->imageurl = trim(clean_param((string)($input['imageurl'] ?? ''), PARAM_URL)) ?: null;
            $record->attributesjson = json_encode(self::attributes((string)($input['attributes'] ?? '')),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $record->sortorder = (int)($input['sortorder'] ?? 0);
            $record->active = !isset($input['active']) || !empty($input['active']) ? 1 : 0;
            // A revision must change even when two saves occur in one second.
            $record->timemodified = max($now, $expected + 1);
            $record->usermodified = $actorid;
            if ($id > 0) {
                $DB->update_record('local_ustar_catalog', $record);
            } else {
                $record->id = (int)$DB->insert_record('local_ustar_catalog', $record);
            }
            self::snapshot($record, $actorid);
            self::audit((int)$record->id, 'catalog_saved', $actorid, ['itemtype' => $type]);
            $tx->allow_commit();
            return $record;
        } finally {
            $lock->release();
        }
    }

    public static function archive(int $id, int $actorid, int $expectedmodified): void {
        global $DB;
        self::assert_manage($actorid);
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')->get_lock('catalog:structure', 10);
        if (!$lock) {
            throw new \moodle_exception('Каталог изменяется в другой сессии. Повторите попытку.');
        }
        try {
            $tx = $DB->start_delegated_transaction();
            $record = $DB->get_record('local_ustar_catalog', ['id' => $id], '*', MUST_EXIST);
            if ($expectedmodified <= 0 || (int)$record->timemodified !== $expectedmodified) {
                throw new \moodle_exception('Карточка уже изменилась. Обновите страницу.');
            }
            if ($DB->record_exists('local_ustar_catalog', ['parentid' => $id, 'active' => 1])) {
                throw new \moodle_exception('Сначала перенесите или архивируйте вложенные карточки.');
            }
            self::snapshot($record, $actorid);
            $record->active = 0;
            $record->timemodified = max(time(), $expectedmodified + 1);
            $record->usermodified = $actorid;
            $DB->update_record('local_ustar_catalog', $record);
            self::snapshot($record, $actorid);
            self::audit($id, 'catalog_archived', $actorid, []);
            $tx->allow_commit();
        } finally {
            $lock->release();
        }
    }

    /** @param array<string,mixed> $upload */
    public static function upload_file(int $id, int $actorid, string $filearea, array $upload): void {
        self::assert_manage($actorid);
        if (!in_array($filearea, [self::FILEAREA_IMAGE, self::FILEAREA_SOURCE], true)) {
            throw new \invalid_parameter_exception('Недопустимый тип файла каталога.');
        }
        if (empty($upload['tmp_name']) || !is_uploaded_file((string)$upload['tmp_name'])) {
            return;
        }
        $record = self::get_any($id);
        if (!$record) { throw new \moodle_exception('Карточка каталога не найдена.'); }
        $filename = clean_param((string)($upload['name'] ?? ''), PARAM_FILE);
        if ($filename === '') { throw new \invalid_parameter_exception('У файла нет имени.'); }
        $mimetype = (string)($upload['type'] ?? '');
        if ($filearea === self::FILEAREA_IMAGE
                && !in_array($mimetype, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            throw new \invalid_parameter_exception('Для карточки разрешены JPEG, PNG, WEBP и GIF.');
        }
        $fs = get_file_storage();
        $context = \context_system::instance();
        $fs->delete_area_files($context->id, 'local_ustar', $filearea, $id);
        $fs->create_file_from_pathname((object)[
            'contextid' => $context->id, 'component' => 'local_ustar', 'filearea' => $filearea,
            'itemid' => $id, 'filepath' => '/', 'filename' => $filename, 'userid' => $actorid,
            'mimetype' => $mimetype ?: null,
        ], (string)$upload['tmp_name']);
        self::audit($id, 'catalog_file_uploaded', $actorid, ['filearea' => $filearea, 'filename' => $filename]);
    }

    private static function get_any(int $id): ?\stdClass {
        global $DB;
        return $id > 0 ? ($DB->get_record('local_ustar_catalog', ['id' => $id]) ?: null) : null;
    }

    private static function assert_parent(int $parentid, string $type, int $selfid): void {
        global $DB;
        if ($parentid <= 0) {
            if ($type !== self::TYPE_GROUP) {
                throw new \invalid_parameter_exception('В корне каталога можно создать только раздел.');
            }
            return;
        }
        if ($parentid === $selfid) {
            throw new \invalid_parameter_exception('Карточка не может быть родителем самой себе.');
        }
        $parent = $DB->get_record('local_ustar_catalog', ['id' => $parentid, 'active' => 1], 'id,itemtype', MUST_EXIST);
        if ($type === self::TYPE_SUBGROUP && (string)$parent->itemtype === self::TYPE_GROUP) { return; }
        if (in_array($type, [self::TYPE_PRODUCT, self::TYPE_MATERIAL, self::TYPE_ASSESSMENT], true)
                && (string)$parent->itemtype === self::TYPE_SUBGROUP) { return; }
        throw new \invalid_parameter_exception('Нарушена иерархия: раздел → категория → карточка.');
    }

    /** @return array<string,string> */
    private static function attributes(string $raw): array {
        $raw = trim($raw);
        if ($raw === '') { return []; }
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && !array_is_list($decoded)) {
            return array_filter($decoded, static fn($value, $key): bool => !str_starts_with((string)$key, '_')
                && is_scalar($value), ARRAY_FILTER_USE_BOTH);
        }
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            [$key, $value] = array_pad(explode(':', $line, 2), 2, '');
            $key = trim(clean_param($key, PARAM_TEXT));
            $value = trim(clean_param($value, PARAM_TEXT));
            if ($key !== '' && $value !== '') { $out[$key] = $value; }
        }
        return $out;
    }

    private static function slug(string $slug, string $title): ?string {
        $slug = trim(clean_param($slug, PARAM_ALPHANUMEXT));
        if ($slug !== '') { return $slug; }
        $slug = \core_text::strtolower($title);
        $slug = preg_replace('/[^a-z0-9а-яё]+/ui', '-', $slug);
        return trim((string)$slug, '-') ?: null;
    }

    private static function snapshot(\stdClass $record, int $actorid): void {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ustar_catalog_versions'))) { return; }
        $version = (int)$DB->get_field_sql(
            'SELECT COALESCE(MAX(versionno), 0) FROM {local_ustar_catalog_versions} WHERE catalogid = :id',
            ['id' => (int)$record->id]
        ) + 1;
        $DB->insert_record('local_ustar_catalog_versions', (object)[
            'catalogid' => (int)$record->id, 'versionno' => $version,
            'snapshotjson' => json_encode(get_object_vars($record), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'actorid' => $actorid, 'timecreated' => time(),
        ]);
    }

    /** @param array<string,mixed> $data */
    private static function audit(int $id, string $event, int $actorid, array $data): void {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ustar_workflow_events'))) { return; }
        $DB->insert_record('local_ustar_workflow_events', (object)[
            'entitytype' => 'catalog', 'entityid' => $id, 'eventtype' => $event, 'actorid' => $actorid,
            'reason' => null, 'detailsjson' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'timecreated' => time(),
        ]);
    }

    private static function assert_manage(int $userid): void {
        if (!self::can_manage($userid)) {
            throw new \required_capability_exception(
                \context_system::instance(), 'local/ustar:managecatalog', 'nopermissions', ''
            );
        }
    }

    private static function view_record(\stdClass $record): array {
        $attrs = json_decode((string)$record->attributesjson, true);
        $attrs = is_array($attrs) ? $attrs : [];
        $publicattrs = [];
        foreach ($attrs as $name => $value) {
            if (str_starts_with((string)$name, '_')) {
                continue;
            }
            if (is_scalar($value) && trim((string)$value) !== '') {
                $publicattrs[] = ['name' => (string)$name, 'value' => (string)$value];
            }
        }

        $type = (string)$record->itemtype;
        $isfolder = in_array($type, [self::TYPE_GROUP, self::TYPE_SUBGROUP], true);
        $imageurl = self::file_url((int)$record->id, self::FILEAREA_IMAGE);
        if ($imageurl === '') {
            $imageurl = trim((string)$record->imageurl);
        }
        $sourceurl = self::file_url((int)$record->id, self::FILEAREA_SOURCE, true);

        $typelabel = match ($type) {
            self::TYPE_GROUP => 'Раздел',
            self::TYPE_SUBGROUP => 'Категория',
            self::TYPE_ASSESSMENT => 'Проверка знаний',
            self::TYPE_MATERIAL => 'Материал',
            default => 'Товар',
        };

        return [
            'id' => (int)$record->id,
            'parentid' => (int)$record->parentid,
            'type' => $type,
            'typelabel' => $typelabel,
            'title' => format_string($record->title),
            'sku' => (string)$record->sku,
            'summary' => (string)$record->summary,
            'descriptionhtml' => format_text((string)$record->description, FORMAT_PLAIN, ['para' => true, 'filter' => false]),
            'imageurl' => $imageurl,
            'hasimage' => $imageurl !== '',
            'attrs' => $publicattrs,
            'hasattrs' => !empty($publicattrs),
            'isfolder' => $isfolder,
            'isproduct' => $type === self::TYPE_PRODUCT,
            'ismaterial' => $type === self::TYPE_MATERIAL,
            'isassessment' => $type === self::TYPE_ASSESSMENT,
            'isdetail' => !$isfolder,
            'url' => (new \moodle_url('/local/ustar/catalog.php', ['parent' => (int)$record->id]))->out(false),
            'detailurl' => (new \moodle_url('/local/ustar/catalog.php', ['product' => (int)$record->id]))->out(false),
            'sourceurl' => $sourceurl,
            'hassource' => $sourceurl !== '',
            'sourcefilename' => self::source_filename((int)$record->id),
        ];
    }
}
