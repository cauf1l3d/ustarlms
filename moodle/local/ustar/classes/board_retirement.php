<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * One-way retirement of legacy DJGMS boards. Board documents are copied to an
 * owner-only archive before their live records are marked deleted.
 */
final class board_retirement {
    public static function available(): bool {
        global $DB;
        $manager = $DB->get_manager();
        return $manager->table_exists(new \xmldb_table('local_ustar_board_archive'));
    }

    /** @return array{archived:int,already:int} */
    public static function archive_live_boards(int $actorid = 0): array {
        global $DB;
        if (!self::available() || !$DB->get_manager()->table_exists(new \xmldb_table('local_ustar_boards'))) {
            return ['archived' => 0, 'already' => 0];
        }
        $archived = 0;
        $already = 0;
        // Preserve soft-deleted history as well: the source table is dropped
        // only after this migration has verified every document.
        $rows = $DB->get_records('local_ustar_boards', [], 'id ASC');
        foreach ($rows as $board) {
            $existing = $DB->get_record('local_ustar_board_archive', ['boardid' => (int)$board->id], '*', IGNORE_MISSING);
            if ($existing) {
                if ((int)$existing->ownerid !== (int)$board->ownerid
                        || !hash_equals((string)$existing->checksum, hash('sha256', (string)$board->documentjson))
                        || (string)$existing->documentjson !== (string)$board->documentjson) {
                    throw new \moodle_exception('Board archive mismatch; source table must be preserved.');
                }
                $already++;
            } else {
                $DB->insert_record('local_ustar_board_archive', (object)[
                    'boardid' => (int)$board->id, 'ownerid' => (int)$board->ownerid,
                    'title' => (string)$board->title, 'documentjson' => (string)$board->documentjson,
                    'version' => (int)$board->version, 'sharedteam' => !empty($board->sharedteam) ? 1 : 0,
                    'archivedat' => time(), 'archivedby' => $actorid,
                    'checksum' => hash('sha256', (string)$board->documentjson),
                ]);
                $archived++;
            }
            $board->deleted = 1;
            $board->timemodified = time();
            $DB->update_record('local_ustar_boards', $board);
        }
        return ['archived' => $archived, 'already' => $already];
    }

    /** @return array<int,array<string,mixed>> */
    public static function own_archive(int $userid): array {
        global $DB;
        if (!self::available()) { return []; }
        $out = [];
        foreach ($DB->get_records('local_ustar_board_archive', ['ownerid' => $userid], 'archivedat DESC, id DESC') as $row) {
            $out[] = [
                'id' => (int)$row->id, 'title' => format_string((string)$row->title),
                'version' => (int)$row->version, 'archivedat' => (int)$row->archivedat,
                'url' => (new \moodle_url('/local/ustar/board_archive.php', ['id' => (int)$row->id]))->out(false),
            ];
        }
        return $out;
    }

    public static function get_for_owner(int $archiveid, int $userid): ?\stdClass {
        global $DB;
        if (!self::available() || $archiveid <= 0) { return null; }
        return $DB->get_record('local_ustar_board_archive', ['id' => $archiveid, 'ownerid' => $userid], '*', IGNORE_MISSING) ?: null;
    }
}
