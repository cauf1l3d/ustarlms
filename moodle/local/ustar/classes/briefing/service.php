<?php
namespace local_ustar\briefing;

use local_ustar\{capabilities, organization_model, people, structure, team_access, view_as};
use local_ustar\task_workspace\{calendar, service as tasks};

defined('MOODLE_INTERNAL') || die();

/** Department forms, weekly submissions and joint minutes. */
final class service {
    public const ZONE = 'Europe/Moscow';
    public const MEETING_TIME = '10:00';
    public const MEETING_DURATION = 3600;
    public const MAX_FIELDS = 24;
    public const FIELD_TYPES = ['text' => 'Короткий текст', 'long' => 'Развёрнутый ответ', 'number' => 'Число'];

    public static function available(): bool {
        return (int)get_config('local_ustar', 'version') >= 2026111700;
    }

    public static function admin(int $actor): bool {
        return self::available() && !view_as::active() && team_access::active_actor($actor)
            && has_capability('local/ustar:admin', \context_system::instance(), $actor);
    }

    public static function overview_allowed(int $actor): bool {
        return self::admin($actor) || (self::available() && !view_as::active() && team_access::active_actor($actor)
            && has_capability('local/ustar:executive', \context_system::instance(), $actor));
    }

    public static function departments(): array {
        return people::department_map(structure::get(structure::NAME_STRUCTURE));
    }

    public static function manager_departments(int $actor): array {
        if (!self::available() || view_as::active() || !team_access::active_actor($actor)
                || !capabilities::has($actor, capabilities::TEAM_READ)) {
            return [];
        }
        $managed = [];
        foreach (organization_model::manager_places($actor) as $place) {
            $managed[$place->departmentid] = true;
        }
        return array_intersect_key(self::departments(), $managed);
    }

    public static function allowed(int $actor): bool {
        return self::overview_allowed($actor) || (bool)self::manager_departments($actor);
    }

    public static function deny(): never {
        throw new \required_capability_exception(\context_system::instance(), 'local/ustar:viewteam', 'nopermissions', '');
    }

    public static function current_week(?int $now = null): string {
        $date = (new \DateTimeImmutable('@' . ($now ?? time())))->setTimezone(new \DateTimeZone(self::ZONE));
        return ((int)$date->format('N') === 1 ? $date : $date->modify('next monday'))->format('Y-m-d');
    }

    /** Keep Monday's agenda available while opening the next report at the meeting start. */
    public static function report_week(?int $now = null): string {
        $now ??= time();
        $week = self::current_week($now);
        return $now < self::week_start($week) ? $week : self::current_week($now + WEEKSECS);
    }

    public static function week_start(string $week): int {
        $timestamp = calendar::timestamp($week, self::MEETING_TIME, self::ZONE);
        if ((int)(new \DateTimeImmutable($week, new \DateTimeZone(self::ZONE)))->format('N') !== 1) {
            throw new input_error('Выберите дату понедельника.');
        }
        return $timestamp;
    }

    public static function due_minutes(): int {
        $value = get_config('local_ustar', 'briefing_deadline_minutes');
        return $value === false ? 30 : (int)$value;
    }

    public static function defaults(): array {
        return [
            ['label' => 'Что сделано за неделю', 'type' => 'long', 'required' => true],
            ['label' => 'Показатели и результаты', 'type' => 'long', 'required' => true],
            ['label' => 'Достижения', 'type' => 'long', 'required' => false],
            ['label' => 'Вопросы и препятствия', 'type' => 'long', 'required' => false],
            ['label' => 'Задачи на следующую неделю', 'type' => 'long', 'required' => false],
        ];
    }

    public static function decode(string $data): array {
        return json_decode($data, true, 32, JSON_THROW_ON_ERROR);
    }

    private static function encode(array $data): string {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function template(string $department): ?\stdClass {
        global $DB;
        return $DB->get_record('local_ustar_brief_templates', ['departmentid' => $department]) ?: null;
    }

    /** Serialize each aggregate and own the transaction across validation and writes. */
    public static function write(string $key, callable $callback): mixed {
        global $DB;
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')->get_lock('briefing:' . $key, 10);
        if (!$lock) {
            throw new input_error('Данные меняются в другой сессии. Повторите действие.');
        }
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $result = $callback();
                $transaction->allow_commit();
                return $result;
            } catch (\Throwable $error) {
                $transaction->rollback($error);
            }
        } finally {
            $lock->release();
        }
    }

    public static function assert_revision(?\stdClass $row, int $expected): void {
        if ((int)($row->revision ?? 0) !== $expected) {
            throw new conflict();
        }
    }

    public static function save_template(int $actor, string $department, int $revision, string $title,
            int $order, bool $active, array $fields): void {
        global $DB;
        tasks::actor($actor);
        if (!self::admin($actor) || !isset(self::departments()[$department])) {
            self::deny();
        }
        $title = trim($title);
        if (!$fields || count($fields) > self::MAX_FIELDS || $title === '' || \core_text::strlen($title) > 200
                || $order < 0 || $order > 9999) {
            throw new input_error('Укажите заголовок, порядок и от 1 до 24 полей.');
        }
        foreach ($fields as $field) {
            if (!isset(self::FIELD_TYPES[$field['type']]) || trim($field['label']) === ''
                    || \core_text::strlen($field['label']) > 150) {
                throw new input_error('Проверьте название и тип каждого поля.');
            }
        }
        self::write('department:' . $department, static function() use ($DB, $department, $revision, $title, $order, $active, $fields) {
            $old = self::template($department);
            self::assert_revision($old, $revision);
            $record = (object)['departmentid' => $department, 'title' => $title, 'schemajson' => self::encode($fields),
                'sortorder' => $order, 'active' => (int)$active, 'revision' => $revision + 1];
            if ($old) {
                $record->id = $old->id;
                $DB->update_record('local_ustar_brief_templates', $record);
            } else {
                $DB->insert_record('local_ustar_brief_templates', $record);
            }
        });
    }

    public static function report(string $department, string $week): ?\stdClass {
        global $DB;
        return $DB->get_record('local_ustar_brief_reports', ['departmentid' => $department, 'weekkey' => $week]) ?: null;
    }

    public static function form(int $actor, string $department, string $week): array {
        if (!isset(self::manager_departments($actor)[$department])) {
            self::deny();
        }
        self::week_start($week);
        $template = self::template($department);
        $report = self::report($department, $week);
        $snapshot = $report ? self::decode($report->schemajson) : [
            'title' => $template->title ?? '', 'fields' => $template ? self::decode($template->schemajson) : [],
        ];
        return $snapshot + [
            'answers' => $report ? self::decode($report->answersjson) : [],
            'revision' => (int)($report->revision ?? 0), 'template' => (int)($template->revision ?? 0),
            'submittedat' => (int)($report->submittedat ?? 0), 'id' => (int)($report->id ?? 0),
            'authorid' => (int)($report->userid ?? 0),
            'editable' => $week === self::report_week() && ($report || !empty($template->active)),
        ];
    }

    public static function validate_answers(array $fields, array $input, bool $submit): array {
        $answers = [];
        foreach ($fields as $key => $field) {
            $value = trim((string)($input[$key] ?? ''));
            $limit = $field['type'] === 'long' ? 12000 : 500;
            if (\core_text::strlen($value) > $limit || ($submit && $value === '' && $field['required'])) {
                throw new input_error('Проверьте длину и заполнение поля «' . $field['label'] . '».');
            }
            if ($submit && $value !== '' && $field['type'] === 'number' && !preg_match('/^-?\d+(?:[,.]\d+)?$/D', $value)) {
                throw new input_error('В поле «' . $field['label'] . '» требуется число.');
            }
            $answers[$key] = $value;
        }
        return $answers;
    }

    public static function save_report(int $actor, string $department, string $week, int $revision, int $template,
            array $input, bool $submit): int {
        global $DB;
        tasks::actor($actor);
        return self::write('department:' . $department, static function() use ($DB, $actor, $department, $week,
                $revision, $template, $input, $submit) {
            $form = self::form($actor, $department, $week);
            if ($form['revision'] === $revision + 1 && $form['authorid'] === $actor
                    && ($revision || ($form['templateversion'] ?? null) === $template)
                    && (bool)$form['submittedat'] === $submit
                    && self::validate_answers($form['fields'], $input, $submit) === $form['answers']) {
                return $form['id'];
            }
            if (!$form['editable']) {
                throw new input_error('Форма не открыта для редактирования этой недели.');
            }
            if ($form['revision'] !== $revision || (!$revision && $form['template'] !== $template)) {
                throw new conflict();
            }
            if ($form['submittedat'] && !$submit) {
                throw new input_error('Для исправления отправленного отчёта нажмите «Сохранить изменения».');
            }
            $answers = self::validate_answers($form['fields'], $input, $submit);
            $now = time();
            $snapshot = ['title' => $form['title'], 'fields' => $form['fields']];
            // Keep snapshots from old releases readable without guessing their original template or deadline.
            if (isset($form['templateversion']) || !$form['id']) {
                $snapshot['templateversion'] = $form['templateversion'] ?? $template;
            }
            if ($submit && !$form['submittedat']) {
                $snapshot['deadlineat'] = self::week_start($week) - self::due_minutes() * MINSECS;
            } else if (isset($form['deadlineat'])) {
                $snapshot['deadlineat'] = $form['deadlineat'];
            }
            $record = (object)['departmentid' => $department, 'weekkey' => $week, 'userid' => $actor,
                'schemajson' => self::encode($snapshot),
                'answersjson' => self::encode($answers), 'revision' => $revision + 1,
                'submittedat' => $submit ? ($form['submittedat'] ?: $now) : 0, 'timemodified' => $now];
            if ($form['id']) {
                $record->id = $form['id'];
                $DB->update_record('local_ustar_brief_reports', $record);
                return $form['id'];
            }
            return (int)$DB->insert_record('local_ustar_brief_reports', $record);
        });
    }

    public static function summary(int $actor, string $week): array {
        global $DB;
        if (!self::overview_allowed($actor)) {
            self::deny();
        }
        $archived = self::week_start($week) + self::MEETING_DURATION <= time();
        $templates = $DB->get_records('local_ustar_brief_templates', [], 'sortorder ASC, title ASC, id ASC');
        $records = array_column($DB->get_records('local_ustar_brief_reports', ['weekkey' => $week]), null, 'departmentid');
        $out = [];
        foreach ($templates as $template) {
            $record = $records[$template->departmentid] ?? null;
            if ($record || ($template->active && !$archived)) {
                $out[$template->departmentid] = ['template' => $template, 'submittedat' => (int)($record->submittedat ?? 0),
                    'draft' => $record && !$record->submittedat, 'report' => !empty($record->submittedat) ? $record : null];
            }
        }
        return $out;
    }

    public static function report_status(array $row): string {
        if (!$row['report']) {
            return $row['draft'] ? 'Черновик' : 'Не сдан';
        }
        $deadline = (int)(self::decode($row['report']->schemajson)['deadlineat'] ?? 0);
        return $deadline && $row['submittedat'] > $deadline ? 'Сдан после срока' : 'Сдан';
    }

    public static function download(int $actor, string $week): never {
        global $CFG;
        $week = $week ?: self::current_week();
        $rows = self::summary($actor, $week);
        $departments = self::departments();
        require_once($CFG->libdir . '/csvlib.class.php');
        $csv = new \csv_export_writer('semicolon', '"', 'text/csv; charset=UTF-8', true);
        $csv->set_filename('briefing-' . $week);
        $csv->add_data(['Понедельник', 'Отдел', 'Статус', 'Вопрос', 'Ответ']);
        foreach ($rows as $department => $row) {
            $name = $departments[$department]['name'] ?? $department;
            $status = self::report_status($row);
            if (!$row['report']) {
                $csv->add_data([$week, $name, $status, '', '']);
                continue;
            }
            $schema = self::decode($row['report']->schemajson);
            $answers = self::decode($row['report']->answersjson);
            foreach ($schema['fields'] as $key => $field) {
                $csv->add_data([$week, $name, $status, $field['label'], $answers[$key] ?? '']);
            }
        }
        $csv->download_file();
    }

    public static function minutes(int $actor, string $week): ?\stdClass {
        global $DB;
        if (!self::allowed($actor)) {
            self::deny();
        }
        self::week_start($week);
        return $DB->get_record('local_ustar_brief_minutes', ['weekkey' => $week]) ?: null;
    }

    public static function save_minutes(int $actor, string $week, int $revision, string $content): void {
        global $DB;
        tasks::actor($actor);
        if (!self::admin($actor)) {
            self::deny();
        }
        self::week_start($week);
        if (\core_text::strlen($content) > 24000) {
            throw new input_error('Протокол превышает 24 000 символов.');
        }
        self::write('minutes:' . $week, static function() use ($DB, $actor, $week, $revision, $content) {
            $old = self::minutes($actor, $week);
            if ($old && (int)$old->userid === $actor && (int)$old->revision === $revision + 1
                    && (string)$old->content === trim($content)) {
                return;
            }
            self::assert_revision($old, $revision);
            $record = (object)['weekkey' => $week, 'userid' => $actor, 'content' => trim($content),
                'revision' => $revision + 1, 'timemodified' => time()];
            if ($old) {
                $record->id = $old->id;
                $DB->update_record('local_ustar_brief_minutes', $record);
            } else {
                $DB->insert_record('local_ustar_brief_minutes', $record);
            }
        });
    }

    /** Authorize a link from a new task to a submitted report. */
    public static function linked_report(int $actor, int $reportid): \stdClass {
        global $DB;
        $report = $DB->get_record('local_ustar_brief_reports', ['id' => $reportid], '*', MUST_EXIST);
        if (!$report->submittedat || !(self::overview_allowed($actor)
                || isset(self::manager_departments($actor)[$report->departmentid]))) {
            self::deny();
        }
        return $report;
    }

    public static function followups(int $actor, int $reportid): array {
        global $DB;
        self::linked_report($actor, $reportid);
        $result = [];
        $rows = $DB->get_recordset('local_ustar_learning_tasks', ['relatedtype' => 'briefing', 'relatedid' => $reportid],
            'id ASC');
        try {
            foreach ($rows as $task) {
                if (tasks::can_view_record($task, $actor)) {
                    $result[$task->id] = $task->title;
                }
            }
        } finally {
            $rows->close();
        }
        return $result;
    }

    public static function calendar_items(int $actor, int $from, int $to): array {
        if (!self::allowed($actor)) {
            return [];
        }
        $items = [];
        $monday = new \DateTimeImmutable(self::current_week($from), new \DateTimeZone(self::ZONE));
        for ($day = $monday; ($start = self::week_start($day->format('Y-m-d'))) < $to; $day = $day->modify('+1 week')) {
            if ($start >= $from) {
                $items[] = ['title' => 'Брифинг руководителей', 'startat' => $start, 'params' => [
                    'view' => 'briefing', 'briefweek' => $day->format('Y-m-d'),
                ]];
            }
        }
        return $items;
    }
}
