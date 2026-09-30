<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();
#[\PHPUnit\Framework\Attributes\CoversClass(notebook_board::class)]
final class notebook_board_test extends \advanced_testcase {
    protected function setUp(): void { parent::setUp(); $this->resetAfterTest(true); }
    public function test_spatial_metadata_preserves_note_and_rejects_stale_updates(): void {
        $u = $this->getDataGenerator()->create_user(); $this->setUser($u);
        $n = learning_tasks::create_note($u->id, 'Private', 'Unchanged text');
        $data = notebook_board::move($u->id, $n['id'], 0, 120, 300, 'yellow');
        $this->assertSame(1, $data['revision']);
        $this->assertSame(['x'=>120,'y'=>300,'color'=>'yellow'], notebook_board::layout($u->id)['items'][$n['id']]);
        $this->assertSame('Unchanged text', learning_tasks::view($n['id'], $u->id)['descriptionplain']);
        $this->expectException(\moodle_exception::class);
        notebook_board::move($u->id, $n['id'], 0, 500, 0, 'blue');
    }
    public function test_session_cannot_read_other_owners_layout(): void {
        $u = $this->getDataGenerator()->create_user(); $v = $this->getDataGenerator()->create_user();
        $this->setUser($u); $n = learning_tasks::create_note($u->id, 'Private', 'Secret');
        notebook_board::move($u->id, $n['id'], 0, 120, 300, 'yellow');
        $this->setUser($v); $this->expectException(\required_capability_exception::class);
        notebook_board::layout($u->id);
    }
    public function test_session_cannot_move_other_users_note(): void {
        $u = $this->getDataGenerator()->create_user(); $v = $this->getDataGenerator()->create_user();
        $this->setUser($u); $n = learning_tasks::create_note($u->id, 'Private', 'Secret');
        $this->setUser($v); $this->expectException(\required_capability_exception::class);
        notebook_board::move($v->id, $n['id'], 0, 120, 300, 'yellow');
    }
    public function test_invalid_coordinates_do_not_change_preferences(): void {
        $u = $this->getDataGenerator()->create_user(); $this->setUser($u);
        $n = learning_tasks::create_note($u->id, 'Private', 'Secret');
        try { notebook_board::move($u->id, $n['id'], 0, -1, 0, 'yellow'); $this->fail('Negative coordinate accepted'); }
        catch (\invalid_parameter_exception $e) { $this->assertSame(0, notebook_board::layout($u->id)['revision']); }
    }
    public function test_frame_size_links_and_background_are_atomic_and_owner_scoped(): void {
        $u=$this->getDataGenerator()->create_user();$v=$this->getDataGenerator()->create_user();$this->setUser($u);
        $a=learning_tasks::create_note($u->id,'A','A');$b=learning_tasks::create_note($u->id,'B','B');
        $data=notebook_board::update($u->id,0,['background'=>'mint',
            'frames'=>['fexample'=>['x'=>0,'y'=>0,'width'=>900,'height'=>500,'title'=>'Group']],
            'items'=>[$a['id']=>['x'=>40,'y'=>60,'width'=>480,'height'=>360,'color'=>'pink','frame'=>'fexample']],
            'links'=>[['from'=>$a['id'],'to'=>$b['id']]]]);
        $this->assertSame(480,$data['items'][$a['id']]['width']);$this->assertSame('mint',$data['background']);
        $data=notebook_board::move($u->id,$a['id'],1,60,80,'green');
        $this->assertSame(480,$data['items'][$a['id']]['width']);$this->assertSame('fexample',$data['items'][$a['id']]['frame']);
        $this->setUser($v);$foreign=learning_tasks::create_note($v->id,'Private','Secret');$this->setUser($u);
        try { notebook_board::update($u->id,2,['background'=>'rose','links'=>[['from'=>$a['id'],'to'=>$foreign['id']]]]);$this->fail('Foreign link accepted'); }
        catch (\invalid_parameter_exception $e) { $this->assertSame('mint',notebook_board::layout($u->id)['background']); }
        $data=notebook_board::update($u->id,2,['frames'=>[]]);$this->assertSame('',$data['items'][$a['id']]['frame']);
    }

}
