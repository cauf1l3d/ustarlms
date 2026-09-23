<?php
namespace local_ustar\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_value;
use local_ustar\structure;

class hr_get_people extends base {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'query' => new external_value(PARAM_TEXT, 'Name, username or email', VALUE_DEFAULT, ''),
            'department' => new external_value(PARAM_ALPHANUMEXT, 'Department id', VALUE_DEFAULT, ''),
            'status' => new external_value(PARAM_ALPHA, 'active | suspended | all', VALUE_DEFAULT, 'active'),
            'limit' => new external_value(PARAM_INT, 'Maximum rows', VALUE_DEFAULT, 100),
        ]);
    }

    public static function execute(string $query = '', string $department = '', string $status = 'active', int $limit = 100): array {
        global $DB;
        self::guard();
        require_capability('local/ustar:hr', \context_system::instance());
        $params = self::validate_parameters(self::execute_parameters(), compact('query', 'department', 'status', 'limit'));
        $query = trim($params['query']);
        $department = trim($params['department']);
        $status = $params['status'];
        $limit = max(1, min(250, $params['limit']));
        if (!in_array($status, ['active', 'suspended', 'all'], true)) {
            throw new \invalid_parameter_exception('Неизвестный статус сотрудника.');
        }

        $st = structure::get(structure::NAME_STRUCTURE);
        $posmap = [];
        foreach ($st['positions'] as $p) {
            $posmap[$p['id']] = $p;
        }

        $where = ['u.deleted = 0', 'u.id > 1'];
        $sqlparams = [];
        if ($status === 'active') {
            $where[] = 'u.suspended = 0';
        } else if ($status === 'suspended') {
            $where[] = 'u.suspended = 1';
        }
        if ($query !== '') {
            $like = '%' . $DB->sql_like_escape($query) . '%';
            $where[] = '(' . $DB->sql_like('u.firstname', ':q1', false) . ' OR ' .
                $DB->sql_like('u.lastname', ':q2', false) . ' OR ' .
                $DB->sql_like('u.username', ':q3', false) . ' OR ' .
                $DB->sql_like('u.email', ':q4', false) . ')';
            $sqlparams += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like];
        }

        $sql = "SELECT u.id, u.username, u.firstname, u.lastname, u.email, u.suspended, u.lastaccess
                  FROM {user} u
                 WHERE " . implode(' AND ', $where) . "
              ORDER BY u.lastname, u.firstname, u.id";
        $people = [];
        $offset = 0;
        do {
            // Position and business-account filters cannot be expressed through
            // the legacy JSON position column. Keep scanning until the page is
            // actually full, including one extra eligible row for hasMore.
            $records = $DB->get_records_sql($sql, $sqlparams, $offset, 250);
            $offset += count($records);
            foreach ($records as $u) {
                if (!\local_ustar\accounts::is_business_account((int)$u->id)) {
                    continue;
                }
                $p = $posmap[\local_ustar\organization_identity::resolve((int)$u->id)['positionid']] ?? null;
                if ($department !== '' && (!$p || $p['department'] !== $department)) {
                    continue;
                }
                $resolved = structure::resolve_user((int)$u->id);
                $people[] = [
                    'id' => (int)$u->id,
                    'username' => $u->username,
                    'fullname' => trim($u->firstname . ' ' . $u->lastname),
                    'email' => $u->email,
                    'suspended' => (bool)$u->suspended,
                    'lastaccess' => (int)$u->lastaccess,
                    'employmentStatus' => \local_ustar\employment::resolve((int)$u->id)['status'],
                    'positionid' => $p['id'] ?? '',
                    'position' => $p['name'] ?? '',
                    'department' => $p['department'] ?? '',
                    'role' => $resolved['role'],
                    'protected' => is_siteadmin($u) || has_capability('local/ustar:admin', \context_system::instance(), $u->id),
                ];
                if (count($people) > $limit) {
                    break;
                }
            }
        } while (count($people) <= $limit && count($records) === 250);
        $hasmore = count($people) > $limit;
        if ($hasmore) {
            array_pop($people);
        }

        return ['json' => json_encode([
            'people' => $people,
            'count' => count($people),
            'hasMore' => $hasmore,
            'positions' => array_values($st['positions']),
            'departments' => array_values($st['departments']),
        ], JSON_UNESCAPED_UNICODE)];
    }

    public static function execute_returns() {
        return new \core_external\external_single_structure(['json' => new external_value(PARAM_RAW, 'People JSON')]);
    }
}
