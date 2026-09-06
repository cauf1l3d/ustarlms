<?php
require_once(__DIR__ . '/../../config.php');

require_login();
global $USER;

$context = context_system::instance();
require_capability('local/ustar:use', $context);

$ishr = has_capability('local/ustar:hrmanage', $context);
$scope = \local_ustar\staffing_requests::manager_scope((int)$USER->id);
$ismanager = !empty($scope['allowed']) && has_capability('local/ustar:viewteam', $context);

if (!$ishr && !$ismanager) {
    throw new required_capability_exception($context, 'local/ustar:viewteam', 'nopermissions', '');
}

$parsedate = static function(string $value): int {
    $value = trim($value);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
        return 0;
    }
    return make_timestamp((int)$m[1], (int)$m[2], (int)$m[3], 12, 0, 0);
};

$action = optional_param('action', '', PARAM_ALPHANUMEXT);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
    require_sesskey();
    try {
        if ($action === 'createhire') {
            if (!$ismanager) {
                throw new required_capability_exception($context, 'local/ustar:viewteam', 'nopermissions', '');
            }
            \local_ustar\staffing_requests::create_hire((int)$USER->id, [
                'firstname' => required_param('firstname', PARAM_NOTAGS),
                'lastname' => required_param('lastname', PARAM_NOTAGS),
                'positionid' => required_param('positionid', PARAM_ALPHANUMEXT),
                'requesteddate' => $parsedate(required_param('requesteddate', PARAM_RAW_TRIMMED)),
                'comment' => optional_param('comment', '', PARAM_TEXT),
            ]);
            redirect(new moodle_url('/local/ustar/staffing.php'), 'Заявка на приём отправлена HRD', null, \core\output\notification::NOTIFY_SUCCESS);
        }

        if ($action === 'createterminate') {
            if (!$ismanager) {
                throw new required_capability_exception($context, 'local/ustar:viewteam', 'nopermissions', '');
            }
            \local_ustar\staffing_requests::create_termination((int)$USER->id, [
                'employeeid' => required_param('employeeid', PARAM_INT),
                'requesteddate' => $parsedate(required_param('requesteddate', PARAM_RAW_TRIMMED)),
                'reason' => required_param('reason', PARAM_TEXT),
                'comment' => optional_param('comment', '', PARAM_TEXT),
            ]);
            redirect(new moodle_url('/local/ustar/staffing.php'), 'Заявка на увольнение отправлена HRD', null, \core\output\notification::NOTIFY_SUCCESS);
        }

        if ($action === 'assignadaptation') {
            if (!$ismanager) {
                throw new required_capability_exception($context, 'local/ustar:viewteam', 'nopermissions', '');
            }
            \local_ustar\adaptation_service::assign_from_request(
                required_param('requestid', PARAM_INT),
                (int)$USER->id,
                required_param('startdate', PARAM_RAW_TRIMMED),
                required_param('plannedworkdays', PARAM_INT)
            );
            redirect(new moodle_url('/local/ustar/staffing.php'), 'Адаптационный чек назначен', null, \core\output\notification::NOTIFY_SUCCESS);
        }

        if ($action === 'approve' || $action === 'reject') {
            if (!$ishr) {
                throw new required_capability_exception($context, 'local/ustar:hrmanage', 'nopermissions', '');
            }
            \local_ustar\staffing_requests::review(
                required_param('requestid', PARAM_INT),
                $action === 'approve'
                    ? \local_ustar\staffing_requests::STATUS_APPROVED
                    : \local_ustar\staffing_requests::STATUS_REJECTED,
                (int)$USER->id,
                [
                    'username' => optional_param('username', '', PARAM_USERNAME),
                    'email' => optional_param('email', '', PARAM_EMAIL),
                    'password' => optional_param('password', '', PARAM_RAW),
                    'reviewcomment' => optional_param('reviewcomment', '', PARAM_TEXT),
                ]
            );
            redirect(
                new moodle_url('/local/ustar/staffing.php'),
                $action === 'approve' ? 'Заявка одобрена и исполнена' : 'Заявка отклонена',
                null,
                $action === 'approve' ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_INFO
            );
        }
    } catch (Throwable $e) {
        \core\notification::error($e->getMessage());
    }
}

$requests = \local_ustar\staffing_requests::list_for((int)$USER->id);
$today = userdate(time(), '%Y-%m-%d');

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/staffing.php'));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Заявки на персонал | USTAR Academy');
$PAGE->set_heading('USTAR Academy');
$PAGE->requires->css(new moodle_url('/local/ustar/styles/staffing.css'));

$data = [
    'ishr' => $ishr,
    'ismanager' => $ismanager,
    'department' => (string)($scope['department'] ?? ''),
    'positions' => $scope['positions'] ?? [],
    'haspositions' => !empty($scope['positions']),
    'employees' => $scope['employees'] ?? [],
    'hasemployees' => !empty($scope['employees']),
    'requests' => $requests,
    'hasrequests' => !empty($requests),
    'sesskey' => sesskey(),
    'today' => $today,
    'teamurl' => (new moodle_url('/local/ustar/team.php'))->out(false),
    'hrurl' => $ishr ? (new moodle_url('/local/ustar/hr.php'))->out(false) : '',
];

$output = $PAGE->get_renderer('local_ustar');
echo $output->header();
echo $output->render_from_template('local_ustar/staffing', $data);
echo $output->footer();
