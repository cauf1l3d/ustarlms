<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();
#[\PHPUnit\Framework\Attributes\CoversClass(course_studio::class)]
final class course_studio_test extends \advanced_testcase {
    protected function setUp(): void { parent::setUp(); $this->resetAfterTest(true); $this->setAdminUser(); }
    private function project(): \stdClass {
        return json_decode('{"data":{"code":"SALES-01","title":"Продажи","version":"1.0","pages":[{"id":"one","title":"Начало","kind":"lesson","html":"<p>Материал</p>"}]},"assets":{},"fonts":[],"theme":{"font":"Arial","headingFont":"Arial","fontSize":17,"headingSize":34,"lineHeight":1.65,"ink":"#2b2b2b","accent":"#ebc500","paper":"#ffffff","background":"#ededed"},"catalogue":{},"revision":0}');
    }
    public function test_shared_save_roundtrip_and_retry_are_one_material(): void {
        global $DB,$USER;
        $p=$this->project();$a=course_studio::save($p,0,0,'mutation-1',$USER->id);
        $b=course_studio::save($p,0,0,'mutation-1',$USER->id);
        $this->assertSame($a['contentid'],$b['contentid']);$this->assertSame(1,$DB->count_records('local_ustar_content_blueprints'));
        $this->assertSame($a['fingerprint'],course_studio::get('SALES-01',$USER->id)['fingerprint']);
        $this->assertSame('draft',$DB->get_field('local_ustar_content','status',['id'=>$a['contentid']]));
    }
    public function test_stale_metadata_revision_is_rejected(): void {
        global $DB,$USER;
        $p=$this->project();$a=course_studio::save($p,0,0,'mutation-1',$USER->id);
        $DB->set_field('local_ustar_content','timemodified',$a['contentmodified']+1,['id'=>$a['contentid']]);
        $p->data->title='Правки';$this->expectException(course_studio_conflict::class);
        course_studio::save($p,$a['revision'],$a['contentmodified'],'mutation-2',$USER->id);
    }
    public function test_published_material_is_not_changed_by_save(): void {
        global $DB,$USER;
        $p=$this->project();$a=course_studio::save($p,0,0,'mutation-1',$USER->id);
        $DB->set_field('local_ustar_content','status','published',['id'=>$a['contentid']]);
        $p->data->title='Правки';
        try { course_studio::save($p,$a['revision'],$a['contentmodified'],'mutation-2',$USER->id); $this->fail('published write'); }
        catch(course_studio_conflict $e) { $this->assertSame('Продажи',$DB->get_field('local_ustar_content','title',['id'=>$a['contentid']])); }
    }
    public function test_saved_asset_is_required_and_learner_cannot_read_source(): void {
        global $USER;
        $p=$this->project();$a=course_studio::save($p,0,0,'mutation-1',$USER->id);
        $learner=$this->getDataGenerator()->create_user();$this->setUser($learner);
        $this->expectException(\required_capability_exception::class); course_studio::get('SALES-01',$learner->id);
    }
    public function test_prepare_twice_returns_same_activity_and_keeps_scope(): void {
        global $DB,$USER;
        $a=course_studio::save($this->project(),0,0,'mutation-1',$USER->id);
        $rules=$DB->get_records('local_ustar_content_access',['contentid'=>$a['contentid']]);
        $first=course_studio::prepare('SALES-01',$a['revision'],$a['contentmodified'],$USER->id);
        $again=course_studio::prepare('SALES-01',$a['revision'],$a['contentmodified'],$USER->id);
        $this->assertSame($first['cmid'],$again['cmid']);
        $this->assertEquals($rules,$DB->get_records('local_ustar_content_access',['contentid'=>$a['contentid']]));
        $this->assertTrue(material_studio::runtime_ready($a['contentid']));
    }
}
