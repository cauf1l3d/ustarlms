<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Staffing request workflow: department head -> HRD -> execution.
 */
final class staffing_requests {
    public const TYPE_HIRE = 'hire';
    public const TYPE_TERMINATE = 'terminate';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    /**
     * Department scope of a position-derived head.
     */
    public static function manager_scope(int $userid): array {
        return organization_model::manager_scope($userid);
    }

    private static function require_manager(int $actorid): array {
        $context = \context_system::instance();
        require_capability('local/ustar:viewteam', $context);
        $scope = self::manager_scope($actorid);
        if (empty($scope['allowed'])) {
            throw new \required_capability_exception(
                $context,
                'local/ustar:viewteam',
                'nopermissions',
                ''
            );
        }
        return $scope;
    }

    public static function create_hire(int $actorid, array $input): int {
        global $DB;

        $scope = self::require_manager($actorid);
        $positionid = clean_param(trim((string)($input['positionid'] ?? '')), PARAM_ALPHANUMEXT);
        $firstname = clean_param(trim((string)($input['firstname'] ?? '')), PARAM_NOTAGS);
        $lastname = clean_param(trim((string)($input['lastname'] ?? '')), PARAM_NOTAGS);
        $comment = clean_param(trim((string)($input['comment'] ?? '')), PARAM_TEXT);
        $requesteddate = (int)($input['requesteddate'] ?? 0);

        if ($firstname === '' || $lastname === '' || $positionid === '' || $requesteddate <= 0) {
            throw new \invalid_parameter_exception('Заполните ФИО, должность и дату выхода');
        }

        $allowed = array_column($scope['positions'], 'id');
        if (!in_array($positionid, $allowed, true)) {
            throw new \invalid_parameter_exception('Должность должна находиться в вашем управленческом контуре');
        }

        $structure = structure::get(structure::NAME_STRUCTURE);
        $positions = people::position_map($structure);
        $selectedposition = $positions[$positionid] ?? null;
        if (!$selectedposition) {
            throw new \invalid_parameter_exception('Должность не найдена');
        }
        $departmentid = (string)($selectedposition['department'] ?? '');
        if ($departmentid === '') {
            throw new \invalid_parameter_exception('У должности не определено подразделение');
        }

        $now = time();
        $request = (object)[
            'requesttype' => self::TYPE_HIRE,
            'departmentid' => $departmentid,
            'positionid' => $positionid,
            'employeeid' => null,
            'firstname' => $firstname,
            'lastname' => $lastname,
            'requesteddate' => $requesteddate,
            'comment' => $comment !== '' ? $comment : null,
            'reason' => null,
            'status' => self::STATUS_PENDING,
            'requestedby' => $actorid,
            'reviewedby' => null,
            'reviewcomment' => null,
            'createduserid' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'reviewedat' => null,
        ];

        $id = (int)$DB->insert_record('local_ustar_staff_requests', $request);
        people::log_action($actorid, null, 'staffing_hire_requested', [
            'requestid' => $id,
            'departmentid' => $departmentid,
            'positionid' => $positionid,
            'requesteddate' => $requesteddate,
        ]);
        return $id;
    }

    public static function create_termination(int $actorid, array $input): int {
        global $DB;

        $scope = self::require_manager($actorid);
        $employeeid = (int)($input['employeeid'] ?? 0);
        $reason = clean_param(trim((string)($input['reason'] ?? '')), PARAM_TEXT);
        $comment = clean_param(trim((string)($input['comment'] ?? '')), PARAM_TEXT);
        $requesteddate = (int)($input['requesteddate'] ?? 0);

        if ($employeeid <= 0 || $requesteddate <= 0 || $reason === '') {
            throw new \invalid_parameter_exception('Выберите сотрудника, дату и укажите причину');
        }
        if ($employeeid === $actorid) {
            throw new \invalid_parameter_exception('Нельзя создать заявку на увольнение самого себя');
        }

        $employee = $DB->get_record('user', ['id' => $employeeid, 'deleted' => 0], '*', MUST_EXIST);
        if (is_siteadmin($employee) || has_capability('local/ustar:admin', \context_system::instance(), $employeeid)) {
            throw new \required_capability_exception(
                \context_system::instance(),
                'local/ustar:viewteam',
                'nopermissions',
                ''
            );
        }

        $allowedusers = array_map('intval', $scope['userids'] ?? []);
        if (!in_array($employeeid, $allowedusers, true)) {
            throw new \invalid_parameter_exception('Можно подавать заявку только на сотрудника своего управленческого контура');
        }

        $structure = structure::get(structure::NAME_STRUCTURE);
        $positions = people::position_map($structure);
        $positionid = people::position_id($employeeid);
        $position = $positions[$positionid] ?? null;
        if (!$position) {
            throw new \invalid_parameter_exception('Должность сотрудника не найдена');
        }
        $departmentid = (string)($position['department'] ?? '');

        $now = time();
        $request = (object)[
            'requesttype' => self::TYPE_TERMINATE,
            'departmentid' => $departmentid,
            'positionid' => $positionid,
            'employeeid' => $employeeid,
            'firstname' => (string)$employee->firstname,
            'lastname' => (string)$employee->lastname,
            'requesteddate' => $requesteddate,
            'comment' => $comment !== '' ? $comment : null,
            'reason' => $reason,
            'status' => self::STATUS_PENDING,
            'requestedby' => $actorid,
            'reviewedby' => null,
            'reviewcomment' => null,
            'createduserid' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'reviewedat' => null,
        ];

        $id = (int)$DB->insert_record('local_ustar_staff_requests', $request);
        people::log_action($actorid, $employeeid, 'staffing_termination_requested', [
            'requestid' => $id,
            'departmentid' => $departmentid,
            'positionid' => $positionid,
            'requesteddate' => $requesteddate,
            'reason' => $reason,
        ]);
        return $id;
    }

    /**
     * Approve/reject a pending request. Hire credentials are accepted only in-memory
     * during HR approval and are never persisted in the request table.
     */
    public static function review(int $requestid, string $decision, int $actorid, array $input = []): array {
        global $DB, $CFG;

        require_capability('local/ustar:hrmanage', \context_system::instance());
        if (!in_array($decision, [self::STATUS_APPROVED, self::STATUS_REJECTED], true)) {
            throw new \invalid_parameter_exception('Неизвестное решение');
        }

        view_as::assert_writable();
        global $USER;
        if ($actorid !== (int)$USER->id) { throw new \invalid_parameter_exception('Неверный автор решения'); }
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')->get_lock('staffing-review', 10);
        if (!$lock) { throw new \moodle_exception('Кадровая операция уже выполняется. Повторите попытку.'); }
        try {
        $transaction = $DB->start_delegated_transaction();
        try {
        $request = $DB->get_record('local_ustar_staff_requests', ['id' => $requestid], '*', MUST_EXIST);
        if ((string)$request->status !== self::STATUS_PENDING) {
            throw new \invalid_parameter_exception('Заявка уже обработана');
        }

        $reviewcomment = clean_param(trim((string)($input['reviewcomment'] ?? '')), PARAM_TEXT);
        if ($decision === self::STATUS_REJECTED && $reviewcomment === '') {
            throw new \invalid_parameter_exception('При отклонении укажите причину');
        }

        $targetuserid = !empty($request->employeeid) ? (int)$request->employeeid : null;
        $createduserid = null;

        if ($decision === self::STATUS_APPROVED) {
            if ((string)$request->requesttype === self::TYPE_HIRE) {
                $username = clean_param(trim((string)($input['username'] ?? '')), PARAM_USERNAME);
                $email = clean_param(trim((string)($input['email'] ?? '')), PARAM_EMAIL);
                $password = (string)($input['password'] ?? '');
                if ($username === '' || $email === '' || $password === '') {
                    throw new \invalid_parameter_exception('Для одобрения найма укажите логин, email и временный пароль');
                }

                $result = hr_people::save([
                    'userid' => 0,
                    'username' => $username,
                    'firstname' => (string)$request->firstname,
                    'lastname' => (string)$request->lastname,
                    'email' => $email,
                    'positionid' => (string)$request->positionid,
                    'accounttype' => accounts::TYPE_EMPLOYEE,
                    'suspended' => 0,
                    'password' => $password,
                ], $actorid);
                $createduserid = (int)$result['userid'];
                $targetuserid = $createduserid;

                organization_model::assign_hire(
                    $createduserid,
                    (string)$request->positionid,
                    (int)$request->requestedby
                );
                position_access::sync_user($createduserid);
            } else if ((string)$request->requesttype === self::TYPE_TERMINATE) {
                require_once($CFG->dirroot . '/user/lib.php');
                $target = $DB->get_record('user', [
                    'id' => (int)$request->employeeid,
                    'deleted' => 0,
                ], '*', MUST_EXIST);
                if (is_siteadmin($target) || (int)$target->id === $actorid || has_capability(
                    'local/ustar:admin',
                    \context_system::instance(),
                    (int)$target->id
                )) {
                    throw new \required_capability_exception(
                        \context_system::instance(),
                        'local/ustar:hrmanage',
                        'nopermissions',
                        ''
                    );
                }

                user_update_user((object)[
                    'id' => (int)$target->id,
                    'suspended' => 1,
                ], false, false);
                organization_model::end_user_assignments(
                    (int)$target->id,
                    'staffing_termination'
                );
                position_access::sync_user((int)$target->id);
                $targetuserid = (int)$target->id;
                people::log_action($actorid, $targetuserid, 'person_terminated', [
                    'requestid' => $requestid,
                    'lastday' => (int)$request->requesteddate,
                    'reason' => (string)$request->reason,
                ]);
            } else {
                throw new \invalid_parameter_exception('Неизвестный тип кадровой заявки');
            }
        }

        $now = time();
        $request->status = $decision;
        $request->reviewedby = $actorid;
        $request->reviewcomment = $reviewcomment !== '' ? $reviewcomment : null;
        $request->createduserid = $createduserid ?: null;
        $request->reviewedat = $now;
        $request->timemodified = $now;
        $DB->update_record('local_ustar_staff_requests', $request);

        people::log_action($actorid, $targetuserid, 'staffing_request_' . $decision, [
            'requestid' => $requestid,
            'requesttype' => (string)$request->requesttype,
            'departmentid' => (string)$request->departmentid,
            'positionid' => (string)$request->positionid,
            'createduserid' => $createduserid,
            'reviewcomment' => $reviewcomment,
        ]);

        $transaction->allow_commit();
        return [
            'requestid' => $requestid,
            'status' => $decision,
            'userid' => $targetuserid,
            'createduserid' => $createduserid,
        ];
        } catch (\Throwable $e) { $transaction->rollback($e); }
        } finally { $lock->release(); }
    }

    public static function list_for(int $viewerid): array {
        global $DB;

        $context = \context_system::instance();
        $ishr = has_capability('local/ustar:hrmanage', $context, $viewerid);
        $scope = self::manager_scope($viewerid);
        if (!$ishr && empty($scope['allowed'])) {
            return [];
        }

        if ($ishr) {
            $records = $DB->get_records('local_ustar_staff_requests', [], 'timecreated DESC');
        } else {
            $records = $DB->get_records('local_ustar_staff_requests', [
                'departmentid' => (string)$scope['departmentid'],
            ], 'timecreated DESC');
        }

        $structure = structure::get(structure::NAME_STRUCTURE);
        $positions = people::position_map($structure);
        $departments = people::department_map($structure);
        $rows = [];
        foreach ($records as $record) {
            $requester = $DB->get_record('user', ['id' => (int)$record->requestedby], 'id,firstname,lastname', IGNORE_MISSING);
            $reviewer = !empty($record->reviewedby)
                ? $DB->get_record('user', ['id' => (int)$record->reviewedby], 'id,firstname,lastname', IGNORE_MISSING)
                : null;
            $status = (string)$record->status;
            $type = (string)$record->requesttype;
            $adaptation = null;
            if ($type === self::TYPE_HIRE && $status === self::STATUS_APPROVED && !empty($record->createduserid)) {
                $adaptation = adaptation_service::for_staffing_request((int)$record->id);
            }
            $adaptationcard = $adaptation ? adaptation_service::manager_card($adaptation, $viewerid) : null;
            $canassignadaptation = $type === self::TYPE_HIRE
                && $status === self::STATUS_APPROVED
                && !empty($record->createduserid)
                && (int)$record->requestedby === $viewerid
                && !$adaptation;
            $rows[] = [
                'id' => (int)$record->id,
                'type' => $type,
                'ishire' => $type === self::TYPE_HIRE,
                'isterminate' => $type === self::TYPE_TERMINATE,
                'typelabel' => $type === self::TYPE_HIRE ? 'Приём сотрудника' : 'Увольнение сотрудника',
                'department' => (string)($departments[(string)$record->departmentid]['name'] ?? $record->departmentid),
                'position' => (string)($positions[(string)$record->positionid]['name'] ?? $record->positionid),
                'fullname' => trim((string)$record->lastname . ' ' . (string)$record->firstname),
                'employeeid' => (int)($record->employeeid ?? 0),
                'requesteddate' => userdate((int)$record->requesteddate, '%d.%m.%Y'),
                'comment' => (string)($record->comment ?? ''),
                'hascomment' => trim((string)($record->comment ?? '')) !== '',
                'reason' => (string)($record->reason ?? ''),
                'hasreason' => trim((string)($record->reason ?? '')) !== '',
                'status' => $status,
                'pending' => $status === self::STATUS_PENDING,
                'approved' => $status === self::STATUS_APPROVED,
                'rejected' => $status === self::STATUS_REJECTED,
                'statuslabel' => $status === self::STATUS_PENDING ? 'На согласовании' : ($status === self::STATUS_APPROVED ? 'Одобрено' : 'Отклонено'),
                'requester' => $requester ? trim((string)$requester->lastname . ' ' . (string)$requester->firstname) : ('#' . (int)$record->requestedby),
                'reviewer' => $reviewer ? trim((string)$reviewer->lastname . ' ' . (string)$reviewer->firstname) : '',
                'hasreviewer' => (bool)$reviewer,
                'reviewcomment' => (string)($record->reviewcomment ?? ''),
                'hasreviewcomment' => trim((string)($record->reviewcomment ?? '')) !== '',
                'timecreated' => userdate((int)$record->timecreated, '%d.%m.%Y %H:%M'),
                'createduserid' => (int)($record->createduserid ?? 0),
                'requesteddateiso' => userdate((int)$record->requesteddate, '%Y-%m-%d'),
                'canassignadaptation' => $canassignadaptation,
                'hasadaptation' => (bool)$adaptationcard,
                'adaptation' => $adaptationcard,
            ];
        }
        usort($rows, static function(array $a, array $b): int {
            if ($a['pending'] !== $b['pending']) {
                return $a['pending'] ? -1 : 1;
            }
            return $b['id'] <=> $a['id'];
        });
        return $rows;
    }
}

