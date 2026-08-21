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
