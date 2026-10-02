<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Small, indexed operational counters for Stage 6.
 *
 * Personal notebook rows are intentionally absent from every metric. They are
 * neither counted nor joined, so an aggregate cannot reveal note activity.
 */
final class stage6_metrics {
    /** @return array<string,array<string,int>> */
    public static function summary(): array {
        return [
            'materials' => [
                'courses' => self::count('local_ustar_content_blueprints', ['kind' => 'course']),
                'scorm' => self::count('local_ustar_content_blueprints', ['kind' => 'scorm']),
                'assessments' => self::count('local_ustar_content_blueprints', ['kind' => 'assessment']),
                'importedscorm' => self::count('local_ustar_content_blueprints', ['kind' => 'scorm', 'packagestatus' => 'imported']),
            ],
            'grades' => [
                'pending' => self::count('local_ustar_grade_requests', ['status' => 'pending']),
                'approved' => self::count('local_ustar_grade_requests', ['status' => 'approved']),
                'rejected' => self::count('local_ustar_grade_requests', ['status' => 'rejected']),
            ],
            'catalog' => catalog::stats(),
            'tasks' => [
                'assignedactive' => self::assigned_active(),
                'review' => self::count('local_ustar_learning_tasks', [
                    'privacy' => learning_tasks::PRIVACY_ASSIGNED,
                    'status' => 'in_review',
                ]),
                'completed' => self::count('local_ustar_learning_tasks', [
                    'privacy' => learning_tasks::PRIVACY_ASSIGNED,
                    'status' => 'completed',
                ]),
            ],
        ];
    }

    private static function count(string $table, array $conditions = []): int {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table($table))) {
            return 0;
        }
        return (int)$DB->count_records($table, $conditions);
    }

    private static function assigned_active(): int {
        global $DB;
        $table = 'local_ustar_learning_tasks';
        if (!$DB->get_manager()->table_exists(new \xmldb_table($table))) {
            return 0;
        }
        return (int)$DB->count_records_select(
            $table,
            'privacy = :privacy AND status IN (:assigned, :progress, :review)',
            [
                'privacy' => learning_tasks::PRIVACY_ASSIGNED,
                'assigned' => 'assigned',
                'progress' => 'in_progress',
                'review' => 'in_review',
            ]
        );
    }
}
