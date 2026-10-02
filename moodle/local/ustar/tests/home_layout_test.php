<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();
#[\PHPUnit\Framework\Attributes\CoversClass(home_layout::class)]
final class home_layout_test extends \advanced_testcase {
    protected function setUp(): void { parent::setUp();$this->resetAfterTest(true); }
    public function test_own_settings_persist_and_stale_write_is_rejected(): void {
        $u=$this->getDataGenerator()->create_user();$this->setUser($u);
        $items=home_layout::defaults($u->id)['items'];$items['tasks']['span']=6;$items['hero']['visible']=false;
        $saved=home_layout::save($u->id,0,$items);
        $this->assertSame(1,$saved['revision']);$this->assertSame(6,home_layout::read($u->id)['items']['tasks']['span']);
        $this->expectException(\moodle_exception::class);home_layout::save($u->id,0,$items);
    }
    public function test_a_hidden_block_cannot_grant_competition_authority(): void {
        $u=$this->getDataGenerator()->create_user();$this->setUser($u);
        $this->assertArrayNotHasKey('competition',home_layout::blocks($u->id));
        $this->expectException(\invalid_parameter_exception::class);
        home_layout::save($u->id,0,['competition'=>['order'=>0,'span'=>12,'style'=>'plain','visible'=>true]]);
    }
    public function test_other_owner_cannot_write_settings(): void {
        $u=$this->getDataGenerator()->create_user();$v=$this->getDataGenerator()->create_user();$this->setUser($v);
        $this->expectException(\required_capability_exception::class);home_layout::save($u->id,0,[]);
    }
}
