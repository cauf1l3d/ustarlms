<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();
require_once(__DIR__.'/fixtures/library_fixture.php');
#[\PHPUnit\Framework\Attributes\CoversClass(library_access::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(library_runtime::class)]
final class library_access_test extends \advanced_testcase {
    use library_fixture;
    protected function setUp(): void {parent::setUp();$this->resetAfterTest(true);$this->setAdminUser();accounts::ensure_profile_field();}
    public function test_paid_visible_closed_and_single_bulk_agree(): void {
        $u=$this->employee();$o=$this->offer();$this->setUser($u);
        $s=library_access::resolve($o->id,$u->id);$this->assertTrue($s['canview']);$this->assertFalse($s['canopen']);
        $this->assertEquals($s,library_access::resolve_many([$o],$u->id)[$o->id]);
        $this->grant($o,$u);$this->assertTrue(library_access::resolve($o->id,$u->id)['canopen']);
    }
    public function test_inactive_and_future_grant_never_bypass_gates(): void {
        global $DB;$u=$this->employee();$o=$this->offer();$id=$this->grant($o,$u,['validfrom'=>time()+1000]);$this->setUser($u);
        $this->assertFalse(library_access::resolve($o->id,$u->id)['canopen']);
        $DB->set_field('local_ustar_library_grant','validfrom',time()-1,['id'=>$id]);
        $DB->set_field('local_ustar_employment','status','suspended',['userid'=>$u->id]);
        $this->assertFalse(library_access::resolve_many([$o],$u->id)[$o->id]['canview']);
    }
    public function test_zero_price_gated_and_ancestor_cycles_fail_closed(): void {
        global $DB;$u=$this->employee();$o=$this->offer('gated',0);$this->setUser($u);
        $this->assertFalse(library_access::resolve($o->id,$u->id)['canopen']);
        $DB->set_field('local_ustar_catalog','parentid',$o->itemid,['id'=>$o->itemid]);
        $this->assertFalse(library_access::resolve($o->id,$u->id)['canview']);
    }
    public function test_grant_cannot_bypass_draft_offer_or_source_audience(): void {
        global $DB;$u=$this->employee();$o=$this->offer();$this->grant($o,$u);$this->setUser($u);
        $DB->set_field('local_ustar_library_offer','visibility','draft',['id'=>$o->id]);$o=library_offer::get($o->id);
        $this->assertFalse(library_access::resolve_many([$o],$u->id)[$o->id]['canview']);
        $id=$this->contentitem(false);$DB->update_record('local_ustar_library_offer',(object)['id'=>$o->id,'itemtype'=>'content','itemid'=>$id,'visibility'=>'published']);
        $this->assertFalse(library_access::resolve($o->id,$u->id)['canview']);
    }
    public function test_actual_body_rechecks_library_entitlement(): void {
        global $DB;$u=$this->employee();$o=$this->offer();$id=$this->contentitem();
        $DB->update_record('local_ustar_library_offer',(object)['id'=>$o->id,'itemtype'=>'content','itemid'=>$id]);
        $this->setUser($u);$record=$DB->get_record('local_ustar_content',['id'=>$id]);
        $this->assertTrue(content::audience_allows_record($record,$u->id));$this->assertFalse(content::can_access_record($record,$u->id));
        $grant=$this->grant($o,$u);$this->assertTrue(content::can_access_record($record,$u->id));
        $DB->set_field('local_ustar_library_grant','revokedat',time(),['id'=>$grant]);$this->assertFalse(content::can_access_record($record,$u->id));
    }
    public function test_native_url_uses_library_licence_and_rechecks_revocation(): void {
        global $DB;$u=$this->employee();$course=$this->getDataGenerator()->create_course();
        $quiz=$this->getDataGenerator()->create_module('quiz',['course'=>$course->id]);$id=$this->contentitem();$o=$this->offer();
        $DB->update_record('local_ustar_content',(object)['id'=>$id,'sourcekind'=>'moodle_cm','cmid'=>$quiz->cmid,'courseid'=>$course->id]);
        $DB->update_record('local_ustar_library_offer',(object)['id'=>$o->id,'itemtype'=>'content','itemid'=>$id]);
        $this->setUser($u);$this->assertFalse(library_runtime::native_allowed($quiz->cmid,$u->id));
        $grant=$this->grant($o,$u);$this->assertTrue(library_runtime::native_allowed($quiz->cmid,$u->id));
        $DB->set_field('local_ustar_library_grant','revokedat',time(),['id'=>$grant]);$this->assertFalse(library_runtime::native_allowed($quiz->cmid,$u->id));
    }
}
