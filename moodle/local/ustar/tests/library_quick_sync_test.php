<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();require_once(__DIR__.'/fixtures/library_fixture.php');
#[\PHPUnit\Framework\Attributes\CoversClass(library_quick_sync::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(library_quick_generator::class)]
final class library_quick_sync_test extends \advanced_testcase {
    use library_fixture;
    protected function setUp(): void {parent::setUp();$this->resetAfterTest(true);$this->setAdminUser();}
    private function catalog_fixture(): array {
        $root=$this->product('Tools',0,'{}','group');$products=[];
        foreach (['steel','wood','brass','plastic'] as $i=>$value) {
            $sg=$this->product('Category '.$i,$root,'{}','subgroup');$products[]=$this->product('Product '.$i,$sg,json_encode(['A'=>'','Material'=>$value]));
        }
        return [$root,$products];
    }
    public function test_dryrun_repeat_and_archive_revive_preserve_one_definition(): void {
        global $DB;[$root,$products]=$this->catalog_fixture();
        $dry=library_quick_sync::run(true);$this->assertSame(4,$dry['created']);$this->assertSame(0,$DB->count_records('local_ustar_library_assessment'));
        $this->assertSame(4,library_quick_sync::run()['created']);$this->assertSame(4,library_quick_sync::run(true)['noop']);
        $row=$DB->get_record('local_ustar_library_assessment',['sourcekey'=>'product:'.$products[0]]);$this->assertSame(2,(int)$row->questioncount);
        $this->assertSame(4,library_quick_sync::run()['noop']);$this->assertSame($row->timemodified,$DB->get_field('local_ustar_library_assessment','timemodified',['id'=>$row->id]));
        $DB->set_field('local_ustar_catalog','active',0,['id'=>$root]);$this->assertSame(4,library_quick_sync::run()['archived']);
        $DB->set_field('local_ustar_catalog','active',1,['id'=>$root]);$this->assertSame(4,library_quick_sync::run()['refreshed']);
        $this->assertSame($row->id,$DB->get_field('local_ustar_library_assessment','id',['sourcekey'=>'product:'.$products[0]]));
    }
    public function test_attribute_pool_matches_first_usable_attribute_and_generation_is_deterministic(): void {
        [$root,$products]=$this->catalog_fixture();library_quick_sync::run();global $DB;
        $row=$DB->get_record('local_ustar_library_assessment',['sourcekey'=>'product:'.$products[0]]);$qs=json_decode($row->questionsjson,true);
        $this->assertStringContainsString('Material',$qs[1]['text']);$this->assertContains('brass',$qs[1]['options']);
        $this->assertSame('steel',$qs[1]['options'][$qs[1]['correct']]);$this->assertSame(4,count(array_unique($qs[1]['options'])));
        $hash=$row->inputhash;library_quick_sync::run();$this->assertSame($hash,$DB->get_field('local_ustar_library_assessment','inputhash',['id'=>$row->id]));
    }
    public function test_targeted_sync_changes_one_definition_and_never_mutates_attempts_or_rewards(): void {
        global $DB;[$root,$products]=$this->catalog_fixture();library_quick_sync::run();
        $row=$DB->get_record('local_ustar_library_assessment',['sourcekey'=>'product:'.$products[0]]);
        $u=$this->employee();$this->setUser($u);$a=library_assessment::get_or_start($row->id,$u->id);$snapshot=$a['attempt']->questionsjson;
        $DB->set_field('local_ustar_catalog','title','Revised',['id'=>$products[0]]);$r=library_quick_sync::sync_product($products[0]);
        $this->assertSame(1,$r['refreshed']);$this->assertSame($snapshot,$DB->get_field('local_ustar_library_attempt','questionsjson',['id'=>$a['attempt']->id]));
        foreach (['coin_ledger','evidence_rec','route_progress'] as $table) {$this->assertSame(0,$DB->count_records('local_ustar_'.$table));}
    }
}
