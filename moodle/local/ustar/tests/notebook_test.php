<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(learning_tasks::class)]
final class notebook_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
    }

    public function test_owner_can_edit_and_delete_without_copying_content_to_events(): void {
        global $DB;
        $owner = $this->getDataGenerator()->create_user();
        $note = learning_tasks::create_note($owner->id, 'Original', 'Private content');
        learning_tasks::update_note($note['id'], $owner->id, $note['version'], 'Updated', 'Secret revised');
        $updated = learning_tasks::view($note['id'], $owner->id);
        $this->assertSame('Secret revised', $updated['descriptionplain']);
        $this->assertSame($note['version'] + 1, $updated['version']);
        learning_tasks::transition($note['id'], $owner->id, 'complete', $updated['version'], 'Do not log this');
        foreach ($DB->get_records('local_ustar_learning_task_events', ['taskid' => $note['id']]) as $event) {
            $this->assertSame('[]', $event->datajson);
        }
        learning_tasks::delete_note($note['id'], $owner->id, $updated['version'] + 1);
        $this->assertFalse($DB->record_exists('local_ustar_learning_tasks', ['id' => $note['id']]));
        $this->assertFalse($DB->record_exists('local_ustar_learning_task_events', ['taskid' => $note['id']]));
    }

    public function test_other_user_cannot_edit_a_note(): void {
        $owner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $note = learning_tasks::create_note($owner->id, 'Original', 'Secret');
        $this->expectException(\required_capability_exception::class);
        learning_tasks::update_note($note['id'], $other->id, $note['version'], 'Intrusion', 'Changed');
    }

    public function test_admin_cannot_delete_another_users_note(): void {
        global $USER;
        $owner = $this->getDataGenerator()->create_user();
        $note = learning_tasks::create_note($owner->id, 'Original', 'Secret');
        $this->expectException(\required_capability_exception::class);
        learning_tasks::delete_note($note['id'], $USER->id, $note['version']);
    }

    public function test_stale_revision_cannot_delete_an_updated_note(): void {
        $owner = $this->getDataGenerator()->create_user();
        $note = learning_tasks::create_note($owner->id, 'Original', 'Secret');
        learning_tasks::update_note($note['id'], $owner->id, $note['version'], 'Updated', 'Updated');
        $this->expectException(\moodle_exception::class);
        learning_tasks::delete_note($note['id'], $owner->id, $note['version']);
    }
}
