<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * ACL-aware, grouped search for the USTAR shell.
 *
 * This deliberately searches a small, actionable set of entities and returns
 * direct destinations. It does not attempt to replace Moodle's full text index.
 */
final class global_search {
    public static function run(int $userid, string $q, int $limit = 6): array {
        global $DB;

        $q = trim($q);
        $limit = max(1, min(12, $limit));
        if (\core_text::strlen($q) < 2) {
            return [];
        }
        if (!is_siteadmin($userid) && !employment::is_active($userid)) {
            return [];
        }

        $like = '%' . $DB->sql_like_escape($q) . '%';
        $groups = [];
        $systemcontext = \context_system::instance();
        $iswide = is_siteadmin($userid)
            || has_capability('local/ustar:hr', $systemcontext, $userid);
        $ownpositionid = people::position_id($userid);

        // Visible Moodle courses remain the source of truth for learning.
        $courses = [];
        $sql = 'SELECT id,fullname,shortname
                  FROM {course}
                 WHERE id <> :siteid
                   AND visible = 1
                   AND (' . $DB->sql_like('fullname', ':q', false) . '
                        OR ' . $DB->sql_like('shortname', ':q2', false) . ')
              ORDER BY fullname,id';
        $coursecontext = \context_system::instance();
        $canseewide = is_siteadmin($userid)
            || has_capability('local/ustar:hr', $coursecontext, $userid)
            || has_capability('local/ustar:admin', $coursecontext, $userid);
        $page = max(30, $limit * 4);
        for ($offset = 0; ; $offset += $page) {
            $batch = $DB->get_records_sql($sql,
                ['siteid' => SITEID, 'q' => $like, 'q2' => $like], $offset, $page);
            foreach ($batch as $record) {
            // Visible does not mean assigned. Normal employees only see courses they are actually enrolled in.
            if (!$canseewide) {
                $ctx = \context_course::instance((int)$record->id, IGNORE_MISSING);
                if (!$ctx || !is_enrolled($ctx, $userid, '', true)) {
                    continue;
                }
            }
            $courses[] = [
                'label' => format_string($record->fullname),
                'meta' => 'Курс',
                'url' => (new \moodle_url('/course/view.php', ['id' => $record->id]))->out(false),
            ];
                if (count($courses) >= $limit) break;
            }
            if (count($courses) >= $limit || count($batch) < $page) break;
        }
        if ($courses) {
            $groups[] = ['label' => 'Обучение', 'items' => $courses];
        }

        // USTAR content is filtered through the existing ACL service.
        $knowledge = [];
        if ($DB->get_manager()->table_exists(new \xmldb_table('local_ustar_content'))) {
            $select = 'status = :status AND type <> :folder AND ('
                . $DB->sql_like('title', ':q', false)
                . ' OR ' . $DB->sql_like('summary', ':q2', false) . ')';
            for ($offset = 0; ; $offset += $page) {
                $records = $DB->get_records_select(
                    'local_ustar_content', $select,
                    ['status' => 'published', 'folder' => 'folder', 'q' => $like, 'q2' => $like],
                    'timemodified DESC,id DESC', '*', $offset, $page
                );
                foreach ($records as $record) {
                if (!content::can_access((int)$record->id, $userid)) {
                    continue;
                }
                $openurl = content::open_url((int)$record->id, $userid);
                if (!$openurl) {
                    continue;
                }
                $knowledge[] = [
                    'label' => format_string($record->title),
                    'meta' => 'База знаний',
                    'url' => $openurl->out(false),
                ];
                    if (count($knowledge) >= $limit) break;
                }
                if (count($knowledge) >= $limit || count($records) < $page) break;
            }
        }
        if ($knowledge) {
            $groups[] = ['label' => 'База знаний', 'items' => $knowledge];
        }

        // Product catalog.
        $products = [];
        if (catalog::available() && (catalog::can_manage($userid)
                || catalog_mastery::has_access($userid))) {
            foreach (catalog::browse(null, $q) as $record) {
                if (empty($record['isproduct'])) {
                    continue;
                }
                $products[] = [
                    'label' => (string)$record['title'],
                    'meta' => !empty($record['sku']) ? 'Товар · ' . $record['sku'] : 'Товар',
                    'url' => (string)$record['detailurl'],
                ];
                if (count($products) >= $limit) {
                    break;
                }
            }
        }
        if ($products) {
            $groups[] = ['label' => 'Каталог', 'items' => $products];
        }

        // Skills and positions come from the existing USTAR structure model.
        $structure = structure::get(structure::NAME_STRUCTURE);
        $skills = [];
        foreach ($structure['skills'] ?? [] as $skill) {
            $skillid = (string)($skill['id'] ?? '');
            if (mb_stripos((string)($skill['name'] ?? ''), $q) === false) {
                continue;
            }
            $relatedposition = '';
            foreach ($structure['matrix'] ?? [] as $pid => $required) {
                if (isset($required[$skillid])) {
                    $relatedposition = (string)$pid;
                    break;
                }
            }
            if (!$iswide && !isset($structure['matrix'][$ownpositionid][$skillid])) continue;
            if ($iswide && $relatedposition === '') continue;
            $destination = $iswide
                ? new \moodle_url('/local/ustar/positions.php',
                    ['positionid'=>$relatedposition,'skillid'=>$skillid])
                : new \moodle_url('/local/ustar/route_career.php');
            if (!$iswide) $destination->set_anchor('skill-' . $skillid);
            $skills[] = [
                'label' => (string)$skill['name'],
                'meta' => 'Навык',
                'url' => $destination->out(false),
            ];
            if (count($skills) >= $limit) {
                break;
            }
        }
        if ($skills) {
            $groups[] = ['label' => 'Навыки', 'items' => $skills];
        }

        $positions = [];
        foreach ($structure['positions'] ?? [] as $position) {
            if (!$iswide && (string)$position['id'] !== $ownpositionid) continue;
            if (mb_stripos((string)($position['name'] ?? ''), $q) === false) {
                continue;
            }
            $destination = $iswide
                ? new \moodle_url('/local/ustar/positions.php', ['positionid'=>$position['id']])
                : new \moodle_url('/local/ustar/route_career.php');
            $positions[] = [
                'label' => (string)$position['name'],
                'meta' => 'Должность',
                'url' => $destination->out(false),
            ];
            if (count($positions) >= $limit) {
                break;
            }
        }
        if ($positions) {
            $groups[] = ['label' => 'Должности', 'items' => $positions];
        }

        // People results must not widen organizational visibility.
        $allowedids = null;
        if (!$iswide && has_capability('local/ustar:viewteam', $systemcontext, $userid)) {
            $allowedids = [];
            try {
                $team = native_data::team();
                foreach ($team['team'] ?? [] as $person) {
                    $allowedids[(int)$person['id']] = true;
                }
                $allowedids[$userid] = true;
            } catch (\Throwable $ignored) {
                $allowedids = [];
            }
        }

        if ($iswide || is_array($allowedids)) {
            $people = [];
            $params = ['q' => $like, 'q2' => $like];
            $scope = '';
            if (is_array($allowedids)) {
                if (!$allowedids) return $groups;
                [$insql, $inparams] = $DB->get_in_or_equal(array_keys($allowedids), SQL_PARAMS_NAMED, 'team');
                $scope = ' AND id ' . $insql;
                $params += $inparams;
            }
            $sql = 'SELECT id,firstname,lastname
                      FROM {user}
                     WHERE deleted = 0
                       AND suspended = 0
                       AND (' . $DB->sql_like('firstname', ':q', false)
                       . ' OR ' . $DB->sql_like('lastname', ':q2', false) . ')'
                       . $scope . ' ORDER BY lastname,firstname,id';
            for ($offset = 0; ; $offset += $page) {
                $batch = $DB->get_records_sql($sql, $params, $offset, $page);
                foreach ($batch as $user) {
                $uid = (int)$user->id;
                if (!accounts::participates($uid)) {
                    continue;
                }
                if (is_array($allowedids) && !isset($allowedids[$uid])) {
                    continue;
                }
                $destination = $iswide
                    ? new \moodle_url('/local/ustar/hr.php', ['userid'=>$uid])
                    : new \moodle_url('/local/ustar/team.php');
                if (!$iswide) $destination->set_anchor('person-' . $uid);
                $people[] = [
                    'label' => fullname($user),
                    'meta' => 'Сотрудник',
                    'url' => $destination->out(false),
                ];
                    if (count($people) >= $limit) break;
                }
                if (count($people) >= $limit || count($batch) < $page) break;
            }
            if ($people) {
                $groups[] = ['label' => 'Сотрудники', 'items' => $people];
            }
        }

        return $groups;
    }
}
