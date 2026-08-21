<?php
namespace local_ustar\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_value;
use local_ustar\structure;

class get_ladder extends base {
    public static function execute_parameters(): external_function_parameters { return new external_function_parameters([]); }

    public static function execute(): array {
        global $USER;
        self::guard();
        $resolved = structure::resolve_user($USER->id);
        $st = $resolved['structure']; $position = $resolved['position']; $role = $resolved['role'];
        if (!$position && $role !== 'superadmin') {
            return ['json' => json_encode(['status' => 'position_missing', 'currentPositionId' => null, 'ladders' => []], JSON_UNESCAPED_UNICODE)];
        }

        $progressbyidnumber = [];
        foreach (self::user_courses($USER->id) as $course) $progressbyidnumber[$course['idnumber']] = $course['progress'];
        $skillmap = [];
        foreach ($st['skills'] as $skill) $skillmap[$skill['id']] = $skill;

        $ladders = [];
        foreach ($st['departments'] as $dept) {
            if ($role !== 'superadmin' && $position && $dept['id'] !== $position['department']) continue;
            $deptpos = array_values(array_filter($st['positions'], static fn($p) => $p['department'] === $dept['id']));
            if (!$deptpos) continue;
            $pmap = []; $inbound = [];
            foreach ($deptpos as $p) { $pmap[$p['id']] = $p; if (!empty($p['next'])) $inbound[$p['next']] = true; }
            $roots = array_values(array_filter($deptpos, static fn($p) => empty($inbound[$p['id']])));
            usort($roots, static fn($a,$b) => ($a['level'] <=> $b['level']) ?: strcmp($a['name'],$b['name']));
            $chains = []; $seen = [];
            foreach ($roots as $root) {
                $chain=[]; $cur=$root; $guard=[];
                while ($cur && empty($guard[$cur['id']])) {
                    $guard[$cur['id']] = true; $seen[$cur['id']] = true; $chain[] = $cur;
                    $next = $cur['next'] ?? null; $cur = ($next && isset($pmap[$next])) ? $pmap[$next] : null;
                }
                if ($chain) $chains[] = $chain;
            }
            foreach ($deptpos as $p) if (empty($seen[$p['id']])) $chains[] = [$p];

            if ($role !== 'superadmin' && $position) {
                $chains = array_values(array_filter($chains, static function($chain) use ($position) {
                    foreach ($chain as $p) if ($p['id'] === $position['id']) return true;
                    return false;
                }));
                if (!$chains) $chains = [[$position]];
            }

            foreach ($chains as $ci => $steps) {
                foreach ($steps as &$step) {
                    $step['isCurrent'] = $position && $step['id'] === $position['id'];
                    $step['isPast'] = false;
                    if ($position) {
                        $currentindex = array_search($position['id'], array_column($steps, 'id'), true);
                        $stepindex = array_search($step['id'], array_column($steps, 'id'), true);
                        $step['isPast'] = $currentindex !== false && $stepindex !== false && $stepindex < $currentindex;
                    }
                    $required = $st['matrix'][$step['id']] ?? []; $skills=[]; $earned=0; $total=0;
                    foreach ($required as $skillid => $requiredlevel) {
                        $skill = $skillmap[$skillid] ?? ['id'=>$skillid,'name'=>$skillid,'courses'=>[]];
                        $sum=0; foreach (($skill['courses'] ?? []) as $idnumber) $sum += $progressbyidnumber[$idnumber] ?? 0;
                        $progress = !empty($skill['courses']) ? (int)round($sum / count($skill['courses'])) : 0;
                        $currentlevel = min((int)$requiredlevel, (int)floor($progress / 100 * (int)$requiredlevel + 0.001));
                        $total += (int)$requiredlevel; $earned += $currentlevel;
                        $skills[] = ['id'=>$skillid,'name'=>$skill['name'],'requiredLevel'=>(int)$requiredlevel,'currentLevel'=>$currentlevel,'progress'=>$progress,'gap'=>max(0,(int)$requiredlevel-$currentlevel)];
                    }
                    $step['skills']=$skills; $step['readiness']=$total ? (int)round($earned/$total*100) : 0;
                }
                unset($step);
                $label = $dept;
                if ($role === 'superadmin' && count($chains) > 1) {
                    $label['id'] = $dept['id'] . ':' . ($steps[0]['id'] ?? $ci);
                    $label['name'] = $dept['name'] . ' · ' . ($steps[0]['name'] ?? ('Маршрут ' . ($ci+1)));
                }
                $ladders[] = ['department'=>$label,'steps'=>$steps];
            }
        }
        return ['json' => json_encode(['status'=>'ok','currentPositionId'=>$position['id'] ?? null,'ladders'=>$ladders], JSON_UNESCAPED_UNICODE)];
    }

    public static function execute_returns() { return new \core_external\external_single_structure(['json' => new external_value(PARAM_RAW, 'Ladder JSON')]); }
}
