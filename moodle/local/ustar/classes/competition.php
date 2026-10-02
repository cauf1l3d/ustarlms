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
            int $startat, int $endat, int $pointsperxp, int $ownerid, string $mode = 'game', string $learningsource = 'route'): int {
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
        $events = self::event_rules($mode, $learningsource, $pointsperxp);
        $transaction = $DB->start_delegated_transaction();
        try {
        $now = time();
        $competitionid = (int)$DB->insert_record('local_ustar_competitions', (object)[
            'code' => $code, 'title' => $title, 'status' => 'draft',
            'audiencekind' => 'department', 'audiencevalue' => $departmentid,
            'privacy' => 'named', 'tiepolicy' => 'shared_place',
            'startat' => $startat, 'endat' => $endat, 'activeversionid' => null,
            'ownerid' => $ownerid, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $ruleid = (int)$DB->insert_record('local_ustar_comp_rules', (object)[
            'competitionid' => $competitionid, 'versionno' => 1,
            'rulesjson' => json_encode([
                'mode' => $mode, 'events' => $events,
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
                if (!isset($rules['events']['game_mastery']) || $occurredat < (int)$rule->timemodified) { $tx->allow_commit(); continue; }
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

    /** Return a named, comparable leaderboard only to an active season participant. */
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
            'privacylabel' => 'Рейтинг участников сезона', 'ruleversion' => (int)$rule->versionno,
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
        self::reconcile_learning(500,$competitionid,true);
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
        $rules=self::validated_rules($r->rulesjson);
        $event=array_key_first($rules['events']);
        $c->pointsperxp=$rules['events'][$event]['pointsperxp'];
        $c->mode=$event==='game_mastery'?'game':'learning';
        $c->learningsource=$event==='course_completion'?'course':'route';
        $c->modelabel=$c->mode==='game'?'Игровой сезон':'Обучающий сезон';
        $c->sourcelabel=['game_mastery'=>'освоение игр','course_completion'=>'завершение курсов Moodle','route_completion'=>'подтверждённые точки учебного маршрута'][$event];
        $c->is_draft=$c->status==='draft';$c->is_closed=$c->status==='closed';
        $c->canclose=$c->status==='published' && $c->endat<=time();
        $c->audience=self::department_name($c->audiencevalue);$c->statuslabel=self::status_label($c->status);
        $c->dates=userdate($c->startat,'%d.%m.%Y').' — '.userdate($c->endat,'%d.%m.%Y');
        if ($c->is_closed) {
            $scores=array_values($DB->get_records_sql('SELECT r.id,r.rankno AS rank,r.points,p.publiclabel AS displayname,p.userid
                FROM {local_ustar_comp_results} r JOIN {local_ustar_comp_participants} p ON p.id=r.participantid
                WHERE r.competitionid=:id ORDER BY r.rankno,p.id',['id'=>$id],0,100));
        } else {
            $scores=array_slice(self::scoreboard($id,0),0,100);
        }
        foreach ($scores as &$score) {
            $userid=is_array($score)?(int)$DB->get_field('local_ustar_comp_participants','userid',['id'=>$score['participantid']]):(int)$score->userid;
            $u=$DB->get_record('user',['id'=>$userid],'id,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename');
            if (is_array($score)) { $score['displayname']=$u?fullname($u):'Удалённая учётная запись'; }
            else { $score->displayname=$u?fullname($u):'Удалённая учётная запись'; }
        }
        unset($score);
        return ['definition'=>(array)$c,'scores'=>$scores];
    }
    public static function revise_draft(int $id,int $actor,int $expected,string $title,string $dept,int $start,int $end,int $rate,string $mode='game',string $learningsource='route'): void {
        global $DB;
        self::require_operator($actor);
        if (trim($title)==='' || $start<=0 || $end<=$start || !self::department_exists($dept) || $rate<1 || $rate>100) {
            throw new \invalid_parameter_exception('Проверьте название, подразделение, даты и баллы за XP.');
        }
        $events=self::event_rules($mode,$learningsource,$rate);
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
        $rules=self::validated_rules($r->rulesjson);$rules['mode']=$mode;$rules['events']=$events;
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
        global $DB, $PAGE;
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
        // Fetch names only after the season and employment boundaries have filtered participants.
        $users = [];
        if ($records) {
            [$insql, $params] = $DB->get_in_or_equal(array_column($records, 'userid'), SQL_PARAMS_NAMED);
            $users = $DB->get_records_select('user', 'id ' . $insql, $params, '',
                'id,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename,email,picture,imagealt');
        }
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
            $avatarurl = '';
            if (isset($users[$record->userid]) && (int)$users[$record->userid]->picture > 0) {
                $picture = new \user_picture($users[$record->userid]);
                $picture->size = 100;
                $picture->alttext = false;
                $avatarurl = $picture->get_url($PAGE)->out(false);
            }
            $rows[] = [
                'participantid' => (int)$record->participantid, 'rank' => $rank, 'points' => $points,
                'displayname' => isset($users[$record->userid]) ? fullname($users[$record->userid]) : 'Удалённая учётная запись',
                'initials' => isset($users[$record->userid])
                    ? ui::initials($users[$record->userid]->firstname, $users[$record->userid]->lastname) : '?',
                'avatarurl' => $avatarurl,
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
        $events=$rules['events'] ?? [];
        if (!is_array($events) || count($events)!==1 || array_diff(array_keys($events),['game_mastery','route_completion','course_completion'])
                || !empty($rules['uscoin']['enabled'])) {
            throw new \moodle_exception('Правило сезона повреждено. Обратитесь к администратору.');
        }
        $rate=$events[array_key_first($events)]['pointsperxp'] ?? 0;
        if (!is_int($rate) || $rate<1 || $rate>100) { throw new \moodle_exception('Правило сезона повреждено.'); }
        return $rules;
    }

    private static function event_rules(string $mode,string $source,int $rate): array {
        if (!in_array($mode,['game','learning'],true) || !in_array($source,['route','course'],true)) {
            throw new \invalid_parameter_exception('Выберите игровой или обучающий сезон.');
        }
        $event=$mode==='game'?'game_mastery':($source==='course'?'course_completion':'route_completion');
        return [$event=>['pointsperxp'=>$rate]];
    }

    /** Same resolver as publication. Real names are visible only in the operator studio. */
    public static function audience_preview(string $department,int $actor): array {
        return self::audiences($actor)[$department]??[];
    }
    public static function audiences(int $actor): array {
        global $DB;
        self::require_operator($actor);$rows=[];
        foreach (self::department_options() as $d) { $rows[$d['id']]=[]; }
        foreach ($DB->get_records_select('user','deleted=0 AND suspended=0 AND id>1',[],'lastname,firstname,id','id,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename') as $u) {
            if (!accounts::participates((int)$u->id)) { continue; }
            $department=self::department_for_user((int)$u->id);
            if (isset($rows[$department])) { $rows[$department][]=['id'=>(int)$u->id,'name'=>fullname($u)]; }
        }
        return $rows;
    }

    public static function record_route_completion(int $cycleid): void {
        global $DB;
        $c=$DB->get_record('local_ustar_completion_cycle',['id'=>$cycleid,'status'=>'confirmed']);
        if (!$c) { return; }
        $frozen=$DB->get_record('local_ustar_reward_grants',['kind'=>'route','sourceid'=>(string)$c->id,'userid'=>$c->userid]);
        $xp=$frozen?(int)$frozen->xp:reward_control::amounts((int)$c->userid,'route',(string)$c->id,(int)$c->completedat)['xp'];
        self::record_learning((int)$c->userid,'route_completion',(string)$c->id,$xp,(int)$c->completedat);
    }
    public static function record_course_completion(int $userid,int $courseid): void {
        global $DB;
        $c=$DB->get_record('course_completions',['userid'=>$userid,'course'=>$courseid]);
        if (!$c || empty($c->timecompleted)) { return; }
        $frozen=$DB->get_record('local_ustar_reward_grants',['eventkey'=>'learning-course:'.$c->id]);
        $xp=$frozen?(int)$frozen->xp:reward_control::amounts($userid,'course',(string)$c->id,(int)$c->timecompleted)['xp'];
        self::record_learning($userid,'course_completion',(string)$c->id,$xp,(int)$c->timecompleted);
    }
    private static function record_learning(int $userid,string $event,string $source,int $xp,int $at): void {
        global $DB;
        if (!self::available() || !accounts::participates($userid) || $xp<=0 || view_as::active()) { return; }
        foreach ($DB->get_records_select('local_ustar_competitions','status=:s AND startat<=:a AND endat>=:b',
                ['s'=>'published','a'=>$at,'b'=>$at]) as $season) {
            $tx=$DB->start_delegated_transaction();
            try {
                $season=$DB->get_record_sql('SELECT * FROM {local_ustar_competitions} WHERE id=:id FOR UPDATE',['id'=>$season->id],MUST_EXIST);
                $participant=$DB->get_record('local_ustar_comp_participants',['competitionid'=>$season->id,'userid'=>$userid,'status'=>'active']);
                $rule=$season->activeversionid?$DB->get_record('local_ustar_comp_rules',['id'=>$season->activeversionid]):null;
                $rules=$rule?self::validated_rules($rule->rulesjson):[];
                $key='competition:'.$season->id.':'.$event.':'.$source;
                if ($season->status==='published' && $participant && isset($rules['events'][$event]) && $at>=(int)$rule->timemodified
                        && !$DB->record_exists('local_ustar_comp_score_events',['idempotencykey'=>$key])) {
                    $DB->insert_record('local_ustar_comp_score_events',(object)['competitionid'=>$season->id,'participantid'=>$participant->id,
                        'ruleversionid'=>$rule->id,'eventtype'=>$event,'points'=>$xp*$rules['events'][$event]['pointsperxp'],
                        'sourcekind'=>$event,'sourceid'=>$source,'idempotencykey'=>$key,'occurredat'=>$at,'timecreated'=>time()]);
                }
                $tx->allow_commit();
            } catch (\Throwable $e) { $tx->rollback($e); }
        }
    }

    /** A durable retry scans only completions within a published season, in bounded batches. */
    public static function reconcile_learning(int $limit=200,int $onlyid=0,bool $complete=false): void {
        global $DB;
        if (!self::available()) { return; }
        foreach ($DB->get_records('local_ustar_competitions',['status'=>'published']+($onlyid?['id'=>$onlyid]:[])) as $s) {
            $r=$DB->get_record('local_ustar_comp_rules',['id'=>$s->activeversionid]);
            if (!$r) { continue; }
            $event=array_key_first(self::validated_rules($r->rulesjson)['events']);
            if ($event==='game_mastery') { continue; }
            $name='competition_cursor_'.$s->id;
            $cursor=$complete?0:(int)get_config('local_ustar',$name);$start=max((int)$s->startat,(int)$r->timemodified);
            $table=$event==='route_completion'?'local_ustar_completion_cycle':'course_completions';
            $date=$event==='route_completion'?'completedat':'timecompleted';
            do {
            $rows=$DB->get_records_select($table,'id>:i AND '.$date.'>=:a AND '.$date.'<=:b',
                ['i'=>$cursor,'a'=>$start,'b'=>$s->endat],'id','*',0,max(1,min(500,$limit)));
            foreach ($rows as $c) {
                if ($event==='route_completion') { self::record_route_completion((int)$c->id); }
                else { self::record_course_completion((int)$c->userid,(int)$c->course); }
                $cursor=(int)$c->id;
            }
            } while ($complete && count($rows)===$limit);
            // Rescan when caught up: out-of-order completion delivery keeps its original timestamp.
            set_config($name,count($rows)<$limit?0:$cursor,'local_ustar');
        }
    }

    private static function matches_audience(int $userid, \stdClass $competition): bool {
        return (string)$competition->audiencekind === 'department'
            && self::department_for_user($userid) === (string)$competition->audiencevalue;
    }

    private static function department_for_user(int $userid): string {
        $identity=organization_identity::resolve($userid);
        return $identity['conflicts'] ? '' : (string)$identity['departmentid'];
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
