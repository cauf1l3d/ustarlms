<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Self-registration bridge into the existing staffing workflow. */
final class registration_service {
    /** Return public choices only; a self-declared department never grants a role. */
    public static function departments(): array {
        $structure = structure::get(structure::NAME_STRUCTURE);
        $departments = people::department_map($structure);
        $result = [];
        foreach ($departments as $id => $department) {
            if ($id !== '' && !empty($department['name'])) {
                $result[] = ['id' => (string)$id, 'name' => (string)$department['name']];
            }
        }
        return $result;
    }

    /** Create one Moodle identity and one pending HRD request in the same transaction. */
    public static function register(array $input): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->libdir . '/moodlelib.php');

        // Limit anonymous creation attempts across PHP workers without
        // writing IP addresses into the personnel records.
        self::throttle();

        $username = \core_text::strtolower(trim((string)($input['username'] ?? '')));
        $email = trim((string)($input['email'] ?? ''));
        $firstname = trim((string)($input['firstname'] ?? ''));
        $lastname = trim((string)($input['lastname'] ?? ''));
        $departmentid = trim((string)($input['departmentid'] ?? ''));
        $password = (string)($input['password'] ?? '');
        if ($username === '' || $username !== clean_param($username, PARAM_USERNAME)
                || strlen($username) > 100 || $email === '' || strlen($email) > 100
                || !validate_email($email) || $firstname === '' || $lastname === ''
                || $firstname !== clean_param($firstname, PARAM_NOTAGS)
                || $lastname !== clean_param($lastname, PARAM_NOTAGS)
                || \core_text::strlen($firstname) > 100 || \core_text::strlen($lastname) > 100) {
            throw new \invalid_parameter_exception('Проверьте логин, имя, фамилию и email.');
        }
        $departments = array_column(self::departments(), 'name', 'id');
        if ($departmentid === '' || !array_key_exists($departmentid, $departments)) {
            throw new \invalid_parameter_exception('Выберите подразделение из справочника.');
        }
        $passworderror = '';
        if ($password === '' || !check_password_policy($password, $passworderror)) {
            throw new \invalid_parameter_exception($passworderror ?: 'Пароль не соответствует политике безопасности.');
        }
        $identitylock = \core\lock\lock_config::get_lock_factory('local_ustar')
            ->get_lock('registration-email-' . hash('sha256', \core_text::strtolower($email)), 10);
        if (!$identitylock) {
            throw new \moodle_exception('Регистрация занята. Повторите попытку позже.');
        }
        try {
            if ($DB->record_exists('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id])
                    || $DB->record_exists_select('user', 'LOWER(email) = LOWER(:email) AND deleted = 0',
                        ['email' => $email])) {
                throw new \invalid_parameter_exception('Логин или email уже используется.');
            }

            $transaction = $DB->start_delegated_transaction();
            try {
                $userid = (int)user_create_user((object)[
                'auth' => 'manual', 'confirmed' => 1, 'mnethostid' => $CFG->mnet_localhost_id,
                'username' => $username, 'password' => $password, 'email' => $email,
                'firstname' => $firstname, 'lastname' => $lastname, 'suspended' => 0,
            ], true, false);
            accounts::set_type($userid, accounts::TYPE_EMPLOYEE);
            employment::register_pending($userid);
            self::submit_department($userid, $departmentid);
                $transaction->allow_commit();
                return $userid;
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
        } finally {
            $identitylock->release();
        }
    }

    private static function throttle(): void {
        $address = (string)getremoteaddr();
        $key = hash('sha256', $address !== '' ? $address : 'unknown');
        $factory = \core\lock\lock_config::get_lock_factory('local_ustar');
        $lock = $factory->get_lock('registration-ip-' . $key, 10);
        if (!$lock) {
            throw new \moodle_exception('Регистрация занята. Повторите попытку позже.');
        }
        try {
            $cache = \cache::make('local_ustar', 'registration_throttle');
            $now = time();
            $item = $cache->get($key);
            if (!is_array($item) || $now - (int)($item['started'] ?? 0) >= 900) {
                $item = ['started' => $now, 'count' => 0];
            }
            if ((int)$item['count'] >= 30) {
                throw new \moodle_exception('Слишком много попыток. Повторите через 15 минут.');
            }
            $item['count']++;
            $cache->set($key, $item);
        } finally {
            $lock->release();
        }
    }

    /** Department is a claim; the position is chosen by HRD during review. */
    public static function request_department(int $userid, string $departmentid): int {
        global $USER;
        if ((int)$USER->id !== $userid) {
            throw new \invalid_parameter_exception('Заявку можно отправить только из своего профиля.');
        }
        view_as::assert_writable();
        return self::submit_department($userid, $departmentid);
    }

    private static function submit_department(int $userid, string $departmentid): int {
        global $DB;
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')
            ->get_lock('registration-request-' . $userid, 10);
        if (!$lock) {
            throw new \moodle_exception('Заявка уже отправляется. Повторите попытку.');
        }
        try {
            $transaction = $DB->start_delegated_transaction();
            $departments = array_column(self::departments(), 'name', 'id');
            if (!isset($departments[$departmentid])) {
                throw new \invalid_parameter_exception('Подразделение не найдено.');
            }
            if (!accounts::is_business_account($userid) || employment::resolve($userid)['status'] !== employment::PENDING) {
                throw new \invalid_parameter_exception('Регистрация уже обработана или недоступна.');
            }
            $existing = $DB->get_records('local_ustar_staff_requests', [
                'requesttype' => staffing_requests::TYPE_REGISTRATION,
                'employeeid' => $userid, 'status' => staffing_requests::STATUS_PENDING,
            ], 'id DESC', '*', 0, 1);
            if ($existing) {
                $request = reset($existing);
                if ((string)$request->departmentid !== $departmentid) {
                    throw new \invalid_parameter_exception('Заявка на другое подразделение уже ожидает решения.');
                }
                $transaction->allow_commit();
                return (int)$request->id;
            }
            $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
            $now = time();
            $id = (int)$DB->insert_record('local_ustar_staff_requests', (object)[
                'requesttype' => staffing_requests::TYPE_REGISTRATION,
                'departmentid' => $departmentid, 'positionid' => '', 'employeeid' => $userid,
                'firstname' => (string)$user->firstname, 'lastname' => (string)$user->lastname,
                'requesteddate' => $now, 'comment' => null, 'reason' => null,
                'status' => staffing_requests::STATUS_PENDING, 'requestedby' => $userid,
                'reviewedby' => null, 'reviewcomment' => null, 'createduserid' => null,
                'timecreated' => $now, 'timemodified' => $now, 'reviewedat' => null,
            ]);
            people::log_action($userid, $userid, 'registration_requested', [
                'requestid' => $id, 'departmentid' => $departmentid,
            ]);
            self::notify_reviewers($id, $departmentid, fullname($user));
            $transaction->allow_commit();
            return $id;
        } catch (\Throwable $e) {
            if (isset($transaction)) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }
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
        // Existing email-auth accounts from the old registration flow may still
        // be pending; keep their saved position requests readable for HRD.
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
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')
            ->get_lock('registration-request-' . $userid, 10);
        if (!$lock) {
            throw new \moodle_exception('Заявка уже отправляется. Повторите попытку.');
        }
        try {
            $transaction = $DB->start_delegated_transaction();
            if (employment::resolve($userid)['status'] !== employment::PENDING) {
                throw new \invalid_parameter_exception('Регистрация уже обработана.');
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
                $transaction->allow_commit();
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
            self::notify_reviewers($id, (string)$position['department'], fullname($user));
            $transaction->allow_commit();
            return $id;
        } catch (\Throwable $e) {
            if (isset($transaction)) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }
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

    private static function notify_reviewers(int $requestid, string $departmentid, string $fullname): void {
        $recipients = [];
        foreach (get_users_by_capability(\context_system::instance(),
                'local/ustar:approveregistration', 'u.id', 'u.id ASC') ?: [] as $candidate) {
            $candidateid = (int)$candidate->id;
            if (team_access::active_actor($candidateid)
                    && has_capability('local/ustar:hrmanage', \context_system::instance(), $candidateid)) {
                $recipients[$candidateid] = true;
            }
        }
        foreach (array_keys($recipients) as $recipientid) {
            target_core::notify([
                'userid' => $recipientid,
                'severity' => 'normal',
                'eventtype' => 'registration_requested',
                'subject' => 'Новая заявка на регистрацию сотрудника',
                'message' => $fullname . ' указал подразделение ' . $departmentid . ' для подтверждения HRD.',
                'actionurl' => (new \moodle_url('/local/ustar/staffing.php'))->out(false),
                'idempotencykey' => 'registration-requested:' . $requestid . ':' . $recipientid,
            ]);
        }
    }
}
