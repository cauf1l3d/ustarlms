<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Versioned, opt-in-by-snapshot Academy competition service. */
final class competition {
    public static function available(): bool {
        global $DB;
        return $DB->get_manager()->table_exists(new \xmldb_table('local_ustar_competitions'));
    }

    public static function create_draft(string $code, string $title, string $departmentid,
            int $startat, int $endat, int $pointsperxp, int $ownerid): int {
        global $DB;
        self::require_operator($ownerid);
        $code = clean_param(strtolower(trim($code)), PARAM_ALPHANUMEXT);
        $title = \core_text::substr(trim($title), 0, 255);
        if ($code === '' || $title === '' || $departmentid === '' || $startat <= 0 || $endat <= $startat) {
            throw new \invalid_parameter_exception('Competition code, title, audience and valid dates are required.');
        }
        if (!self::department_exists($departmentid) || $pointsperxp < 1 || $pointsperxp > 100) {
            throw new \invalid_parameter_exception('Competition audience or rule is invalid.');
        }
        $now = time();
        $competitionid = (int)$DB->insert_record('local_ustar_competitions', (object)[
            'code' => $code, 'title' => $title, 'status' => 'draft',
            'audiencekind' => 'department', 'audiencevalue' => $departmentid,
            'privacy' => 'pseudonymous', 'tiepolicy' => 'shared_place',
            'startat' => $startat, 'endat' => $endat, 'activeversionid' => null,
            'ownerid' => $ownerid, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $ruleid = (int)$DB->insert_record('local_ustar_comp_rules', (object)[
            'competitionid' => $competitionid, 'versionno' => 1,
            'rulesjson' => json_encode([
                'events' => ['game_mastery' => ['pointsperxp' => $pointsperxp]],
                'uscoin' => ['enabled' => false],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'draft', 'createdby' => $ownerid, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        return $competitionid;
    }

    /** Publish freezes the audience snapshot and one immutable rule version. */
    public static function publish(int $competitionid, int $actorid): void {
        global $DB;
        self::require_operator($actorid);
        $competition = $DB->get_record('local_ustar_competitions', ['id' => $competitionid], '*', MUST_EXIST);
        if ((string)$competition->status !== 'draft') {
            throw new \moodle_exception('Only a draft competition can be published.');
        }
        if ((int)$competition->endat <= (int)$competition->startat) {
            throw new \moodle_exception('Competition dates are invalid.');
        }
        $overlap = $DB->record_exists_select(
            'local_ustar_competitions',
            'id <> :id AND status = :status AND audiencekind = :kind AND audiencevalue = :value'
                . ' AND startat < :endat AND endat > :startat',
            ['id' => $competitionid, 'status' => 'published', 'kind' => $competition->audiencekind,
                'value' => $competition->audiencevalue, 'endat' => $competition->endat, 'startat' => $competition->startat]
        );
        if ($overlap) {
            throw new \moodle_exception('Comparable audience already has an overlapping published competition.');
        }
        $rule = $DB->get_record('local_ustar_comp_rules', ['competitionid' => $competitionid, 'status' => 'draft'], '*', MUST_EXIST);
        self::validated_rules((string)$rule->rulesjson);
        $users = [];
        foreach ($DB->get_records_select('user', 'deleted = 0 AND suspended = 0 AND id > 1', [], 'id ASC', 'id') as $user) {
            if (accounts::participates((int)$user->id) && self::matches_audience((int)$user->id, $competition)) {
                $users[] = (int)$user->id;
            }
        }
        if (!$users) {
            throw new \moodle_exception('The published audience is empty.');
        }
        $now = time();
        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ($users as $index => $userid) {
                $DB->insert_record('local_ustar_comp_participants', (object)[
                    'competitionid' => $competitionid, 'userid' => $userid,
                    'publiclabel' => 'Участник ' . str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT),
                    'audiencekey' => (string)$competition->audiencevalue, 'status' => 'active',
                    'joinedat' => $now, 'leftat' => null, 'timecreated' => $now,
                ]);
            }
            $rule->status = 'published';
            $rule->timemodified = $now;
            $DB->update_record('local_ustar_comp_rules', $rule);
            $competition->status = 'published';
            $competition->activeversionid = (int)$rule->id;
            $competition->timemodified = $now;
            $DB->update_record('local_ustar_competitions', $competition);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }

    /** Record only an allowed event for a frozen participant in a live season. */
    public static function record_game_mastery(int $userid, int $masteryid, int $xp, int $occurredat): void {
        global $DB;
        if (!self::available() || $userid <= 0 || $masteryid <= 0 || $xp <= 0) {
            return;
        }
        foreach ($DB->get_records_select(
            'local_ustar_competitions',
            'status = :status AND startat <= :now AND endat >= :now',
            ['status' => 'published', 'now' => $occurredat]
        ) as $competition) {
            $participant = $DB->get_record('local_ustar_comp_participants', [
                'competitionid' => $competition->id, 'userid' => $userid, 'status' => 'active',
            ]);
            if (!$participant || empty($competition->activeversionid)) {
                continue;
            }
            $rule = $DB->get_record('local_ustar_comp_rules', ['id' => $competition->activeversionid], '*', MUST_EXIST);
            $rules = self::validated_rules((string)$rule->rulesjson);
            $points = $xp * (int)$rules['events']['game_mastery']['pointsperxp'];
            $key = 'competition:' . (int)$competition->id . ':game_mastery:' . $masteryid;
            try {
                $DB->insert_record('local_ustar_comp_score_events', (object)[
                    'competitionid' => (int)$competition->id, 'participantid' => (int)$participant->id,
                    'ruleversionid' => (int)$rule->id, 'eventtype' => 'game_mastery', 'points' => $points,
                    'sourcekind' => 'game_mastery', 'sourceid' => (string)$masteryid, 'idempotencykey' => $key,
                    'occurredat' => $occurredat, 'timecreated' => time(),
                ]);
            } catch (\dml_write_exception $e) {
                if (!$DB->record_exists('local_ustar_comp_score_events', ['idempotencykey' => $key])) {
                    throw $e;
                }
            }
        }
    }

    /** Return a pseudonymous, comparable leaderboard only to a participant. */
    public static function current_for_user(int $userid): ?array {
        global $DB;
        if (!self::available()) {
            return null;
        }
        $now = time();
        $participant = $DB->get_record_sql(
            'SELECT p.*, c.title, c.endat, c.privacy, c.tiepolicy, c.activeversionid
               FROM {local_ustar_comp_participants} p
               JOIN {local_ustar_competitions} c ON c.id = p.competitionid
              WHERE p.userid = :userid AND p.status = :participantstatus AND c.status = :competitionstatus
                AND c.startat <= :now AND c.endat >= :now
           ORDER BY c.endat ASC',
            ['userid' => $userid, 'participantstatus' => 'active', 'competitionstatus' => 'published', 'now' => $now],
            IGNORE_MULTIPLE
        );
        if (!$participant) {
            return null;
        }
        $rows = self::scoreboard((int)$participant->competitionid, $userid);
        $rule = $DB->get_record('local_ustar_comp_rules', ['id' => $participant->activeversionid], 'versionno', MUST_EXIST);
        return [
            'title' => (string)$participant->title, 'enddate' => userdate((int)$participant->endat, '%d.%m.%Y'),
            'privacylabel' => 'Псевдонимный рейтинг участников', 'ruleversion' => (int)$rule->versionno,
            'rows' => $rows, 'current' => current(array_filter($rows, static fn(array $row): bool => !empty($row['current']))) ?: null,
        ];
    }

    /** Close only after the season ends and snapshot shared-place results. */
    public static function close(int $competitionid, int $actorid): void {
        global $DB;
        self::require_operator($actorid);
        $competition = $DB->get_record('local_ustar_competitions', ['id' => $competitionid], '*', MUST_EXIST);
        if ((string)$competition->status !== 'published' || (int)$competition->endat > time()) {
            throw new \moodle_exception('Only a finished published competition may be closed.');
        }
        $factory = \core\lock\lock_config::get_lock_factory('local_ustar');
        $lock = $factory->get_lock('competition-close:' . $competitionid, 10);
        if (!$lock) {
            throw new \moodle_exception('Unable to acquire competition close lock.');
        }
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                if ($DB->record_exists('local_ustar_comp_results', ['competitionid' => $competitionid])) {
                    throw new \moodle_exception('Competition results already exist.');
                }
                foreach (self::scoreboard($competitionid, 0) as $row) {
                    $DB->insert_record('local_ustar_comp_results', (object)[
                        'competitionid' => $competitionid, 'participantid' => $row['participantid'],
                        'ruleversionid' => (int)$competition->activeversionid, 'rankno' => $row['rank'],
                        'points' => $row['points'], 'tiekey' => 'score-' . $row['points'],
                        'status' => 'final', 'finalizedat' => time(),
                    ]);
                }
                $competition->status = 'closed';
                $competition->timemodified = time();
                $DB->update_record('local_ustar_competitions', $competition);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
        } finally {
            $lock->release();
        }
    }

    public static function operator_rows(): array {
        global $DB;
        $rows = [];
        foreach ($DB->get_records('local_ustar_competitions', null, 'timecreated DESC') as $competition) {
            $rows[] = [
                'id' => (int)$competition->id, 'code' => (string)$competition->code, 'title' => (string)$competition->title,
                'status' => (string)$competition->status, 'audience' => self::department_name((string)$competition->audiencevalue),
                'dates' => userdate((int)$competition->startat, '%d.%m.%Y') . ' — ' . userdate((int)$competition->endat, '%d.%m.%Y'),
                'participants' => (int)$DB->count_records('local_ustar_comp_participants', ['competitionid' => $competition->id]),
                'canpublish' => (string)$competition->status === 'draft',
                'canclose' => (string)$competition->status === 'published' && (int)$competition->endat <= time(),
            ];
        }
        return $rows;
    }

    public static function department_options(): array {
        $options = [];
        foreach ((structure::get(structure::NAME_STRUCTURE)['departments'] ?? []) as $department) {
            $id = (string)($department['id'] ?? '');
            if ($id !== '') {
                $options[] = ['id' => $id, 'name' => (string)($department['name'] ?? $id)];
            }
        }
        return $options;
    }

    /** @return array<int,array<string,mixed>> */
    private static function scoreboard(int $competitionid, int $viewerid): array {
        global $DB;
        $records = $DB->get_records_sql(
            'SELECT p.id AS participantid, p.userid, p.publiclabel, COALESCE(SUM(e.points), 0) AS points
               FROM {local_ustar_comp_participants} p
          LEFT JOIN {local_ustar_comp_score_events} e
                 ON e.participantid = p.id AND e.competitionid = p.competitionid
              WHERE p.competitionid = :competitionid AND p.status = :status
           GROUP BY p.id, p.userid, p.publiclabel
           ORDER BY points DESC, p.id ASC',
            ['competitionid' => $competitionid, 'status' => 'active']
        );
        $counts = [];
        foreach ($records as $record) {
            $counts[(string)$record->points] = ($counts[(string)$record->points] ?? 0) + 1;
        }
        $rows = [];
        $previous = null;
        $rank = 0;
        foreach (array_values($records) as $index => $record) {
            $points = (int)$record->points;
            if ($previous === null || $points !== $previous) {
                $rank = $index + 1;
                $previous = $points;
            }
            $current = $viewerid > 0 && (int)$record->userid === $viewerid;
            $rows[] = [
                'participantid' => (int)$record->participantid, 'rank' => $rank, 'points' => $points,
                'displayname' => $current ? 'Вы' : (string)$record->publiclabel,
                'initials' => $current ? 'Вы' : ui::initials('Участник', (string)$record->publiclabel),
                'current' => $current, 'sharedplace' => $counts[(string)$points] > 1,
            ];
        }
        return $rows;
    }

    /** @return array<string,mixed> */
    private static function validated_rules(string $rulesjson): array {
        $rules = json_decode($rulesjson, true);
        if (!is_array($rules)) {
            throw new \moodle_exception('Competition rule version is invalid.');
        }
        $rate = (int)($rules['events']['game_mastery']['pointsperxp'] ?? 0);
        if ($rate < 1 || $rate > 100 || !empty($rules['uscoin']['enabled'])) {
            throw new \moodle_exception('Competition rule version is invalid.');
        }
        return $rules;
    }

    private static function matches_audience(int $userid, \stdClass $competition): bool {
        return (string)$competition->audiencekind === 'department'
            && self::department_for_user($userid) === (string)$competition->audiencevalue;
    }

    private static function department_for_user(int $userid): string {
        $positions = people::position_map(structure::get(structure::NAME_STRUCTURE));
        return (string)($positions[people::position_id($userid)]['department'] ?? '');
    }

    private static function department_exists(string $departmentid): bool {
        foreach (self::department_options() as $option) {
            if ($option['id'] === $departmentid) {
                return true;
            }
        }
        return false;
    }

    private static function department_name(string $departmentid): string {
        foreach (self::department_options() as $option) {
            if ($option['id'] === $departmentid) {
                return $option['name'];
            }
        }
        return $departmentid;
    }

    private static function require_operator(int $userid): void {
        if ($userid <= 0 || !has_capability('local/ustar:managecompetition', \context_system::instance(), $userid)) {
            throw new \required_capability_exception(\context_system::instance(), 'local/ustar:managecompetition', 'nopermissions', '');
        }
    }
}
