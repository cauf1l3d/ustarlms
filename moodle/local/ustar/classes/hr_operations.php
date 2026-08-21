<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * HR operational dashboard derived from current Moodle/USTAR facts.
 * No decorative/fabricated KPIs and no "overdue" metric without due dates.
 */
class hr_operations {

    /** @return array<string,mixed> */
    public static function dashboard(): array {
        global $DB;

        $structure = structure::get(structure::NAME_STRUCTURE);
        $positions = people::position_map($structure);

        $users = $DB->get_records_select(
            'user',
            'deleted = 0 AND suspended = 0 AND username <> :guest',
            ['guest' => 'guest'],
            'lastname ASC, firstname ASC, id ASC',
            'id,username,firstname,lastname,email,suspended'
        );

        $employeeids = [];
        foreach ($users as $user) {
            $userid = (int)$user->id;
            if (!accounts::participates($userid)) {
                continue;
            }

            $positionid = people::position_id($userid);
            if ($positionid === '' || !isset($positions[$positionid])) {
                continue;
            }

            $employeeids[] = $userid;
        }

        $compliancependingassignments = 0;
        $compliancependingpeople = [];
        $learningnotstarted = 0;
        $learninginprogress = 0;
        $learningcompleted = 0;
        $learningpeople = [];
        $skillgaps = 0;
        $skillgappeople = [];
        $profiles = [];

        foreach ($employeeids as $userid) {
            $profile = employee_profile::build($userid);
            $profiles[$userid] = $profile;

            $identity = $profile['identity'];
            $hrurl = (new \moodle_url('/local/ustar/hr.php', [
                'userid' => $userid,
                'theme' => 'ustar',
            ]))->out(false);

            $pendingdocs = (int)$profile['knowledge']['pending'];
            if ($pendingdocs > 0) {
                $compliancependingassignments += $pendingdocs;
                $compliancependingpeople[] = [
                    'userid' => $userid,
                    'fullname' => $identity['fullname'],
                    'position' => $identity['position'],
                    'department' => $identity['department'],
                    'count' => $pendingdocs,
                    'url' => $hrurl,
                ];
            }

            $lns = (int)$profile['learning']['notstarted'];
            $lip = (int)$profile['learning']['inprogress'];
            $lcp = (int)$profile['learning']['completed'];
            $learningnotstarted += $lns;
            $learninginprogress += $lip;
            $learningcompleted += $lcp;

            if ($lns > 0 || $lip > 0) {
                $learningpeople[] = [
                    'userid' => $userid,
                    'fullname' => $identity['fullname'],
                    'position' => $identity['position'],
                    'notstarted' => $lns,
                    'inprogress' => $lip,
                    'url' => $hrurl,
                ];
            }

            $gaps = (int)$profile['skills']['gaps'];
            if ($gaps > 0) {
                $skillgaps += $gaps;
                $skillgappeople[] = [
                    'userid' => $userid,
                    'fullname' => $identity['fullname'],
                    'position' => $identity['position'],
                    'count' => $gaps,
                    'confirmed' => (int)$profile['skills']['confirmed'],
                    'required' => (int)$profile['skills']['required'],
                    'url' => $hrurl,
                ];
            }
        }

        usort($compliancependingpeople, static fn(array $a, array $b): int =>
            ($b['count'] <=> $a['count']) ?: strcasecmp($a['fullname'], $b['fullname'])
        );
        usort($learningpeople, static fn(array $a, array $b): int =>
            (($b['notstarted'] + $b['inprogress']) <=> ($a['notstarted'] + $a['inprogress']))
                ?: strcasecmp($a['fullname'], $b['fullname'])
        );
        usort($skillgappeople, static fn(array $a, array $b): int =>
            ($b['count'] <=> $a['count']) ?: strcasecmp($a['fullname'], $b['fullname'])
        );

        return [
            'generatedat' => time(),
            'employees' => count($employeeids),
            'compliance' => [
                'pendingassignments' => $compliancependingassignments,
                'pendingpeople' => count($compliancependingpeople),
                'people' => array_slice($compliancependingpeople, 0, 100),
            ],
            'learning' => [
                'notstarted' => $learningnotstarted,
                'inprogress' => $learninginprogress,
                'completed' => $learningcompleted,
                'people' => array_slice($learningpeople, 0, 100),
            ],
            'skills' => [
                'gaps' => $skillgaps,
                'peoplewithgaps' => count($skillgappeople),
                'people' => array_slice($skillgappeople, 0, 100),
            ],
            'profiles' => $profiles,
        ];
    }
}
