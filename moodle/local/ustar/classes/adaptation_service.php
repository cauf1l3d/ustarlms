<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Manager-assigned employee adaptation lifecycle.
 *
 * Learning Route remains canonical for training. This service owns the parallel
 * adaptation cycle, independent employee/manager daily sheets, final reports,
 * escalation events and HRD control state.
 */
final class adaptation_service {
    public const CHECKLIST_KEY = 'adaptation_standard';
    public const DEFINITION_VERSION = 1;
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ESCALATED = 'escalated';
    public const STATUS_CANCELLED = 'cancelled';

    private const EVENT_ENTITY = 'adaptation';
    private const EVENT_FINAL_REPORT = 'adaptation_final_report';
    private const EVENT_FINAL_DECISION = 'adaptation_final_decision';
    private const EVENT_HRD_DECISION = 'adaptation_hrd_decision';
    private const EVENT_ISSUE_RESOLVED = 'adaptation_issue_resolved';
    private const EVENT_CASE_OPEN = 'adaptation_case_open';
    private const EVENT_CASE_TAKEN = 'adaptation_case_taken';
    private const EVENT_CASE_RESOLVED = 'adaptation_case_resolved';

    public static function for_staffing_request(int $requestid): ?\stdClass {
        global $DB;
        return $DB->get_record('local_ustar_adaptations', ['staffingrequestid' => $requestid]) ?: null;
    }

    public static function active_for_user(int $userid): ?\stdClass {
        global $DB;
        return $DB->get_record_sql(
            "SELECT * FROM {local_ustar_adaptations}
              WHERE userid = :userid AND status = :status
           ORDER BY id DESC",
            ['userid' => $userid, 'status' => self::STATUS_ACTIVE],
            IGNORE_MULTIPLE
        ) ?: null;
    }

    private static function current_for_user(int $userid): ?\stdClass {
        global $DB;
        return $DB->get_record_sql(
            "SELECT * FROM {local_ustar_adaptations}
              WHERE userid = :userid AND status IN (:active, :escalated)
           ORDER BY id DESC",
            ['userid' => $userid, 'active' => self::STATUS_ACTIVE, 'escalated' => self::STATUS_ESCALATED],
            IGNORE_MULTIPLE
        ) ?: null;
    }

    public static function assign_from_request(int $requestid, int $managerid, string $startdate, int $plannedworkdays): int {
        global $DB;

        require_capability('local/ustar:viewteam', \context_system::instance());

        if ($plannedworkdays < 1 || $plannedworkdays > 180) {
            throw new \invalid_parameter_exception('Срок адаптации должен быть от 1 до 180 рабочих дней');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startdate)) {
            throw new \invalid_parameter_exception('Укажите корректную дату начала адаптации');
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $startdate);
        if (!$dt || $dt->format('Y-m-d') !== $startdate) {
            throw new \invalid_parameter_exception('Укажите корректную дату начала адаптации');
        }

        $request = $DB->get_record('local_ustar_staff_requests', ['id' => $requestid], '*', MUST_EXIST);
        if ((string)$request->requesttype !== staffing_requests::TYPE_HIRE
                || (string)$request->status !== staffing_requests::STATUS_APPROVED
                || empty($request->createduserid)) {
            throw new \invalid_parameter_exception('Адаптацию можно назначить только по исполненной заявке на приём');
        }
        if ((int)$request->requestedby !== $managerid) {
            throw new \required_capability_exception(\context_system::instance(), 'local/ustar:viewteam', 'nopermissions', '');
        }
        if (self::for_staffing_request($requestid)) {
            throw new \invalid_parameter_exception('Адаптационный чек по этой заявке уже назначен');
        }

        $hiredate = date('Y-m-d', (int)$request->requesteddate);
        if (strtotime($startdate) < strtotime($hiredate)) {
            throw new \invalid_parameter_exception('Адаптация не может начинаться раньше даты выхода сотрудника');
        }

        $userid = (int)$request->createduserid;
        $assignment = $DB->get_record_sql(
            "SELECT * FROM {local_ustar_assignments}
              WHERE userid = :userid AND assignmenttype = :atype AND status = :status
           ORDER BY effectivefrom DESC, id DESC",
            ['userid' => $userid, 'atype' => 'primary', 'status' => 'active'],
            IGNORE_MULTIPLE
        );
        if (!$assignment) {
            throw new \invalid_parameter_exception('У сотрудника не найдено активное основное назначение');
        }

        $now = time();
        $transaction = $DB->start_delegated_transaction();
        $id = (int)$DB->insert_record('local_ustar_adaptations', (object)[
            'staffingrequestid' => $requestid,
            'userid' => $userid,
            'managerid' => $managerid,
            'assignmentid' => (int)$assignment->id,
            'positionid' => (string)$request->positionid,
            'checklistkey' => self::CHECKLIST_KEY,
            'definitionversion' => self::DEFINITION_VERSION,
            'startdate' => $startdate,
            'plannedworkdays' => $plannedworkdays,
            'status' => self::STATUS_ACTIVE,
            'rulesjson' => json_encode([
                'calendar' => 'actual_workdays',
                'employee_overdue_blocks_learning' => true,
                'manager_overdue_does_not_block_employee' => true,
                'independent_perspectives' => true,
                'final_reports_independent' => true,
                'hrd_control_queue' => true,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'createdby' => $managerid,
            'timecreated' => $now,
            'timemodified' => $now,
            'completedat' => null,
        ]);
        people::log_action($managerid, $userid, 'adaptation_assigned', [
            'adaptationid' => $id,
            'requestid' => $requestid,
            'assignmentid' => (int)$assignment->id,
            'startdate' => $startdate,
            'plannedworkdays' => $plannedworkdays,
        ]);
        $transaction->allow_commit();
        return $id;
    }

    private static function submissions(\stdClass $adaptation): array {
        global $DB;
        $rows = $DB->get_records(
            'local_ustar_check_submits',
            ['adaptationid' => (int)$adaptation->id],
            'workdate ASC, id ASC'
        );
        $map = ['employee' => [], 'manager' => []];
        foreach ($rows as $row) {
            $perspective = (string)$row->perspective;
            if (!isset($map[$perspective])) {
                continue;
            }
            // Latest immutable correction/submission is the effective view for this date.
            $map[$perspective][(string)$row->workdate] = $row;
        }
        return $map;
    }

    private static function today(): string {
        return userdate(time(), '%Y-%m-%d');
    }

    private static function factual_dates(array $map): array {
        $dates = array_values(array_unique(array_merge(array_keys($map['employee']), array_keys($map['manager']))));
        sort($dates);
        return $dates;
    }

    private static function pair_days(array $map): int {
        $count = 0;
        foreach (self::factual_dates($map) as $date) {
            if (isset($map['employee'][$date]) && isset($map['manager'][$date])) {
                $count++;
            }
        }
        return $count;
    }

    private static function daily_complete(\stdClass $adaptation, array $map): bool {
        return self::pair_days($map) >= (int)$adaptation->plannedworkdays;
    }

    private static function due_workdate(\stdClass $adaptation, string $perspective, array $map): ?string {
        if ((string)$adaptation->status !== self::STATUS_ACTIVE || self::daily_complete($adaptation, $map)) {
            return null;
        }
        $today = self::today();
        if ($today < (string)$adaptation->startdate) {
            return null;
        }

        $other = $perspective === 'employee' ? 'manager' : 'employee';
        $dates = self::factual_dates($map);

        // Close the oldest factual workday where the opposite side already submitted.
        foreach ($dates as $date) {
            if (isset($map[$other][$date]) && !isset($map[$perspective][$date])) {
                return $date;
            }
        }

        // A factual workday exists only when at least one side actually works with the form.
        if (!isset($map[$perspective][$today]) && self::pair_days($map) < (int)$adaptation->plannedworkdays) {
            return $today;
        }
        return null;
    }

    private static function day_number(\stdClass $adaptation, ?string $workdate, array $map): int {
        $dates = self::factual_dates($map);
        if ($workdate !== null && !in_array($workdate, $dates, true)) {
            $dates[] = $workdate;
            sort($dates);
        }
        if ($workdate !== null) {
            $idx = array_search($workdate, $dates, true);
            if ($idx !== false) {
                return min((int)$adaptation->plannedworkdays, (int)$idx + 1);
            }
        }
        return min((int)$adaptation->plannedworkdays, max(1, count($dates)));
    }

    private static function employee_overdue(array $map): ?string {
        $today = self::today();
        $dates = array_keys($map['manager']);
        sort($dates);
        foreach ($dates as $date) {
            if ($date < $today && !isset($map['employee'][$date])) {
                return $date;
            }
        }
        return null;
    }

    private static function manager_overdue_dates(array $map): array {
        $today = self::today();
        $out = [];
        foreach (array_keys($map['employee']) as $date) {
            if ($date < $today && !isset($map['manager'][$date])) {
                $out[] = $date;
            }
        }
        sort($out);
        return $out;
    }

    private static function label_for_row(?\stdClass $row): string {
        return $row ? ('Заполнено ' . userdate((int)$row->timecreated, '%H:%M')) : 'Не заполнено';
    }

    private static function event(int $adaptationid, string $eventtype, int $actorid, string $reason = '', array $details = []): int {
        global $DB;
        return (int)$DB->insert_record('local_ustar_workflow_events', (object)[
            'entitytype' => self::EVENT_ENTITY,
            'entityid' => $adaptationid,
            'eventtype' => $eventtype,
            'actorid' => max(0, $actorid),
            'reason' => trim($reason) !== '' ? trim($reason) : null,
            'detailsjson' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'timecreated' => time(),
        ]);
    }

    private static function events(int $adaptationid): array {
        global $DB;
        return array_values($DB->get_records('local_ustar_workflow_events', [
            'entitytype' => self::EVENT_ENTITY,
            'entityid' => $adaptationid,
        ], 'id ASC'));
    }

    private static function details(\stdClass $event): array {
        return json_decode((string)($event->detailsjson ?? ''), true) ?: [];
    }

    private static function current_round(int $adaptationid): int {
        $round = 1;
        foreach (self::events($adaptationid) as $event) {
            if (!in_array((string)$event->eventtype, [self::EVENT_FINAL_DECISION, self::EVENT_HRD_DECISION], true)) {
                continue;
            }
            $details = self::details($event);
            if (($details['decision'] ?? '') === 'extend') {
                $round++;
            }
        }
        return $round;
    }

    private static function final_reports(int $adaptationid, int $round): array {
        $reports = ['employee' => null, 'manager' => null];
        foreach (self::events($adaptationid) as $event) {
            if ((string)$event->eventtype !== self::EVENT_FINAL_REPORT) {
                continue;
            }
            $details = self::details($event);
            if ((int)($details['round'] ?? 0) !== $round) {
                continue;
            }
            $perspective = (string)($details['perspective'] ?? '');
            if (array_key_exists($perspective, $reports)) {
                $reports[$perspective] = ['event' => $event, 'details' => $details];
            }
        }
        return $reports;
    }

    private static function final_view(?array $report): ?array {
        if (!$report) {
            return null;
        }
        $d = $report['details'];
        return [
            'mastered' => (string)($d['mastered'] ?? ''),
            'risks' => (string)($d['risks'] ?? ''),
            'support' => (string)($d['support'] ?? ''),
            'ready' => self::ready_label((string)($d['ready'] ?? '')),
            'readyraw' => (string)($d['ready'] ?? ''),
            'submittedat' => userdate((int)$report['event']->timecreated, '%d.%m.%Y %H:%M'),
        ];
    }

    private static function ready_label(string $value): string {
        return $value === 'yes' ? 'Да' : ($value === 'partly' ? 'Частично' : ($value === 'no' ? 'Нет' : $value));
    }

    private static function help_label(string $value): string {
        return $value === 'yes' ? 'Да' : ($value === 'no' ? 'Нет' : $value);
    }

    private static function answer_view(?\stdClass $row): ?array {
        if (!$row) {
            return null;
        }
        $answers = json_decode((string)$row->answersjson, true) ?: [];
        $issues = json_decode((string)$row->issuesjson, true) ?: [];
        return [
            'submissionid' => (int)$row->id,
            'mastered' => (string)($answers['mastered'] ?? ''),
            'succeeded' => (string)($answers['succeeded'] ?? ''),
            'difficult' => (string)($answers['difficult'] ?? ''),
            'help' => self::help_label((string)($answers['help'] ?? '')),
            'helpraw' => (string)($answers['help'] ?? ''),
            'ready' => self::ready_label((string)($answers['ready'] ?? '')),
            'readyraw' => (string)($answers['ready'] ?? ''),
            'overduereason' => (string)($answers['overduereason'] ?? ''),
            'action' => (string)($issues['items'][0]['action'] ?? ''),
            'submittedat' => userdate((int)$row->timecreated, '%d.%m.%Y %H:%M'),
        ];
    }

    private static function issue_resolution_fingerprints(int $adaptationid): array {
        $resolved = [];
        foreach (self::events($adaptationid) as $event) {
            if ((string)$event->eventtype !== self::EVENT_ISSUE_RESOLVED) {
                continue;
            }
            $d = self::details($event);
            $fp = (string)($d['fingerprint'] ?? '');
            if ($fp !== '') {
                $resolved[$fp] = true;
            }
        }
        return $resolved;
    }

    private static function case_states(int $adaptationid): array {
        $states = [];
        foreach (self::events($adaptationid) as $event) {
            $type = (string)$event->eventtype;
            if (!in_array($type, [self::EVENT_CASE_OPEN, self::EVENT_CASE_TAKEN, self::EVENT_CASE_RESOLVED], true)) {
                continue;
            }
            $d = self::details($event);
            $fp = (string)($d['fingerprint'] ?? '');
            if ($fp === '') {
                continue;
            }
            if ($type === self::EVENT_CASE_OPEN) {
                $states[$fp] = [
                    'fingerprint' => $fp,
                    'status' => 'open',
                    'casetype' => (string)($d['casetype'] ?? 'attention'),
                    'severity' => (string)($d['severity'] ?? 'warning'),
                    'summary' => (string)($d['summary'] ?? ''),
                    'workdate' => (string)($d['workdate'] ?? ''),
                    'sourceperspective' => (string)($d['sourceperspective'] ?? ''),
                    'sourceid' => (int)($d['sourceid'] ?? 0),
                    'openedat' => (int)$event->timecreated,
                    'assignedto' => 0,
                    'resolution' => '',
                ];
            } else if (isset($states[$fp]) && $type === self::EVENT_CASE_TAKEN) {
                $states[$fp]['status'] = 'in_control';
                $states[$fp]['assignedto'] = (int)$event->actorid;
            } else if (isset($states[$fp]) && $type === self::EVENT_CASE_RESOLVED) {
                $states[$fp]['status'] = 'resolved';
                $states[$fp]['resolution'] = (string)($event->reason ?? '');
            }
        }
        return $states;
    }

    private static function ensure_case(\stdClass $adaptation, string $fingerprint, string $casetype, string $severity,
            string $summary, string $workdate = '', string $sourceperspective = '', int $sourceid = 0): void {
        $states = self::case_states((int)$adaptation->id);
        if (isset($states[$fingerprint])) {
            return;
        }
        self::event((int)$adaptation->id, self::EVENT_CASE_OPEN, 0, '', [
            'fingerprint' => $fingerprint,
            'casetype' => $casetype,
            'severity' => $severity,
            'summary' => $summary,
            'workdate' => $workdate,
            'sourceperspective' => $sourceperspective,
            'sourceid' => $sourceid,
        ]);
    }

    /** Synchronise deterministic HRD escalation signals from current adaptation facts. */
    public static function sync_cases(\stdClass $adaptation): void {
        $map = self::submissions($adaptation);
        $today = self::today();
        $dates = self::factual_dates($map);
        $resolvedissues = self::issue_resolution_fingerprints((int)$adaptation->id);

        foreach (['employee', 'manager'] as $perspective) {
            foreach ($map[$perspective] as $date => $row) {
                $answers = json_decode((string)$row->answersjson, true) ?: [];
                $help = (string)($answers['help'] ?? '');
                $ready = (string)($answers['ready'] ?? '');
                $label = $perspective === 'employee' ? 'Сотрудник' : 'Руководитель';
                if ($ready === 'no') {
                    self::ensure_case(
                        $adaptation,
                        'notready:' . $row->id,
                        'not_ready',
                        'critical',
                        $label . ' указал готовность «Нет»',
                        $date,
                        $perspective,
                        (int)$row->id
                    );
                    continue;
                }
                if ($help === 'yes' || $ready === 'partly') {
                    $issuefp = 'issue:' . $row->id;
                    if (!isset($resolvedissues[$issuefp])) {
                        $laterfactual = false;
                        foreach ($dates as $candidate) {
                            if ($candidate > $date) {
                                $laterfactual = true;
                                break;
                            }
                        }
                        if ($laterfactual) {
                            self::ensure_case(
                                $adaptation,
                                'support:' . $row->id,
                                'support_overdue',
                                'warning',
                                'Запрошенная помощь/действие не закрыты к следующему рабочему дню',
                                $date,
                                $perspective,
                                (int)$row->id
                            );
                        }
                    }
                }
            }
        }

        foreach ($map['manager'] as $date => $row) {
            if ($date < $today && !isset($map['employee'][$date])) {
                self::ensure_case($adaptation, 'employee_overdue:' . $date, 'employee_overdue', 'warning',
                    'Сотрудник просрочил обязательный адаптационный лист', $date, 'employee', (int)$row->id);
            }
        }
        foreach (self::manager_overdue_dates($map) as $date) {
            $sourceid = isset($map['employee'][$date]) ? (int)$map['employee'][$date]->id : 0;
            self::ensure_case($adaptation, 'manager_overdue:' . $date, 'manager_overdue', 'warning',
                'Руководитель просрочил свой адаптационный лист; сотрудник не заблокирован', $date, 'manager', $sourceid);
        }

        foreach (self::events((int)$adaptation->id) as $event) {
            if ((string)$event->eventtype !== self::EVENT_FINAL_DECISION) {
                continue;
            }
            $d = self::details($event);
            if (($d['decision'] ?? '') === 'escalate') {
                self::ensure_case($adaptation, 'final:' . $event->id, 'final_hrd', 'critical',
                    'Итог адаптации передан на решение HRD', '', 'manager', (int)$event->id);
            }
        }
    }

    private static function unresolved_manager_issues(\stdClass $adaptation, array $map, string $workdate): array {
        $resolved = self::issue_resolution_fingerprints((int)$adaptation->id);
        $items = [];
        foreach (['employee', 'manager'] as $perspective) {
            $row = $map[$perspective][$workdate] ?? null;
            if (!$row) {
                continue;
            }
            $answers = json_decode((string)$row->answersjson, true) ?: [];
            $issues = json_decode((string)$row->issuesjson, true) ?: [];
            $action = (string)($issues['items'][0]['action'] ?? '');
            $help = (string)($answers['help'] ?? '');
            $ready = (string)($answers['ready'] ?? '');
            if ($action === '' || ($help !== 'yes' && $ready === 'yes')) {
                continue;
            }
            $fp = 'issue:' . $row->id;
            if (isset($resolved[$fp])) {
                continue;
            }
            $items[] = [
                'submissionid' => (int)$row->id,
                'perspectivelabel' => $perspective === 'employee' ? 'Сотрудник' : 'Руководитель',
                'action' => $action,
            ];
        }
        return $items;
    }

    public static function manager_card(\stdClass $adaptation, int $viewerid): array {
        self::sync_cases($adaptation);
        $map = self::submissions($adaptation);
        $today = self::today();
        $managerdue = self::due_workdate($adaptation, 'manager', $map);
        $employeedue = self::due_workdate($adaptation, 'employee', $map);
        $displaydate = $managerdue ?? $employeedue ?? ($map['manager'] ? (string)array_key_last($map['manager']) : $today);
        $day = self::day_number($adaptation, $displaydate, $map);
        $employee = $map['employee'][$displaydate] ?? null;
        $manager = $map['manager'][$displaydate] ?? null;
        $dailycomplete = self::daily_complete($adaptation, $map);
        $round = self::current_round((int)$adaptation->id);
        $finals = self::final_reports((int)$adaptation->id, $round);
        $escalated = (string)$adaptation->status === self::STATUS_ESCALATED;
        $active = (string)$adaptation->status === self::STATUS_ACTIVE;
        $canfinalfill = $active && $dailycomplete && !$finals['manager'] && (int)$adaptation->managerid === $viewerid;
        $canfinaldecide = $active && $dailycomplete && $finals['employee'] && $finals['manager'] && (int)$adaptation->managerid === $viewerid;

        return [
            'id' => (int)$adaptation->id,
            'active' => $active,
            'escalated' => $escalated,
            'statuslabel' => $escalated ? 'Передано HRD' : ($dailycomplete ? 'Ежедневные листы завершены' : 'Активна'),
            'startdate' => userdate(strtotime((string)$adaptation->startdate . ' 12:00:00'), '%d.%m.%Y'),
            'plannedworkdays' => (int)$adaptation->plannedworkdays,
            'daynumber' => $day,
            'workdate' => $displaydate,
            'employeestatus' => self::label_for_row($employee),
            'managerstatus' => self::label_for_row($manager),
            'canmanagerfill' => $active && (int)$adaptation->managerid === $viewerid && $managerdue !== null && !$dailycomplete,
            'managerformurl' => (new \moodle_url('/local/ustar/adaptation.php', ['id' => (int)$adaptation->id]))->out(false),
            'dailycomplete' => $dailycomplete,
            'awaitingfinal' => $dailycomplete && $active,
            'canfinalfill' => $canfinalfill,
            'canfinaldecide' => $canfinaldecide,
            'finalformurl' => (new \moodle_url('/local/ustar/adaptation.php', ['id' => (int)$adaptation->id, 'mode' => 'final']))->out(false),
            'employeefinalstatus' => $finals['employee'] ? ('Заполнено ' . userdate((int)$finals['employee']['event']->timecreated, '%H:%M')) : 'Не заполнено',
            'managerfinalstatus' => $finals['manager'] ? ('Заполнено ' . userdate((int)$finals['manager']['event']->timecreated, '%H:%M')) : 'Не заполнено',
        ];
    }

    public static function route_card(int $userid): ?array {
        $adaptation = self::current_for_user($userid);
        if (!$adaptation) {
            return null;
        }
        self::sync_cases($adaptation);
        $map = self::submissions($adaptation);
        $due = self::due_workdate($adaptation, 'employee', $map);
        $today = self::today();
        $displaydate = $due ?? ($map['employee'] ? (string)array_key_last($map['employee']) : $today);
        $day = self::day_number($adaptation, $displaydate, $map);
        $own = $map['employee'][$displaydate] ?? null;
        $other = $map['manager'][$displaydate] ?? null;
        $overduedate = self::employee_overdue($map);
        $dailycomplete = self::daily_complete($adaptation, $map);
        $round = self::current_round((int)$adaptation->id);
        $finals = self::final_reports((int)$adaptation->id, $round);
        $active = (string)$adaptation->status === self::STATUS_ACTIVE;
        $escalated = (string)$adaptation->status === self::STATUS_ESCALATED;

        return [
            'id' => (int)$adaptation->id,
            'plannedworkdays' => (int)$adaptation->plannedworkdays,
            'daynumber' => $day,
            'workdate' => $displaydate,
            'startdate' => userdate(strtotime((string)$adaptation->startdate . ' 12:00:00'), '%d.%m.%Y'),
            'canfill' => $active && $due !== null && !$dailycomplete,
            'submitted' => $own !== null,
            'employeestatus' => self::label_for_row($own),
            'managerstatus' => self::label_for_row($other),
            'formurl' => (new \moodle_url('/local/ustar/adaptation.php', ['id' => (int)$adaptation->id]))->out(false),
            'blocked' => $active && $overduedate !== null,
            'overduedate' => $overduedate ? userdate(strtotime($overduedate . ' 12:00:00'), '%d.%m.%Y') : '',
            'dailycomplete' => $dailycomplete,
            'awaitingfinal' => $dailycomplete && $active,
            'escalated' => $escalated,
            'statuslabel' => $escalated ? 'Передано HRD' : ($dailycomplete ? 'Ежедневные листы завершены' : 'Активна'),
            'canfinalfill' => $active && $dailycomplete && !$finals['employee'],
            'finalformurl' => (new \moodle_url('/local/ustar/adaptation.php', ['id' => (int)$adaptation->id, 'mode' => 'final']))->out(false),
            'employeefinalstatus' => $finals['employee'] ? ('Заполнено ' . userdate((int)$finals['employee']['event']->timecreated, '%H:%M')) : 'Не заполнено',
            'managerfinalstatus' => $finals['manager'] ? ('Заполнено ' . userdate((int)$finals['manager']['event']->timecreated, '%H:%M')) : 'Не заполнено',
        ];
    }

    public static function learning_blocked(int $userid): bool {
        $adaptation = self::active_for_user($userid);
        return $adaptation ? self::employee_overdue(self::submissions($adaptation)) !== null : false;
    }

    private static function perspective(\stdClass $adaptation, int $actorid): string {
        if ((int)$adaptation->userid === $actorid) {
            return 'employee';
        }
        if ((int)$adaptation->managerid === $actorid) {
            return 'manager';
        }
        throw new \required_capability_exception(\context_system::instance(), 'local/ustar:use', 'nopermissions', '');
    }

    public static function page_context(int $adaptationid, int $actorid, string $mode = 'daily'): array {
        global $DB;
        $adaptation = $DB->get_record('local_ustar_adaptations', ['id' => $adaptationid], '*', MUST_EXIST);
        $perspective = self::perspective($adaptation, $actorid);
        self::sync_cases($adaptation);
        $map = self::submissions($adaptation);
        $dailycomplete = self::daily_complete($adaptation, $map);
        $employee = $perspective === 'employee';
        $base = [
            'adaptationid' => (int)$adaptation->id,
            'employee' => $employee,
            'manager' => !$employee,
            'perspectivelabel' => $employee ? 'Лист сотрудника' : 'Лист руководителя',
            'plannedworkdays' => (int)$adaptation->plannedworkdays,
            'dailycomplete' => $dailycomplete,
            'escalated' => (string)$adaptation->status === self::STATUS_ESCALATED,
            'completed' => (string)$adaptation->status === self::STATUS_COMPLETED,
            'routeurl' => (new \moodle_url('/local/ustar/route.php'))->out(false),
            'staffingurl' => (new \moodle_url('/local/ustar/staffing.php'))->out(false),
            'dailyurl' => (new \moodle_url('/local/ustar/adaptation.php', ['id' => (int)$adaptation->id]))->out(false),
            'finalurl' => (new \moodle_url('/local/ustar/adaptation.php', ['id' => (int)$adaptation->id, 'mode' => 'final']))->out(false),
        ];

        if ($mode === 'final') {
            $round = self::current_round((int)$adaptation->id);
            $finals = self::final_reports((int)$adaptation->id, $round);
            $own = $finals[$perspective];
            $otherperspective = $employee ? 'manager' : 'employee';
            $other = $own ? $finals[$otherperspective] : null;
            $ownview = self::final_view($own);
            $otherview = self::final_view($other);
            $cancomplete = false;
            if ($finals['employee'] && $finals['manager']) {
                $er = (string)($finals['employee']['details']['ready'] ?? '');
                $mr = (string)($finals['manager']['details']['ready'] ?? '');
                $cancomplete = $er === 'yes' && $mr === 'yes';
            }
            return $base + [
                'finalmode' => true,
                'dailymode' => false,
                'round' => $round,
                'canfinalfill' => (string)$adaptation->status === self::STATUS_ACTIVE && $dailycomplete && !$own,
                'hasownfinal' => (bool)$ownview,
                'ownfinal' => $ownview,
                'hasotherfinal' => (bool)$otherview,
                'otherfinal' => $otherview,
                'canfinaldecide' => !$employee && (int)$adaptation->managerid === $actorid
                    && (string)$adaptation->status === self::STATUS_ACTIVE && $dailycomplete
                    && $finals['employee'] && $finals['manager'],
                'cancomplete' => $cancomplete,
            ];
        }

        if ((string)$adaptation->status !== self::STATUS_ACTIVE) {
            return $base + [
                'finalmode' => false,
                'dailymode' => true,
                'canfill' => false,
                'alreadyfilled' => false,
                'hasown' => false,
                'hasother' => false,
            ];
        }

        $due = self::due_workdate($adaptation, $perspective, $map);
        $today = self::today();
        $ownmap = $map[$perspective];
        $otherperspective = $employee ? 'manager' : 'employee';
        $workdate = $due;
        if ($workdate === null && isset($ownmap[$today])) {
            $workdate = $today;
        }
        if ($workdate === null) {
            $dates = array_keys($ownmap);
            sort($dates);
            $workdate = $dates ? (string)end($dates) : $today;
        }
        $ownrow = $ownmap[$workdate] ?? null;
        $otherrow = $ownrow ? ($map[$otherperspective][$workdate] ?? null) : null;
        $isoverdue = $employee && $due !== null && $due < $today;
        $managerissues = !$employee && $ownrow && $otherrow
            ? self::unresolved_manager_issues($adaptation, $map, $workdate) : [];

        return $base + [
            'finalmode' => false,
            'dailymode' => true,
            'daynumber' => self::day_number($adaptation, $workdate, $map),
            'workdate' => $workdate,
            'workdatelabel' => userdate(strtotime($workdate . ' 12:00:00'), '%d.%m.%Y'),
            'canfill' => $due !== null && !$dailycomplete,
            'alreadyfilled' => $ownrow !== null,
            'own' => self::answer_view($ownrow),
            'hasown' => $ownrow !== null,
            'other' => self::answer_view($otherrow),
            'hasother' => $otherrow !== null,
            'isoverdue' => $isoverdue,
            'managerissues' => $managerissues,
            'hasmanagerissues' => !empty($managerissues),
            'q1' => $employee ? 'Что я сегодня освоил?' : 'Что сотрудник сегодня освоил?',
            'q2' => $employee ? 'Что у меня получилось?' : 'Что у сотрудника получилось?',
            'q3' => 'Что осталось трудным или рискованным?',
            'q4' => $employee ? 'Нужна ли мне помощь?' : 'Нужна ли сотруднику помощь?',
            'q5' => $employee ? 'Готов ли я идти дальше?' : 'Готов ли сотрудник идти дальше?',
        ];
    }

    public static function submit_daily(int $adaptationid, int $actorid, array $input): int {
        global $DB;
        $adaptation = $DB->get_record('local_ustar_adaptations', ['id' => $adaptationid], '*', MUST_EXIST);
        if ((string)$adaptation->status !== self::STATUS_ACTIVE) {
            throw new \invalid_parameter_exception('Адаптационный цикл не активен');
        }
        $perspective = self::perspective($adaptation, $actorid);
        $map = self::submissions($adaptation);
        $workdate = self::due_workdate($adaptation, $perspective, $map);
        if ($workdate === null || isset($map[$perspective][$workdate])) {
            throw new \invalid_parameter_exception('Обязательный лист уже заполнен или ещё не открыт');
        }

        $mastered = clean_param(trim((string)($input['mastered'] ?? '')), PARAM_TEXT);
        $succeeded = clean_param(trim((string)($input['succeeded'] ?? '')), PARAM_TEXT);
        $difficult = clean_param(trim((string)($input['difficult'] ?? '')), PARAM_TEXT);
        $help = clean_param(trim((string)($input['help'] ?? '')), PARAM_ALPHANUMEXT);
        $ready = clean_param(trim((string)($input['ready'] ?? '')), PARAM_ALPHANUMEXT);
        $action = clean_param(trim((string)($input['action'] ?? '')), PARAM_TEXT);
        $overduereason = clean_param(trim((string)($input['overduereason'] ?? '')), PARAM_TEXT);

        if ($mastered === '' || $succeeded === '' || $difficult === ''
                || !in_array($help, ['yes', 'no'], true)
                || !in_array($ready, ['yes', 'partly', 'no'], true)) {
            throw new \invalid_parameter_exception('Ответьте на все пять вопросов');
        }
        if (($help === 'yes' || $ready !== 'yes') && $action === '') {
            throw new \invalid_parameter_exception('Если нужна помощь или готовность неполная, укажите конкретное следующее действие');
        }
        if ($perspective === 'employee' && $workdate < self::today() && $overduereason === '') {
            throw new \invalid_parameter_exception('Для просроченного листа сотрудник обязан указать причину просрочки');
        }

        $issues = [];
        if ($action !== '') {
            $issues[] = ['action' => $action, 'status' => 'open', 'source' => 'adaptation_daily'];
        }

        $id = target_core::submit_checklist([
            'userid' => (int)$adaptation->userid,
            'assignmentid' => (int)$adaptation->assignmentid,
            'adaptationid' => (int)$adaptation->id,
            'checklistkey' => (string)$adaptation->checklistkey,
            'definitionversion' => (int)$adaptation->definitionversion,
            'perspective' => $perspective,
            'workdate' => $workdate,
            'status' => 'submitted',
            'answers' => [
                'mastered' => $mastered,
                'succeeded' => $succeeded,
                'difficult' => $difficult,
                'help' => $help,
                'ready' => $ready,
                'overduereason' => $overduereason,
            ],
            'issues' => $issues,
        ], $actorid);

        people::log_action($actorid, (int)$adaptation->userid, 'adaptation_checklist_submitted', [
            'adaptationid' => (int)$adaptation->id,
            'submissionid' => $id,
            'perspective' => $perspective,
            'workdate' => $workdate,
        ]);
        self::sync_cases($adaptation);
        return $id;
    }

    public static function resolve_daily_issue(int $adaptationid, int $actorid, int $submissionid, string $note): void {
        global $DB;
        $adaptation = $DB->get_record('local_ustar_adaptations', ['id' => $adaptationid], '*', MUST_EXIST);
        if ((int)$adaptation->managerid !== $actorid) {
            throw new \required_capability_exception(\context_system::instance(), 'local/ustar:viewteam', 'nopermissions', '');
        }
        $row = $DB->get_record('local_ustar_check_submits', ['id' => $submissionid, 'adaptationid' => $adaptationid], '*', MUST_EXIST);
        $note = clean_param(trim($note), PARAM_TEXT);
        if ($note === '') {
            throw new \invalid_parameter_exception('Укажите, как закрыто действие');
        }
        $fp = 'issue:' . $row->id;
        if (isset(self::issue_resolution_fingerprints($adaptationid)[$fp])) {
            return;
        }
        self::event($adaptationid, self::EVENT_ISSUE_RESOLVED, $actorid, $note, [
            'fingerprint' => $fp,
            'submissionid' => (int)$row->id,
            'workdate' => (string)$row->workdate,
        ]);
        $cases = self::case_states($adaptationid);
        $casefp = 'support:' . $row->id;
        if (isset($cases[$casefp]) && $cases[$casefp]['status'] === 'open') {
            self::event($adaptationid, self::EVENT_CASE_RESOLVED, $actorid, 'Закрыто руководителем до взятия HRD в контроль', [
                'fingerprint' => $casefp,
            ]);
        }
    }

    public static function submit_final_report(int $adaptationid, int $actorid, array $input): int {
        global $DB;
        $adaptation = $DB->get_record('local_ustar_adaptations', ['id' => $adaptationid], '*', MUST_EXIST);
        if ((string)$adaptation->status !== self::STATUS_ACTIVE) {
            throw new \invalid_parameter_exception('Адаптационный цикл не активен');
        }
        $map = self::submissions($adaptation);
        if (!self::daily_complete($adaptation, $map)) {
            throw new \invalid_parameter_exception('Итоговый отчёт открывается после завершения всех ежедневных листов');
        }
        $perspective = self::perspective($adaptation, $actorid);
        $round = self::current_round($adaptationid);
        $reports = self::final_reports($adaptationid, $round);
        if ($reports[$perspective]) {
            throw new \invalid_parameter_exception('Итоговый отчёт этой стороны уже отправлен');
        }

        $mastered = clean_param(trim((string)($input['mastered'] ?? '')), PARAM_TEXT);
        $risks = clean_param(trim((string)($input['risks'] ?? '')), PARAM_TEXT);
        $support = clean_param(trim((string)($input['support'] ?? '')), PARAM_TEXT);
        $ready = clean_param(trim((string)($input['ready'] ?? '')), PARAM_ALPHANUMEXT);
        if ($mastered === '' || $risks === '' || $support === '' || !in_array($ready, ['yes', 'partly', 'no'], true)) {
            throw new \invalid_parameter_exception('Заполните весь итоговый отчёт');
        }

        $id = self::event($adaptationid, self::EVENT_FINAL_REPORT, $actorid, '', [
            'round' => $round,
            'perspective' => $perspective,
            'mastered' => $mastered,
            'risks' => $risks,
            'support' => $support,
            'ready' => $ready,
        ]);
        people::log_action($actorid, (int)$adaptation->userid, 'adaptation_final_report_submitted', [
            'adaptationid' => $adaptationid,
            'round' => $round,
            'perspective' => $perspective,
            'eventid' => $id,
        ]);
        return $id;
    }

    public static function manager_final_decision(int $adaptationid, int $actorid, string $decision, string $reason, int $extensiondays = 0): void {
        global $DB;
        $adaptation = $DB->get_record('local_ustar_adaptations', ['id' => $adaptationid], '*', MUST_EXIST);
        if ((int)$adaptation->managerid !== $actorid || (string)$adaptation->status !== self::STATUS_ACTIVE) {
            throw new \required_capability_exception(\context_system::instance(), 'local/ustar:viewteam', 'nopermissions', '');
        }
        $map = self::submissions($adaptation);
        if (!self::daily_complete($adaptation, $map)) {
            throw new \invalid_parameter_exception('Сначала завершите ежедневные листы');
        }
        $round = self::current_round($adaptationid);
        $reports = self::final_reports($adaptationid, $round);
        if (!$reports['employee'] || !$reports['manager']) {
            throw new \invalid_parameter_exception('Сначала обе стороны должны независимо отправить итоговые отчёты');
        }
        if (!in_array($decision, ['complete', 'extend', 'escalate'], true)) {
            throw new \invalid_parameter_exception('Неизвестное итоговое решение');
        }
        $reason = clean_param(trim($reason), PARAM_TEXT);
        if (($decision === 'extend' || $decision === 'escalate') && $reason === '') {
            throw new \invalid_parameter_exception('Для продления или передачи HRD укажите причину');
        }
        if ($decision === 'extend' && ($extensiondays < 1 || $extensiondays > 180)) {
            throw new \invalid_parameter_exception('Продление должно быть от 1 до 180 рабочих дней');
        }
        if ($decision === 'complete') {
            $er = (string)($reports['employee']['details']['ready'] ?? '');
            $mr = (string)($reports['manager']['details']['ready'] ?? '');
            if ($er !== 'yes' || $mr !== 'yes') {
                throw new \invalid_parameter_exception('Завершить адаптацию можно только если обе стороны подтвердили полную готовность');
            }
        }

        $now = time();
        if ($decision === 'complete') {
            $adaptation->status = self::STATUS_COMPLETED;
            $adaptation->completedat = $now;
        } else if ($decision === 'extend') {
            $adaptation->plannedworkdays = (int)$adaptation->plannedworkdays + $extensiondays;
        } else {
            $adaptation->status = self::STATUS_ESCALATED;
        }
        $adaptation->timemodified = $now;
        $DB->update_record('local_ustar_adaptations', $adaptation);
        $eventid = self::event($adaptationid, self::EVENT_FINAL_DECISION, $actorid, $reason, [
            'round' => $round,
            'decision' => $decision,
            'extensiondays' => $extensiondays,
        ]);
        people::log_action($actorid, (int)$adaptation->userid, 'adaptation_final_decision', [
            'adaptationid' => $adaptationid,
            'round' => $round,
            'decision' => $decision,
            'extensiondays' => $extensiondays,
            'eventid' => $eventid,
        ]);
        self::sync_cases($adaptation);
    }

    public static function is_hrd_actor(int $actorid): bool {
        if (is_siteadmin($actorid)) {
            return true;
        }
        try {
            $resolved = structure::resolve_user($actorid);
            $role = strtolower(trim((string)($resolved['role'] ?? '')));
            $position = is_array($resolved['position'] ?? null) ? $resolved['position'] : [];
            $profile = strtolower(trim((string)(
                $position['accessprofile'] ?? $resolved['accessprofile'] ?? ''
            )));
            if ($role !== 'hrd' && $profile !== 'hrd') {
                return false;
            }
            // HRD identity is canonical. Do not couple this HRD-only surface to the
            // shared local/ustar:hrmanage capability (HR and HRD may share it).
            return has_capability('local/ustar:use', \context_system::instance(), $actorid);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** HRD-only guard based on canonical USTAR role/access profile. */
    public static function require_hrd_actor(int $actorid): void {
        $context = \context_system::instance();
        require_capability('local/ustar:use', $context);
        if (!self::is_hrd_actor($actorid)) {
            throw new \required_capability_exception($context, 'local/ustar:use', 'nopermissions', '');
        }
    }

    public static function take_case(int $adaptationid, string $fingerprint, int $actorid): void {
        self::require_hrd_actor($actorid);
        global $DB;
        $adaptation = $DB->get_record('local_ustar_adaptations', ['id' => $adaptationid], '*', MUST_EXIST);
        self::sync_cases($adaptation);
        $states = self::case_states($adaptationid);
        if (!isset($states[$fingerprint]) || $states[$fingerprint]['status'] === 'resolved') {
            throw new \invalid_parameter_exception('Эскалация уже закрыта или не найдена');
        }
        if ($states[$fingerprint]['status'] !== 'in_control') {
            self::event($adaptationid, self::EVENT_CASE_TAKEN, $actorid, '', ['fingerprint' => $fingerprint]);
        }
    }

    public static function resolve_case(int $adaptationid, string $fingerprint, int $actorid, string $resolution): void {
        self::require_hrd_actor($actorid);
        global $DB;
        $adaptation = $DB->get_record('local_ustar_adaptations', ['id' => $adaptationid], '*', MUST_EXIST);
        self::sync_cases($adaptation);
        $states = self::case_states($adaptationid);
        if (!isset($states[$fingerprint]) || $states[$fingerprint]['status'] === 'resolved') {
            throw new \invalid_parameter_exception('Эскалация уже закрыта или не найдена');
        }
        $resolution = clean_param(trim($resolution), PARAM_TEXT);
        if ($resolution === '') {
            throw new \invalid_parameter_exception('Укажите результат разбора');
        }
        self::event($adaptationid, self::EVENT_CASE_RESOLVED, $actorid, $resolution, ['fingerprint' => $fingerprint]);
    }

    public static function hrd_final_decision(int $adaptationid, int $actorid, string $decision, string $reason, int $extensiondays = 0): void {
        self::require_hrd_actor($actorid);
        global $DB;
        $adaptation = $DB->get_record('local_ustar_adaptations', ['id' => $adaptationid], '*', MUST_EXIST);
        if ((string)$adaptation->status !== self::STATUS_ESCALATED) {
            throw new \invalid_parameter_exception('Адаптация не находится на решении HRD');
        }
        if (!in_array($decision, ['complete', 'extend'], true)) {
            throw new \invalid_parameter_exception('HRD может завершить адаптацию или продлить её');
        }
        $reason = clean_param(trim($reason), PARAM_TEXT);
        if ($reason === '') {
            throw new \invalid_parameter_exception('Укажите решение HRD');
        }
        if ($decision === 'extend' && ($extensiondays < 1 || $extensiondays > 180)) {
            throw new \invalid_parameter_exception('Продление должно быть от 1 до 180 рабочих дней');
        }
        $round = self::current_round($adaptationid);
        $now = time();
        if ($decision === 'complete') {
            $adaptation->status = self::STATUS_COMPLETED;
            $adaptation->completedat = $now;
        } else {
            $adaptation->status = self::STATUS_ACTIVE;
            $adaptation->plannedworkdays = (int)$adaptation->plannedworkdays + $extensiondays;
            $adaptation->completedat = null;
        }
        $adaptation->timemodified = $now;
        $DB->update_record('local_ustar_adaptations', $adaptation);
        self::event($adaptationid, self::EVENT_HRD_DECISION, $actorid, $reason, [
            'round' => $round,
            'decision' => $decision,
            'extensiondays' => $extensiondays,
        ]);
        $states = self::case_states($adaptationid);
        foreach ($states as $fp => $state) {
            if ($state['casetype'] === 'final_hrd' && $state['status'] !== 'resolved') {
                self::event($adaptationid, self::EVENT_CASE_RESOLVED, $actorid, $reason, ['fingerprint' => $fp]);
            }
        }
        people::log_action($actorid, (int)$adaptation->userid, 'adaptation_hrd_decision', [
            'adaptationid' => $adaptationid,
            'decision' => $decision,
            'extensiondays' => $extensiondays,
        ]);
    }

    public static function hr_case_count(int $actorid): int {
        self::require_hrd_actor($actorid);
        $ctx = self::hr_control_context($actorid, 0);
        return (int)$ctx['opencount'] + (int)$ctx['incontrolcount'];
    }

    public static function hr_control_context(int $actorid, int $selectedid = 0): array {
        self::require_hrd_actor($actorid);
        global $DB;
        $adaptations = $DB->get_records('local_ustar_adaptations', [], 'id DESC');
        $structure = structure::get(structure::NAME_STRUCTURE);
        $positions = people::position_map($structure);
        $cases = [];
        $cycles = [];
        $opencount = 0;
        $criticalcount = 0;
        $incontrolcount = 0;

        foreach ($adaptations as $adaptation) {
            self::sync_cases($adaptation);
            $states = self::case_states((int)$adaptation->id);
            $employee = $DB->get_record('user', ['id' => (int)$adaptation->userid], 'id,firstname,lastname', IGNORE_MISSING);
            $manager = $DB->get_record('user', ['id' => (int)$adaptation->managerid], 'id,firstname,lastname', IGNORE_MISSING);
            $map = self::submissions($adaptation);
            $status = (string)$adaptation->status;
            $cycles[] = [
                'id' => (int)$adaptation->id,
                'employee' => $employee ? fullname($employee) : ('#' . (int)$adaptation->userid),
                'manager' => $manager ? fullname($manager) : ('#' . (int)$adaptation->managerid),
                'position' => (string)($positions[(string)$adaptation->positionid]['name'] ?? $adaptation->positionid),
                'status' => $status,
                'statuslabel' => $status === self::STATUS_ACTIVE ? 'Активна' : ($status === self::STATUS_ESCALATED ? 'На решении HRD' : ($status === self::STATUS_COMPLETED ? 'Завершена' : $status)),
                'active' => $status === self::STATUS_ACTIVE,
                'escalated' => $status === self::STATUS_ESCALATED,
                'completed' => $status === self::STATUS_COMPLETED,
                'paireddays' => self::pair_days($map),
                'plannedworkdays' => (int)$adaptation->plannedworkdays,
                'detailurl' => (new \moodle_url('/local/ustar/adaptation_control.php', ['adaptationid' => (int)$adaptation->id]))->out(false),
            ];
            foreach ($states as $state) {
                if ($state['status'] === 'resolved') {
                    continue;
                }
                if ($state['status'] === 'open') {
                    $opencount++;
                } else if ($state['status'] === 'in_control') {
                    $incontrolcount++;
                }
                if ($state['severity'] === 'critical') {
                    $criticalcount++;
                }
                $cases[] = [
                    'adaptationid' => (int)$adaptation->id,
                    'fingerprint' => $state['fingerprint'],
                    'status' => $state['status'],
                    'isopen' => $state['status'] === 'open',
                    'isincontrol' => $state['status'] === 'in_control',
                    'severity' => $state['severity'],
                    'critical' => $state['severity'] === 'critical',
                    'warning' => $state['severity'] !== 'critical',
                    'summary' => $state['summary'],
                    'workdate' => $state['workdate'] !== '' ? userdate(strtotime($state['workdate'] . ' 12:00:00'), '%d.%m.%Y') : '',
                    'hasworkdate' => $state['workdate'] !== '',
                    'employee' => $employee ? fullname($employee) : ('#' . (int)$adaptation->userid),
                    'manager' => $manager ? fullname($manager) : ('#' . (int)$adaptation->managerid),
                    'position' => (string)($positions[(string)$adaptation->positionid]['name'] ?? $adaptation->positionid),
                    'openedat' => userdate((int)$state['openedat'], '%d.%m.%Y %H:%M'),
                    'detailurl' => (new \moodle_url('/local/ustar/adaptation_control.php', ['adaptationid' => (int)$adaptation->id]))->out(false),
                ];
            }
        }

        usort($cases, static function(array $a, array $b): int {
            if ($a['critical'] !== $b['critical']) {
                return $a['critical'] ? -1 : 1;
            }
            if ($a['isincontrol'] !== $b['isincontrol']) {
                return $a['isincontrol'] ? -1 : 1;
            }
            return strcmp($b['openedat'], $a['openedat']);
        });

        $detail = $selectedid > 0 ? self::hr_detail_context($selectedid) : null;
        return [
            'cycles' => $cycles,
            'hascycles' => !empty($cycles),
            'cases' => $cases,
            'hascases' => !empty($cases),
            'opencount' => $opencount,
            'criticalcount' => $criticalcount,
            'incontrolcount' => $incontrolcount,
            'activecount' => $DB->count_records('local_ustar_adaptations', ['status' => self::STATUS_ACTIVE]),
            'hasdetail' => (bool)$detail,
            'detail' => $detail,
            'sesskey' => sesskey(),
        ];
    }

    private static function hr_detail_context(int $adaptationid): array {
        global $DB;
        $adaptation = $DB->get_record('local_ustar_adaptations', ['id' => $adaptationid], '*', MUST_EXIST);
        self::sync_cases($adaptation);
        $map = self::submissions($adaptation);
        $employee = $DB->get_record('user', ['id' => (int)$adaptation->userid], 'id,firstname,lastname', IGNORE_MISSING);
        $manager = $DB->get_record('user', ['id' => (int)$adaptation->managerid], 'id,firstname,lastname', IGNORE_MISSING);
        $structure = structure::get(structure::NAME_STRUCTURE);
        $positions = people::position_map($structure);
        $days = [];
        foreach (self::factual_dates($map) as $date) {
            $days[] = [
                'date' => userdate(strtotime($date . ' 12:00:00'), '%d.%m.%Y'),
                'employee' => self::answer_view($map['employee'][$date] ?? null),
                'hasemployee' => isset($map['employee'][$date]),
                'manager' => self::answer_view($map['manager'][$date] ?? null),
                'hasmanager' => isset($map['manager'][$date]),
            ];
        }
        $round = self::current_round($adaptationid);
        $finals = self::final_reports($adaptationid, $round);
        $states = self::case_states($adaptationid);
        $detailcases = [];
        foreach ($states as $state) {
            if ($state['status'] === 'resolved') {
                continue;
            }
            $detailcases[] = $state + [
                'isopen' => $state['status'] === 'open',
                'isincontrol' => $state['status'] === 'in_control',
                'critical' => $state['severity'] === 'critical',
                'warning' => $state['severity'] !== 'critical',
            ];
        }
        return [
            'id' => (int)$adaptation->id,
            'employee' => $employee ? fullname($employee) : ('#' . (int)$adaptation->userid),
            'manager' => $manager ? fullname($manager) : ('#' . (int)$adaptation->managerid),
            'position' => (string)($positions[(string)$adaptation->positionid]['name'] ?? $adaptation->positionid),
            'status' => (string)$adaptation->status,
            'active' => (string)$adaptation->status === self::STATUS_ACTIVE,
            'escalated' => (string)$adaptation->status === self::STATUS_ESCALATED,
            'completed' => (string)$adaptation->status === self::STATUS_COMPLETED,
            'plannedworkdays' => (int)$adaptation->plannedworkdays,
            'paireddays' => self::pair_days($map),
            'days' => $days,
            'hasdays' => !empty($days),
            'round' => $round,
            'employeefinal' => self::final_view($finals['employee']),
            'hasemployeefinal' => (bool)$finals['employee'],
            'managerfinal' => self::final_view($finals['manager']),
            'hasmanagerfinal' => (bool)$finals['manager'],
            'cases' => $detailcases,
            'hascases' => !empty($detailcases),
            // HRD reviews the full adaptation dossier here. The generic HR employee
            // editor has a different permission boundary and is intentionally not linked.
            'profileurl' => '',
        ];
    }
}
