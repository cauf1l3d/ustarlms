<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(route_point_access::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(route_model::class)]
final class route_point_access_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
        accounts::ensure_profile_field();
    }

    private function fixture(array $policy): array {
        global $DB;
        $gen = $this->getDataGenerator();
        $g = $gen->get_plugin_generator('local_ustar');
        $course = $gen->create_course(['enablecompletion'=>1]);
        $scorm = $gen->create_module('scorm', ['course'=>$course->id,'completion'=>1]);
        $user = $gen->create_and_enrol($course, 'student');
        $position = 'review_fixture';
        $structure = structure::get(structure::NAME_STRUCTURE);
        $structure['positions'][] = ['id'=>$position,'name'=>'Review learner','department'=>'fixture','level'=>1];
        structure::save(structure::NAME_STRUCTURE, $structure);
        $field = $DB->get_record('user_info_field', ['shortname'=>'ustar_position']);
        if (!$field) { $field = $gen->create_custom_profile_field(['shortname'=>'ustar_position','name'=>'Position','datatype'=>'text']); }
        $DB->insert_record('user_info_data', (object)['userid'=>$user->id,'fieldid'=>$field->id,'data'=>$position,'dataformat'=>0]);
        $route = $g->create_route(['positionid'=>$position]);
        $point = $g->create_point($route);
        $version = $g->create_version($point, ['accesspolicyjson'=>json_encode($policy),
            'requirementsjson'=>json_encode([['type'=>'cm','sourceid'=>$scorm->cmid,'required'=>true]])]);
        $next = $g->create_point($route, ['sortorder'=>20]);
        $g->create_version($next, ['requirementsjson'=>json_encode([['type'=>'native','sourcekey'=>'fixture_next','required'=>true]])]);
        $this->setUser($user);
        return [$user,$position,$point,$version,$scorm,$next];
    }

    private function complete(\stdClass $user, \stdClass $scorm): void {
        $cm = get_coursemodule_from_id('scorm', $scorm->cmid);
        (new \completion_info(get_course($cm->course)))->update_state($cm, COMPLETION_COMPLETE, $user->id);
    }

    public function test_manual_review_has_one_credit_and_explicit_close_advances(): void {
        global $DB;
        [$u,$position,$point,$version,$scorm,$next] = $this->fixture(['advance'=>'manual','repeat'=>'close','finish'=>'route']);
        set_config('route_rewards_startedat',time()-10,'local_ustar');
        $before = route_model::for_user($position, $u->id);
        $this->assertSame((int)$point->id, $before['currentpoint']['id']);
        $this->assertSame(0, $DB->count_records('local_ustar_route_access'));
        $this->complete($u,$scorm);
        $review = route_model::for_user($position, $u->id);
        $this->assertTrue($review['currentpoint']['reviewing']);
        $this->assertSame((int)$point->id,$review['currentpoint']['id']);
        $this->assertSame(1,$DB->count_records('local_ustar_route_progress',['userid'=>$u->id]));
        $this->assertSame(1,$DB->count_records('local_ustar_completion_cycle',['userid'=>$u->id]));
        $this->assertSame(1,$DB->count_records('local_ustar_coin_ledger',['userid'=>$u->id]));
        $this->assertTrue(route_point_access::allowed_cmid($u->id,$scorm->cmid));
        $window = $review['currentpoint']['accessid'];
        route_model::for_user($position,$u->id);
        route_point_access::advance($u->id,$point->id,$version->id,$window);
        route_point_access::advance($u->id,$point->id,$version->id,$window);
        $after = route_model::for_user($position,$u->id);
        $this->assertSame((int)$next->id,$after['currentpoint']['id']);
        $this->assertFalse(route_point_access::allowed_cmid($u->id,$scorm->cmid));
        $this->assertSame(1,$DB->count_records('local_ustar_route_access'));
        $this->assertSame(1,$DB->count_records('local_ustar_completion_cycle',['userid'=>$u->id]));
        $this->assertSame(1,$DB->count_records('local_ustar_coin_ledger',['userid'=>$u->id]));
    }

    public function test_a_timer_never_bypasses_an_unfinished_required_task(): void {
        global $DB;
        [$u,$position,$point,$version,$scorm] = $this->fixture(['advance'=>'timed','seconds'=>60]);
        $requirements=json_decode($version->requirementsjson,true);
        $requirements[]=['type'=>'native','sourcekey'=>'fixture_required_task','required'=>true];
        $DB->set_field('local_ustar_route_versions','requirementsjson',json_encode($requirements),['id'=>$version->id]);
        $this->complete($u,$scorm);
        $route=route_model::for_user($position,$u->id);
        $this->assertSame((int)$point->id,$route['currentpoint']['id']);
        $this->assertFalse($route['currentpoint']['reviewing']);
        $this->assertSame(0,$DB->count_records('local_ustar_route_access'));
        $this->assertSame(0,$DB->count_records('local_ustar_route_progress'));
    }

    public function test_an_old_publication_cannot_close_the_new_point_window(): void {
        global $DB;
        [$u,$position,$point,$version,$scorm] = $this->fixture(['advance'=>'manual']);
        $this->complete($u,$scorm);
        $review=route_model::for_user($position,$u->id);
        $this->getDataGenerator()->get_plugin_generator('local_ustar')->create_version($point,[
            'versionno'=>2,'requirementsjson'=>$version->requirementsjson,'accesspolicyjson'=>$version->accesspolicyjson]);
        try {
            route_point_access::advance($u->id,$point->id,$version->id,$review['currentpoint']['accessid']);
            $this->fail('A stale publication must be rejected');
        } catch (\moodle_exception $e) {
            $this->assertSame(0,(int)$DB->get_field('local_ustar_route_access','closedat',['id'=>$review['currentpoint']['accessid']]));
        }
    }

    public function test_timer_reconciles_without_a_browser_and_preserves_completion(): void {
        global $DB;
        [$u,$position,$point,$version,$scorm,$next] = $this->fixture(['advance'=>'timed','seconds'=>3600,'repeat'=>'close','finish'=>'route']);
        $this->complete($u,$scorm);
        $review = route_model::for_user($position,$u->id);
        $row = $DB->get_record('local_ustar_route_access',['id'=>$review['currentpoint']['accessid']], '*', MUST_EXIST);
        $this->assertGreaterThanOrEqual(time()+3595,(int)$row->deadline);
        $DB->set_field('local_ustar_route_access','deadline',time()-1,['id'=>$row->id]);
        $progress = $DB->get_records('local_ustar_route_progress',['userid'=>$u->id]);
        $this->setAdminUser();
        (new \local_ustar\task\reconcile_route_access())->execute();
        $this->assertGreaterThan(0,(int)$DB->get_field('local_ustar_route_access','closedat',['id'=>$row->id]));
        $this->assertEquals($progress,$DB->get_records('local_ustar_route_progress',['userid'=>$u->id]));
        $this->assertSame((int)$next->id,route_model::read_only_snapshot($position,$u->id)['currentpoint']['id']);
    }

    public function test_repeat_keep_is_available_after_advancing(): void {
        [$u,$position,$point,$version,$scorm] = $this->fixture(['advance'=>'manual','repeat'=>'keep','finish'=>'route']);
        $this->complete($u,$scorm);
        $review=route_model::for_user($position,$u->id);
        route_point_access::advance($u->id,$point->id,$version->id,$review['currentpoint']['accessid']);
        $this->assertTrue(route_point_access::allowed_cmid($u->id,$scorm->cmid));
        $done=route_model::for_user($position,$u->id)['points'][0];
        $this->assertTrue($done['canlaunch']);
        $this->assertTrue($done['done']);
    }

    public function test_a_keep_policy_edit_does_not_relock_an_already_left_point(): void {
        [$u,$position,$point,$version,$scorm,$next] = $this->fixture([]);
        $this->complete($u,$scorm);
        $this->assertSame((int)$next->id,route_model::for_user($position,$u->id)['currentpoint']['id']);
        $this->getDataGenerator()->get_plugin_generator('local_ustar')->create_version($point,[
            'versionno'=>2,'requirementsjson'=>$version->requirementsjson,'accesspolicyjson'=>json_encode(['advance'=>'manual'])]);
        $this->assertSame((int)$next->id,route_model::for_user($position,$u->id)['currentpoint']['id']);
    }

    public function test_a_foreign_actor_cannot_close_another_window(): void {
        [$u,$position,$point,$version,$scorm] = $this->fixture(['advance'=>'manual']);
        $this->complete($u,$scorm);
        $review=route_model::for_user($position,$u->id);
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        route_point_access::advance($u->id,$point->id,$version->id,$review['currentpoint']['accessid']);
    }

    public function test_read_only_review_creates_no_window_or_completion(): void {
        global $DB;
        [$u,$position,$point,$version,$scorm] = $this->fixture(['advance'=>'manual']);
        $this->complete($u,$scorm);
        route_model::for_user($position,$u->id,true);
        $this->assertSame(0,$DB->count_records('local_ustar_route_access'));
        $this->assertSame(0,$DB->count_records('local_ustar_route_progress'));
    }

    public function test_only_proven_single_scorm_import_can_supply_course_evidence(): void {
        global $DB;
        [$u,$position,$point,$version,$scorm] = $this->fixture([]);
        $method=new \ReflectionMethod(route_model::class,'requirement_result');
        $req=['type'=>'course','sourceid'=>$scorm->course,'required'=>true];
        $this->complete($u,$scorm);
        $plain=$method->invoke(null,$req,$u->id,$position,[],$version);
        $this->assertFalse($plain['satisfied']);
        $DB->insert_record('local_ustar_workflow_events',(object)['entitytype'=>'studio_scorm_runtime','entityid'=>123,
            'eventtype'=>'scorm_runtime_created','actorid'=>0,'timecreated'=>time(),
            'detailsjson'=>json_encode(['sourceversion'=>1,'cmid'=>(int)$scorm->cmid,'courseid'=>(int)$scorm->course,'filename'=>'fixture.zip'])]);
        $managed=$method->invoke(null,$req,$u->id,$position,[],$version);
        $this->assertTrue($managed['satisfied']);
        $this->assertEmpty($DB->get_field('course_completions','timecompleted',['course'=>$scorm->course,'userid'=>$u->id]));
        $this->setAdminUser();
        $this->getDataGenerator()->create_module('page',['course'=>$scorm->course]);
        $extra=$method->invoke(null,$req,$u->id,$position,[],$version);
        $this->assertFalse($extra['satisfied']);
    }
}
