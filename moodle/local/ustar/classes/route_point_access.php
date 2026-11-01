<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Versioned review access. Learning facts and rewards remain in the existing completion model. */
final class route_point_access {
    public static function normalize(array $input): array {
        $policy = $input + ['advance'=>'auto','repeat'=>'close','seconds'=>0,'finish'=>'next'];
        if (!in_array($policy['advance'], ['auto','manual','timed'], true)
                || !in_array($policy['repeat'], ['close','keep'], true)
                || !in_array($policy['finish'], ['route','next','stay'], true)) {
            throw new \invalid_parameter_exception('Неизвестное правило доступа или перехода точки.');
        }
        $seconds = (int)$policy['seconds'];
        if ($policy['advance'] === 'timed' && ($seconds < 60 || $seconds > 3650 * 86400)) {
            throw new \invalid_parameter_exception('Укажите срок повторения от минуты до 3650 дней.');
        }
        return ['advance'=>$policy['advance'],'repeat'=>$policy['repeat'],
            'seconds'=>$policy['advance'] === 'timed' ? $seconds : 0,'finish'=>$policy['finish']];
    }

    public static function policy(\stdClass $version): array {
        $raw = trim((string)($version->accesspolicyjson ?? ''));
        $input = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($input)) { throw new \invalid_parameter_exception('Повреждены правила доступа точки.'); }
        return self::normalize($input);
    }

    public static function description(\stdClass $version): string {
        $p = self::policy($version);
        $advance = ['auto'=>'Сразу после изучения','manual'=>'По кнопке «Далее»',
            'timed'=>'По кнопке «Далее» или через '.($p['seconds']/3600).' ч'];
        $finish = ['route'=>'В маршрут','next'=>'Следующий доступный шаг','stay'=>'Остаться в материале'];
        return $advance[$p['advance']].' · '.($p['repeat'] === 'keep' ? 'Повторение доступно' : 'Закрыть повторение')
            .' · '.$finish[$p['finish']];
    }

    /** Pure server-clock projection, also used on read-only pages. */
    public static function window_state(array $policy, ?\stdClass $window, int $now): array {
        $closed = $policy['advance'] === 'auto' || ($window && (int)$window->closedat > 0)
            || ($policy['advance'] === 'timed' && $window && (int)$window->deadline > 0 && (int)$window->deadline <= $now);
        return ['held'=>!$closed, 'id'=>(int)($window->id ?? 0),
            'deadline'=>(int)($window->deadline ?? 0), 'closesaccess'=>$policy['repeat'] === 'close'];
    }

    /** Do not make a non-SCORM point hold on a policy it cannot display or reopen. */
    public static function validate_for_requirements(array $policy, array $requirements): void {
        global $DB;
        if ($policy === self::normalize([])) { return; }
        foreach ($requirements as $r) {
            if (($r['type'] ?? '') === 'cm') {
                $cm = get_coursemodule_from_id(null, (int)$r['sourceid'], 0, false, IGNORE_MISSING);
                if ($cm && $cm->modname === 'scorm') { return; }
            } else if (($r['type'] ?? '') === 'course' && $DB->record_exists_sql(
                    'SELECT 1 FROM {course_modules} cm JOIN {modules} m ON m.id=cm.module
                      WHERE cm.course=:course AND cm.deletioninprogress=0 AND m.name=:module',
                    ['course'=>(int)$r['sourceid'],'module'=>'scorm'])) { return; }
        }
        throw new \invalid_parameter_exception('Правила повторного доступа применяются к точке со SCORM-курсом или SCORM-активностью.');
    }

    /** All SCORM resources in their published requirement order. */
    public static function review_links(\stdClass $version): array {
        $links = [];
        foreach (route_model::requirements_for_version($version) as $r) {
            $params = ['pointid'=>(int)$version->pointid,'versionid'=>(int)$version->id];
            if ($r['type'] === 'course') {
                $path = '/local/ustar/course_launch.php';
                $params['courseid'] = (int)$r['sourceid'];
            } else if ($r['type'] === 'cm') {
                $cm = get_coursemodule_from_id(null, (int)$r['sourceid'], 0, false, IGNORE_MISSING);
                if (!$cm || $cm->modname !== 'scorm') { continue; }
                $path = '/local/ustar/scorm_launch.php';
                $params['cmid'] = (int)$r['sourceid'];
            } else { continue; }
            $links[] = ['url'=>(new \moodle_url($path,$params))->out(false),
                'label'=>(string)($r['label'] ?? 'Повторить материал')];
        }
        return $links;
    }

    /** Hold advancement after verified completion, never manufacture a learning fact. */
    public static function project(int $userid, \stdClass $point, \stdClass $version, array $fact, bool $persist): array {
        global $DB;
        $policy = self::policy($version);
        $fact['studied'] = !empty($fact['satisfied']);
        $fact['reviewing'] = false;
        $fact['accessid'] = 0;
        $fact['accessdeadline'] = 0;
        $fact['closesaccess'] = $policy['repeat'] === 'close';
        if (empty($fact['satisfied'])) { return $fact; }
        $completedat = (int)($fact['completedat'] ?? 0);
        $inherited = !empty($fact['inherited']);
        if (!$inherited && $policy['advance'] !== 'auto') {
            $stored = $DB->get_record('local_ustar_route_progress',[
                'userid'=>$userid,'pointid'=>(int)$point->id,'versionid'=>(int)$version->id]);
            $evidence = $stored ? json_decode($stored->evidencejson,true) : [];
            $inherited = ($evidence['mode'] ?? '') === 'inherited';
        }
        // Publishing keep-policy settings must not send employees back to points they already left.
        if ($policy['advance'] !== 'auto' && $inherited
                && !$DB->record_exists('local_ustar_route_access',['userid'=>$userid,'pointid'=>(int)$point->id,
                    'versionid'=>(int)$version->id,'studyat'=>$completedat])
                && !$DB->record_exists_select('local_ustar_route_access',
                'userid=:userid AND pointid=:pointid AND versionid<>:versionid AND closedat=0 AND (deadline=0 OR deadline>:now)',
                ['userid'=>$userid,'pointid'=>(int)$point->id,'versionid'=>(int)$version->id,'now'=>time()])) {
            if ($policy['repeat'] === 'keep') {
                $links = self::review_links($version); $fact['launchurl'] = (string)($links[0]['url'] ?? '');
            }
            return $fact;
        }
        $window = null;
        if ($policy['advance'] !== 'auto' && $completedat > 0) {
            $key = ['userid'=>$userid,'pointid'=>(int)$point->id,'versionid'=>(int)$version->id,'studyat'=>$completedat];
            if ($persist) {
                view_as::assert_writable();
                $lock = self::lock($userid);
                try {
                    $tx = $DB->start_delegated_transaction();
                    $window = $DB->get_record('local_ustar_route_access',$key);
                    if (!$window) {
                        $now = time();
                        $id = $DB->insert_record('local_ustar_route_access',(object)($key + [
                            'openedat'=>$now,'deadline'=>$policy['advance'] === 'timed' ? $now+$policy['seconds'] : 0,
                            'closedat'=>0,'closereason'=>'','timecreated'=>$now,'timemodified'=>$now]));
                        $window = $DB->get_record('local_ustar_route_access',['id'=>$id],'*',MUST_EXIST);
                    }
                    if ($policy['advance'] === 'timed' && (int)$window->deadline <= time() && !(int)$window->closedat) {
                        self::close($window,'deadline',0);
                    }
                    $tx->allow_commit();
                } catch (\Throwable $e) { $tx->rollback($e); } finally { $lock->release(); }
            } else { $window = $DB->get_record('local_ustar_route_access',$key) ?: null; }
        }
        $state = self::window_state($policy,$window,time());
        $fact['reviewing'] = $state['held'];
        $fact['accessid'] = $state['id'];
        $fact['accessdeadline'] = $state['deadline'];
        if ($state['held']) {
            $fact['satisfied'] = false;
            $links = self::review_links($version);
            $fact['launchurl'] = (string)($links[0]['url'] ?? '');
            $fact['reviewlinks'] = $links;
        } else if ($policy['repeat'] === 'keep') {
            $links = self::review_links($version);
            $fact['launchurl'] = (string)($links[0]['url'] ?? '');
            $fact['reviewlinks'] = $links;
        }
        return $fact;
    }

    private static function lock(int $userid): \core\lock\lock {
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar_routes')->get_lock('review:'.$userid,10);
        if (!$lock) { throw new \moodle_exception('Переход уже выполняется. Повторите попытку.'); }
        return $lock;
    }

    /** Caller holds the user access lock and transaction. */
    private static function close(\stdClass $window, string $reason, int $actorid): void {
        global $DB;
        if ((int)$window->closedat) { return; }
        $window->closedat = time();
        $window->closereason = $reason;
        $window->timemodified = time();
        $DB->update_record('local_ustar_route_access',$window);
        $DB->insert_record('local_ustar_workflow_events',(object)[
            'entitytype'=>'route_review_access','entityid'=>$window->id,'eventtype'=>'review_closed',
            'actorid'=>$actorid,'reason'=>$reason,'timecreated'=>time(),
            'detailsjson'=>json_encode(['userid'=>(int)$window->userid,'pointid'=>(int)$window->pointid,
                'versionid'=>(int)$window->versionid,'studyat'=>(int)$window->studyat])]);
    }

    /** Close obsolete/expired windows too; this does not complete or pass any learning requirement. */
    public static function expire(int $userid): void {
        global $DB;
        $lock = self::lock($userid);
        $tx = null;
        try {
            $tx = $DB->start_delegated_transaction();
            foreach ($DB->get_records_select('local_ustar_route_access',
                    'userid=:userid AND closedat=0 AND deadline>0 AND deadline<=:now',
                    ['userid'=>$userid,'now'=>time()]) as $window) { self::close($window,'deadline',0); }
            $tx->allow_commit();
        } catch (\Throwable $e) { if ($tx) { $tx->rollback($e); } throw $e;
        } finally { $lock->release(); }
    }

    /** Exact own-user, current-version, verified-window command. Replays are harmless. */
    public static function advance(int $userid, int $pointid, int $versionid, int $windowid): void {
        global $DB,$USER;
        view_as::assert_writable();
        if ((int)($USER->id ?? 0) !== $userid || !employment::learning_allowed($userid)
                || !has_capability('local/ustar:use',\context_system::instance(),$userid)) {
            throw new \required_capability_exception(\context_system::instance(),'local/ustar:use','nopermissions','');
        }
        $resolved = structure::resolve_user($userid);
        $position = (string)($resolved['position']['id'] ?? '');
        if ($position === '' || !empty(adaptation_service::route_card($userid,false)['blocked'])) {
            throw new \moodle_exception('Сначала выполните текущие обязательные задания.');
        }
        $lock = self::lock($userid);
        try {
            $tx = $DB->start_delegated_transaction();
            $version = route_model::current_published_version($pointid);
            if (!$version || (int)$version->id !== $versionid) { throw new \moodle_exception('Версия точки изменилась. Обновите маршрут.'); }
            $window = $DB->get_record('local_ustar_route_access',[
                'id'=>$windowid,'userid'=>$userid,'pointid'=>$pointid,'versionid'=>$versionid],'*',MUST_EXIST);
            $progress = $DB->get_record('local_ustar_route_progress',[
                'userid'=>$userid,'pointid'=>$pointid,'versionid'=>$versionid,'status'=>'complete'],'*',MUST_EXIST);
            if ((int)$progress->completedat !== (int)$window->studyat
                    || (!empty($progress->expiresat) && (int)$progress->expiresat < time())) {
                throw new \moodle_exception('Результат точки изменился. Обновите маршрут.');
            }
            $snapshot = route_model::read_only_snapshot($position,$userid);
            $reachable = false;
            foreach ($snapshot['points'] ?? [] as $row) {
                if ((int)$row['id'] === $pointid && empty($row['locked'])) { $reachable = true; }
            }
            if (!$reachable) { throw new \moodle_exception('Точка недоступна в вашем маршруте.'); }
            self::close($window,'manual',$userid);
            $tx->allow_commit();
        } catch (\Throwable $e) { $tx->rollback($e); } finally { $lock->release(); }
    }

    /** Native player/file access must agree with published route windows; null means not managed. */
    public static function allowed_cmid(int $userid, int $cmid): ?bool {
        global $DB;
        $resolved = structure::resolve_user($userid);
        $position = (string)($resolved['position']['id'] ?? '');
        if ($position === '') { return null; }
        $cm = get_coursemodule_from_id('scorm',$cmid,0,false,IGNORE_MISSING);
        if (!$cm) { return null; }
        $snapshot = route_model::read_only_snapshot($position,$userid);
        $managed = false;
        $allowed = false;
        foreach ($snapshot['points'] ?? [] as $point) {
            $version = route_model::current_published_version((int)$point['id']);
            if (!$version || !self::contains($version,$cmid,(int)$cm->course)) { continue; }
            $custom = trim((string)($version->accesspolicyjson ?? '')) !== '';
            $managed = $managed || $custom;
            if (empty($point['locked']) && ($point['current'] || !$custom || self::policy($version)['repeat'] === 'keep')) {
                $allowed = true;
            }
        }
        // A saved old URL cannot revive an access window from an obsolete publication.
        if (!$managed) {
            foreach ($DB->get_records_sql('SELECT DISTINCT v.* FROM {local_ustar_route_versions} v
                JOIN {local_ustar_route_access} a ON a.versionid=v.id WHERE a.userid=:userid',['userid'=>$userid]) as $old) {
                if (self::contains($old,$cmid,(int)$cm->course)) { $managed = true; break; }
            }
        }
        return $managed ? $allowed : null;
    }

    public static function contains(\stdClass $version, int $cmid, int $courseid): bool {
        foreach (route_model::requirements_for_version($version) as $r) {
            if (($r['type'] === 'cm' && (int)$r['sourceid'] === $cmid)
                    || ($r['type'] === 'course' && (int)$r['sourceid'] === $courseid)) { return true; }
        }
        return false;
    }
}
