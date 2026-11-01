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
        global $DB;
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
        global $DB;
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
        $this->assertSame('/mod/scorm/player.php', $url->get_path());
        $this->assertSame((int)$scorm->cmid, $url->get_param('cm'));
        $this->assertSame((int)$sco->id, $url->get_param('scoid'));
        $this->assertSame($before, $DB->get_records('scorm_scoes_value', [], 'id'));
        $this->assertSame(1, $DB->count_records('scorm_attempt', ['userid' => $learner->id]));
    }
}
