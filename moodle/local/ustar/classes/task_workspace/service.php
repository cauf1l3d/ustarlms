<?php
namespace local_ustar\task_workspace;
use local_ustar\{learning_tasks, task_files, capabilities, organization_model, org, team_access, forced_retraining,
    workflow_notifications, view_as};
defined('MOODLE_INTERNAL') || die();

/** Commands/read models on the existing task aggregate. No second employee or completion store. */
final class service {
    private static array $submissions = [];

    public static function available(): bool {
        global $DB;
        return learning_tasks::available() && $DB->get_manager()->table_exists(new \xmldb_table('local_ustar_task_meta'));
    }

    public static function actor(int $actorid): void {
        global $USER;
        if ((int)$USER->id !== $actorid || !team_access::active_actor($actorid)) { self::deny(); }
        view_as::assert_writable();
    }

    public static function can_manage(int $actorid): bool {
        if (!team_access::active_actor($actorid)) { return false; }
        if (team_access::company($actorid)) { return true; }
        $scope = organization_model::manager_scope($actorid);
        return capabilities::has($actorid, capabilities::TEAM_READ) && !empty($scope['allowed']);
    }

    public static function is_hrd(int $actorid): bool {
        return team_access::active_actor($actorid)
            && has_capability('local/ustar:taskescalation', \context_system::instance(), $actorid);
    }

    public static function meta(int $taskid): ?\stdClass {
        global $DB;
        if (!self::available()) { return null; }
        return $DB->get_record('local_ustar_task_meta', ['taskid' => $taskid]) ?: null;
    }

    public static function can_view_record(\stdClass $task, int $actorid): bool {
        if ($task->privacy !== learning_tasks::PRIVACY_ASSIGNED || !team_access::active_actor($actorid)) { return false; }
        if ($actorid === (int)$task->assigneeid) { return true; }
        return team_access::company($actorid) || learning_tasks::can_assign($actorid, (int)$task->assigneeid);
    }

    public static function reviewer(\stdClass $task): int {
        $manager = org::manager_id((int)$task->assigneeid);
        if ($manager > 1 && learning_tasks::can_assign($manager, (int)$task->assigneeid)) { return $manager; }
        // Explicit company actors may own a task for an employee without a reporting line.
        $creator = (int)$task->assignerid;
        return !$manager && team_access::company($creator)
            && learning_tasks::can_assign($creator, (int)$task->assigneeid) ? $creator : 0;
    }

    public static function can_review_record(\stdClass $task, int $actorid): bool {
        if (!self::can_view_record($task, $actorid)) { return false; }
        $reviewer = self::reviewer($task);
        return $reviewer ? $reviewer === $actorid : self::is_hrd($actorid);
    }

    /** ACL before LIMIT; no private-note rows enter any aggregate. */
    public static function scope_sql(int $actorid, string $scope = 'mine'): array {
        if (!team_access::active_actor($actorid)) { self::deny(); }
        $where = "t.privacy = :privacy";
        $params = ['privacy' => learning_tasks::PRIVACY_ASSIGNED];
        if ($scope === 'mine') { return [$where . ' AND t.assigneeid = :actor', $params + ['actor' => $actorid]]; }
        if (!self::can_manage($actorid)) { self::deny(); }
        if ($scope === 'outgoing') {
            $where .= ' AND t.assignerid = :creator'; $params['creator'] = $actorid;
        }
        if (!team_access::company($actorid)) {
            global $DB;
            $ids = array_values(array_unique(array_map('intval', organization_model::manager_scope($actorid)['userids'] ?? [])));
            if (!$ids) { return [$where . ' AND 1=0', $params]; }
            [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'scope');
            $where .= ' AND t.assigneeid ' . $insql; $params += $inparams;
        }
        return [$where, $params];
    }

    public static function rows(int $actorid, string $scope, string $filter = 'all', int $page = 0,
            int $from = 0, int $to = 0, string $query = '', string $kind = ''): array {
        global $DB;
        [$where, $params] = self::scope_sql($actorid, $scope);
        $now = time();
        if ($filter === 'active') { $where .= " AND t.status IN ('assigned','in_progress')"; }
        else if ($filter === 'review') { $where .= " AND t.status='in_review'"; }
        else if ($filter === 'late') {
            $where .= " AND t.status IN ('assigned','in_progress') AND t.dueat>0 AND t.dueat < :now"; $params['now'] = $now;
        } else if ($filter === 'done') { $where .= " AND t.status IN ('completed','cancelled')"; }
        if ($from && $to) {
            if ($to <= $from || $to - $from > 93 * DAYSECS) { throw new \invalid_parameter_exception('Окно календаря не более 93 дней.'); }
            $where .= ' AND t.dueat >= :fromdate AND t.dueat < :todate'; $params += ['fromdate' => $from, 'todate' => $to];
        }
        if ($query !== '') {
            $where .= ' AND ' . $DB->sql_like('t.title', ':query', false);
            $params['query'] = '%' . $DB->sql_like_escape(clean_param($query, PARAM_TEXT)) . '%';
        }
        if ($kind === 'other') { $where .= " AND (m.kind IS NULL OR m.kind <> 'checklist')"; }
        else if ($kind !== '') { $where .= ' AND m.kind = :kind'; $params['kind'] = $kind; }
        $sql = ' FROM {local_ustar_learning_tasks} t LEFT JOIN {local_ustar_task_meta} m ON m.taskid=t.id WHERE ' . $where;
        $total = $DB->count_records_sql('SELECT COUNT(t.id)' . $sql, $params);
        $tasks = $DB->get_records_sql('SELECT t.*' . $sql . ' ORDER BY CASE WHEN t.dueat IS NULL THEN 1 ELSE 0 END,
            t.dueat ASC, t.id ASC', $params, max(0, $page) * 50, 50);
        $out = []; $metas = []; $names = [];
        if ($tasks) {
            $metas = $DB->get_records_list('local_ustar_task_meta', 'taskid', array_keys($tasks));
            $metas = array_column(array_values($metas), null, 'taskid');
            $ids = [];
            foreach ($tasks as $task) { $ids[] = (int)$task->assigneeid; $ids[] = (int)$task->assignerid; }
            $names = self::names($ids);
        }
        foreach ($tasks as $task) { $out[] = self::present($task, $metas[$task->id] ?? null, true, $names); }
        return ['items' => $out, 'total' => $total, 'page' => $page, 'hasmore' => ($page + 1) * 50 < $total];
    }

    public static function summary(int $actorid, string $scope, int $from = 0, int $to = 0): array {
        global $DB;
        [$where, $params] = self::scope_sql($actorid, $scope);
        if ($from && $to) { $where .= ' AND t.dueat>=:f AND t.dueat<:e'; $params += ['f' => $from, 'e' => $to]; }
        $now = time();
        $params += ['now' => $now];
        $row = $DB->get_record_sql("SELECT COUNT(t.id) AS total,
            SUM(CASE WHEN t.status IN ('assigned','in_progress') THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN t.status='in_review' THEN 1 ELSE 0 END) AS review,
            SUM(CASE WHEN t.status IN ('assigned','in_progress') AND t.dueat>0 AND t.dueat<:now THEN 1 ELSE 0 END) AS late
            FROM {local_ustar_learning_tasks} t WHERE $where", $params);
        return array_map('intval', (array)$row);
    }

    public static function analytics(int $actorid, string $scope, int $from, int $to): array {
        global $DB;
        if ($to <= $from || $to - $from > 93 * DAYSECS) { throw new \invalid_parameter_exception('Период аналитики: до 93 дней.'); }
        [$where, $params] = self::scope_sql($actorid, $scope);
        $where .= " AND t.dueat>=:fromdate AND t.dueat<:todate AND t.dueat<=:cutoff AND t.status<>'cancelled'";
        $params += ['fromdate' => $from, 'todate' => $to, 'cutoff' => time()];
        $rows = $DB->get_records_sql("SELECT t.assigneeid AS id, COUNT(t.id) AS due,
            SUM(CASE WHEN t.status='completed' THEN 1 ELSE 0 END) AS accepted,
            SUM(CASE WHEN t.status='in_review' THEN 1 ELSE 0 END) AS review,
            SUM(CASE WHEN t.status IN ('assigned','in_progress') THEN 1 ELSE 0 END) AS late,
            SUM(COALESCE(m.kpiweight,0)) AS plannedweight,
            SUM(CASE WHEN t.status='completed' THEN COALESCE(m.kpiweight,0) ELSE 0 END) AS acceptedweight,
            SUM(CASE WHEN t.status='completed' AND m.submittedat>0 AND m.submittedat<=t.dueat THEN 1 ELSE 0 END) AS ontime
            FROM {local_ustar_learning_tasks} t LEFT JOIN {local_ustar_task_meta} m ON m.taskid=t.id
            WHERE $where GROUP BY t.assigneeid ORDER BY t.assigneeid", $params, 0, 501);
        if (count($rows) > 500) { throw new \invalid_parameter_exception('Сузьте область аналитики: более 500 сотрудников.'); }
        $out = []; $names = $rows ? self::names(array_keys($rows)) : [];
        foreach ($rows as $row) { $item = (array)$row; $item['fullname'] = $names[$row->id] ?? '—'; $out[] = $item; }
        return $out;
    }

    private static function names(array $ids): array {
        global $DB;
        $out = [];
        foreach ($DB->get_records_list('user', 'id', array_values(array_unique($ids)), '',
                'id,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename') as $u) {
            $out[$u->id] = fullname($u);
        }
        return $out;
    }
    public static function name(int $userid): string { return self::names([$userid])[$userid] ?? '—'; }

    public static function present(\stdClass $task, ?\stdClass $m = null, bool $loaded = false, array $names = []): array {
        if (!$loaded) { $m = self::meta((int)$task->id); }
        return ['id' => (int)$task->id, 'title' => (string)$task->title, 'status' => (string)$task->status,
            'assigneeid' => (int)$task->assigneeid, 'assignee' => $names[$task->assigneeid] ?? self::name((int)$task->assigneeid),
            'assignerid' => (int)$task->assignerid, 'assigner' => $names[$task->assignerid] ?? self::name((int)$task->assignerid),
            'description' => (string)$task->description, 'dueat' => (int)$task->dueat, 'version' => (int)$task->version,
            'kind' => $m ? $m->kind : 'task', 'photo' => $m && !empty($m->requirephoto),
            'parentid' => $m ? (int)$m->parentid : 0, 'seriesid' => $m ? (int)$m->seriesid : 0,
            'submittedat' => $m ? (int)$m->submittedat : 0, 'reviewdueat' => $m ? (int)$m->reviewdueat : 0,
            'timezone' => $m ? $m->timezone : 'Europe/Moscow', 'kpiweight' => $m ? (int)$m->kpiweight : 0,
            'relatedtype' => $task->relatedtype, 'relatedid' => (int)$task->relatedid,
            'reviewlate' => $m && $task->status === 'in_review' && $m->reviewdueat > 0 && $m->reviewdueat < time(),
            'late' => in_array($task->status, ['assigned', 'in_progress'], true) && $task->dueat && $task->dueat < time()];
    }

    public static function detail(int $taskid, int $actorid): array {
        global $DB;
        learning_tasks::view($taskid, $actorid);
        $task = $DB->get_record('local_ustar_learning_tasks', ['id' => $taskid], '*', MUST_EXIST);
        if ($task->privacy !== learning_tasks::PRIVACY_ASSIGNED) { self::deny(); }
        $out = self::present($task);
        $out['meta'] = self::meta($taskid);
        $out['fields'] = self::fields($out['meta']);
        $reportwhere = ['taskid' => $taskid];
        if ((int)$task->assigneeid !== $actorid) { $reportwhere['status'] = 'final'; }
        $out['reports'] = array_values($DB->get_records('local_ustar_task_reports', $reportwhere, 'taskversion DESC', '*', 0, 50));
        $out['files'] = task_files::list_for($taskid, $actorid);
        $out['events'] = learning_tasks::events($taskid, $actorid, 50);
        $out['canreview'] = $out['meta'] ? self::can_review_record($task, $actorid) : (int)$task->assignerid === $actorid;
        $out['canedit'] = self::can_edit($task, $actorid);
        $out['reviewer'] = self::name($out['meta'] ? self::reviewer($task) : (int)$task->assignerid);
        return $out;
    }

    public static function can_read_result(int $taskid, int $actorid, int $version): bool {
        global $DB;
        if (!self::meta($taskid)) { return true; }
        $task = $DB->get_record('local_ustar_learning_tasks', ['id' => $taskid], '*', MUST_EXIST);
        if (!self::can_view_record($task, $actorid)) { return false; }
        return (int)$task->assigneeid === $actorid || $DB->record_exists('local_ustar_task_reports',
            ['taskid' => $taskid, 'taskversion' => $version, 'status' => 'final']);
    }

    public static function fields(?\stdClass $meta): array {
        global $DB;
        if (!$meta || !$meta->templateversionid) { return []; }
        $v = $DB->get_record('local_ustar_task_tpl_versions', ['id' => $meta->templateversionid], '*', MUST_EXIST);
        return json_decode($v->definitionjson, true) ?: [];
    }

    public static function policy_for(int $actorid): array {
        global $DB;
        $record = $DB->get_record('local_ustar_task_settings', ['ownerid' => $actorid]);
        return $record ? policy::validate(json_decode($record->policyjson, true)) : policy::defaults();
    }

    public static function save_policy(int $actorid, array $input, int $expected): void {
        global $DB;
        self::actor($actorid);
        if (!self::can_manage($actorid)) { self::deny(); }
        $policy = policy::validate($input);
        $lock = self::lock('task-policy:' . $actorid);
        try {
            $tx = $DB->start_delegated_transaction();
            $row = $DB->get_record('local_ustar_task_settings', ['ownerid' => $actorid]);
            if (($row ? (int)$row->revision : 0) !== $expected) { self::conflict(); }
            if ($row) {
                $row->policyjson = self::json($policy); $row->revision++; $row->timemodified = time();
                $DB->update_record('local_ustar_task_settings', $row);
            } else {
                $DB->insert_record('local_ustar_task_settings', (object)['ownerid' => $actorid, 'revision' => 1,
                    'policyjson' => self::json($policy), 'timemodified' => time()]);
            }
            $tx->allow_commit();
        } finally { $lock->release(); }
    }

    public static function templates(int $actorid): array {
        global $DB;
        return array_values($DB->get_records('local_ustar_task_templates', ['ownerid' => $actorid, 'active' => 1],
            'title ASC', '*', 0, 100));
    }

    public static function save_template(int $actorid, int $id, int $expected, string $title, array $fields,
            int $weight, bool $photo): int {
        global $DB;
        self::actor($actorid);
        if (!self::can_manage($actorid)) { self::deny(); }
        $title = self::title($title); $fields = policy::definition($fields); self::weight($weight);
        $lock = self::lock('task-template:' . ($id ?: 'new-' . $actorid));
        try {
            $tx = $DB->start_delegated_transaction();
            $row = $id ? $DB->get_record('local_ustar_task_templates', ['id' => $id], '*', MUST_EXIST) : null;
            if ($row && ((int)$row->ownerid !== $actorid || !$row->active)) { self::deny(); }
            if (($row ? (int)$row->revision : 0) !== $expected) { self::conflict(); }
            if (!$row) {
                $id = $DB->insert_record('local_ustar_task_templates', (object)['ownerid' => $actorid, 'title' => $title,
                    'revision' => 1, 'versionid' => 0, 'active' => 1, 'timecreated' => time(), 'timemodified' => time()]);
                $row = $DB->get_record('local_ustar_task_templates', ['id' => $id], '*', MUST_EXIST);
            } else { $row->revision++; }
            $versionid = $DB->insert_record('local_ustar_task_tpl_versions', (object)['templateid' => $id,
                'versionno' => $row->revision, 'definitionjson' => self::json($fields), 'kpiweight' => $weight,
                'requirephoto' => $photo ? 1 : 0, 'createdby' => $actorid, 'timecreated' => time()]);
            $row->title = $title; $row->versionid = $versionid; $row->timemodified = time();
            $DB->update_record('local_ustar_task_templates', $row);
            $tx->allow_commit();
            return (int)$id;
        } finally { $lock->release(); }
    }

    /** Validate one assignment, with current org/learning scope checked before any write. */
    public static function create(int $actorid, int $assigneeid, array $input, array $uploads = []): int {
        self::actor($actorid);
        $parent = (int)($input['parentid'] ?? 0);
        $lock = $parent ? self::lock('learning-task:' . $parent) : null;
        try { return self::create_locked($actorid, $assigneeid, $input, $uploads); }
        finally { if ($lock) { $lock->release(); } }
    }

    private static function create_locked(int $actorid, int $assigneeid, array $input, array $uploads): int {
        global $DB;
        self::actor($actorid);
        if (!learning_tasks::can_assign($actorid, $assigneeid)) { self::deny(); }
        $title = self::title((string)($input['title'] ?? ''));
        $kind = (string)($input['kind'] ?? 'task');
        if (!in_array($kind, ['task', 'checklist', 'retraining'], true)) { throw new \invalid_parameter_exception('Неизвестный тип задачи.'); }
        $p = policy::validate($input['policy'] ?? self::policy_for($actorid));
        $dueat = calendar::timestamp($input['date'] ?? '', $input['clock'] ?? '', $p['timezone']);
        $parentid = (int)($input['parentid'] ?? 0);
        if ($parentid) {
            $parent = self::detail($parentid, $actorid);
            if (in_array($parent['status'], ['completed', 'cancelled', 'in_review'], true)
                    || ($parent['assigneeid'] !== $actorid && !$parent['canedit'])
                    || ($parent['dueat'] && $dueat > $parent['dueat'])) {
                throw new \invalid_parameter_exception('Делегировать можно активную задачу в пределах её срока.');
            }
        }
        if (!empty($input['repeat']) && ($parentid || !calendar::is_workday($input['date'], $p))) {
            throw new \invalid_parameter_exception('Серия начинается в выбранный рабочий день и не связывается с разовым родительским поручением.');
        }
        $templateversionid = 0; $weight = (int)($input['kpiweight'] ?? 10); $photo = !empty($input['requirephoto']);
        if ($kind === 'checklist') {
            $template = $DB->get_record('local_ustar_task_templates', ['id' => (int)($input['templateid'] ?? 0),
                'ownerid' => $actorid, 'active' => 1], '*', MUST_EXIST);
            $v = $DB->get_record('local_ustar_task_tpl_versions', ['id' => $template->versionid], '*', MUST_EXIST);
            $templateversionid = (int)$v->id; $weight = (int)$v->kpiweight; $photo = !empty($v->requirephoto);
        }
        self::weight($weight);
        $tx = $DB->start_delegated_transaction();
        $relatedid = 0;
        if ($kind === 'retraining') {
            if (!empty($input['repeat'])) { throw new \invalid_parameter_exception('Переобучение назначается отдельной задачей, без автоматической серии.'); }
            $policyid = (int)($input['policyid'] ?? 0);
            $eligible = forced_retraining::topics_for_user($actorid, $assigneeid);
            if (!in_array($policyid, array_map(static fn($t) => (int)$t['policyid'], $eligible), true)) {
                throw new \invalid_parameter_exception('Выберите ранее пройденный материал, доступный для переобучения сотрудника.');
            }
            $ids = forced_retraining::assign($actorid, $assigneeid, [$policyid], $title);
            $relatedid = (int)$ids[0]; $photo = false;
        }
        $task = learning_tasks::assign($actorid, $assigneeid, ['title' => $title,
            'description' => (string)($input['description'] ?? ''), 'requirereview' => $kind === 'retraining' ? 0 : 1,
            'dueat' => $dueat, 'relatedtype' => $relatedid ? 'forced_retraining' : '', 'relatedid' => $relatedid], $uploads);
        $DB->insert_record('local_ustar_task_meta', (object)['taskid' => $task['id'], 'kind' => $kind,
            'parentid' => $parentid, 'templateversionid' => $templateversionid, 'seriesid' => 0, 'occurdate' => '',
            'timezone' => $p['timezone'], 'kpiweight' => $weight, 'requirephoto' => $photo ? 1 : 0,
            'submittedat' => 0, 'reviewdueat' => 0, 'reviewedat' => 0, 'policyjson' => self::json($p)]);
        if (!empty($input['repeat'])) {
            $enddate = (string)($input['enddate'] ?? '');
            calendar::timestamp($enddate, '00:00', $p['timezone']);
            if ($enddate < $input['date'] || $enddate > calendar::date($dueat + 366 * DAYSECS, $p['timezone'])) {
                throw new \invalid_parameter_exception('Серия должна завершаться в пределах года от начала.');
            }
            $seriesid = $DB->insert_record('local_ustar_task_series', (object)['assignerid' => $actorid,
                'assigneeid' => $assigneeid, 'templateversionid' => $templateversionid, 'title' => $title,
                'description' => (string)($input['description'] ?? ''), 'nextdate' => calendar::next_date($input['date'], $p['timezone']),
                'enddate' => $enddate, 'dueclock' => $input['clock'], 'timezone' => $p['timezone'],
                'weekdaysjson' => self::json($p['weekdays']), 'policyjson' => self::json($p), 'kpiweight' => $weight,
                'requirephoto' => $photo ? 1 : 0, 'revision' => 1, 'status' => 'active',
                'timecreated' => time(), 'timemodified' => time()]);
            $DB->set_field('local_ustar_task_meta', 'seriesid', $seriesid, ['taskid' => $task['id']]);
            $DB->set_field('local_ustar_task_meta', 'occurdate', $input['date'], ['taskid' => $task['id']]);
        }
        $tx->allow_commit();
        return (int)$task['id'];
    }

    public static function can_edit(\stdClass $task, int $actorid): bool {
        return (int)$task->assignerid === $actorid && learning_tasks::can_assign($actorid, (int)$task->assigneeid);
    }

    /** Hooks also protect legacy entrypoints; a modern task cannot bypass the report contract. */
    public static function before_transition(\stdClass $task, int $actorid, string $action, string $comment): void {
        $meta = self::meta((int)$task->id);
        if (!$meta) { return; }
        self::actor($actorid);
        if ($action === 'submit') {
            if ($meta->kind === 'retraining' || !isset(self::$submissions[$task->id])) {
                throw new \invalid_parameter_exception('Отправьте результат через форму отчёта задачи.');
            }
            global $DB;
            $raw = self::$submissions[$task->id];
            $draft = $DB->get_record_sql("SELECT * FROM {local_ustar_task_reports} WHERE taskid=:id
                ORDER BY taskversion DESC", ['id' => $task->id], IGNORE_MULTIPLE);
            $draftversion = $draft ? (int)$draft->taskversion : 0;
            self::photo_count((int)$task->id, $draftversion, $raw['uploads']);
            $hasphoto = self::validate_photos($raw['uploads']) || ($draftversion && self::has_photos((int)$task->id, $draftversion));
            if ($meta->requirephoto && !$hasphoto) { throw new \invalid_parameter_exception('Приложите фото результата.'); }
            $answers = policy::answers(self::fields($meta), $raw['answers'], $hasphoto, true);
            if (!$answers && trim($raw['comment']) === '') { throw new \invalid_parameter_exception('Заполните отчёт о выполнении.'); }
            self::$submissions[$task->id] = ['answers' => $answers, 'comment' => $raw['comment'], 'draftversion' => $draftversion];
            if ($DB->record_exists_sql("SELECT 1 FROM {local_ustar_task_meta} m JOIN {local_ustar_learning_tasks} t ON t.id=m.taskid
                    WHERE m.parentid=:parent AND t.status NOT IN ('completed','cancelled')", ['parent' => $task->id])) {
                throw new \invalid_parameter_exception('Сначала завершите или отмените связанные поручения.');
            }
        }
        if (in_array($action, ['approve', 'return'], true) && !self::can_review_record($task, $actorid)) { self::deny(); }
        if ($action === 'cancel' && !self::can_edit($task, $actorid)) { self::deny(); }
    }

    public static function after_transition(\stdClass $task, string $action): void {
        global $DB;
        $m = self::meta((int)$task->id);
        if (!$m) { return; }
        if ($action === 'submit') {
            $data = self::$submissions[$task->id];
            self::copy_draft_photos((int)$task->id, (int)$data['draftversion'], (int)$task->version);
            $DB->insert_record('local_ustar_task_reports', (object)['taskid' => $task->id, 'taskversion' => $task->version,
                'status' => 'final', 'answersjson' => self::json($data['answers']), 'commenttext' => $data['comment'],
                'actorid' => $task->assigneeid, 'submittedat' => time(), 'timecreated' => time()]);
            $m->submittedat = time();
            $m->reviewdueat = calendar::add_minutes($m->submittedat, (int)json_decode($m->policyjson, true)['reviewminutes'],
                json_decode($m->policyjson, true));
        } else if ($action === 'approve') { $m->reviewedat = time(); $m->reviewdueat = 0; }
        else if (in_array($action, ['return', 'cancel'], true)) { $m->reviewdueat = 0; }
        if ($action === 'cancel' && $m->kind === 'retraining') {
            // Cancellation is a canonical retraining event; its history is preserved.
            forced_retraining::cancel((int)$task->assignerid, (int)$task->assigneeid, (int)$task->relatedid,
                'Отмена связанной задачи');
        }
        $DB->update_record('local_ustar_task_meta', $m);
        if ($task->status === 'completed' && $m->kind !== 'retraining') {
            \local_ustar\reward_control::grant((int)$task->assigneeid,$m->kind,(string)$task->id,
                (int)$task->completedat,'work-task:'.$task->id,'task','Принята задача: '.$task->title);
        }
    }

    public static function report(int $taskid, int $actorid, int $expected, array $answers, string $comment,
            bool $final, array $uploads = []): void {
        global $DB;
        self::actor($actorid);
        if ($final) {
            self::$submissions[$taskid] = ['answers' => $answers, 'comment' => trim(clean_param($comment, PARAM_TEXT)), 'uploads' => $uploads];
            try { learning_tasks::transition($taskid, $actorid, 'submit', $expected, $comment, $uploads); }
            finally { unset(self::$submissions[$taskid]); }
            return;
        }
        $lock = self::lock('learning-task:' . $taskid);
        try {
            $tx = $DB->start_delegated_transaction();
            $task = $DB->get_record_sql('SELECT * FROM {local_ustar_learning_tasks} WHERE id=:id FOR UPDATE', ['id' => $taskid], MUST_EXIST);
            $meta = self::meta($taskid);
            if (!$meta || (int)$task->assigneeid !== $actorid || $meta->kind === 'retraining'
                    || !in_array($task->status, ['assigned', 'in_progress'], true)) { self::deny(); }
            if ((int)$task->version !== $expected) { self::conflict(); }
            $draft = $DB->get_record_sql("SELECT * FROM {local_ustar_task_reports} WHERE taskid=:id
                ORDER BY taskversion DESC", ['id' => $taskid], IGNORE_MULTIPLE);
            $draftversion = $draft ? (int)$draft->taskversion : 0;
            self::photo_count($taskid, $draftversion, $uploads);
            $hasphoto = self::validate_photos($uploads) || ($draftversion && self::has_photos($taskid, $draftversion));
            $answers = policy::answers(self::fields($meta), $answers, $hasphoto, false);
            $comment = trim(clean_param($comment, PARAM_TEXT));
            $task->version++; $task->timemodified = time();
            task_files::store($taskid, task_files::RESULT, $uploads, (int)$task->version);
            self::copy_draft_photos($taskid, $draftversion, (int)$task->version);
            $DB->update_record('local_ustar_learning_tasks', $task);
            $DB->insert_record('local_ustar_task_reports', (object)['taskid' => $taskid, 'taskversion' => $task->version,
                'status' => 'draft', 'answersjson' => self::json($answers), 'commenttext' => $comment,
                'actorid' => $actorid, 'submittedat' => 0, 'timecreated' => time()]);
            self::event($taskid, $actorid, 'report_draft_saved', ['reportversion' => $task->version]);
            $tx->allow_commit();
        } finally { unset(self::$submissions[$taskid]); $lock->release(); }
    }

    public static function validate_photos(array $uploads): bool {
        if (count($uploads) > 5) { throw new \invalid_parameter_exception('До пяти фотографий в одной отправке.'); }
        foreach ($uploads as $file) {
            if (empty($file['tmp']) || (int)$file['size'] > min(8 * 1024 * 1024, task_files::max_file_bytes())) {
                throw new \invalid_parameter_exception('Размер фотографии превышает лимит.');
            }
            $image = @getimagesize($file['tmp']);
            $extensions = ['image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'image/webp' => ['webp']];
            if (!$image || !isset($extensions[$image['mime']])
                    || !in_array(strtolower(pathinfo($file['filename'] ?? '', PATHINFO_EXTENSION)), $extensions[$image['mime']], true)
                    || $image[0] * $image[1] > 40000000) {
                throw new \invalid_parameter_exception('Фотоотчёт принимает только настоящие JPG, PNG и WebP.');
            }
        }
        return count($uploads) > 0;
    }

    private static function photo_count(int $id, int $version, array $uploads): void {
        $names = array_column($uploads, 'filename');
        if ($version) {
            foreach (get_file_storage()->get_area_files(\context_system::instance()->id, 'local_ustar', task_files::RESULT,
                    $id, '', false) as $file) {
                if ($file->get_filepath() === '/v' . $version . '/') { $names[] = $file->get_filename(); }
            }
        }
        if (count(array_unique($names)) > 5) { throw new \invalid_parameter_exception('В отчёте допускается до пяти фотографий вместе с сохранённым черновиком.'); }
    }

    private static function has_photos(int $id, int $version): bool {
        foreach (get_file_storage()->get_area_files(\context_system::instance()->id, 'local_ustar', task_files::RESULT,
                $id, '', false) as $file) {
            if ($file->get_filepath() === '/v' . $version . '/' && in_array($file->get_mimetype(), ['image/jpeg', 'image/png', 'image/webp'], true)) {
                return true;
            }
        }
        return false;
    }

    private static function copy_draft_photos(int $id, int $from, int $to): void {
        if (!$from || $from === $to) { return; }
        $fs = get_file_storage(); $contextid = \context_system::instance()->id;
        foreach ($fs->get_area_files($contextid, 'local_ustar', task_files::RESULT, $id, '', false) as $file) {
            if ($file->get_filepath() !== '/v' . $from . '/') { continue; }
            if ($fs->get_file($contextid, 'local_ustar', task_files::RESULT, $id, '/v' . $to . '/', $file->get_filename())) { continue; }
            $fs->create_file_from_storedfile(['contextid' => $contextid, 'component' => 'local_ustar',
                'filearea' => task_files::RESULT, 'itemid' => $id, 'filepath' => '/v' . $to . '/',
                'filename' => $file->get_filename()], $file);
        }
    }

    public static function series(int $actorid): array {
        global $DB;
        if (!self::can_manage($actorid)) { return []; }
        return array_values($DB->get_records('local_ustar_task_series', ['assignerid' => $actorid], 'id DESC', '*', 0, 100));
    }

    public static function set_series(int $id, int $actorid, int $expected, string $status): void {
        global $DB;
        self::actor($actorid);
        if (!in_array($status, ['active', 'paused', 'cancelled'], true)) { throw new \invalid_parameter_exception('Неизвестный статус серии.'); }
        $lock = self::lock('task-series:' . $id);
        try {
            $tx = $DB->start_delegated_transaction();
            $s = $DB->get_record('local_ustar_task_series', ['id' => $id], '*', MUST_EXIST);
            if ((int)$s->assignerid !== $actorid || !learning_tasks::can_assign($actorid, (int)$s->assigneeid)) { self::deny(); }
            if ((int)$s->revision !== $expected || in_array($s->status, ['cancelled', 'finished'], true)) { self::conflict(); }
            $s->status = $status; $s->revision++; $s->timemodified = time();
            // Resume starts today; no surprise catch-up assignments for missed paused days.
            if ($status === 'active') { $s->nextdate = max($s->nextdate, calendar::date(time(), $s->timezone)); }
            $DB->update_record('local_ustar_task_series', $s);
            $tx->allow_commit();
        } finally { $lock->release(); }
    }

    public static function event(int $taskid, int $actorid, string $type, array $data): void {
        global $DB;
        $DB->insert_record('local_ustar_learning_task_events', (object)['taskid' => $taskid,
            'eventtype' => $type, 'actorid' => $actorid, 'datajson' => self::json($data), 'timecreated' => time()]);
    }

    public static function json(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
    public static function lock(string $key): \core\lock\lock { $lock = \core\lock\lock_config::get_lock_factory('local_ustar')->get_lock($key, 10);
        if (!$lock) { throw new \moodle_exception('Данные изменяются в другой сессии. Повторите действие.'); } return $lock; }
    private static function title(string $title): string { $title = trim(clean_param($title, PARAM_TEXT));
        if (!$title || \core_text::strlen($title) > 255) { throw new \invalid_parameter_exception('Название: 1–255 символов.'); } return $title; }
    private static function weight(int $weight): void { if ($weight < 0 || $weight > 10000) { throw new \invalid_parameter_exception('Баллы: от 0 до 10000.'); } }
    private static function deny(): void { throw new \required_capability_exception(\context_system::instance(), 'local/ustar:viewteam', 'nopermissions', ''); }
    private static function conflict(): void { throw new \moodle_exception('Данные уже изменились. Обновите страницу.'); }
}
