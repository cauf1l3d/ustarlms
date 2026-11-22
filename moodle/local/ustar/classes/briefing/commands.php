<?php
namespace local_ustar\briefing;

use local_ustar\task_workspace\calendar;

defined('MOODLE_INTERNAL') || die();

/** Session-protected commands from the Tasks workspace. */
final class commands {
    public static function run(string $action, int $actor, array $state): array {
        $params = ['view' => $state['view'], 'scope' => $state['scope'], 'saved' => 1];
        $week = $state['briefweek'];
        $department = $state['briefdept'];
        $id = $state['meetingid'];
        switch ($action) {
            case 'brief_template':
                $labels = required_param_array('brief_label', PARAM_TEXT);
                $types = required_param_array('brief_type', PARAM_ALPHA);
                $required = required_param_array('brief_required', PARAM_BOOL);
                $fields = [];
                foreach ($labels as $key => $label) {
                    $fields[] = ['label' => trim($label), 'type' => $types[$key] ?? '', 'required' => $required[$key] ?? false];
                }
                service::save_template($actor, $department, required_param('briefrevision', PARAM_INT),
                    required_param('brieftitle', PARAM_TEXT), required_param('brieforder', PARAM_INT),
                    optional_param('briefactive', false, PARAM_BOOL), $fields);
                return $params + ['briefsection' => 'settings', 'briefweek' => $week, 'briefdept' => $department];

            case 'brief_report':
                $form = service::form($actor, $department, $week);
                $answers = [];
                foreach ($form['fields'] as $key => $field) {
                    $answers[$key] = optional_param('briefanswer_' . $key, '', PARAM_RAW);
                }
                service::save_report($actor, $department, $week, required_param('briefrevision', PARAM_INT),
                    required_param('templateversion', PARAM_INT), $answers, required_param('submitreport', PARAM_BOOL));
                return $params + ['briefsection' => 'report', 'briefweek' => $week, 'briefdept' => $department];

            case 'brief_minutes':
                service::save_minutes($actor, $week, required_param('briefrevision', PARAM_INT),
                    optional_param('briefminutes', '', PARAM_RAW));
                return $params + ['briefsection' => 'minutes', 'briefweek' => $week];

            case 'brief_schedule':
                meetings::schedule($actor, required_param('deadline', PARAM_INT), required_param('briefroom', PARAM_INT));
                return $params + ['briefsection' => 'settings', 'briefweek' => $week];

            case 'meeting_room':
                meetings::save_room($actor, required_param('roomid', PARAM_INT), required_param('briefrevision', PARAM_INT),
                    required_param('roomname', PARAM_TEXT), required_param('capacity', PARAM_INT),
                    optional_param('roomactive', false, PARAM_BOOL));
                return $params + ['meetingsection' => 'rooms'];

            case 'meeting_save':
                $id = meetings::save($actor, $id, required_param('briefrevision', PARAM_INT),
                    required_param('meetingkey', PARAM_ALPHANUM), required_param('meetingtitle', PARAM_TEXT),
                    optional_param('agenda', '', PARAM_RAW), required_param('roomid', PARAM_INT),
                    self::local_time(required_param('starttime', PARAM_RAW_TRIMMED)),
                    self::local_time(required_param('endtime', PARAM_RAW_TRIMMED)),
                    optional_param_array('participants', [], PARAM_INT));
                return $params + ['meetingid' => $id];

            case 'meeting_cancel':
                meetings::cancel($actor, $id, required_param('briefrevision', PARAM_INT));
                return $params + ['meetingid' => $id];

            case 'meeting_rsvp':
                meetings::respond($actor, $id, required_param('briefrevision', PARAM_INT), required_param('rsvp', PARAM_ALPHA),
                    optional_param('expectedrsvp', null, PARAM_ALPHA));
                return $params + ['meetingid' => $id];

            default:
                throw new input_error('Неизвестное действие брифинга.');
        }
    }

    public static function local_time(string $value): int {
        if (!preg_match('/\A(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})\z/', $value, $match)) {
            throw new input_error('Укажите дату и время встречи.');
        }
        try {
            return calendar::timestamp($match[1], $match[2], service::ZONE);
        } catch (\invalid_parameter_exception $error) {
            throw new input_error('Проверьте дату и время встречи.');
        }
    }
}
