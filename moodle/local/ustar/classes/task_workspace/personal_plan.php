<?php
namespace local_ustar\task_workspace;

use local_ustar\learning_tasks;

defined('MOODLE_INTERNAL') || die();

/** A private layout of current assignments. Task status, deadlines and reports remain on the task. */
final class personal_plan {
    public const PREF = 'ustar_personal_task_plan';
    public const DEFAULT_COLUMN = 'backlog';
    public const COLORS = ['neutral' => 'Нейтральный', 'blue' => 'Синий', 'gold' => 'Жёлтый', 'green' => 'Зелёный'];
    public const MAX_COLUMNS = 8;
    private const MAX_PLACEMENTS = 1000;
    private const PAGE_SIZE = 20;
    private const OPEN_TASKS = "t.status IN ('assigned', 'in_progress', 'in_review')";

    public static function layout(int $userid): array {
        global $DB;
        service::actor($userid);
        $raw = $DB->get_field('user_preferences', 'value', ['userid' => $userid, 'name' => self::PREF]);
        return json_decode((string)$raw, true) ?: [
            'revision' => 0,
            'columns' => [
                self::DEFAULT_COLUMN => ['title' => 'Без плана', 'color' => 'neutral'],
                'nextweek' => ['title' => 'На следующей неделе', 'color' => 'blue'],
            ],
            'placements' => [],
        ];
    }

    public static function cards(int $userid, array $layout, string $column, int $page, string $query): array {
        global $DB;
        service::actor($userid);
        $params = ['assignee' => $userid, 'privacy' => learning_tasks::PRIVACY_ASSIGNED];
        $where = 't.assigneeid = :assignee AND t.privacy = :privacy AND ' . self::OPEN_TASKS;
        $default = $column === self::DEFAULT_COLUMN;
        $ids = $default ? array_keys($layout['placements'])
            : array_keys($layout['placements'], $column, true);
        if ($ids) {
            [$in, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'card', !$default);
            $where .= ' AND t.id ' . $in;
            $params += $inparams;
        } else if (!$default) {
            return ['items' => [], 'total' => 0, 'page' => 0, 'hasmore' => false];
        }
        if ($query !== '') {
            $where .= ' AND ' . $DB->sql_like('t.title', ':titlequery', false);
            $params['titlequery'] = '%' . $DB->sql_like_escape($query) . '%';
        }
        $total = $DB->count_records_sql("SELECT COUNT(t.id) FROM {local_ustar_learning_tasks} t WHERE $where", $params);
        $page = max(0, min($page, intdiv(max(0, $total - 1), self::PAGE_SIZE)));
        $items = $total ? $DB->get_records_sql("SELECT t.id, t.title, t.status, t.dueat, m.timezone
             FROM {local_ustar_learning_tasks} t
        LEFT JOIN {local_ustar_task_meta} m ON m.taskid = t.id
            WHERE $where
         ORDER BY CASE WHEN t.dueat IS NULL THEN 1 ELSE 0 END, t.dueat ASC, t.id ASC",
            $params, $page * self::PAGE_SIZE, self::PAGE_SIZE) : [];
        return ['items' => $items, 'total' => $total, 'page' => $page, 'hasmore' => ($page + 1) * self::PAGE_SIZE < $total];
    }

    public static function update(int $userid, int $revision, string $action, string $column, int $taskid = 0,
            string $title = '', string $color = 'neutral'): void {
        service::actor($userid);
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')->get_lock('personal-plan:' . $userid, 5);
        if (!$lock) {
            throw new \moodle_exception('workplanbusy', 'local_ustar');
        }
        try {
            $layout = self::layout($userid);
            if ($layout['revision'] !== $revision) {
                throw new \moodle_exception('workplanchanged', 'local_ustar');
            }
            $columns = $layout['columns'];
            $placements = $layout['placements'];
            if ($action === 'plan_add' || $action === 'plan_edit') {
                $title = trim(clean_param($title, PARAM_TEXT));
                if ($title === '' || \core_text::strlen($title) > 60 || !isset(self::COLORS[$color])) {
                    throw new \moodle_exception('workplaninvalid', 'local_ustar');
                }
            }
            if ($action === 'plan_add') {
                if (count($columns) >= self::MAX_COLUMNS) {
                    throw new \moodle_exception('workplancolumnlimit', 'local_ustar', '', self::MAX_COLUMNS);
                }
                $column = 'p' . bin2hex(random_bytes(8));
            } else if (!isset($columns[$column])) {
                throw new \moodle_exception('workplaninvalid', 'local_ustar');
            }
            switch ($action) {
                case 'plan_add':
                case 'plan_edit':
                    $columns[$column] = ['title' => $title, 'color' => $color];
                    break;
                case 'plan_left':
                case 'plan_right':
                    $order = array_keys($columns);
                    $index = array_search($column, $order, true);
                    $next = $index + ($action === 'plan_left' ? -1 : 1);
                    if (isset($order[$next])) {
                        [$order[$next], $order[$index]] = [$order[$index], $order[$next]];
                        $columns = array_replace(array_fill_keys($order, []), $columns);
                    }
                    break;
                case 'plan_remove':
                    if ($column === self::DEFAULT_COLUMN) {
                        throw new \moodle_exception('workplaninvalid', 'local_ustar');
                    }
                    unset($columns[$column]);
                    $placements = array_filter($placements, static fn($value) => $value !== $column);
                    break;
                case 'plan_move':
                    if (!isset(self::current_cards($userid, [$taskid])[$taskid])) {
                        throw new \required_capability_exception(\context_system::instance(), 'local/ustar:use', 'nopermissions', '');
                    }
                    if ($column === self::DEFAULT_COLUMN) {
                        unset($placements[$taskid]);
                    } else {
                        $placements[$taskid] = $column;
                    }
                    break;
                default:
                    throw new \moodle_exception('workplaninvalid', 'local_ustar');
            }
            $placements = array_intersect_key($placements, self::current_cards($userid, array_keys($placements)));
            if (count($placements) > self::MAX_PLACEMENTS) {
                throw new \moodle_exception('workplanlimit', 'local_ustar', '', self::MAX_PLACEMENTS);
            }
            if ($layout['columns'] !== $columns || $layout['placements'] !== $placements) {
                set_user_preference(self::PREF, json_encode([
                    'revision' => $revision + 1, 'columns' => $columns, 'placements' => $placements,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $userid);
            }
        } finally {
            $lock->release();
        }
    }

    private static function current_cards(int $userid, array $ids): array {
        global $DB;
        if (!$ids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'card');
        $ids = $DB->get_fieldset_sql("SELECT t.id FROM {local_ustar_learning_tasks} t
             WHERE t.assigneeid = :assignee AND t.privacy = :privacy AND t.id $in AND " . self::OPEN_TASKS,
            $params + ['assignee' => $userid, 'privacy' => learning_tasks::PRIVACY_ASSIGNED]);
        return array_fill_keys($ids, true);
    }
}
