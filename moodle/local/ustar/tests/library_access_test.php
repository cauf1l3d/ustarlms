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
    public function test_current_mandatory_native_activity_has_independent_access_without_purchase(): void {
        global $DB;
        $gen=$this->getDataGenerator();$g=$gen->get_plugin_generator('local_ustar');
        $course=$gen->create_course(['enablecompletion'=>1]);$scorm=$gen->create_module('scorm',['course'=>$course->id]);
        $u=$gen->create_and_enrol($course,'student');$position='library_required_fixture';
        $tree=structure::get(structure::NAME_STRUCTURE);
        $tree['departments'][]=['id'=>'library_fixture','name'=>'Library fixture'];
        $tree['positions'][]=['id'=>$position,'name'=>'Library learner','department'=>'library_fixture','level'=>1];
        structure::save(structure::NAME_STRUCTURE,$tree);
        $field=$DB->get_record('user_info_field',['shortname'=>'ustar_position']);
        if (!$field) { $field=$gen->create_custom_profile_field(['shortname'=>'ustar_position','name'=>'Position','datatype'=>'text']); }
        $DB->insert_record('user_info_data',(object)['userid'=>$u->id,'fieldid'=>$field->id,'data'=>$position,'dataformat'=>0]);
        $route=$g->create_route(['positionid'=>$position]);$point=$g->create_point($route);
        $g->create_version($point,['requirementsjson'=>json_encode([['type'=>'cm','sourceid'=>$scorm->cmid,'required'=>true]])]);
        $content=$this->contentitem();$offer=$this->offer();
        $DB->update_record('local_ustar_content',(object)['id'=>$content,'sourcekind'=>'moodle_cm','cmid'=>$scorm->cmid,'courseid'=>$course->id]);
        $DB->update_record('local_ustar_library_offer',(object)['id'=>$offer->id,'itemtype'=>'content','itemid'=>$content]);
        $this->setUser($u);
        $this->assertFalse(library_access::resolve($offer->id,$u->id)['canopen'],'Library licence remains closed');
        $this->assertTrue(library_runtime::native_allowed($scorm->cmid,$u->id),'Current mandatory point does not require a purchase');
        $this->assertSame(0,$DB->count_records('local_ustar_library_grant',['userid'=>$u->id]));
        $this->assertSame(0,$DB->count_records('local_ustar_route_progress',['userid'=>$u->id]),'Read-only access never completes the point');
    }
    private function marketplace_employee(string $department): \stdClass {
        global $DB;
        $tree=structure::get(structure::NAME_STRUCTURE);
        foreach (['market_sales'=>'Продажи','market_stock'=>'Склад'] as $id=>$name) {
            if (!in_array($id,array_column($tree['departments'],'id'),true)) {
                $tree['departments'][]=['id'=>$id,'name'=>$name];
                $tree['positions'][]=['id'=>$id.'_job','name'=>$name.' fixture','department'=>$id,'level'=>1];
            }
        }
        structure::save(structure::NAME_STRUCTURE,$tree);
        if (!$DB->record_exists('user_info_field',['shortname'=>'ustar_position'])) {
            $this->getDataGenerator()->create_custom_profile_field(['shortname'=>'ustar_position','name'=>'Position','datatype'=>'text']);
        }
        $u=$this->employee();people::set_position_id($u->id,$department.'_job');return $u;
    }
    public function test_marketplace_default_department_transfer_and_admin_preview(): void {
        $u=$this->marketplace_employee('market_sales');$admin=get_admin()->id;
        $this->assertTrue(library_access::can_view_marketplace($admin));
        $this->assertFalse(library_access::can_view_marketplace($u->id));
        library_access::save_marketplace_departments(['market_sales'],$admin,0);
        $this->assertTrue(library_access::can_view_marketplace($u->id));
        people::set_position_id($u->id,'market_stock_job');
        $this->assertFalse(library_access::can_view_marketplace($u->id),'Current canonical department, not an old allow decision');
        view_as::set('market_stock_job');
        try {
            $this->assertFalse(library_access::can_view_marketplace($admin),'Preview cannot borrow admin bypass');
            $this->assertFalse(catalog::can_download_source($admin));
        } finally {view_as::clear();}
    }
    public function test_marketplace_settings_reject_stale_unknown_and_employee_writes(): void {
        $u=$this->marketplace_employee('market_sales');$admin=get_admin()->id;
        library_access::save_marketplace_departments(['market_sales','market_sales'],$admin,0);
        $this->assertSame(['market_sales'],library_access::marketplace_departments());
        $revision=(int)get_config('local_ustar','library_settings_revision');
        foreach ([[['market_stock'],0],[['invented'],$revision]] as [$ids,$expected]) {
            try {library_access::save_marketplace_departments($ids,$admin,$expected);$this->fail('Invalid settings accepted');}
            catch (\moodle_exception $e) {$this->assertSame(['market_sales'],library_access::marketplace_departments());}
        }
        $this->setUser($u);
        try {library_access::save_marketplace_departments(['market_stock'],$u->id,$revision);$this->fail('Employee changed access');}
        catch (\moodle_exception $e) {$this->assertSame(['market_sales'],library_access::marketplace_departments());}
    }
    public function test_hidden_marketplace_never_lists_or_charges_but_keeps_owned_assignments(): void {
        global $DB;$u=$this->marketplace_employee('market_sales');$o=$this->offer();$this->grant($o,$u);
        $this->setUser($u);set_config('library_store_enabled',1,'local_ustar');
        $this->assertFalse(library_access::can_view_marketplace($u->id));
        $this->assertTrue(library_access::can_view_offer_page($o->id,$u->id));
        $this->assertTrue(library_access::resolve($o->id,$u->id)['canopen'],'Assigned material remains a separate entitlement');
        $this->assertSame(0,library_store::storefront($u->id)['total']);
        $this->assertSame('marketplace_hidden',library_store::purchase($o->id,$u->id,10,$o->timemodified,bin2hex(random_bytes(16)))['reason']);
        $this->assertSame(0,$DB->count_records('local_ustar_coin_ledger',['userid'=>$u->id]));
    }
    public function test_catalog_source_file_is_editor_only_even_when_product_body_is_free(): void {
        $product=$this->product();$context=\context_system::instance();
        get_file_storage()->create_file_from_string(['contextid'=>$context->id,'component'=>'local_ustar',
            'filearea'=>'catalog_source','itemid'=>$product,'filepath'=>'/','filename'=>'fixture.docx'],'fixture original');
        $this->assertNotEmpty(catalog::file_url($product,catalog::FILEAREA_SOURCE,true));
        $u=$this->employee();$this->setUser($u);
        $this->assertTrue(library_runtime::catalog_allowed($product,$u->id));
        $this->assertSame('',catalog::file_url($product,catalog::FILEAREA_SOURCE,true));
        $this->assertSame('',catalog::source_filename($product));
        $this->assertFalse(catalog::view($product)['hassource']);
        require_once(__DIR__.'/../lib.php');
        $this->assertFalse(\local_ustar_pluginfile(null,null,$context,'catalog_source',[$product,'fixture.docx'],true));
    }
    public function test_future_seed_creates_drafts_and_preserves_existing_human_offer(): void {
        $existing=$this->offer('free',0);$product=$this->product('New product for review');
        library_offer::seed(get_admin()->id);
        $new=library_offer::for_subject('catalog',$product);
        $this->assertSame('draft',$new->visibility);$this->assertSame('assigned',$new->accesspolicy);
        $this->assertSame('published',library_offer::get($existing->id)->visibility);
        $this->assertSame('free',library_offer::get($existing->id)->accesspolicy);
    }

}
