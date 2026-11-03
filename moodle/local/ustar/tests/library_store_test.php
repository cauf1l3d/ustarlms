<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();require_once(__DIR__.'/fixtures/library_fixture.php');
#[\PHPUnit\Framework\Attributes\CoversClass(library_store::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(library_grant::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(library_offer::class)]
final class library_store_test extends \advanced_testcase {
    use library_fixture {employee as original_employee;}
    private function employee(): \stdClass {
        global $DB;
        $tree=structure::get(structure::NAME_STRUCTURE);
        if (!in_array('library_shop_fixture',array_column($tree['departments'],'id'),true)) {
            $tree['departments'][]=['id'=>'library_shop_fixture','name'=>'Library shop fixture'];
            $tree['positions'][]=['id'=>'library_shop_fixture_job','name'=>'Library shop job','department'=>'library_shop_fixture','level'=>1];
            structure::save(structure::NAME_STRUCTURE,$tree);
        }
        if (!$DB->record_exists('user_info_field',['shortname'=>'ustar_position'])) {
            $this->getDataGenerator()->create_custom_profile_field(['shortname'=>'ustar_position','name'=>'Position','datatype'=>'text']);
        }
        $u=$this->original_employee();people::set_position_id($u->id,'library_shop_fixture_job');
        set_config('library_marketplace_departments','["library_shop_fixture"]','local_ustar');return $u;
    }

    protected function setUp(): void {parent::setUp();$this->resetAfterTest(true);$this->setAdminUser();accounts::ensure_profile_field();set_config('library_store_enabled',1,'local_ustar');}
    private function buy(object $o,object $u,string $nonce=''): array {return library_store::purchase($o->id,$u->id,(int)$o->pricecoin,(int)$o->timemodified,$nonce?:bin2hex(random_bytes(16)));}
    private function fund(object $u,int $amount=25): void {economy::post($u->id,$amount,'fixture','fixture:'.random_string(20),'fixture','1','Fixture',get_admin()->id);}
    public function test_purchase_repeat_refund_and_new_purchase_keep_ledger_truthful(): void {
        global $DB;$u=$this->employee();$o=$this->offer();$this->fund($u);$this->setUser($u);$nonce=bin2hex(random_bytes(16));
        $first=$this->buy($o,$u,$nonce);$again=$this->buy($o,$u,$nonce);
        $this->assertTrue($first['ok']);$this->assertSame($first['grantid'],$again['grantid']);$this->assertSame(15,economy::balance($u->id));
        $this->setAdminUser();$this->assertTrue(library_grant::refund($first['grantid'],get_admin()->id,'Fixture'));
        $this->assertFalse(library_grant::refund($first['grantid'],get_admin()->id,'Repeat'));$this->assertSame(25,economy::balance($u->id));
        $this->setUser($u);$this->assertFalse($this->buy($o,$u,$nonce)['ok']);$new=$this->buy($o,$u);
        $this->assertTrue($new['ok']);$this->assertNotSame($first['grantid'],$new['grantid']);$this->assertSame(15,economy::balance($u->id));
        $this->assertSame(2,$DB->count_records('local_ustar_coin_ledger',['userid'=>$u->id,'txtype'=>'library_purchase']));
    }
    public function test_insufficient_stale_quote_and_store_off_never_charge(): void {
        global $DB;$u=$this->employee();$o=$this->offer();$this->setUser($u);
        $this->assertSame('insufficient_balance',$this->buy($o,$u)['reason']);$this->fund($u);
        $DB->set_field('local_ustar_library_offer','timemodified',$o->timemodified+1,['id'=>$o->id]);
        $this->assertSame('offer_changed',$this->buy($o,$u)['reason']);set_config('library_store_enabled',0,'local_ustar');
        $this->assertSame('store_disabled',$this->buy($o,$u)['reason']);$this->assertSame(25,economy::balance($u->id));
        $this->assertSame(0,$DB->count_records('local_ustar_library_grant'));
    }
    public function test_actor_spoof_is_refused_in_domain(): void {
        $u=$this->employee();$o=$this->offer();$this->expectException(\required_capability_exception::class);$this->buy($o,$u);
    }
    public function test_leader_cannot_grant_outside_scope_even_with_capability(): void {
        $leader=$this->employee();$target=$this->employee();$o=$this->offer();$role=create_role('Library fixture','libfixture','');
        assign_capability('local/ustar:librarygrant',CAP_ALLOW,$role,\context_system::instance()->id);role_assign($role,$leader->id,\context_system::instance()->id);
        $this->setUser($leader);$this->expectException(\moodle_exception::class);
        library_grant::assign($o->id,$target->id,$leader->id,'admin','Spoof source',bin2hex(random_bytes(16)));
    }
    public function test_admin_assignment_is_idempotent_and_independent_of_purchase(): void {
        global $DB;$u=$this->employee();$o=$this->offer();$actor=get_admin()->id;$nonce=bin2hex(random_bytes(16));
        $id=library_grant::assign($o->id,$u->id,$actor,'purchase','Fixture',$nonce);
        $this->assertSame($id,library_grant::assign($o->id,$u->id,$actor,'leader','Fixture',$nonce));
        $this->assertSame('admin',$DB->get_field('local_ustar_library_grant','source',['id'=>$id]));
        $this->assertSame(0,$DB->count_records('local_ustar_coin_ledger'));
        $this->assertTrue(library_grant::revoke($id,$actor));$this->assertNull(library_access::active_grant($o->id,$u->id));
    }
    public function test_offer_revision_prevents_overwrite(): void {
        $o=$this->offer();$input=(array)$o;$input['title']='Revised';$actor=get_admin()->id;
        library_offer::save($o->id,$input,$actor,(int)$o->timemodified);
        $this->expectException(\moodle_exception::class);library_offer::save($o->id,$input,$actor,(int)$o->timemodified);
    }
    public function test_fresh_defaults_prepare_manager_grant_capability_without_personal_assignments(): void {
        global $DB;
        require_once(__DIR__.'/../db/library_defaults.php');
        $before=$DB->count_records('role_assignments');
        \local_ustar_library_defaults();
        $role=(int)$DB->get_field('role','id',['shortname'=>'ustar_manager']);$this->assertGreaterThan(0,$role);
        $permission=$DB->get_field('role_capabilities','permission',['roleid'=>$role,'contextid'=>\context_system::instance()->id,'capability'=>'local/ustar:librarygrant']);
        $this->assertSame(CAP_ALLOW,(int)$permission);$this->assertSame($before,$DB->count_records('role_assignments'));
        foreach (['librarypublish','librarygrant','libraryassess'] as $name) {
            $this->assertNotSame('[[ustar:'.$name.']]',get_string('ustar:'.$name,'local_ustar'));
        }
    }
    public function test_revocation_requires_fresh_assignment_form_and_preserves_history(): void {
        global $DB;$u=$this->employee();$o=$this->offer();$actor=get_admin()->id;$nonce=bin2hex(random_bytes(16));
        $first=library_grant::assign($o->id,$u->id,$actor,'','Fixture',$nonce);
        library_grant::revoke($first,$actor);
        $second=library_grant::assign($o->id,$u->id,$actor,'','New form',bin2hex(random_bytes(16)));
        $this->assertNotSame($first,$second);$this->assertSame(2,$DB->count_records('local_ustar_library_grant',['userid'=>$u->id]));
        $this->expectException(\moodle_exception::class);library_grant::assign($o->id,$u->id,$actor,'','Stale form',$nonce);
    }
}
