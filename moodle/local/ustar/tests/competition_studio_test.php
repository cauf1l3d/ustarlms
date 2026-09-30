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
}
