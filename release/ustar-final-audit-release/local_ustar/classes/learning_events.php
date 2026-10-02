<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Immutable material learning events and the derived personal library.
 *
 * The library is deliberately not an access catalogue. A row is created only
 * after an employee opens a material through an unlocked route checkpoint.
 */
final class learning_events {
    public const EVENT_OPENED = 'route_material_opened';
    public const EVENT_STUDIED = 'route_material_studied';
    public const EVENT_MOVED = 'content_moved';

    private static function insert_event(array $data): int {
        global $DB;

        $key = (string)$data['idempotencykey'];
        $existing = $DB->get_field('local_ustar_content_events', 'id', ['idempotencykey' => $key]);
        if ($existing) {
            return (int)$existing;
        }

        try {
            return (int)$DB->insert_record('local_ustar_content_events', (object)[
                'actorid' => (int)($data['actorid'] ?? 0),
                'userid' => !empty($data['userid']) ? (int)$data['userid'] : null,
                'contentid' => (int)$data['contentid'],
                'contentversionid' => !empty($data['contentversionid']) ? (int)$data['contentversionid'] : null,
                'routepointid' => !empty($data['routepointid']) ? (int)$data['routepointid'] : null,
                'routeversionid' => !empty($data['routeversionid']) ? (int)$data['routeversionid'] : null,
                'eventtype' => clean_param((string)$data['eventtype'], PARAM_ALPHANUMEXT),
                'idempotencykey' => $key,
                'detailsjson' => json_encode($data['details'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'timecreated' => (int)($data['timecreated'] ?? time()),
            ]);
        } catch (\dml_write_exception $e) {
            $existing = $DB->get_field('local_ustar_content_events', 'id', ['idempotencykey' => $key]);
            if (!$existing) {
                throw $e;
            }
            return (int)$existing;
        }
    }

    public static function record_route_open(
        int $userid,
        int $contentid,
        int $pointid,
        int $routeversionid
    ): int {
        global $DB;

        $content = $DB->get_record('local_ustar_content', ['id' => $contentid], '*', MUST_EXIST);
        if ((string)$content->status !== content::STATUS_PUBLISHED || !content::can_access_record($content, $userid)) {
            throw new \required_capability_exception(
                \context_system::instance(), 'local/ustar:use', 'nopermissions', ''
            );
        }
        $contentversion = content::current_version($contentid);
        if (!$contentversion || empty($contentversion->iscurrent) || (string)$contentversion->status !== content::STATUS_PUBLISHED) {
            throw new \moodle_exception('У материала нет текущей опубликованной версии');
        }

        $now = time();
        $transaction = $DB->start_delegated_transaction();
        $eventid = self::insert_event([
            'actorid' => $userid,
            'userid' => $userid,
            'contentid' => $contentid,
            'contentversionid' => (int)$contentversion->id,
            'routepointid' => $pointid,
            'routeversionid' => $routeversionid,
            'eventtype' => self::EVENT_OPENED,
            'idempotencykey' => 'route-open:' . $userid . ':' . $contentid . ':' . $pointid . ':' . $routeversionid,
            'details' => ['source' => 'route_gateway'],
            'timecreated' => $now,
        ]);

        $library = $DB->get_record('local_ustar_library', ['userid' => $userid, 'contentid' => $contentid]);
        if (!$library) {
            try {
                $DB->insert_record('local_ustar_library', (object)[
                    'userid' => $userid,
                    'contentid' => $contentid,
                    'unlockedversionid' => (int)$contentversion->id,
                    'firsteventid' => $eventid,
                    'routepointid' => $pointid,
                    'routeversionid' => $routeversionid,
                    'unlockedat' => $now,
                    'lastaccessedat' => $now,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
            } catch (\dml_write_exception $e) {
                if (!$DB->record_exists('local_ustar_library', ['userid' => $userid, 'contentid' => $contentid])) {
                    throw $e;
                }
            }
        } else {
            $library->lastaccessedat = $now;
            $library->timemodified = $now;
            $DB->update_record('local_ustar_library', $library);
        }
        $transaction->allow_commit();
        return $eventid;
    }

    public static function record_route_studied(
        int $userid,
        int $contentid,
        int $pointid,
        int $routeversionid
    ): int {
        global $DB;
        $opened = $DB->get_record('local_ustar_content_events', [
            'userid' => $userid,
            'contentid' => $contentid,
            'routepointid' => $pointid,
            'routeversionid' => $routeversionid,
            'eventtype' => self::EVENT_OPENED,
        ], '*', MUST_EXIST);
        $version = content::current_version($contentid);
        return self::insert_event([
            'actorid' => $userid,
            'userid' => $userid,
            'contentid' => $contentid,
            'contentversionid' => $version ? (int)$version->id : 0,
            'routepointid' => $pointid,
            'routeversionid' => $routeversionid,
            'eventtype' => self::EVENT_STUDIED,
            'idempotencykey' => 'route-studied:' . $userid . ':' . $contentid . ':' . $pointid . ':' . $routeversionid,
            'details' => ['opened_event_id' => (int)$opened->id],
        ]);
    }

    public static function route_fact(
        int $userid,
        int $contentid,
        int $pointid,
        int $routeversionid,
        string $mode
    ): ?\stdClass {
        global $DB;
        $eventtype = $mode === 'ack' ? self::EVENT_STUDIED : self::EVENT_OPENED;
        return $DB->get_record('local_ustar_content_events', [
            'userid' => $userid,
            'contentid' => $contentid,
            'routepointid' => $pointid,
            'routeversionid' => $routeversionid,
            'eventtype' => $eventtype,
        ]) ?: null;
    }

    public static function library_for_user(int $userid): array {
        global $DB;
        $rows = $DB->get_records('local_ustar_library', ['userid' => $userid], 'lastaccessedat DESC, id DESC');
        if (!$rows) {
            return [];
        }
        $allowed = [];
        foreach (content::list_for_user($userid) as $item) {
            $allowed[(int)$item['id']] = $item;
        }
        $result = [];
        foreach ($rows as $row) {
            $contentid = (int)$row->contentid;
            if (!isset($allowed[$contentid])) {
                continue;
            }
            $item = $allowed[$contentid];
            $item['library_unlockedat'] = (int)$row->unlockedat;
            $item['library_lastaccessedat'] = (int)$row->lastaccessedat;
            $item['library_routepointid'] = (int)$row->routepointid;
            $result[] = $item;
        }
        return $result;
    }

    public static function record_content_move(
        int $actorid,
        int $contentid,
        int $oldparentid,
        int $newparentid,
        int $expectedmodified
    ): int {
        return self::insert_event([
            'actorid' => $actorid,
            'contentid' => $contentid,
            'eventtype' => self::EVENT_MOVED,
            'idempotencykey' => 'content-move:' . sha1(implode(':', [
                $actorid, $contentid, $oldparentid, $newparentid, $expectedmodified, microtime(true), random_int(1, PHP_INT_MAX),
            ])),
            'details' => [
                'oldparentid' => $oldparentid,
                'newparentid' => $newparentid,
                'expectedmodified' => $expectedmodified,
            ],
        ]);
    }
}
