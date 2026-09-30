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
            throw new \invalid_parameter_exception('Укажите название, подразделение и корректные даты сезона.');
        }
        if (!self::department_exists($departmentid) || $pointsperxp < 1 || $pointsperxp > 100) {
            throw new \invalid_parameter_exception('Выберите действующее подразделение и от 1 до 100 баллов за XP.');
        }
        $transaction = $DB->start_delegated_transaction();
        try {
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
        $transaction->allow_commit();
        } catch (\Throwable $e) { $transaction->rollback($e); }
        return $competitionid;
    }

    /** Publish freezes the audience snapshot and one immutable rule version. */
    public static function publish(int $competitionid, int $actorid): void {
        global $DB;
        self::require_operator($actorid);
        $lock=\core\lock\lock_config::get_lock_factory('local_ustar')->get_lock('competition-publish',10);
        if (!$lock) throw new \moodle_exception('Другой сезон сейчас публикуется. Повторите действие.');
        try {
            $tx=$DB->start_delegated_transaction();
            self::publish_locked($competitionid,$actorid);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            $tx->rollback($e);
        } finally { $lock->release(); }
    }
    private static function publish_locked(int $competitionid, int $actorid): void {
        global $DB;
        self::require_operator($actorid);
        $competition = $DB->get_record_sql('SELECT * FROM {local_ustar_competitions} WHERE id=:id FOR UPDATE', ['id'=>$competitionid], MUST_EXIST);
        if ((string)$competition->status !== 'draft') {
            throw new \moodle_exception('Опубликовать можно только черновик.');
        }
        if ((int)$competition->endat <= (int)$competition->startat) {
            throw new \moodle_exception('Проверьте даты сезона.');
        }
        $overlap = $DB->record_exists_select(
            'local_ustar_competitions',
            'id <> :id AND status = :status AND audiencekind = :kind AND audiencevalue = :value'
                . ' AND startat < :endat AND endat > :startat',
            ['id' => $competitionid, 'status' => 'published', 'kind' => $competition->audiencekind,
                'value' => $competition->audiencevalue, 'endat' => $competition->endat, 'startat' => $competition->startat]
        );
        if ($overlap) {
            throw new \moodle_exception('У подразделения уже есть сезон с пересекающимися датами.');
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
            throw new \moodle_exception('В подразделении нет действующих участников. Проверьте кадровые назначения.');
        }
        $now = time();
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

        } catch (\Throwable $e) {
            throw $e;
        }
    }

    /** Record only an allowed event for a frozen participant in a live season. */
    public static function record_game_mastery(int $userid, int $masteryid, int $xp, int $occurredat): void {
        global $DB;
        if (!self::available() || !accounts::participates($userid) || $masteryid <= 0 || $xp <= 0) {
            return;
        }
        foreach ($DB->get_records_select(
            'local_ustar_competitions',
            'status = :status AND startat <= :now AND endat >= :endnow',
            ['status' => 'published', 'now' => $occurredat, 'endnow' => $occurredat]
        ) as $competition) {
            $tx=$DB->start_delegated_transaction();
            try {
            $competition=$DB->get_record_sql('SELECT * FROM {local_ustar_competitions} WHERE id=:id FOR UPDATE',
                ['id'=>$competition->id],MUST_EXIST);
            $participant=$DB->get_record('local_ustar_comp_participants',[
                'competitionid'=>$competition->id,'userid'=>$userid,'status'=>'active']);
            $key='competition:'.(int)$competition->id.':game_mastery:'.$masteryid;
            if ($competition->status==='published' && $participant && !empty($competition->activeversionid)
                    && !$DB->record_exists('local_ustar_comp_score_events',['idempotencykey'=>$key])) {
                $rule=$DB->get_record('local_ustar_comp_rules',['id'=>$competition->activeversionid],'*',MUST_EXIST);
                $rules=self::validated_rules($rule->rulesjson);
                $DB->insert_record('local_ustar_comp_score_events',(object)[
                    'competitionid'=>(int)$competition->id,'participantid'=>(int)$participant->id,
                    'ruleversionid'=>(int)$rule->id,'eventtype'=>'game_mastery',
                    'points'=>$xp*(int)$rules['events']['game_mastery']['pointsperxp'],
                    'sourcekind'=>'game_mastery','sourceid'=>(string)$masteryid,'idempotencykey'=>$key,
                    'occurredat'=>$occurredat,'timecreated'=>time()]);
            }
            $tx->allow_commit();
            } catch (\Throwable $e) { $tx->rollback($e); }
        }
    }

    /** Return a pseudonymous, comparable leaderboard only to a participant. */
    public static function current_for_user(int $userid): ?array {
        global $DB;
        if (!self::available() || !accounts::participates($userid)) {
            return null;
        }
        $now = time();
        $participant = $DB->get_record_sql(
            'SELECT p.*, c.title, c.endat, c.privacy, c.tiepolicy, c.activeversionid
               FROM {local_ustar_comp_participants} p
               JOIN {local_ustar_competitions} c ON c.id = p.competitionid
              WHERE p.userid = :userid AND p.status = :participantstatus AND c.status = :competitionstatus
                AND c.startat <= :now AND c.endat >= :endnow
           ORDER BY c.endat ASC',
            ['userid' => $userid, 'participantstatus' => 'active', 'competitionstatus' => 'published', 'now' => $now, 'endnow' => $now],
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
            throw new \moodle_exception('Сезон сейчас закрывается. Повторите действие.');
        }
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $competition=$DB->get_record_sql('SELECT * FROM {local_ustar_competitions} WHERE id=:id FOR UPDATE', ['id'=>$competitionid], MUST_EXIST);
                if ($competition->status!=='published' || $competition->endat>time()) {
                    throw new \moodle_exception('Сезон уже закрыт или ещё не завершился.');
                }
                if ($DB->record_exists('local_ustar_comp_results', ['competitionid' => $competitionid])) {
                    throw new \moodle_exception('Итоги сезона уже сохранены.');
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

    public static function operator_rows(int $page = 0): array {
        global $DB;
        $rows = [];
        $records = $DB->get_records_sql('SELECT c.*, COALESCE(p.total,0) AS participantcount
            FROM {local_ustar_competitions} c LEFT JOIN (
                SELECT competitionid,COUNT(id) AS total FROM {local_ustar_comp_participants} GROUP BY competitionid
            ) p ON p.competitionid=c.id ORDER BY c.timecreated DESC,c.id DESC',[],max(0,$page)*25,25);
        foreach ($records as $c) {
            $rows[] = ['id'=>(int)$c->id,'title'=>$c->title,'status'=>$c->status,
                'audience'=>self::department_name($c->audiencevalue),
                'dates'=>userdate($c->startat,'%d.%m.%Y').' — '.userdate($c->endat,'%d.%m.%Y'),
                'participants'=>(int)$c->participantcount,'statuslabel'=>self::status_label($c->status),
                'url'=>(new \moodle_url('/local/ustar/competition_studio.php',['season'=>$c->id]))->out(false)];
        }
        return $rows;
    }
    private static function status_label(string $status): string {
        return ['draft'=>'Черновик','published'=>'Опубликован','closed'=>'Завершён'][$status] ?? 'Архив';
    }
    public static function operator_season(int $id, int $actor): array {
        global $DB;
        self::require_operator($actor);
        $c=$DB->get_record('local_ustar_competitions',['id'=>$id],'*',MUST_EXIST);
        $r=$DB->get_record('local_ustar_comp_rules',['competitionid'=>$id,'versionno'=>1],'*',MUST_EXIST);
        $c->pointsperxp=self::validated_rules($r->rulesjson)['events']['game_mastery']['pointsperxp'];
        $c->is_draft=$c->status==='draft';$c->is_closed=$c->status==='closed';
        $c->canclose=$c->status==='published' && $c->endat<=time();
        $c->audience=self::department_name($c->audiencevalue);$c->statuslabel=self::status_label($c->status);
        $c->dates=userdate($c->startat,'%d.%m.%Y').' — '.userdate($c->endat,'%d.%m.%Y');
        if ($c->is_closed) {
            $scores=array_values($DB->get_records_sql('SELECT r.id,r.rankno AS rank,r.points,p.publiclabel AS displayname
                FROM {local_ustar_comp_results} r JOIN {local_ustar_comp_participants} p ON p.id=r.participantid
                WHERE r.competitionid=:id ORDER BY r.rankno,p.id',['id'=>$id],0,100));
        } else {
            $scores=array_slice(self::scoreboard($id,0),0,100);
        }
        return ['definition'=>(array)$c,'scores'=>$scores];
    }
    public static function revise_draft(int $id,int $actor,int $expected,string $title,string $dept,int $start,int $end,int $rate): void {
        global $DB;
        self::require_operator($actor);
        if (trim($title)==='' || $start<=0 || $end<=$start || !self::department_exists($dept) || $rate<1 || $rate>100) {
            throw new \invalid_parameter_exception('Проверьте название, подразделение, даты и баллы за XP.');
        }
        $tx=$DB->start_delegated_transaction();
        try {
        $c=$DB->get_record_sql('SELECT * FROM {local_ustar_competitions} WHERE id=:id FOR UPDATE',['id'=>$id],MUST_EXIST);
        if ($c->status!=='draft' || (int)$c->timemodified!==$expected) {
            throw new \moodle_exception('Сезон уже изменился или опубликован. Обновите страницу.');
        }
        $c->title=\core_text::substr(trim($title),0,255);$c->audiencevalue=$dept;
        $c->startat=$start;$c->endat=$end;$c->timemodified=max(time(),(int)$c->timemodified+1);
        $DB->update_record('local_ustar_competitions',$c);
        $r=$DB->get_record('local_ustar_comp_rules',['competitionid'=>$id,'versionno'=>1],'*',MUST_EXIST);
        $rules=self::validated_rules($r->rulesjson);$rules['events']['game_mastery']['pointsperxp']=$rate;
        $r->rulesjson=json_encode($rules);$r->timemodified=$c->timemodified;
        $DB->update_record('local_ustar_comp_rules',$r);$tx->allow_commit();
        } catch (\Throwable $e) { $tx->rollback($e); }
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
        $records = array_filter($records, static fn(\stdClass $record): bool =>
            accounts::participates((int)$record->userid)
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
            throw new \moodle_exception('Правило сезона повреждено. Обратитесь к администратору.');
        }
        $rate = (int)($rules['events']['game_mastery']['pointsperxp'] ?? 0);
        if ($rate < 1 || $rate > 100 || !empty($rules['uscoin']['enabled'])) {
            throw new \moodle_exception('Правило сезона повреждено. Обратитесь к администратору.');
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
        global $USER;
        view_as::assert_writable();
        if ($userid <= 0 || (int)$USER->id !== $userid || !has_capability('local/ustar:managecompetition', \context_system::instance(), $userid)) {
            throw new \required_capability_exception(\context_system::instance(), 'local/ustar:managecompetition', 'nopermissions', '');
        }
    }
}
