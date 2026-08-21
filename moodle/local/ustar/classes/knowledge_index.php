<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Stable ACL-aware handoff boundary for the next AI/RAG phase.
 *
 * No LLMs, embeddings, vector DBs, API keys or extraction are implemented
 * here. The service only enumerates current published knowledge and provides
 * stable source references plus access metadata.
 */
class knowledge_index {
    public const CAP_INDEX = 'local/ustar:admin';

    public static function require_privileged(): void {
        global $USER;

        $actorid = (int)$USER->id;
        $context = \context_system::instance();

        if (is_siteadmin($actorid)) {
            return;
        }

        if (!has_capability(self::CAP_INDEX, $context, $actorid)) {
            throw new \required_capability_exception(
                $context,
                self::CAP_INDEX,
                'nopermissions',
                ''
            );
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function enumerate_for_user(int $userid): array {
        global $DB;

        self::require_actor_can_query($userid);

        $rows = [];
        $contents = $DB->get_records(
            'local_ustar_content',
            ['status' => content::STATUS_PUBLISHED],
            'sortorder ASC, id ASC'
        );

        foreach ($contents as $item) {
            if (!content::can_access((int)$item->id, $userid)) {
                continue;
            }

            $row = self::build_row($item, false);
            if ($row && $row['indexable']) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    public static function enumerate_privileged(): array {
        global $DB;

        self::require_privileged();

        $rows = [];
        foreach ($DB->get_records(
            'local_ustar_content',
            ['status' => content::STATUS_PUBLISHED],
            'sortorder ASC, id ASC'
        ) as $item) {
            $row = self::build_row($item, true);
            if ($row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @return array<string,mixed>|null */
    public static function item_for_user(int $contentid, int $userid): ?array {
        global $DB;

        self::require_actor_can_query($userid);

        if (!content::can_access($contentid, $userid)) {
            return null;
        }

        $item = $DB->get_record(
            'local_ustar_content',
            ['id' => $contentid, 'status' => content::STATUS_PUBLISHED]
        );

        return $item ? self::build_row($item, false) : null;
    }

    /**
     * Employee-facing calls may only query the current user. Querying another
     * user is an administrative operation and requires the indexing capability.
     */
    private static function require_actor_can_query(int $userid): void {
        global $USER;

        if ((int)$USER->id === $userid) {
            return;
        }

        self::require_privileged();
    }

    /** @return array<string,mixed>|null */
    private static function build_row(\stdClass $item, bool $withscope): ?array {
        $version = content::current_version((int)$item->id);
        if (!$version) {
            return null;
        }

        $live = !empty($version->iscurrent)
            && $version->status === content::STATUS_PUBLISHED
            && $item->status === content::STATUS_PUBLISHED;

        $source = self::stable_source($item, (int)$version->id);
        $mimetype = self::resolve_mimetype($item, (int)$version->id);
        $sourcevalid = (string)$item->sourcekind === content::SOURCE_FILE
            ? $mimetype !== ''
            : self::source_is_resolvable($source);

        $row = [
            'contentid' => (int)$item->id,
            'versionid' => (int)$version->id,
            'versionno' => (int)$version->versionno,
            'versionlabel' => $version->versionlabel ?: ('v' . $version->versionno),
            'title' => (string)$item->title,
            'summary' => (string)($item->summary ?? ''),
            'category' => (string)($item->category ?? ''),
            'type' => (string)$item->type,
            'mimetype' => $mimetype,
            'sourcekind' => (string)$item->sourcekind,
            'iscurrent' => !empty($version->iscurrent),
            'updated' => max(
                (int)($item->timemodified ?? 0),
                (int)($version->timecreated ?? 0)
            ),
            'indexable' => $live && $sourcevalid,
            'stable_ref' => 'ustar:' . (int)$item->id . ':version:' . (int)$version->id,
            'source' => $source,
        ];

        if ($withscope) {
            $row['scope'] = self::scope_metadata((int)$item->id);
        }

        return $row;
    }

    private static function resolve_mimetype(\stdClass $item, int $versionid): string {
        if ((string)$item->sourcekind === content::SOURCE_FILE) {
            $files = get_file_storage()->get_area_files(
                \context_system::instance()->id,
                'local_ustar',
                'content_version',
                $versionid,
                'sortorder DESC, id ASC',
                false
            );

            foreach ($files as $file) {
                if (!$file->is_directory()) {
                    return (string)$file->get_mimetype();
                }
            }
            return '';
        }

        if ((string)$item->sourcekind === content::SOURCE_MOODLE) {
            return 'application/x-moodle-activity';
        }

        if ((string)$item->sourcekind === content::SOURCE_EXTERNAL) {
            return 'text/uri-list';
        }

        return '';
    }

    /** @return array<string,mixed> */
    private static function stable_source(\stdClass $item, int $versionid): array {
        $kind = (string)$item->sourcekind;

        if ($kind === content::SOURCE_FILE) {
            return [
                'kind' => content::SOURCE_FILE,
                'contextid' => \context_system::instance()->id,
                'component' => 'local_ustar',
                'filearea' => 'content_version',
                'itemid' => $versionid,
            ];
        }

        if ($kind === content::SOURCE_MOODLE) {
            return [
                'kind' => content::SOURCE_MOODLE,
                'courseid' => (int)($item->courseid ?? 0),
                'cmid' => (int)($item->cmid ?? 0),
            ];
        }

        return [
            'kind' => content::SOURCE_EXTERNAL,
            'url' => (string)($item->externalurl ?? ''),
        ];
    }

    private static function source_is_resolvable(array $source): bool {
        global $DB;

        $kind = (string)($source['kind'] ?? '');

        if ($kind === content::SOURCE_FILE) {
            return !empty($source['itemid']);
        }

        if ($kind === content::SOURCE_MOODLE) {
            $courseid = (int)($source['courseid'] ?? 0);
            $cmid = (int)($source['cmid'] ?? 0);
            if ($courseid <= 0 || $cmid <= 0) {
                return false;
            }

            // A published catalog reference can become stale later if an
            // administrator hides/deletes its Moodle source. Never hand such
            // a source to the indexing pipeline as currently indexable.
            return $DB->record_exists_sql(
                'SELECT 1
                   FROM {course_modules} cm
                   JOIN {course} c ON c.id = cm.course
                  WHERE cm.id = :cmid
                    AND c.id = :courseid
                    AND cm.visible = 1
                    AND c.visible = 1',
                [
                    'cmid' => $cmid,
                    'courseid' => $courseid,
                ]
            );
        }

        if ($kind === content::SOURCE_EXTERNAL) {
            return trim((string)($source['url'] ?? '')) !== '';
        }

        return false;
    }

    /** @return array<int,array{scopetype:string,scopeid:string}> */
    private static function scope_metadata(int $contentid): array {
        global $DB;

        $out = [];
        foreach ($DB->get_records(
            'local_ustar_content_access',
            ['contentid' => $contentid, 'active' => 1],
            'id ASC'
        ) as $rule) {
            $out[] = [
                'scopetype' => trim((string)$rule->scopetype),
                'scopeid' => trim((string)$rule->scopeid),
            ];
        }

        return $out;
    }
}
