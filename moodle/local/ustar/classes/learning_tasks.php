<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Academy-native tasks. Private notes are never visible outside their owner,
 * including to HR, HRD, managers, analytics and exports.
 */
final class learning_tasks {
    public const PRIVACY_OWNER = 'owner';
    public const PRIVACY_ASSIGNED = 'assigned';

    public static function available(): bool {
        global $DB;
        $manager = $DB->get_manager();
        return $manager->table_exists(new \xmldb_table('local_ustar_learning_tasks'))
            && $manager->table_exists(new \xmldb_table('local_ustar_learning_task_events'));
    }

    public static function can_assign(int $actorid, int $assigneeid): bool {
        if ($actorid <= 0 || $assigneeid <= 1 || $actorid === $assigneeid) {
            return false;
        }
        if (!accounts::participates($assigneeid) || !employment::is_active($assigneeid)) {
            return false;
        }
        if (capabilities::has($actorid, capabilities::COMPANY_READ)) {
            return true;
        }
        if (!capabilities::has($actorid, capabilities::TEAM_READ)) { return false; }
        $scope = organization_model::manager_scope($actorid);
        return !empty($scope['allowed'])
            && in_array($assigneeid, array_map('intval', $scope['userids'] ?? []), true);
    }

    /** Bounded, scoped recipient search; the final assign() checks scope again. */
    public static function candidates(int $actorid, string $query): array {
        global $DB;
        $query = trim(clean_param($query, PARAM_TEXT));
        if (\core_text::strlen($query) < 2) { return []; }
        // Employment::is_active() treats pre-migration users without a row as active.
        $where = 'u.id > 1 AND u.deleted = 0 AND u.suspended = 0
            AND (e.status = :active OR e.id IS NULL)';
        $params = ['active' => 'active'];
        $like = '%' . $DB->sql_like_escape($query) . '%';
        $where .= ' AND (' . $DB->sql_like('u.firstname', ':first', false) . ' OR '
            . $DB->sql_like('u.lastname', ':last', false) . ' OR '
            . $DB->sql_like('u.username', ':username', false) . ')';
        $params += ['first' => $like, 'last' => $like, 'username' => $like];
        if (!capabilities::has($actorid, capabilities::COMPANY_READ)) {
            if (!capabilities::has($actorid, capabilities::TEAM_READ)) { return []; }
            $scope = organization_model::manager_scope($actorid);
            $ids = array_values(array_filter(array_map('intval', $scope['userids'] ?? [])));
            if (empty($scope['allowed']) || !$ids) { return []; }
            [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'candidate');
            $where .= ' AND u.id ' . $insql;
            $params += $inparams;
        }
        $rows = $DB->get_records_sql('SELECT u.id, u.username, u.firstname, u.lastname,
            u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
            FROM {user} u LEFT JOIN {local_ustar_employment} e ON e.userid = u.id
            WHERE ' . $where . ' ORDER BY u.lastname, u.firstname, u.id', $params, 0, 25);
        $out = [];
        foreach ($rows as $user) {
            if (self::can_assign($actorid, (int)$user->id)) {
                $out[] = ['id' => (int)$user->id, 'fullname' => fullname($user), 'position' => ''];
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public static function create_note(int $ownerid, string $title, string $body, array $uploads = []): array {
        global $DB;
        self::assert_available();
        $title = trim(clean_param($title, PARAM_TEXT));
        if ($title === '') {
            throw new \invalid_parameter_exception('Укажите название личной заметки.');
        }
        $tx = $DB->start_delegated_transaction();
        $now = time();
        $id = self::insert_task((object)[
            'ownerid' => $ownerid, 'assigneeid' => $ownerid, 'assignerid' => null,
            'tasktype' => 'note', 'title' => $title, 'description' => self::plain($body),
            'status' => 'open', 'requirereview' => 0,
            'relatedtype' => null, 'relatedid' => null, 'privacy' => self::PRIVACY_OWNER,
            'version' => 1, 'dueat' => null, 'completedat' => null, 'cancelledat' => null,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        task_files::store($id, task_files::ATTACHMENT, $uploads);
        self::event($id, $ownerid, 'note_created', $ownerid, []);
        $tx->allow_commit();
        return self::view($id, $ownerid);
    }

    public static function update_note(int $id, int $ownerid, int $version, string $title, string $body,
            array $uploads = []): void {
        $title = self::plain($title);
        if ($title === '') { throw new \invalid_parameter_exception('Укажите название личной заметки.'); }
        self::mutate_note($id, $ownerid, $version, $title, self::plain($body), $uploads);
    }

    public static function delete_note(int $id, int $ownerid, int $version): void {
        self::mutate_note($id, $ownerid, $version, null, null);
    }

    private static function mutate_note(int $id, int $ownerid, int $version, ?string $title, ?string $body,
            array $uploads = []): void {
        global $DB;
        self::assert_available();
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')->get_lock('learning-task:' . $id, 10);
        if (!$lock) { throw new \moodle_exception('Заметка изменяется в другой сессии. Повторите действие.'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $note = $DB->get_record_sql('SELECT * FROM {local_ustar_learning_tasks} WHERE id = :id FOR UPDATE',
                ['id' => $id], MUST_EXIST);
            self::assert_view($note, $ownerid);
            if ($note->privacy !== self::PRIVACY_OWNER || (int)$note->ownerid !== $ownerid) {
                throw new \required_capability_exception(\context_system::instance(), 'local/ustar:use', 'nopermissions', '');
            }
            if ($version <= 0 || (int)$note->version !== $version) {
                throw new \moodle_exception('Заметка уже изменилась. Обновите страницу.');
            }
            if ($title === null) {
                task_files::delete_note_files($id);
                $DB->delete_records('local_ustar_learning_task_events', ['taskid' => $id]);
                $DB->delete_records('local_ustar_learning_tasks', ['id' => $id]);
            } else {
                task_files::store($id, task_files::ATTACHMENT, $uploads);
                $note->title = $title;
                $note->description = $body;
                $note->version = $version + 1;
                $note->timemodified = time();
                $DB->update_record('local_ustar_learning_tasks', $note);
                self::event($id, $ownerid, 'note_updated', $ownerid, []);
            }
            $tx->allow_commit();
        } finally {
            $lock->release();
        }
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public static function assign(int $actorid, int $assigneeid, array $input, array $uploads = []): array {
        global $DB;
        self::assert_available();
        if (!self::can_assign($actorid, $assigneeid)) {
            throw new \required_capability_exception(
                \context_system::instance(), 'local/ustar:viewteam', 'nopermissions', ''
            );
        }
        $title = trim(clean_param((string)($input['title'] ?? ''), PARAM_TEXT));
        if ($title === '') {
            throw new \invalid_parameter_exception('Укажите задачу.');
        }
        $relatedtype = clean_param((string)($input['relatedtype'] ?? ''), PARAM_ALPHANUMEXT);
        $relatedid = (int)($input['relatedid'] ?? 0);
        $allowedlinks = ['checklist', 'grade_request', 'forced_retraining', 'route'];
        if ($relatedtype !== '' && !in_array($relatedtype, $allowedlinks, true)) {
            throw new \invalid_parameter_exception('Недопустимая связь задачи.');
        }
        if ($relatedtype === '') { $relatedid = 0; }
        $dueat = (int)($input['dueat'] ?? 0);
        $tx = $DB->start_delegated_transaction();
        $now = time();
        $id = self::insert_task((object)[
            'ownerid' => $assigneeid, 'assigneeid' => $assigneeid, 'assignerid' => $actorid,
            'tasktype' => 'assigned', 'title' => $title, 'description' => self::plain((string)($input['description'] ?? '')),
            'status' => 'assigned', 'requirereview' => !empty($input['requirereview']) ? 1 : 0,
            'relatedtype' => $relatedtype ?: null, 'relatedid' => $relatedid ?: null,
            'privacy' => self::PRIVACY_ASSIGNED, 'version' => 1,
            'dueat' => $dueat > 0 ? $dueat : null, 'completedat' => null, 'cancelledat' => null,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        task_files::store($id, task_files::ATTACHMENT, $uploads);
        self::event($id, $assigneeid, 'task_assigned', $actorid, [
            'requirereview' => !empty($input['requirereview']), 'relatedtype' => $relatedtype, 'relatedid' => $relatedid,
        ]);
        self::notify($assigneeid, 'task_assigned', 'Новая задача', $title,
            '/local/ustar/tasks.php?tab=assigned', 'task-assigned:' . $id);
        $tx->allow_commit();
        return self::view($id, $actorid);
    }

    /** The creator may adjust the deadline; reassignment is limited to a task not started. */
    public static function revise(int $taskid, int $actorid, int $expectedversion,
            int $assigneeid, int $dueat): array {
        global $DB;
        self::assert_available();
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')
            ->get_lock('learning-task:' . $taskid, 10);
        if (!$lock) { throw new \moodle_exception('Задача сейчас изменяется. Повторите действие.'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $task = $DB->get_record_sql('SELECT * FROM {local_ustar_learning_tasks} WHERE id = :id FOR UPDATE',
                ['id' => $taskid], MUST_EXIST);
            if ((string)$task->privacy !== self::PRIVACY_ASSIGNED || (int)$task->assignerid !== $actorid
                    || !in_array((string)$task->status, ['assigned', 'in_progress'], true)) {
                throw new \required_capability_exception(\context_system::instance(),
                    'local/ustar:viewteam', 'nopermissions', '');
            }
            if ($expectedversion !== (int)$task->version) {
                throw new \moodle_exception('Задача уже изменилась. Обновите страницу.');
            }
            $previous = (int)$task->assigneeid;
            if ($assigneeid !== $previous) {
                if ((string)$task->status !== 'assigned' || !self::can_assign($actorid, $assigneeid)) {
                    throw new \invalid_parameter_exception('Исполнителя можно сменить до начала задачи и в своей области.');
                }
                $task->assigneeid = $assigneeid;
                $task->ownerid = $assigneeid;
            }
            $oldduedate = (int)$task->dueat;
            if ($oldduedate === $dueat && $previous === $assigneeid) {
                $tx->allow_commit();
                return self::view($taskid, $actorid);
            }
            $task->dueat = $dueat > 0 ? $dueat : null;
            $task->version++;
            $task->timemodified = time();
            $DB->update_record('local_ustar_learning_tasks', $task);
            self::event($taskid, (int)$task->ownerid, 'task_revised', $actorid, [
                'previousassigneeid' => $previous, 'assigneeid' => $assigneeid,
                'previousdueat' => $oldduedate, 'dueat' => $dueat,
            ]);
            if ($previous !== $assigneeid) {
                self::notify($previous, 'task_reassigned', 'Поручение переназначено',
                    (string)$task->title, '/local/ustar/tasks.php?tab=assigned',
                    'task-reassigned-old:' . $taskid . ':' . $task->version);
            }
            self::notify($assigneeid, 'task_revised', 'Поручение изменено',
                (string)$task->title, '/local/ustar/tasks.php?tab=assigned',
                'task-revised:' . $taskid . ':' . $task->version);
            $tx->allow_commit();
            return self::view($taskid, $actorid);
        } finally {
            $lock->release();
        }
    }

    /** @return array<string,mixed> */
    public static function transition(int $taskid, int $actorid, string $action, int $expectedversion,
            string $comment = '', array $uploads = []): array {
        global $DB;
        self::assert_available();
        $action = clean_param($action, PARAM_ALPHANUMEXT);
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')->get_lock('learning-task:' . $taskid, 10);
        if (!$lock) {
            throw new \moodle_exception('Задача сейчас изменяется в другой сессии. Повторите действие.');
        }
        try {
            $tx = $DB->start_delegated_transaction();
            $task = $DB->get_record_sql(
                'SELECT * FROM {local_ustar_learning_tasks} WHERE id = :id FOR UPDATE',
                ['id' => $taskid], MUST_EXIST
            );
            self::assert_view($task, $actorid);
            if ($expectedversion <= 0 || (int)$task->version !== $expectedversion) {
                throw new \moodle_exception('Задача уже изменилась. Обновите страницу.');
            }
            $isnote = (string)$task->privacy === self::PRIVACY_OWNER;
            $isassignee = $actorid === (int)$task->assigneeid;
            $isassigner = $actorid === (int)$task->assignerid;
            $next = '';
            $event = '';
            if ($isnote && $isassignee && $action === 'complete' && (string)$task->status === 'open') {
                $next = 'completed'; $event = 'note_completed';
            } else if (!$isnote && $isassignee && $action === 'start' && (string)$task->status === 'assigned') {
                $next = 'in_progress'; $event = 'task_started';
            } else if (!$isnote && $isassignee && $action === 'submit'
                    && in_array((string)$task->status, ['assigned', 'in_progress'], true)) {
                $next = !empty($task->requirereview) ? 'in_review' : 'completed';
                $event = $next === 'in_review' ? 'task_sent_for_review' : 'task_completed';
            } else if (!$isnote && $isassigner && $action === 'approve' && (string)$task->status === 'in_review') {
                $next = 'completed'; $event = 'task_approved';
            } else if (!$isnote && $isassigner && $action === 'return' && (string)$task->status === 'in_review') {
                if (trim($comment) === '') {
                    throw new \invalid_parameter_exception('Укажите, что нужно доработать.');
                }
                $next = 'in_progress'; $event = 'task_returned';
            } else if (!$isnote && $isassigner && $action === 'cancel'
                    && !in_array((string)$task->status, ['completed', 'cancelled'], true)) {
                if (trim($comment) === '') {
                    throw new \invalid_parameter_exception('Укажите причину отмены.');
                }
                $next = 'cancelled'; $event = 'task_cancelled';
            } else {
                throw new \required_capability_exception(
                    \context_system::instance(), 'local/ustar:viewteam', 'nopermissions', ''
                );
            }
            if ($uploads && $action !== 'submit') {
                throw new \invalid_parameter_exception('Вложения результата доступны только при отправке задачи.');
            }
            $now = time();
            $previousstatus = (string)$task->status;
            $task->status = $next;
            $task->version++;
            $task->timemodified = $now;
            if ($action === 'submit') {
                task_files::store($taskid, task_files::RESULT, $uploads, (int)$task->version);
            }
            if ($next === 'completed') { $task->completedat = $now; }
            if ($next === 'cancelled') { $task->cancelledat = $now; }
            $DB->update_record('local_ustar_learning_tasks', $task);
            self::event($taskid, (int)$task->ownerid, $event, $actorid, $isnote ? [] : [
                'comment' => self::plain($comment), 'previousstatus' => $previousstatus,
            ]);
            if (!$isnote && $actorid !== (int)$task->assigneeid) {
                self::notify((int)$task->assigneeid, $event, 'Изменился статус задачи',
                    (string)$task->title, '/local/ustar/tasks.php?tab=assigned', 'task-event:' . $taskid . ':' . $event . ':' . $task->version);
            }
            if (!$isnote && $actorid === (int)$task->assigneeid && $next === 'in_review') {
                self::notify((int)$task->assignerid, $event, 'Задача ждёт проверки',
                    (string)$task->title, '/local/ustar/tasks.php?tab=outgoing', 'task-review:' . $taskid . ':' . $task->version);
            }
            $tx->allow_commit();
            return self::view($taskid, $actorid);
        } finally {
            $lock->release();
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function notes_for_owner(int $userid, int $page = 0): array {
        return self::list_by('ownerid = :ownerid AND privacy = :privacy',
            ['ownerid' => $userid, 'privacy' => self::PRIVACY_OWNER], $userid, $page);
    }

    /** @return array<int,array<string,mixed>> */
    public static function assigned_to(int $userid, int $page = 0): array {
        return self::list_by('assigneeid = :userid AND privacy = :privacy',
            ['userid' => $userid, 'privacy' => self::PRIVACY_ASSIGNED], $userid, $page);
    }

    /** @return array<int,array<string,mixed>> */
    public static function assigned_by(int $userid, int $page = 0): array {
        return self::list_by('assignerid = :userid AND privacy = :privacy',
            ['userid' => $userid, 'privacy' => self::PRIVACY_ASSIGNED], $userid, $page);
    }

    public static function count_for(int $userid, string $tab): int {
        global $DB;
        $fields = ['notebook' => 'ownerid', 'assigned' => 'assigneeid', 'outgoing' => 'assignerid'];
        if (!isset($fields[$tab]) || !self::available()) { return 0; }
        $field = $fields[$tab];
        return $DB->count_records_select('local_ustar_learning_tasks',
            "$field = :userid AND privacy = :privacy",
            ['userid' => $userid, 'privacy' => $tab === 'notebook' ? self::PRIVACY_OWNER : self::PRIVACY_ASSIGNED]);
    }

    /** @return array<string,mixed> */
    public static function view(int $taskid, int $actorid): array {
        global $DB;
        $task = $DB->get_record('local_ustar_learning_tasks', ['id' => $taskid], '*', MUST_EXIST);
        self::assert_view($task, $actorid);
        return self::view_record($task, $actorid);
    }

    /** @return array<int,array<string,mixed>> */
    public static function events(int $taskid, int $actorid): array {
        global $DB;
        $task = $DB->get_record('local_ustar_learning_tasks', ['id' => $taskid], '*', MUST_EXIST);
        self::assert_view($task, $actorid);
        $events = $DB->get_records('local_ustar_learning_task_events', ['taskid' => $taskid], 'timecreated ASC, id ASC');
        $out = [];
        foreach ($events as $event) {
            $data = json_decode((string)$event->datajson, true);
            $out[] = [
                'event' => (string)$event->eventtype,
                'actorid' => (int)$event->actorid,
                'time' => (int)$event->timecreated,
                'comment' => (string)(is_array($data) ? ($data['comment'] ?? '') : ''),
            ];
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private static function list_by(string $where, array $params, int $actorid, int $page): array {
        global $DB;
        if (!self::available()) { return []; }
        $out = [];
        foreach ($DB->get_records_select('local_ustar_learning_tasks', $where, $params,
                'timemodified DESC, id DESC', '*', max(0, $page) * 25, 25) as $task) {
            // The select itself is restrictive, assert_view also guards future callers.
            $out[] = self::view_record($task, $actorid);
        }
        return $out;
    }

    private static function assert_view(\stdClass $task, int $actorid): void {
        if ((string)$task->privacy === self::PRIVACY_OWNER) {
            if ($actorid !== (int)$task->ownerid) {
                throw new \required_capability_exception(
                    \context_system::instance(), 'local/ustar:use', 'nopermissions', ''
                );
            }
            return;
        }
        if ($actorid !== (int)$task->assigneeid && $actorid !== (int)$task->assignerid) {
            throw new \required_capability_exception(
                \context_system::instance(), 'local/ustar:viewteam', 'nopermissions', ''
            );
        }
    }

    /** @return array<string,mixed> */
    private static function view_record(\stdClass $task, int $actorid): array {
        $out = [
            'id' => (int)$task->id, 'title' => format_string((string)$task->title),
            'titleplain' => (string)$task->title, 'descriptionplain' => (string)$task->description,
            'description' => format_text((string)$task->description, FORMAT_PLAIN, ['para' => true, 'filter' => false]),
            'status' => (string)$task->status, 'version' => (int)$task->version,
            'assigned' => (string)$task->privacy === self::PRIVACY_ASSIGNED,
            'private' => (string)$task->privacy === self::PRIVACY_OWNER,
            'requirereview' => !empty($task->requirereview),
            'relatedtype' => (string)$task->relatedtype, 'relatedid' => (int)$task->relatedid,
            'dueat' => (int)$task->dueat, 'timecreated' => (int)$task->timecreated,
            'assigneeid' => (int)$task->assigneeid, 'assignerid' => (int)$task->assignerid,
        ];
        // Do not add private task data to an actor's aggregate / proxy record.
        return $out;
    }

    private static function insert_task(\stdClass $task): int {
        global $DB;
        return (int)$DB->insert_record('local_ustar_learning_tasks', $task);
    }

    /** @param array<string,mixed> $data */
    private static function event(int $taskid, int $ownerid, string $eventtype, int $actorid, array $data): void {
        global $DB;
        // Personal-note events deliberately contain no title or content.
        $DB->insert_record('local_ustar_learning_task_events', (object)[
            'taskid' => $taskid, 'eventtype' => $eventtype, 'actorid' => $actorid,
            'datajson' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'timecreated' => time(),
        ]);
    }

    private static function notify(int $userid, string $eventtype, string $subject, string $message, string $url, string $key): void {
        workflow_notifications::enqueue($userid, $eventtype, $subject, $message, $url, $key);
    }

    private static function plain(string $text): string {
        return trim(clean_param($text, PARAM_TEXT));
    }

    private static function assert_available(): void {
        if (!self::available()) {
            throw new \moodle_exception('Сервис задач будет доступен после обновления базы данных.');
        }
    }
}
