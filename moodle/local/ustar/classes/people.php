<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** People-domain helpers shared by HR and executive endpoints. */
class people {
    public static function position_map(array $structure): array {
        $map = [];
        foreach ($structure['positions'] ?? [] as $position) {
            $map[$position['id']] = $position;
        }
        return $map;
    }

    public static function department_map(array $structure): array {
        $map = [];
        foreach ($structure['departments'] ?? [] as $department) {
            $map[$department['id']] = $department;
        }
        return $map;
    }

    public static function position_id(int $userid): string {
        global $DB, $USER;
        if ((int)$USER->id === $userid && class_exists('\\local_ustar\\view_as') && view_as::active()) { return view_as::position_id(); }
        $sql = "SELECT d.data
                  FROM {user_info_data} d
                  JOIN {user_info_field} f ON f.id = d.fieldid
                 WHERE d.userid = :uid AND f.shortname = 'ustar_position'";
        $value = $DB->get_field_sql($sql, ['uid' => $userid]);
        return trim((string)$value);
    }

    public static function set_position_id(int $userid, string $positionid): void {
        global $DB;
        $field = $DB->get_record('user_info_field', ['shortname' => 'ustar_position'], '*', MUST_EXIST);
        $record = $DB->get_record('user_info_data', ['userid' => $userid, 'fieldid' => $field->id]);
        if ($record) {
            $record->data = $positionid;
            $record->dataformat = 0;
            $DB->update_record('user_info_data', $record);
        } else {
            $DB->insert_record('user_info_data', (object)[
                'userid' => $userid,
                'fieldid' => $field->id,
                'data' => $positionid,
                'dataformat' => 0,
            ]);
        }
    }

    public static function log_action(int $actorid, ?int $targetuserid, string $action, array $details = []): void {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ustar_hr_actions'))) {
            return;
        }
        $DB->insert_record('local_ustar_hr_actions', (object)[
            'actorid' => $actorid,
            'targetuserid' => $targetuserid,
            'action' => $action,
            'detailsjson' => json_encode($details, JSON_UNESCAPED_UNICODE),
            'timecreated' => time(),
        ]);
    }
}
