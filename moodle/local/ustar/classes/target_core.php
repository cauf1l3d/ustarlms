<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Constitution-backed services for Evidence, Gates, Checklists, Tasks and Notifications. */
final class target_core {
    private const EVIDENCE_TYPES = ['learning','assessment','practice','manager_review','checklist','certification'];
    private const EVIDENCE_OUTCOMES = ['observed','completed','passed','failed'];
    private const GATE_DECISIONS = ['granted','denied','revoked','expired'];
    private const TASK_CATEGORIES = ['learning','development','adaptation','evidence','gap','hr_process'];
    private const TASK_SOURCES = ['system','manager','hr','process'];
    private const NOTIFICATION_SEVERITIES = ['normal','action','critical'];

    private static function json($value): string {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return $encoded === false ? '{}' : $encoded;
    }

    private static function clean_code(string $value, int $length = 64): string {
        $value = trim(clean_param($value, PARAM_ALPHANUMEXT));
        return \core_text::substr($value, 0, $length);
    }

    private static function require_user(int $userid): void {
        global $DB;
        if (!$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            throw new \invalid_parameter_exception('User not found');
        }
    }

    private static function can_manage(int $actorid, int $userid): bool {
        if ($actorid <= 0) return false;
        $context = \context_system::instance();
        if (has_capability('local/ustar:hrmanage', $context, $actorid)) return true;
        return has_capability('local/ustar:viewteam', $context, $actorid)
            && org::manager_id($userid) === $actorid;
    }

    private static function require_manager(int $actorid, int $userid): void {
        self::require_user($actorid);
        self::require_user($userid);
        if (!self::can_manage($actorid, $userid)) {
            throw new \required_capability_exception(
                \context_system::instance(), 'local/ustar:viewteam', 'nopermissions', ''
            );
        }
    }

    /** Create one immutable evidence fact, idempotently. */
    public static function record_evidence(array $data, int $actorid): int {
        global $DB;
        $userid = (int)($data['userid'] ?? 0);
        self::require_user($userid);
        if ($actorid > 0 && $actorid !== $userid) self::require_manager($actorid, $userid);
        $type = self::clean_code((string)($data['evidencetype'] ?? ''), 32);
        $outcome = self::clean_code((string)($data['outcome'] ?? ''), 16);
        if (!in_array($type, self::EVIDENCE_TYPES, true) || !in_array($outcome, self::EVIDENCE_OUTCOMES, true)) {
            throw new \invalid_parameter_exception('Unsupported evidence type or outcome');
        }
        $sourcekind = self::clean_code((string)($data['sourcekind'] ?? ''), 32);
        $sourceid = \core_text::substr(trim((string)($data['sourceid'] ?? '')), 0, 128);
        if ($sourcekind === 'human_skill_level') {
            $level = $data['details']['level'] ?? null;
            if ($actorid <= 0 || $actorid === $userid
                    || !in_array($type, ['manager_review'], true) || $outcome !== 'passed'
                    || !is_int($level) || $level < 1 || $level > 5
                    || self::clean_code((string)($data['skillid'] ?? '')) === ''
                    || self::clean_code((string)($data['positionid'] ?? '')) === '') {
                throw new \invalid_parameter_exception('A human skill level requires a manager, position, skill and reviewed level');
            }
        }
        $key = trim((string)($data['idempotencykey'] ?? ''));
        if ($sourcekind === '' || $sourceid === '' || $key === '') {
            throw new \invalid_parameter_exception('Evidence source and idempotency key are required');
        }
        $key = \core_text::substr($key, 0, 128);
        if ($existing = $DB->get_field('local_ustar_evidence_rec', 'id', ['idempotencykey' => $key])) {
            return (int)$existing;
        }
        $now = time();
        $validfrom = max(0, (int)($data['validfrom'] ?? $now));
        $expiresat = !empty($data['expiresat']) ? (int)$data['expiresat'] : null;
        if ($expiresat !== null && $expiresat <= $validfrom) {
            throw new \invalid_parameter_exception('Evidence expiry must be after its valid-from time');
        }
        $record = (object)[
            'userid' => $userid,
            'assignmentid' => !empty($data['assignmentid']) ? (int)$data['assignmentid'] : null,
            'skillid' => self::clean_code((string)($data['skillid'] ?? '')) ?: null,
            'positionid' => self::clean_code((string)($data['positionid'] ?? '')) ?: null,
            'evidencetype' => $type,
            'sourcekind' => $sourcekind,
            'sourceid' => \core_text::substr($sourceid, 0, 128),
            'outcome' => $outcome,
            'status' => 'valid',
            'idempotencykey' => $key,
            'detailsjson' => self::json($data['details'] ?? []),
            'validfrom' => $validfrom,
            'expiresat' => $expiresat,
            'recordedby' => max(0, $actorid),
            'timecreated' => $now,
        ];
        try {
            return (int)$DB->insert_record('local_ustar_evidence_rec', $record);
        } catch (\dml_write_exception $e) {
            $existing = $DB->get_field('local_ustar_evidence_rec', 'id', ['idempotencykey' => $key]);
            if ($existing) return (int)$existing;
            throw $e;
        }
    }

    /** Append a correction/revocation; the original evidence row is never overwritten. */
    public static function append_evidence_event(
        int $evidenceid, string $eventtype, string $reason, int $actorid, ?int $replacementid = null
    ): int {
        global $DB;
        $evidence = $DB->get_record('local_ustar_evidence_rec', ['id' => $evidenceid], '*', MUST_EXIST);
        self::require_manager($actorid, (int)$evidence->userid);
        $eventtype = self::clean_code($eventtype, 16);
        $reason = trim($reason);
        if (!in_array($eventtype, ['corrected','revoked','renewed','restored'], true) || $reason === '') {
            throw new \invalid_parameter_exception('A supported evidence event and reason are required');
        }
        $latest = self::latest_evidence_event($evidenceid);
        if ($latest
                && (string)$latest->eventtype === $eventtype
                && (int)($latest->replacementid ?? 0) === (int)($replacementid ?? 0)
                && (string)$latest->reason === $reason) {
            return (int)$latest->id;
        }
        if ($replacementid !== null) {
            $replacement = $DB->get_record('local_ustar_evidence_rec', ['id' => $replacementid], 'id,userid', MUST_EXIST);
            if ((int)$replacement->userid !== (int)$evidence->userid) {
                throw new \invalid_parameter_exception('Replacement evidence belongs to another user');
            }
        }
        return (int)$DB->insert_record('local_ustar_evidence_evt', (object)[
            'evidenceid' => $evidenceid, 'eventtype' => $eventtype, 'reason' => $reason,
            'replacementid' => $replacementid, 'actorid' => $actorid, 'timecreated' => time(),
        ]);
    }

    /** Return the newest immutable lifecycle event for one evidence fact. */
    private static function latest_evidence_event(int $evidenceid, ?int $attime = null): ?\stdClass {
        global $DB;
        $where = 'evidenceid = :evidenceid';
        $params = ['evidenceid' => $evidenceid];
        if ($attime !== null) {
            $where .= ' AND timecreated <= :attime';
            $params['attime'] = $attime;
        }
        $event = $DB->get_record_sql(
            'SELECT * FROM {local_ustar_evidence_evt}
              WHERE ' . $where . '
           ORDER BY timecreated DESC, id DESC',
            $params,
            IGNORE_MULTIPLE
        );
        return $event ?: null;
    }

    /** Current evidence state is derived from immutable facts and its latest lifecycle event. */
    public static function evidence_is_valid(int $evidenceid, ?int $attime = null): bool {
        global $DB;
        $evidence = $DB->get_record('local_ustar_evidence_rec', ['id' => $evidenceid], '*', IGNORE_MISSING);
        if (!$evidence || $evidence->outcome === 'failed') {
            return false;
        }
        $attime = $attime ?? time();
        if ((int)$evidence->timecreated > $attime || (int)$evidence->validfrom > $attime
                || (!empty($evidence->expiresat) && (int)$evidence->expiresat <= $attime)) {
            return false;
        }
        $latest = self::latest_evidence_event($evidenceid, $attime);
        return !$latest || (string)$latest->eventtype === 'restored';
    }

    /** Batch lifecycle projection for reports; the latest event is time-scoped. */
    public static function evidence_validity(array $evidenceids, ?int $attime = null): array {
        global $DB;
        $ids = array_values(array_unique(array_filter(array_map('intval', $evidenceids),
            static fn(int $id): bool => $id > 0)));
        if (!$ids) return [];
        $attime = $attime ?? time();
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'ev');
        $records = $DB->get_records_select('local_ustar_evidence_rec', 'id ' . $insql,
            $params, '', 'id,outcome,validfrom,expiresat,timecreated');
        [$eventsql, $eventparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'evt');
        $eventparams['at'] = $attime;
        $events = $DB->get_records_select('local_ustar_evidence_evt',
            'evidenceid ' . $eventsql . ' AND timecreated <= :at', $eventparams,
            'timecreated DESC,id DESC', 'id,evidenceid,eventtype');
        $latest = [];
        foreach ($events as $event) {
            $eid = (int)$event->evidenceid;
            if (!isset($latest[$eid])) $latest[$eid] = (string)$event->eventtype;
        }
        $valid = [];
        foreach ($ids as $id) {
            $fact = $records[$id] ?? null;
            $valid[$id] = $fact !== null && (string)$fact->outcome !== 'failed'
                && (int)$fact->timecreated <= $attime && (int)$fact->validfrom <= $attime
                && (empty($fact->expiresat) || (int)$fact->expiresat > $attime)
                && (!isset($latest[$id]) || $latest[$id] === 'restored');
        }
        return $valid;
    }

    /**
     * Renew evidence as a new immutable fact and supersede the old fact.
     *
     * The caller supplies a new idempotency key and may override source,
     * validity and details. Identity, type and outcome stay explicit in the
     * copied payload, so every supported evidence type follows one lifecycle.
     */
    public static function renew_evidence(
        int $evidenceid,
        array $overrides,
        int $actorid
    ): int {
        global $DB;
        $evidence = $DB->get_record('local_ustar_evidence_rec', ['id' => $evidenceid], '*', MUST_EXIST);
        self::require_manager($actorid, (int)$evidence->userid);

        $key = \core_text::substr(trim((string)($overrides['idempotencykey'] ?? '')), 0, 128);
        if ($key === '') {
            throw new \invalid_parameter_exception('Renewal idempotency key is required');
        }

        $transaction = $DB->start_delegated_transaction();
        $replacementid = self::record_evidence([
            'userid' => (int)$evidence->userid,
            'assignmentid' => $overrides['assignmentid'] ?? $evidence->assignmentid,
            'skillid' => $overrides['skillid'] ?? $evidence->skillid,
            'positionid' => $overrides['positionid'] ?? $evidence->positionid,
            'evidencetype' => (string)$evidence->evidencetype,
            'sourcekind' => $overrides['sourcekind'] ?? $evidence->sourcekind,
            'sourceid' => $overrides['sourceid'] ?? $evidence->sourceid,
            'outcome' => $overrides['outcome'] ?? $evidence->outcome,
            'idempotencykey' => $key,
            'details' => $overrides['details'] ?? json_decode((string)$evidence->detailsjson, true) ?? [],
            'validfrom' => $overrides['validfrom'] ?? time(),
            'expiresat' => $overrides['expiresat'] ?? null,
        ], $actorid);

        self::append_evidence_event(
            $evidenceid,
            'renewed',
            trim((string)($overrides['reason'] ?? 'Evidence renewed')),
            $actorid,
            $replacementid
        );
        $transaction->allow_commit();
        return $replacementid;
    }

    /** Restore a previously revoked fact without deleting its audit history. */
    public static function restore_evidence(int $evidenceid, string $reason, int $actorid): int {
        global $DB;
        $evidence = $DB->get_record('local_ustar_evidence_rec', ['id' => $evidenceid], '*', MUST_EXIST);
        self::require_manager($actorid, (int)$evidence->userid);
        $latest = self::latest_evidence_event($evidenceid);
        if (!$latest || (string)$latest->eventtype !== 'revoked') {
            throw new \invalid_parameter_exception('Only revoked evidence can be restored');
        }
        return self::append_evidence_event($evidenceid, 'restored', $reason, $actorid);
    }

    /** Record a human decision for a published critical-operation gate. */
    public static function decide_gate(array $data, int $actorid): int {
        global $DB;
        $gateid = (int)($data['gateid'] ?? 0);
        $userid = (int)($data['userid'] ?? 0);
        $gate = $DB->get_record('local_ustar_gate_defs', ['id' => $gateid, 'status' => 'published'], '*', MUST_EXIST);
        self::require_manager($actorid, $userid);
        $decision = self::clean_code((string)($data['decision'] ?? ''), 16);
        $reason = trim((string)($data['reason'] ?? ''));
        if (!in_array($decision, self::GATE_DECISIONS, true) || $reason === '') {
            throw new \invalid_parameter_exception('Gate decision and reason are required');
        }
        $evidenceids = array_values(array_unique(array_map('intval', $data['evidenceids'] ?? [])));
        if ($decision === 'granted' && !$evidenceids) {
            throw new \invalid_parameter_exception('A grant requires evidence');
        }
        foreach ($evidenceids as $evidenceid) {
            $record = $DB->get_record('local_ustar_evidence_rec', ['id' => $evidenceid], 'id,userid', MUST_EXIST);
            if ((int)$record->userid !== $userid || !self::evidence_is_valid($evidenceid)) {
                throw new \invalid_parameter_exception('Gate evidence is not valid for this user');
            }
        }
        $previous = $DB->get_record_sql(
            'SELECT * FROM {local_ustar_gate_decisions} WHERE gateid = :gateid AND userid = :userid ORDER BY timecreated DESC, id DESC',
            ['gateid' => $gateid, 'userid' => $userid], IGNORE_MULTIPLE
        );
        $now = time();
        $validfrom = max(0, (int)($data['validfrom'] ?? $now));
        $expiresat = !empty($data['expiresat']) ? (int)$data['expiresat'] : null;
        if ($expiresat !== null && $expiresat <= $validfrom) {
            throw new \invalid_parameter_exception('Gate expiry must be after valid-from time');
        }
        return (int)$DB->insert_record('local_ustar_gate_decisions', (object)[
            'gateid' => (int)$gate->id, 'userid' => $userid,
            'assignmentid' => !empty($data['assignmentid']) ? (int)$data['assignmentid'] : null,
            'decision' => $decision, 'reason' => $reason, 'evidencejson' => self::json($evidenceids),
            'validfrom' => $validfrom, 'expiresat' => $expiresat,
            'supersedesid' => $previous ? (int)$previous->id : null,
            'decidedby' => $actorid, 'timecreated' => $now,
        ]);
    }

    /** Append one employee/manager checklist perspective; corrections remain separate rows. */
    public static function submit_checklist(array $data, int $actorid): int {
        global $DB;
        $userid = (int)($data['userid'] ?? 0);
        self::require_user($userid);
        $perspective = self::clean_code((string)($data['perspective'] ?? ''), 16);
        if (!in_array($perspective, ['employee','manager','mentor'], true)) {
            throw new \invalid_parameter_exception('Unsupported checklist perspective');
        }
        if ($perspective === 'employee') {
            if ($actorid !== $userid) throw new \required_capability_exception(\context_system::instance(), 'local/ustar:use', 'nopermissions', '');
        } else {
            self::require_manager($actorid, $userid);
        }
        $checklistkey = self::clean_code((string)($data['checklistkey'] ?? ''));
        $workdate = trim((string)($data['workdate'] ?? ''));
        if ($checklistkey === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $workdate)) {
            throw new \invalid_parameter_exception('Checklist key and ISO work date are required');
        }
        $correctionof = !empty($data['correctionofid']) ? (int)$data['correctionofid'] : null;
        if ($correctionof !== null) {
            $old = $DB->get_record('local_ustar_check_submits', ['id' => $correctionof], '*', MUST_EXIST);
            if ((int)$old->userid !== $userid || (string)$old->checklistkey !== $checklistkey
                    || (string)$old->perspective !== $perspective || empty($data['correctionreason'])) {
                throw new \invalid_parameter_exception('Checklist correction must match the original and include a reason');
            }
        }
        return (int)$DB->insert_record('local_ustar_check_submits', (object)[
            'checklistkey' => $checklistkey, 'definitionversion' => max(1, (int)($data['definitionversion'] ?? 1)),
            'userid' => $userid, 'assignmentid' => !empty($data['assignmentid']) ? (int)$data['assignmentid'] : null,
            'adaptationid' => !empty($data['adaptationid']) ? (int)$data['adaptationid'] : null,
            'perspective' => $perspective, 'workdate' => $workdate,
            'status' => self::clean_code((string)($data['status'] ?? 'submitted'), 16) ?: 'submitted',
            'answersjson' => self::json($data['answers'] ?? []),
            'issuesjson' => self::json(['items' => $data['issues'] ?? [], 'correctionreason' => (string)($data['correctionreason'] ?? '')]),
            'correctionofid' => $correctionof, 'submittedby' => $actorid, 'timecreated' => time(),
        ]);
    }

    private static function workflow_event(string $entitytype, int $entityid, string $eventtype, int $actorid, string $reason = '', array $details = []): int {
        global $DB;
        return (int)$DB->insert_record('local_ustar_workflow_events', (object)[
            'entitytype' => self::clean_code($entitytype, 32), 'entityid' => $entityid,
            'eventtype' => self::clean_code($eventtype, 32), 'actorid' => max(0, $actorid),
            'reason' => trim($reason) ?: null, 'detailsjson' => self::json($details), 'timecreated' => time(),
        ]);
    }

    /** Create an official task only inside USTAR's learning/development boundary. */
    public static function create_official_task(array $data, int $actorid): int {
        global $DB;
        $userid = (int)($data['userid'] ?? 0);
        $sourcekind = self::clean_code((string)($data['sourcekind'] ?? ''), 32);
        $category = self::clean_code((string)($data['category'] ?? ''), 32);
        if (!in_array($sourcekind, self::TASK_SOURCES, true) || !in_array($category, self::TASK_CATEGORIES, true)) {
            throw new \invalid_parameter_exception('Unsupported official task source or category');
        }
        if ($actorid > 0) self::require_manager($actorid, $userid); else self::require_user($userid);
        $title = trim((string)($data['title'] ?? ''));
        $sourceid = trim((string)($data['sourceid'] ?? ''));
        $completion = $data['completion'] ?? [];
        if ($title === '' || $sourceid === '' || !$completion) {
            throw new \invalid_parameter_exception('Official task source, title and completion condition are required');
        }
        if ($existing = $DB->get_record('local_ustar_official_tasks', [
            'userid' => $userid, 'sourcekind' => $sourcekind, 'sourceid' => $sourceid, 'status' => 'open'
        ])) return (int)$existing->id;
        $now = time();
        $id = (int)$DB->insert_record('local_ustar_official_tasks', (object)[
            'userid' => $userid, 'assignmentid' => !empty($data['assignmentid']) ? (int)$data['assignmentid'] : null,
            'sourcekind' => $sourcekind, 'sourceid' => \core_text::substr($sourceid, 0, 128),
            'category' => $category, 'title' => \core_text::substr($title, 0, 255),
            'description' => trim((string)($data['description'] ?? '')) ?: null,
            'completionjson' => self::json($completion), 'status' => 'open',
            'ownerid' => !empty($data['ownerid']) ? (int)$data['ownerid'] : $userid,
            'createdby' => max(0, $actorid), 'dueat' => !empty($data['dueat']) ? (int)$data['dueat'] : null,
            'completedat' => null, 'archivedat' => null, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        self::workflow_event('official_task', $id, 'created', $actorid, '', ['sourcekind' => $sourcekind, 'category' => $category]);
        return $id;
    }

    public static function transition_official_task(int $taskid, string $status, int $actorid, string $reason = ''): void {
        global $DB;
        $task = $DB->get_record('local_ustar_official_tasks', ['id' => $taskid], '*', MUST_EXIST);
        self::require_manager($actorid, (int)$task->userid);
        $status = self::clean_code($status, 16);
        $allowed = [
            'open' => ['in_progress','completed','cancelled'],
            'in_progress' => ['open','completed','cancelled'],
            'completed' => ['open'],
            'cancelled' => ['open'],
        ];
        if (!in_array($status, $allowed[(string)$task->status] ?? [], true)) {
            throw new \invalid_parameter_exception('Invalid official task transition');
        }
        if (in_array((string)$task->status, ['completed','cancelled'], true) && $reason === '') {
            throw new \invalid_parameter_exception('Reopening a closed task requires a reason');
        }
        $task->status = $status;
        $task->completedat = $status === 'completed' ? time() : null;
        $task->timemodified = time();
        $DB->update_record('local_ustar_official_tasks', $task);
        self::workflow_event('official_task', $taskid, $status, $actorid, $reason);
    }

    /** Personal tasks remain private and can only be mutated by their owner. */
    public static function save_personal_task(array $data, int $actorid): int {
        global $DB;
        $userid = (int)($data['userid'] ?? $actorid);
        if ($userid !== $actorid) throw new \required_capability_exception(\context_system::instance(), 'local/ustar:use', 'nopermissions', '');
        self::require_user($userid);
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') throw new \invalid_parameter_exception('Personal task title is required');
        $now = time();
        $id = (int)($data['id'] ?? 0);
        if ($id) {
            $task = $DB->get_record('local_ustar_personal_tasks', ['id' => $id, 'userid' => $userid], '*', MUST_EXIST);
            $task->title = \core_text::substr($title, 0, 255);
            $task->description = trim((string)($data['description'] ?? '')) ?: null;
            $task->status = self::clean_code((string)($data['status'] ?? $task->status), 16);
            $task->dueat = !empty($data['dueat']) ? (int)$data['dueat'] : null;
            $task->sharedwithjson = self::json(array_values(array_unique(array_map('intval', $data['sharedwith'] ?? []))));
            $task->timemodified = $now;
            $DB->update_record('local_ustar_personal_tasks', $task);
            self::workflow_event('personal_task', $id, 'updated', $actorid);
            return $id;
        }
        $id = (int)$DB->insert_record('local_ustar_personal_tasks', (object)[
            'userid' => $userid, 'title' => \core_text::substr($title, 0, 255),
            'description' => trim((string)($data['description'] ?? '')) ?: null,
            'status' => 'open', 'dueat' => !empty($data['dueat']) ? (int)$data['dueat'] : null,
            'sharedwithjson' => self::json(array_values(array_unique(array_map('intval', $data['sharedwith'] ?? [])))),
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        self::workflow_event('personal_task', $id, 'created', $actorid);
        return $id;
    }

    /** Canonical USTAR notification with local persistence and optional Bitrix outbox. */
    public static function notify(array $data): int {
        global $DB;
        $userid = (int)($data['userid'] ?? 0);
        self::require_user($userid);
        $severity = self::clean_code((string)($data['severity'] ?? 'normal'), 16);
        if (!in_array($severity, self::NOTIFICATION_SEVERITIES, true)) {
            throw new \invalid_parameter_exception('Unsupported notification severity');
        }
        $key = trim((string)($data['idempotencykey'] ?? ''));
        $subject = trim((string)($data['subject'] ?? ''));
        $message = trim((string)($data['message'] ?? ''));
        if ($key === '' || $subject === '' || $message === '') {
            throw new \invalid_parameter_exception('Notification key, subject and message are required');
        }
        $key = \core_text::substr($key, 0, 128);
        if ($existing = $DB->get_field('local_ustar_notifications', 'id', ['idempotencykey' => $key])) return (int)$existing;
        $now = time();
        $factory = \core\lock\lock_config::get_lock_factory('local_ustar');
        $lock = $factory->get_lock('notification:' . sha1($key), 10);
        if (!$lock) throw new \moodle_exception('Unable to acquire notification idempotency lock');
        try {
            if ($existing = $DB->get_field('local_ustar_notifications', 'id', ['idempotencykey' => $key])) return (int)$existing;
            $transaction = $DB->start_delegated_transaction();
            try {
                $id = (int)$DB->insert_record('local_ustar_notifications', (object)[
                    'userid' => $userid, 'severity' => $severity,
                    'eventtype' => self::clean_code((string)($data['eventtype'] ?? 'general')) ?: 'general',
                    'subject' => \core_text::substr($subject, 0, 255), 'message' => $message,
                    'actionurl' => trim((string)($data['actionurl'] ?? '')) ?: null,
                    'dueat' => !empty($data['dueat']) ? (int)$data['dueat'] : null,
                    'status' => 'unread', 'idempotencykey' => $key, 'ackat' => null,
                    'timecreated' => $now, 'timemodified' => $now,
                ]);
                $DB->insert_record('local_ustar_notify_delivery', (object)[
                    'notificationid' => $id, 'channel' => 'ustar', 'status' => 'delivered', 'attempts' => 1,
                    'nextattempt' => null, 'providerref' => null, 'lasterror' => null,
                    'timecreated' => $now, 'timemodified' => $now,
                ]);
                if ($severity !== 'normal') {
                    $DB->insert_record('local_ustar_notify_delivery', (object)[
                        'notificationid' => $id, 'channel' => 'bitrix', 'status' => 'pending', 'attempts' => 0,
                        'nextattempt' => $now, 'providerref' => null, 'lasterror' => null,
                        'timecreated' => $now, 'timemodified' => $now,
                    ]);
                }
                $transaction->allow_commit();
                return $id;
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
        } finally {
            $lock->release();
        }
    }

    public static function acknowledge_notification(int $notificationid, int $userid): void {
        global $DB;
        $notification = $DB->get_record('local_ustar_notifications', ['id' => $notificationid, 'userid' => $userid], '*', MUST_EXIST);
        if ((string)$notification->status !== 'acknowledged') {
            $notification->status = 'acknowledged';
            $notification->ackat = time();
            $notification->timemodified = time();
            $DB->update_record('local_ustar_notifications', $notification);
        }
    }
}
