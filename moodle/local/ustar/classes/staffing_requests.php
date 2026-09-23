<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Staffing request workflow: department head -> HRD -> execution.
 */
final class staffing_requests {
    public const TYPE_HIRE = 'hire';
    public const TYPE_TERMINATE = 'terminate';
    public const TYPE_REGISTRATION = 'registration';

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
        self::assert_actor($actorid);
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

    private static function assert_actor(int $actorid): void {
        global $USER;
        if ((int)$USER->id !== $actorid || !team_access::active_actor($actorid)) {
            throw new \invalid_parameter_exception('Кадровое действие требует действующего текущего пользователя');
        }
        view_as::assert_writable();
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
        self::assert_actor($actorid);
        global $DB, $CFG;

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
        if ((string)$request->requesttype === self::TYPE_REGISTRATION) {
            require_capability('local/ustar:approveregistration', \context_system::instance());
            require_capability('local/ustar:hrmanage', \context_system::instance());
        } else {
            require_capability('local/ustar:hrmanage', \context_system::instance());
        }
        if ((string)$request->status !== self::STATUS_PENDING) {
            if ((string)$request->requesttype === self::TYPE_REGISTRATION
                    && (string)$request->status === $decision) {
                $transaction->allow_commit();
                return ['requestid' => $requestid, 'status' => $decision,
                    'userid' => (int)$request->employeeid, 'createduserid' => null,
                    'idempotent' => true];
            }
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
                employment::set_status($createduserid, employment::ACTIVE, $actorid, 'staffing_hire');
            } else if ((string)$request->requesttype === self::TYPE_REGISTRATION) {
                $targetuserid = (int)$request->employeeid;
                if ($targetuserid <= 1 || employment::resolve($targetuserid)['status'] !== employment::PENDING) {
                    throw new \invalid_parameter_exception('Регистрация уже активирована или недоступна.');
                }
                $positionid = clean_param(trim((string)($input['positionid'] ?? $request->positionid)),
                    PARAM_ALPHANUMEXT);
                $positions = people::position_map(structure::get(structure::NAME_STRUCTURE));
                if ($positionid === '' || !isset($positions[$positionid])
                        || (string)$positions[$positionid]['department'] !== (string)$request->departmentid) {
                    throw new \invalid_parameter_exception('Выберите должность внутри заявленного подразделения.');
                }
                organization_model::assign_position_by_hr($targetuserid, $positionid, $actorid);
                people::set_position_id($targetuserid, $positionid);
                employment::approve_registration($targetuserid, $actorid, $positionid);
                $request->positionid = $positionid;
                assignment::sync_user($targetuserid);
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
                employment::set_status((int)$target->id, employment::TERMINATED, $actorid, 'staffing_termination');
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

        if ((string)$request->requesttype === self::TYPE_REGISTRATION && $targetuserid) {
            target_core::notify([
                'userid' => $targetuserid,
                'severity' => 'normal',
                'eventtype' => 'registration_' . $decision,
                'subject' => $decision === self::STATUS_APPROVED
                    ? 'Должность подтверждена' : 'Заявка на должность отклонена',
                'message' => $decision === self::STATUS_APPROVED
                    ? 'Ваша должность подтверждена. Назначенный маршрут обучения уже доступен.'
                    : ('Причина: ' . $reviewcomment),
                'actionurl' => (new \moodle_url('/local/ustar/profile.php'))->out(false),
                'idempotencykey' => 'registration-' . $decision . ':' . $requestid,
            ]);
        }

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
        $canapproveregistration = has_capability('local/ustar:approveregistration', $context, $viewerid);
        $scope = self::manager_scope($viewerid);
        if (!$ishr && !$canapproveregistration && empty($scope['allowed'])) {
            return [];
        }

        if ($ishr || $canapproveregistration) {
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
            $isregistration = $type === self::TYPE_REGISTRATION;
            if ($isregistration && !$canapproveregistration) {
                continue;
            }
            $applicant = $isregistration && !empty($record->employeeid)
                ? $DB->get_record('user', ['id' => (int)$record->employeeid, 'deleted' => 0],
                    'id,username,email', IGNORE_MISSING) : null;
            $departmentpositions = [];
            if ($isregistration) {
                foreach ($positions as $position) {
                    if ((string)($position['department'] ?? '') === (string)$record->departmentid) {
                        $departmentpositions[] = [
                            'id' => (string)$position['id'], 'name' => (string)$position['name'],
                            'selected' => (string)$record->positionid === (string)$position['id'],
                        ];
                    }
                }
            }
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
                'isregistration' => $isregistration,
                'registrationusername' => $applicant ? (string)$applicant->username : '',
                'registrationemail' => $applicant ? (string)$applicant->email : '',
                'typelabel' => $type === self::TYPE_HIRE ? 'Приём сотрудника'
                    : ($isregistration ? 'Подтверждение регистрации' : 'Увольнение сотрудника'),
                'department' => (string)($departments[(string)$record->departmentid]['name'] ?? $record->departmentid),
                'position' => (string)($positions[(string)$record->positionid]['name'] ?? 'Должность выбирает HRD'),
                'departmentpositions' => $departmentpositions,
                'hasdepartmentpositions' => !empty($departmentpositions),
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
                'canreview' => $status === self::STATUS_PENDING
                    && ((!$isregistration && $ishr) || ($isregistration && $canapproveregistration)),
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
