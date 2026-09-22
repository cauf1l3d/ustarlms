<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Self-registration bridge into the existing staffing workflow. */
final class registration_service {
    public static function initialize(int $userid): void {
        global $DB;
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id,auth', IGNORE_MISSING);
        if (!$user || (string)$user->auth !== 'email' || !accounts::is_business_account($userid)) {
            return;
        }
        employment::register_pending($userid);
    }

    public static function submit(int $userid, string $positionid): int {
        global $DB, $USER;
        // A few import/test paths create users without dispatching observers;
        // email-auth users are still safely lowered before accepting a request.
        self::initialize($userid);
        if ((int)$USER->id !== $userid || $userid <= 1 || !accounts::is_business_account($userid)) {
            throw new \invalid_parameter_exception('Заявку можно отправить только из своего профиля.');
        }
        view_as::assert_writable();
        if (employment::resolve($userid)['status'] !== employment::PENDING) {
            throw new \invalid_parameter_exception('Регистрация уже обработана.');
        }
        $positionid = clean_param(trim($positionid), PARAM_ALPHANUMEXT);
        $positions = people::position_map(structure::get(structure::NAME_STRUCTURE));
        $position = $positions[$positionid] ?? null;
        if (!$position || empty($position['department'])) {
            throw new \invalid_parameter_exception('Выберите должность из справочника USTAR.');
        }
        $existing = $DB->get_record('local_ustar_staff_requests', [
            'requesttype' => staffing_requests::TYPE_REGISTRATION,
            'employeeid' => $userid,
            'status' => staffing_requests::STATUS_PENDING,
        ], '*', IGNORE_MISSING);
        if ($existing) {
            if ((string)$existing->positionid !== $positionid) {
                throw new \invalid_parameter_exception('У вас уже есть заявка на другую должность. Дождитесь решения.');
            }
            return (int)$existing->id;
        }
        if ($DB->record_exists('local_ustar_staff_requests', [
            'requesttype' => staffing_requests::TYPE_REGISTRATION,
            'employeeid' => $userid,
            'status' => staffing_requests::STATUS_APPROVED,
        ])) {
            throw new \invalid_parameter_exception('Регистрация уже подтверждена.');
        }
        $user = $DB->get_record('user', ['id' => $userid], 'id,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename', MUST_EXIST);
        $now = time();
        $id = (int)$DB->insert_record('local_ustar_staff_requests', (object)[
            'requesttype' => staffing_requests::TYPE_REGISTRATION,
            'departmentid' => (string)$position['department'],
            'positionid' => $positionid,
            'employeeid' => $userid,
            'firstname' => (string)$user->firstname,
            'lastname' => (string)$user->lastname,
            'requesteddate' => $now,
            'comment' => null,
            'reason' => null,
            'status' => staffing_requests::STATUS_PENDING,
            'requestedby' => $userid,
            'reviewedby' => null,
            'reviewcomment' => null,
            'createduserid' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'reviewedat' => null,
        ]);
        people::log_action($userid, $userid, 'registration_requested', [
            'requestid' => $id, 'positionid' => $positionid,
            'departmentid' => (string)$position['department'],
        ]);
        self::notify_reviewers($id, $positionid, (string)$position['department'], fullname($user));
        return $id;
    }

    public static function state(int $userid): array {
        global $DB;
        $employment = employment::resolve($userid);
        $requests = $DB->get_records_select('local_ustar_staff_requests',
            'requesttype = :type AND employeeid = :userid',
            ['type' => staffing_requests::TYPE_REGISTRATION, 'userid' => $userid],
            'id DESC', '*', 0, 1);
        $request = $requests ? reset($requests) : null;
        return ['employment' => $employment, 'request' => $request];
    }

    private static function notify_reviewers(int $requestid, string $positionid,
            string $departmentid, string $fullname): void {
        global $DB;
        $recipients = [];
        foreach ($DB->get_records('user', ['deleted' => 0, 'suspended' => 0], '', 'id') as $candidate) {
            $candidateid = (int)$candidate->id;
            if (!has_capability('local/ustar:viewteam', \context_system::instance(), $candidateid)) continue;
            $scope = organization_model::manager_scope($candidateid);
            if (!empty($scope['allowed']) && (string)$scope['departmentid'] === $departmentid
                    && in_array($positionid, array_column($scope['positions'] ?? [], 'id'), true)) {
                $recipients[$candidateid] = true;
            }
        }
        foreach (array_keys($recipients) as $recipientid) {
            target_core::notify([
                'userid' => $recipientid,
                'severity' => 'normal',
                'eventtype' => 'registration_requested',
                'subject' => 'Новая заявка на подтверждение должности',
                'message' => $fullname . ' указал должность для подтверждения.',
                'actionurl' => (new \moodle_url('/local/ustar/staffing.php'))->out(false),
                'idempotencykey' => 'registration-requested:' . $requestid . ':' . $recipientid,
            ]);
        }
    }
}
