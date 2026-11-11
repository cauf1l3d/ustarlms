<?php
namespace local_ustar;

#[\PHPUnit\Framework\Attributes\CoversClass(scorm_route_launch::class)]
final class scorm_route_launch_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
    }

    public function test_course_resumes_the_first_unfinished_scorm_in_section_order(): void {
        global $CFG, $DB;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $generator->create_module('forum', ['course' => $course->id, 'section' => 0]);
        $first = $generator->create_module('scorm', ['course' => $course->id,
            'section' => 1, 'completion' => 1]);
        $second = $generator->create_module('scorm', ['course' => $course->id,
            'section' => 2, 'completion' => 1]);
        $learner = $generator->create_and_enrol($course, 'student');
        $this->setUser($learner);
        $this->assertSame((int)$first->cmid, scorm_route_launch::course_cmid((int)$course->id, (int)$learner->id));
        $completion = new \completion_info($course);
        $cm = get_coursemodule_from_id('scorm', $first->cmid);
        $completion->update_state($cm, COMPLETION_COMPLETE, $learner->id);
        $this->assertSame((int)$second->cmid, scorm_route_launch::course_cmid((int)$course->id, (int)$learner->id));
        $completion->update_state(get_coursemodule_from_id('scorm', $second->cmid), COMPLETION_COMPLETE, $learner->id);
        $this->assertSame((int)$first->cmid, scorm_route_launch::course_cmid((int)$course->id, (int)$learner->id));
        $this->assertSame(0, $DB->count_records('scorm_attempt', ['userid' => $learner->id]));
    }

    public function test_a_hidden_scorm_is_not_selected_and_non_scorm_courses_keep_their_fallback(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $generator->create_module('scorm', ['course' => $course->id, 'visible' => 0]);
        $learner = $generator->create_and_enrol($course, 'student');
        $this->setUser($learner);
        $this->assertSame(0, scorm_route_launch::course_cmid((int)$course->id, (int)$learner->id));
        $this->setAdminUser();
        $plain = $generator->create_course();
        $generator->create_module('page', ['course' => $plain->id]);
        $this->assertSame(0, scorm_route_launch::course_cmid((int)$plain->id, (int)$learner->id));
    }

    public function test_player_url_uses_native_sco_without_resetting_attempt_or_resume_data(): void {
        global $CFG, $DB;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $scorm = $generator->create_module('scorm', ['course' => $course->id,
            'forcenewattempt' => 0, 'skipview' => 0]);
        $learner = $generator->create_and_enrol($course, 'student');
        $this->setUser($learner);
        $sco = $DB->get_record_select('scorm_scoes', 'scorm = :id AND launch <> :empty',
            ['id' => $scorm->id, 'empty' => ''], '*', MUST_EXIST);
        scorm_insert_track($learner->id, $scorm->id, $sco->id, 1, 'cmi.core.lesson_status', 'incomplete');
        scorm_insert_track($learner->id, $scorm->id, $sco->id, 1, 'cmi.core.lesson_location', 'page-3');
        $before = $DB->get_records('scorm_scoes_value', [], 'id');
        $cm = get_coursemodule_from_id('scorm', $scorm->cmid);
        $url = scorm_route_launch::player_url($cm, $learner);
        $basepath = parse_url($CFG->wwwroot, PHP_URL_PATH) ?? '';
        $this->assertSame($basepath . '/mod/scorm/player.php', $url->get_path());
        $this->assertSame((int)$scorm->cmid, (int)$url->get_param('cm'));
        $this->assertSame((int)$sco->id, (int)$url->get_param('scoid'));
        $this->assertEquals($before, $DB->get_records('scorm_scoes_value', [], 'id'));
        $this->assertSame(1, $DB->count_records('scorm_attempt', ['userid' => $learner->id]));
    }

    public function test_enrolled_user_without_a_learner_role_gets_native_tracking_and_keeps_enrolment_dates(): void {
        global $CFG, $DB;
        require_once($CFG->libdir.'/enrollib.php');
        $gen=$this->getDataGenerator();
        $course=$gen->create_course(['enablecompletion'=>1]);
        $scorm=$gen->create_module('scorm',['course'=>$course->id,'completion'=>2,'completionstatusrequired'=>6]);
        $learner=$gen->create_user();
        $instances=array_filter(enrol_get_instances($course->id,false),static fn($i)=>$i->enrol==='manual');
        $manual=enrol_get_plugin('manual');
        $instance=reset($instances);
        $start=time()-100;$end=time()+86400;
        $manual->enrol_user($instance,$learner->id,0,$start,$end,ENROL_USER_ACTIVE);
        $this->setUser($learner);
        $cm=get_coursemodule_from_id('scorm',$scorm->cmid);
        $context=\context_module::instance($cm->id);
        $this->assertFalse(has_capability('mod/scorm:savetrack',$context));
        $url=scorm_route_launch::player_url($cm,$learner);
        $this->assertSame((int)$cm->id,(int)$url->get_param('cm'));
        $this->assertTrue(has_capability('mod/scorm:savetrack',$context));
        $before=$DB->get_record('user_enrolments',['enrolid'=>$instance->id,'userid'=>$learner->id]);
        $this->assertSame($start,(int)$before->timestart);
        $this->assertSame($end,(int)$before->timeend);
        scorm_route_launch::ensure_tracking($cm,$learner);
        $this->assertEquals($before,$DB->get_record('user_enrolments',['id'=>$before->id]));
        $this->assertSame(0,$DB->count_records('scorm_attempt',['userid'=>$learner->id]));
        $sco=$DB->get_record_select('scorm_scoes','scorm=:id AND launch<>:empty',
            ['id'=>$scorm->id,'empty'=>''],'*',MUST_EXIST);
        scorm_insert_track($learner->id,$scorm->id,$sco->id,1,'cmi.core.lesson_status','completed');
        (new \completion_info($course))->update_state($cm,COMPLETION_UNKNOWN,$learner->id);
        $this->assertContains((int)$DB->get_field('course_modules_completion','completionstate',
            ['coursemoduleid'=>$cm->id,'userid'=>$learner->id]),[1,2]);
    }

    public function test_explicit_tracking_denials_do_not_add_student_role(): void {
        global $CFG,$DB;
        require_once($CFG->libdir.'/enrollib.php');
        $gen=$this->getDataGenerator();$course=$gen->create_course();
        $scorm=$gen->create_module('scorm',['course'=>$course->id]);
        $learner=$gen->create_user();
        $instances=array_filter(enrol_get_instances($course->id,false),static fn($i)=>$i->enrol==='manual');$instance=reset($instances);
        enrol_get_plugin('manual')->enrol_user($instance,$learner->id,0);
        $context=\context_module::instance($scorm->cmid);
        $role=create_role('Restricted learner','scorm_restricted_fixture','');
        role_assign($role,$learner->id,$context->id);
        foreach ([CAP_PREVENT,CAP_PROHIBIT] as $permission) {
            $this->setAdminUser();assign_capability('mod/scorm:savetrack',$permission,$role,$context->id,true);
            accesslib_clear_all_caches(true);$this->setUser($learner);
            $before=$DB->get_records('role_assignments',['userid'=>$learner->id],'id');
            try {
                scorm_route_launch::ensure_tracking(get_coursemodule_from_id('scorm',$scorm->cmid),$learner);
                $this->fail('A configured tracking denial must be preserved');
            } catch (\moodle_exception $expected) {
                $this->assertEquals($before,$DB->get_records('role_assignments',['userid'=>$learner->id],'id'));
                $this->assertFalse(has_capability('mod/scorm:savetrack',$context));
            }
        }
    }
}
