<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

/** Versioned reward policy, immutable grants and non-destructive personal resets. */
final class reward_control {
    public static function can_manage(int $actor): bool {
        global $DB;
        $u = $DB->get_record('user', ['id'=>$actor,'deleted'=>0,'suspended'=>0], 'id,username');
        return $u && $u->username === 'emheadabdurahman'
            && (is_siteadmin($actor) || has_capability('local/ustar:admin', \context_system::instance(), $actor));
    }
    public static function assert_manager(int $actor): void {
        global $USER;
        view_as::assert_writable();
        if ($actor !== (int)$USER->id || !self::can_manage($actor)) {
            throw new \required_capability_exception(\context_system::instance(), 'local/ustar:admin', 'nopermissions', '');
        }
    }
    public static function defaults(): array {
        return ['course'=>['xp'=>100,'coins'=>0], 'activity'=>['xp'=>10,'coins'=>0],
            'route'=>['xp'=>10,'coins'=>1], 'game'=>['xp'=>-1,'coins'=>0],
            'task'=>['xp'=>0,'coins'=>0], 'checklist'=>['xp'=>0,'coins'=>0]];
    }
    public static function versions(): array {
        return json_decode((string)get_config('local_ustar','reward_rules_v1'), true) ?: [];
    }
    public static function rules(?int $at = null): array {
        $rules = self::defaults();
        foreach (self::versions() as $v) { if ($v['at'] <= ($at ?? time())) { $rules = $v['rules']; } }
        return $rules;
    }
    public static function save(int $actor, array $input, string $reason, string $expected): void {
        self::assert_manager($actor);
        if (trim($reason) === '') { throw new \invalid_parameter_exception('Укажите причину изменения правил.'); }
        $rules = self::defaults();
        foreach ($rules as $kind => &$rule) {
            foreach (['xp','coins'] as $unit) {
                $v = $input[$kind][$unit] ?? null;
                if (!is_int($v) || $v < ($kind==='game' && $unit==='xp' ? -1 : 0) || $v > 10000) {
                    throw new \invalid_parameter_exception('Награда должна быть целым числом от 0 до 10000. Для XP игры −1 означает настройку вопроса.');
                }
                if (in_array($kind,['course','activity','game'],true) && $unit==='coins' && $v!==0) {
                    throw new \invalid_parameter_exception('USCOIN начисляется за подтверждённую точку маршрута или принятую рабочую задачу.');
                }
                $rule[$unit] = $v;
            }
        }
        unset($rule);
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')->get_lock('reward-rules',10);
        if (!$lock) { throw new \moodle_exception('Правила сейчас редактируются.'); }
        try {
            $versions = self::versions();
            if (!hash_equals(hash('sha256',json_encode($versions)), $expected)) { throw new \moodle_exception('Правила изменились. Обновите страницу.'); }
            // Effective next second: existing same-second completions keep their old rule.
            $at = max(time()+1, (int)(end($versions)['at'] ?? 0)+1);
            $conditions=end($versions)['conditions']??[];
            $versions[] = ['at'=>$at,'rules'=>$rules,'conditions'=>$conditions,'actor'=>$actor,'reason'=>$reason];
            if (count($versions)>1000) { throw new \moodle_exception('Достигнут лимит версий правил.'); }
            set_config('reward_rules_v1',json_encode($versions,JSON_UNESCAPED_UNICODE),'local_ustar');
        } finally { $lock->release(); }
    }
    public static function conditions(?int $at=null): array {
        $conditions=[];
        foreach (self::versions() as $v) { if ($v['at']<=($at??time())) { $conditions=$v['conditions']??[]; } }
        return $conditions;
    }
    public static function amounts(int $userid,string $kind,string $source,int $at): array {
        $rule=self::rules($at)[$kind]??null;
        if (!$rule) { throw new \invalid_parameter_exception('Неизвестное событие награды.'); }
        foreach (self::conditions($at) as $condition) {
            if (reward_conditions::matches($condition,$userid,$kind,$source,$at)) {
                $rule=['xp'=>$condition['xp'],'coins'=>$condition['coins']];
            }
        }
        return $rule;
    }
    public static function save_condition(int $actor,?array $input,string $disable,string $reason,string $expected): void {
        self::assert_manager($actor);
        if (trim($reason)==='') { throw new \invalid_parameter_exception('Укажите причину изменения.'); }
        $condition=$input!==null?reward_conditions::validate($input):null;
        $lock=\core\lock\lock_config::get_lock_factory('local_ustar')->get_lock('reward-rules',10);
        if (!$lock) { throw new \moodle_exception('Правила сейчас редактируются.'); }
        try {
            $versions=self::versions();
            if (!hash_equals(hash('sha256',json_encode($versions)),$expected)) { throw new \moodle_exception('Правила изменились. Обновите страницу.'); }
            $latest=end($versions);$conditions=$latest['conditions']??[];
            if ($condition) { $conditions[]=$condition; }
            else {
                $found=false;
                foreach ($conditions as &$c) { if ($c['id']===$disable) { $c['active']=false;$found=true; } }
                unset($c);
                if (!$found) { throw new \invalid_parameter_exception('Условие не найдено.'); }
            }
            if (count($conditions)>100 || count($versions)>=1000) { throw new \invalid_parameter_exception('Достигнут лимит версий или условий.'); }
            $at=max(time()+1,(int)($latest['at']??0)+1);
            $versions[]=['at'=>$at,'rules'=>$latest['rules']??self::defaults(),'conditions'=>$conditions,'actor'=>$actor,'reason'=>$reason];
            set_config('reward_rules_v1',json_encode($versions,JSON_UNESCAPED_UNICODE),'local_ustar');
        } finally { $lock->release(); }
    }

    /** Freeze Moodle completion XP in the existing immutable grant store. */
    public static function course_completion(int $userid,int $courseid): void {
        global $DB;
        $c=$DB->get_record('course_completions',['userid'=>$userid,'course'=>$courseid]);
        if (!$c || empty($c->timecompleted) || (int)$c->timecompleted<(int)get_config('local_ustar','completion_reward_startedat')
                || !(int)get_config('local_ustar','completion_reward_startedat')) { return; }
        self::grant($userid,'course',(string)$c->id,(int)$c->timecompleted,'learning-course:'.$c->id);
    }
    public static function activity_completion(int $id): void {
        global $DB;
        $c=$DB->get_record('course_modules_completion',['id'=>$id]);
        if (!$c || !in_array((int)$c->completionstate,[1,2],true) || !(int)get_config('local_ustar','completion_reward_startedat')
                || (int)$c->timemodified<(int)get_config('local_ustar','completion_reward_startedat')) { return; }
        self::grant((int)$c->userid,'activity',(string)$c->id,(int)$c->timemodified,'learning-activity:'.$c->id);
    }
    public static function reconcile_learning(int $limit=200): void {
        global $DB;
        $since=(int)get_config('local_ustar','completion_reward_startedat');
        if (!$since) { return; }
        foreach (['course'=>['course_completions','timecompleted'],'activity'=>['course_modules_completion','timemodified']] as $kind=>[$table,$date]) {
            $name='reward_learning_cursor_'.$kind;$cursor=(int)get_config('local_ustar',$name);
            $rows=$DB->get_records_select($table,'id>:id AND '.$date.'>=:since',['id'=>$cursor,'since'=>$since],'id','*',0,$limit);
            foreach ($rows as $c) {
                if ($kind==='course') { self::course_completion((int)$c->userid,(int)$c->course); }
                else { self::activity_completion((int)$c->id); }
                $cursor=(int)$c->id;
            }
            set_config($name,count($rows)<$limit?0:$cursor,'local_ustar');
        }
    }

    public static function available(): bool {
        global $DB;
        return $DB->get_manager()->table_exists(new \xmldb_table('local_ustar_reward_grants'));
    }
    public static function grant(int $userid, string $kind, string $sourceid, int $at, string $key,
            string $sourcekind = '', string $comment = ''): bool {
        global $DB;
        if (!self::available() || !accounts::participates($userid)) { return false; }
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')->get_lock('reward-grant:'.sha1($key),10);
        if (!$lock) { throw new \moodle_exception('Не удалось сохранить награду.'); }
        try {
            if ($DB->record_exists('local_ustar_reward_grants',['eventkey'=>$key])) { return false; }
            $rule = self::amounts($userid,$kind,$sourceid,$at);
            if (!$rule || $rule['xp']<0) { throw new \invalid_parameter_exception('Неизвестное событие награды.'); }
            $tx = $DB->start_delegated_transaction();
            $DB->insert_record('local_ustar_reward_grants',(object)['userid'=>$userid,'kind'=>$kind,
                'sourceid'=>$sourceid,'eventkey'=>$key,'xp'=>$rule['xp'],'coins'=>$rule['coins'],'timecreated'=>$at]);
            if ($rule['coins']>0) {
                economy::post($userid,$rule['coins'],$kind==='route'?'route_reward':'work_reward',$key,
                    $sourcekind ?: $kind,$sourceid,$comment,0);
            }
            $tx->allow_commit(); return true;
        } finally { $lock->release(); }
    }
    public static function kpi(int $userid): int {
        global $DB;
        if (!task_workspace\service::available()) { return 0; }
        $since = (int)get_user_preferences('ustar_kpi_reset_at',0,$userid);
        return (int)$DB->get_field_sql("SELECT COALESCE(SUM(m.kpiweight),0)
            FROM {local_ustar_learning_tasks} t JOIN {local_ustar_task_meta} m ON m.taskid=t.id
            WHERE t.assigneeid=:u AND t.privacy='assigned' AND t.status='completed'
            AND t.dueat<=:now AND t.completedat>:since",['u'=>$userid,'now'=>time(),'since'=>$since]);
    }
    public static function xp(int $userid, ?array $courses = null): array {
        global $DB;
        $since = (int)get_user_preferences('ustar_xp_reset_at',0,$userid);
        $total = 0;
        foreach ($courses ?? external\base::user_courses($userid) as $course) {
            if (($course['progress'] ?? 0)<100) { continue; }
            $completion=$DB->get_record('course_completions',['userid'=>$userid,'course'=>$course['id']]);
            $at=(int)($completion->timecompleted??0);
            if ($completion && self::available() && $DB->record_exists('local_ustar_reward_grants',['eventkey'=>'learning-course:'.$completion->id])) { continue; }
            if (!$since || $at>$since) { $total += self::rules($at)['course']['xp']; }
        }
        foreach ($DB->get_records_select('course_modules_completion','userid=:u AND completionstate IN (1,2) AND timemodified>:s',
                ['u'=>$userid,'s'=>$since], '', 'id,timemodified') as $c) {
            if (self::available() && $DB->record_exists('local_ustar_reward_grants',['eventkey'=>'learning-activity:'.$c->id])) { continue; }
            $total += self::rules((int)$c->timemodified)['activity']['xp'];
        }
        $game = (int)$DB->get_field_sql('SELECT COALESCE(SUM(xpearned),0) FROM {local_ustar_game_mastery} WHERE userid=:u AND timecreated>:s',['u'=>$userid,'s'=>$since]);
        $total += $game;
        // Legacy route rewards retain the historical 10 XP; future grants freeze their own amounts.
        if (economy::available()) {
            $sql = "SELECT l.id,l.timecreated FROM {local_ustar_coin_ledger} l WHERE l.userid=:u AND l.txtype='route_reward'
                AND l.timecreated>:s AND NOT EXISTS (SELECT 1 FROM {local_ustar_coin_ledger} rev WHERE rev.reversalofid=l.id)";
            if (self::available()) { $sql .= ' AND NOT EXISTS (SELECT 1 FROM {local_ustar_reward_grants} g WHERE g.eventkey=l.idempotencykey)'; }
            $total += count($DB->get_records_sql($sql,['u'=>$userid,'s'=>$since]))*route_rewards::XP;
        }
        if (self::available()) {
            $total += (int)$DB->get_field_sql("SELECT COALESCE(SUM(g.xp),0) FROM {local_ustar_reward_grants} g
                WHERE g.userid=:u AND g.timecreated>:s AND (g.kind<>'route' OR EXISTS
                (SELECT 1 FROM {local_ustar_completion_cycle} c WHERE c.id=".$DB->sql_cast_char2int("CASE WHEN g.kind='route' THEN g.sourceid ELSE '0' END")." AND c.status='confirmed'))",
                ['u'=>$userid,'s'=>$since]);
        }
        return ['xp'=>$total,'gamexp'=>$game];
    }
    public static function preview(int $actor, int $userid): array {
        global $DB;
        self::assert_manager($actor);
        $u = $DB->get_record('user',['id'=>$userid,'deleted'=>0,'suspended'=>0],'*',MUST_EXIST);
        if (!accounts::is_business_account($userid) || $userid===$actor || is_siteadmin($userid)) {
            throw new \invalid_parameter_exception('Выберите действующую учётную запись сотрудника.');
        }
        return ['userid'=>$userid,'fullname'=>fullname($u),'xp'=>self::xp($userid)['xp'],
            'coins'=>economy::balance($userid),'kpi'=>self::kpi($userid)];
    }
    public static function reset(int $actor, int $userid, array $units, string $reason, array $expected, string $token): void {
        global $DB;
        self::assert_manager($actor);
        if (!$units || array_diff($units,['xp','coins','kpi']) || trim($reason)==='' || !preg_match('/^[a-f0-9]{32}$/',$token)) {
            throw new \invalid_parameter_exception('Выберите показатели и укажите причину сброса.');
        }
        $lock=\core\lock\lock_config::get_lock_factory('local_ustar')->get_lock('reward-reset:'.$userid,10);
        if (!$lock) { throw new \moodle_exception('Показатели сейчас изменяются.'); }
        try {
            if ($DB->record_exists('local_ustar_reward_resets',['token'=>$token])) { return; }
            $before=self::preview($actor,$userid);
            foreach ($units as $unit) { if ((int)($expected[$unit]??-1)!==$before[$unit]) { throw new \moodle_exception('Показатели изменились. Повторите предварительный просмотр.'); } }
            $tx=$DB->start_delegated_transaction();
            if (in_array('coins',$units,true) && $before['coins']>0) {
                economy::spend($userid,$before['coins'],'admin_reset','reward-reset:'.$token,'reset',$token,$reason,$actor,$before['coins']);
            }
            $now=time();
            if (in_array('xp',$units,true)) { set_user_preference('ustar_xp_reset_at',$now,$userid); }
            if (in_array('kpi',$units,true)) { set_user_preference('ustar_kpi_reset_at',$now,$userid); }
            $DB->insert_record('local_ustar_reward_resets',(object)['userid'=>$userid,'actorid'=>$actor,'token'=>$token,
                'detailsjson'=>json_encode(['units'=>$units,'before'=>$before,'reason'=>$reason],JSON_UNESCAPED_UNICODE),'timecreated'=>$now]);
            $tx->allow_commit();
        } finally { $lock->release(); }
    }
}
