<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(workflow_notifications::class)]
final class workflow_notifications_test extends \advanced_testcase {
    public function test_redelivery_preserves_one_notification(): void {
        global $DB;
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        for ($i = 0; $i < 2; $i++) {
            workflow_notifications::enqueue($user->id, 'task_assigned', 'Subject', 'Body',
                '/local/ustar/tasks.php', 'fixture:delivery');
        }
        $this->assertSame(1, $DB->count_records('local_ustar_notifications', ['idempotencykey' => 'fixture:delivery']));
    }

    public function test_key_collision_cannot_silently_drop_another_recipients_message(): void {
        $this->resetAfterTest(true);
        $first = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user();
        workflow_notifications::enqueue($first->id, 'task_assigned', 'Subject', 'Body',
            '/local/ustar/tasks.php', 'fixture:collision');
        $this->expectException(\coding_exception::class);
        workflow_notifications::enqueue($second->id, 'task_assigned', 'Subject', 'Body',
            '/local/ustar/tasks.php', 'fixture:collision');
    }
}
