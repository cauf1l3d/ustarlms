<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Human-reviewed skill levels, separate from Moodle learning completion. */
final class skill_assessment {
    public static function record(int $userid, string $skillid, int $level, int $versionid,
            string $reason, string $nonce, int $actorid): int {
        view_as::assert_writable();
        if ($actorid === $userid || $level < 1 || $level > 5
                || trim($reason) === '' || trim($nonce) === '') {
            throw new \invalid_parameter_exception('Укажите уровень, основание оценки и другого сотрудника');
        }
        $positionid = people::position_id($userid);
        $version = $positionid === '' ? null : standard_model::current_position($positionid);
        if (!$version || (int)$version->id !== $versionid) {
            throw new \invalid_parameter_exception('Стандарт должности обновился. Откройте карточку заново');
        }
        $structure = structure::get(structure::NAME_STRUCTURE);
        $matrix = $structure['matrix'][$positionid] ?? [];
        $requirements = json_decode((string)$version->requirementsjson, true);
        $expected = null;
        foreach (is_array($requirements) ? $requirements : [] as $requirement) {
            if (($requirement['sourcekind'] ?? '') === 'ustar_skill'
                    && (string)($requirement['sourceid'] ?? '') === $skillid
                    && !empty($requirement['required'])) {
                $expected = (int)($requirement['targetlevel'] ?? 0);
                break;
            }
        }
        if ($expected < 1 || (int)($matrix[$skillid] ?? 0) !== $expected) {
            throw new \invalid_parameter_exception('Этот навык изменился после публикации стандарта');
        }
        $reason = trim($reason);
        if (\core_text::strlen($reason) < 10 || \core_text::strlen($reason) > 1000) {
            throw new \invalid_parameter_exception('Опишите основание оценки (от 10 до 1000 символов)');
        }
        // The target service checks that the actor manages the employee and
        // records the human decision as a revocable, immutable evidence fact.
        return target_core::record_evidence([
            'userid' => $userid, 'positionid' => $positionid, 'skillid' => $skillid,
            'evidencetype' => 'manager_review', 'sourcekind' => 'human_skill_level',
            'sourceid' => (string)$version->id, 'outcome' => 'passed',
            'idempotencykey' => 'skill_level_' . hash('sha256', $userid . ':' . $skillid . ':' . $nonce),
            'details' => ['level' => $level, 'reason' => $reason,
                'standardversionid' => (int)$version->id, 'targetlevel' => $expected],
        ], $actorid);
    }

    /** Most recent decision; an invalidated latest decision never exposes an older one. */
    public static function current(int $userid, string $positionid, string $skillid, int $versionid): ?array {
        global $DB;
        if ($versionid <= 0) return null;
        $fact = $DB->get_record_sql(
            'SELECT * FROM {local_ustar_evidence_rec}
              WHERE userid=:userid AND positionid=:positionid AND skillid=:skillid
                AND sourcekind=:sourcekind AND sourceid=:sourceid
                AND evidencetype=:evidencetype AND outcome=:outcome
           ORDER BY timecreated DESC, id DESC',
            ['userid'=>$userid,'positionid'=>$positionid,'skillid'=>$skillid,
                'sourcekind'=>'human_skill_level','sourceid'=>(string)$versionid,
                'evidencetype'=>'manager_review','outcome'=>'passed'],
            IGNORE_MULTIPLE
        );
        if (!$fact || (int)$fact->recordedby <= 0 || (int)$fact->recordedby === $userid
                || !target_core::evidence_is_valid((int)$fact->id)) return null;
        $details = json_decode((string)$fact->detailsjson, true);
        $level = is_array($details) ? ($details['level'] ?? null) : null;
        if (!is_int($level) || $level < 1 || $level > 5) return null;
        return ['id'=>(int)$fact->id,'level'=>$level,'reason'=>(string)($details['reason'] ?? '')];
    }

    public static function revoke(int $userid, int $evidenceid, string $reason, int $actorid): void {
        global $DB;
        view_as::assert_writable();
        $fact = $DB->get_record('local_ustar_evidence_rec', ['id'=>$evidenceid,
            'userid'=>$userid,'sourcekind'=>'human_skill_level','evidencetype'=>'manager_review'],
            '*', MUST_EXIST);
        if (!target_core::evidence_is_valid((int)$fact->id)) {
            throw new \invalid_parameter_exception('Эта оценка уже отозвана или больше не действует');
        }
        target_core::append_evidence_event($evidenceid, 'revoked', trim($reason), $actorid);
    }
}
