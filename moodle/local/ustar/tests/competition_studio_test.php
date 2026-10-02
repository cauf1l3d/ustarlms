<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();
#[\PHPUnit\Framework\Attributes\CoversClass(competition::class)]
final class competition_studio_test extends \advanced_testcase {
    protected function setUp(): void { parent::setUp();$this->resetAfterTest(true);$this->setAdminUser(); }
    public function test_draft_can_be_revised_then_a_stale_revision_is_rejected(): void {
        global $USER,$DB;
        $dept=competition::department_options()[0]['id'];
        $id=competition::create_draft('fixture','Original',$dept,time()+10,time()+100,1,$USER->id);
        $expected=(int)$DB->get_field('local_ustar_competitions','timemodified',['id'=>$id]);
        competition::revise_draft($id,$USER->id,$expected,'Revised',$dept,time()+20,time()+200,3);
        $season=competition::operator_season($id,$USER->id)['definition'];
        $this->assertSame('Revised',$season['title']);$this->assertSame(3,$season['pointsperxp']);
        try { competition::revise_draft($id,$USER->id,$expected,'Stale',$dept,time()+20,time()+200,2);$this->fail('Stale change accepted'); }
        catch (\moodle_exception $e) { $this->assertSame('Revised',$DB->get_field('local_ustar_competitions','title',['id'=>$id])); }
    }
    public function test_published_rule_is_not_editable(): void {
        global $USER,$DB;
        $dept=competition::department_options()[0]['id'];
        $id=competition::create_draft('fixture_locked','Original',$dept,time()+10,time()+100,1,$USER->id);
        $expected=(int)$DB->get_field('local_ustar_competitions','timemodified',['id'=>$id]);
        $DB->set_field('local_ustar_competitions','status','published',['id'=>$id]);
        $this->expectException(\moodle_exception::class);
        competition::revise_draft($id,$USER->id,$expected,'Changed',$dept,time()+20,time()+200,9);
    }
    public function test_real_audience_and_learning_season_exclude_games_and_old_completions(): void {
        global $USER,$DB;
        $u=$this->getDataGenerator()->create_user(['firstname'=>'Real','lastname'=>'Employee']);
        $place=$DB->insert_record('local_ustar_staff_places',(object)['placecode'=>'competition_employee','positionid'=>'retail_seller','departmentid'=>'retail']);
        $DB->insert_record('local_ustar_assignments',(object)['userid'=>$u->id,'staffplaceid'=>$place,'assignmenttype'=>'primary']);
        $rows=competition::audience_preview('retail',(int)$USER->id);
        $this->assertContains((int)$u->id,array_column($rows,'id'));$this->assertContains('Real Employee',array_column($rows,'name'));
        $this->assertNotContains((int)$u->id,array_column(competition::audience_preview('hr',(int)$USER->id),'id'));
        $id=competition::create_draft('learning_fixture','Learning','retail',time()-100,time()+100,2,(int)$USER->id,'learning','course');
        competition::publish($id,(int)$USER->id);
        competition::record_game_mastery($u->id,999,50,time());
        $this->assertSame(0,$DB->count_records('local_ustar_comp_score_events',['competitionid'=>$id]));
        $course=$this->getDataGenerator()->create_course(['enablecompletion'=>1]);
        $cid=$DB->insert_record('course_completions',(object)['userid'=>$u->id,'course'=>$course->id,'timecompleted'=>time()-50]);
        competition::record_course_completion($u->id,$course->id);
        $this->assertSame(0,$DB->count_records('local_ustar_comp_score_events',['competitionid'=>$id]));
        $DB->set_field('course_completions','timecompleted',time(),['id'=>$cid]);
        competition::record_course_completion($u->id,$course->id);competition::record_course_completion($u->id,$course->id);
        $this->assertSame(1,$DB->count_records('local_ustar_comp_score_events',['competitionid'=>$id]));
        $this->assertSame(200,(int)$DB->get_field('local_ustar_comp_score_events','points',['competitionid'=>$id]));
        $this->assertSame('Real Employee',competition::operator_season($id,(int)$USER->id)['scores'][0]['displayname']);
        $personal=competition::current_for_user($u->id);
        $this->assertArrayNotHasKey('userid',$personal['rows'][0]);
    }

    public function test_participants_see_real_names_in_existing_season_but_outsiders_and_inactive_accounts_do_not(): void {
        global $USER, $DB;
        $operator = (int)$USER->id;
        $first = $this->getDataGenerator()->create_user(['firstname' => 'First', 'lastname' => 'Seller']);
        $second = $this->getDataGenerator()->create_user(['firstname' => 'Second', 'lastname' => 'Seller']);
        $outsider = $this->getDataGenerator()->create_user(['firstname' => 'Outside', 'lastname' => 'Season']);
        foreach ([$first, $second] as $user) {
            $place = $DB->insert_record('local_ustar_staff_places', (object)[
                'placecode' => 'named_season_' . $user->id, 'positionid' => 'retail_seller', 'departmentid' => 'retail']);
            $DB->insert_record('local_ustar_assignments', (object)[
                'userid' => $user->id, 'staffplaceid' => $place, 'assignmenttype' => 'primary']);
        }
        $id = competition::create_draft('named_fixture', 'Named season', 'retail', time()-60, time()+600, 1, $operator);
        competition::publish($id, $operator);
        // Model the already-published production season without migrating its legacy privacy metadata.
        $DB->set_field('local_ustar_competitions', 'privacy', 'pseudonymous', ['id' => $id]);
        $this->setUser($first);
        $personal = competition::current_for_user($first->id);
        $this->assertSame(['First Seller', 'Second Seller'], array_column($personal['rows'], 'displayname'));
        $this->assertSame('First Seller', $personal['current']['displayname']);
        $this->assertSame('FS', $personal['current']['initials']);
        $this->assertTrue($personal['rows'][0]['current']);
        $this->assertFalse($personal['rows'][1]['current']);
        $this->assertSame([1, 1], array_column($personal['rows'], 'rank'));
        $this->assertArrayNotHasKey('userid', $personal['rows'][0]);
        $this->assertSame('Рейтинг участников сезона', $personal['privacylabel']);
        $this->setUser($outsider);
        $this->assertNull(competition::current_for_user($outsider->id));
        $this->setUser($first);
        $DB->set_field('user', 'suspended', 1, ['id' => $second->id]);
        $this->assertCount(1, competition::current_for_user($first->id)['rows']);
        $this->assertNull(competition::current_for_user($second->id));
        $DB->set_field('local_ustar_comp_participants', 'status', 'left', ['competitionid' => $id, 'userid' => $first->id]);
        $this->assertNull(competition::current_for_user($first->id));
    }

}
