<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Server-side board persistence and conservative team sharing. */
final class boards {
    public static function available(): bool {
        global $DB;
        return $DB->get_manager()->table_exists(new \xmldb_table('local_ustar_boards'));
    }

    public static function list_for_user(int $userid): array {
        global $DB;
        if (!self::available()) {
            return [];
        }

        // Query shared records too, then enforce the USTAR team boundary below.
        $sql = 'SELECT * FROM {local_ustar_boards}
                 WHERE deleted = 0
                   AND (ownerid = :uid OR sharedteam = 1)
              ORDER BY timemodified DESC';
        $out = [];
        foreach ($DB->get_records_sql($sql, ['uid' => $userid]) as $record) {
            $ownerid = (int)$record->ownerid;
            if ($ownerid !== $userid && !self::same_team($ownerid, $userid)) {
                continue;
            }
            $out[] = [
                'id' => (int)$record->id,
                'title' => format_string($record->title),
                'version' => (int)$record->version,
                'shared' => !empty($record->sharedteam),
                'owned' => $ownerid === $userid,
                'date' => userdate((int)$record->timemodified, '%d.%m.%Y %H:%M'),
                'url' => (new \moodle_url('/local/ustar/boards.php', ['id' => (int)$record->id]))->out(false),
            ];
        }
        return $out;
    }

    public static function create(int $userid, string $title = 'Новая доска'): int {
        global $DB;
        if (!self::available()) {
            throw new \moodle_exception('Хранилище досок ещё не установлено');
        }
        $now = time();
        return (int)$DB->insert_record('local_ustar_boards', (object)[
            'ownerid' => $userid,
            'title' => clean_param($title, PARAM_TEXT) ?: 'Новая доска',
            'documentjson' => '{"pages":[{"id":"page1","name":"Страница 1","children":[]}]}',
            'version' => 1,
            'sharedteam' => 0,
            'deleted' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    public static function get_for_user(int $id, int $userid): ?\stdClass {
        global $DB;
        if (!self::available()) {
            return null;
        }
        $record = $DB->get_record('local_ustar_boards', ['id' => $id, 'deleted' => 0]);
        if (!$record) {
            return null;
        }
        $ownerid = (int)$record->ownerid;
        if ($ownerid !== $userid && (empty($record->sharedteam) || !self::same_team($ownerid, $userid))) {
            return null;
        }
        return $record;
    }

    public static function save(int $id, int $userid, string $json, int $expectedversion): int {
        global $DB;

        // Serialize the expected-version check with the update. A plain read
        // followed by update allows concurrent requests to validate the same
        // version and silently overwrite one another.
        $transaction = $DB->start_delegated_transaction();
        try {
            $record = $DB->get_record_sql(
                'SELECT *
                   FROM {local_ustar_boards}
                  WHERE id = :id
                    AND ownerid = :ownerid
                    AND deleted = 0
                  FOR UPDATE',
                ['id' => $id, 'ownerid' => $userid],
                MUST_EXIST
            );
            if ((int)$record->version !== $expectedversion) {
                throw new \moodle_exception('Доска уже была изменена в другой сессии. Обновите страницу.');
            }
            json_decode($json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \invalid_parameter_exception('Некорректный JSON документа');
            }
            // Keep one board from becoming an unbounded request payload.
            if (strlen($json) > 10 * 1024 * 1024) {
                throw new \invalid_parameter_exception('Документ доски превышает лимит 10 МБ');
            }
            $record->documentjson = $json;
            $record->version++;
            $record->timemodified = time();
            $DB->update_record('local_ustar_boards', $record);
            $transaction->allow_commit();
            return (int)$record->version;
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Team-sharing boundary. Explicit USTAR department membership is used here;
     * if a user's position cannot be resolved, sharing fails closed.
     */
    private static function same_team(int $ownerid, int $viewerid): bool {
        if ($ownerid === $viewerid || is_siteadmin($viewerid)) {
            return true;
        }
        try {
            $owner = structure::resolve_user($ownerid);
            $viewer = structure::resolve_user($viewerid);
            $ownerdept = (string)($owner['position']['department'] ?? '');
            $viewerdept = (string)($viewer['position']['department'] ?? '');
            return $ownerdept !== '' && $ownerdept === $viewerdept;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
