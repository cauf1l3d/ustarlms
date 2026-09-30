<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();
#[\PHPUnit\Framework\Attributes\CoversClass(notebook_layout::class)]
final class notebook_layout_test extends \advanced_testcase {
    protected function setUp(): void { parent::setUp(); $this->resetAfterTest(true); }
    public function test_owner_position_survives_without_changing_note_content(): void {
        $owner=$this->getDataGenerator()->create_user();$this->setUser($owner);
        $note=learning_tasks::create_note($owner->id,'Private','Original');
        notebook_layout::save($note['id'],$owner->id,720,360);
        $this->assertSame(['x'=>720,'y'=>360],notebook_layout::positions($owner->id,[$note])[$note['id']]);
        $this->assertSame('Original',learning_tasks::view($note['id'],$owner->id)['descriptionplain']);
    }
    public function test_another_employee_cannot_move_private_note(): void {
        $owner=$this->getDataGenerator()->create_user();$this->setUser($owner);
        $note=learning_tasks::create_note($owner->id,'Private','Secret');
        $other=$this->getDataGenerator()->create_user();$this->setUser($other);
        $this->expectException(\required_capability_exception::class);
        notebook_layout::save($note['id'],$other->id,30,40);
    }
    public function test_employee_cannot_impersonate_owner_in_layout_command(): void {
        $owner=$this->getDataGenerator()->create_user();$this->setUser($owner);
        $note=learning_tasks::create_note($owner->id,'Private','Secret');
        $other=$this->getDataGenerator()->create_user();$this->setUser($other);
        $this->expectException(\required_capability_exception::class);
        notebook_layout::save($note['id'],$owner->id,30,40);
    }
}
