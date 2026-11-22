<?php
namespace local_ustar;

use core_message\api as messaging;
use local_ustar\privacy\workplace;
use local_ustar\task_workspace\{discussion, personal_plan, service};

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(discussion::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(personal_plan::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(workplace::class)]
final class workplace_test extends \advanced_testcase {
    private \stdClass $assignee;
    private \stdClass $colleague;
    private int $task;

    protected function setUp(): void {
        global $PAGE;
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('messaging', 1);
        set_config('messagingallusers', 1);
        $ctx = \context_system::instance();
        $PAGE->set_context($ctx);
        $PAGE->set_url(new \moodle_url('/local/ustar/tasks.php'));
        $role = $this->getDataGenerator()->create_role();
        foreach (['local/ustar:use', 'moodle/site:sendmessage'] as $capability) {
            assign_capability($capability, CAP_ALLOW, $role, $ctx->id);
        }
        $this->assignee = $this->getDataGenerator()->create_user();
        $this->colleague = $this->getDataGenerator()->create_user();
        foreach ([$this->assignee, $this->colleague] as $user) {
            role_assign($role, $user->id, $ctx->id);
        }
        $this->task = service::create((int)get_admin()->id, (int)$this->assignee->id,
            ['kind' => 'task', 'title' => 'Scoped discussion', 'date' => gmdate('Y-m-d', time() + DAYSECS), 'clock' => '14:00']);
    }

    public function test_meeting_directory_matches_workforce_policy_at_constant_query_count(): void {
        global $DB;
        $admin = (int)get_admin()->id;
        $first = \local_ustar\briefing\meetings::eligible_participants($admin);
        $start = $DB->perf_get_reads();
        \local_ustar\briefing\meetings::eligible_participants($admin);
        $single = $DB->perf_get_reads() - $start;
        $users = [];
        foreach (range(1, 12) as $i) {
            $users[] = $this->getDataGenerator()->create_user();
        }
        accounts::set_type($users[0]->id, accounts::TYPE_TEST);
        accounts::set_type($users[1]->id, accounts::TYPE_SERVICE);
        employment::register_pending($users[2]->id);
        $DB->set_field('user', 'suspended', 1, ['id' => $users[3]->id]);
        $start = $DB->perf_get_reads();
        $many = \local_ustar\briefing\meetings::eligible_participants($admin);
        $this->assertLessThanOrEqual($single + 1, $DB->perf_get_reads() - $start);
        $this->assertCount(count($first) + 8, $many);
        foreach ($users as $user) {
            $this->assertSame(accounts::participates($user->id), isset($many[$user->id]));
        }
        $this->assertArrayNotHasKey($admin, $many);
    }

    public function test_discussion_is_native_and_revoked_on_reassignment(): void {
        global $DB;
        $admin = (int)get_admin()->id;
        $assignee = (int)$this->assignee->id;
        $colleague = (int)$this->colleague->id;
        $token = str_repeat('a', 32);
        $id = communication::send($admin, 0, 'Private task context', [], $token, $this->task);
        $this->assertSame($id, communication::send($admin, 0, 'Private task context', [], $token, $this->task));
        $conversation = (int)$DB->get_field('messages', 'conversationid', ['id' => $id], MUST_EXIST);
        $this->assertSame(1, $DB->count_records('messages', ['conversationid' => $conversation]));
        $this->assertSame(0, messaging::count_conversation_members($conversation));

        $this->setUser($this->assignee);
        $this->assertFalse(messaging::is_user_in_conversation($assignee, $conversation));
        $this->assertTrue(discussion::inbox($assignee, 0, '')[0]['hasunread']);
        $this->assertSame([$id], discussion::thread($assignee, $this->task)['ids']);
        $this->assertFalse(discussion::inbox($assignee, 0, '')[0]['hasunread']);
        $this->assertTrue(chat_files::can_read($assignee, $id));
        $this->assertFalse(chat_files::can_read($colleague, $id));

        $this->setAdminUser();
        $due = (int)$DB->get_field('local_ustar_learning_tasks', 'dueat', ['id' => $this->task]);
        learning_tasks::revise($this->task, $admin, 1, $colleague, $due);
        $this->setUser($this->assignee);
        $this->assertSame([], discussion::inbox($assignee, 0, ''));
        $this->assertFalse(chat_files::can_read($assignee, $id));
        $this->assertTrue(chat_files::can_read($colleague, $id));
        $this->expectException(\invalid_parameter_exception::class);
        discussion::thread($assignee, $this->task);
    }

    public function test_personal_layout_cannot_change_canonical_work_or_another_users_plan(): void {
        global $DB;
        $this->setUser($this->assignee);
        $actor = (int)$this->assignee->id;
        $before = $DB->get_record('local_ustar_learning_tasks', ['id' => $this->task], '*', MUST_EXIST);
        $note = learning_tasks::create_note($actor, 'Private note', 'Fixture');
        personal_plan::update($actor, 0, 'plan_add', '', 0, 'Сегодня', 'gold');
        $layout = personal_plan::layout($actor);
        $lane = array_key_last($layout['columns']);
        personal_plan::update($actor, 1, 'plan_move', $lane, $this->task);
        $layout = personal_plan::layout($actor);
        $this->assertArrayHasKey($this->task, personal_plan::cards($actor, $layout, $lane, 0, '')['items']);
        $this->assertSame(0, personal_plan::cards($actor, $layout, personal_plan::DEFAULT_COLUMN, 0, '')['total']);
        $this->assertEquals($before, $DB->get_record('local_ustar_learning_tasks', ['id' => $this->task]));
        $this->expectException(\required_capability_exception::class);
        personal_plan::update($actor, 2, 'plan_move', $lane, $note['id']);
    }

    public function test_plan_rejects_a_stale_tab_and_preserves_cards_when_lane_is_deleted(): void {
        $this->setUser($this->assignee);
        $actor = (int)$this->assignee->id;
        personal_plan::update($actor, 0, 'plan_move', 'nextweek', $this->task);
        personal_plan::update($actor, 1, 'plan_remove', 'nextweek');
        $layout = personal_plan::layout($actor);
        $this->assertArrayHasKey($this->task, personal_plan::cards($actor, $layout, personal_plan::DEFAULT_COLUMN, 0, '')['items']);
        $this->setUser($this->colleague);
        $this->assertArrayHasKey('nextweek', personal_plan::layout((int)$this->colleague->id)['columns']);
        $this->setUser($this->assignee);
        $this->expectException(\moodle_exception::class);
        personal_plan::update($actor, 1, 'plan_edit', personal_plan::DEFAULT_COLUMN, 0, 'Старая вкладка');
    }

    public function test_privacy_erasure_only_removes_the_selected_authors_task_message_and_plan(): void {
        global $DB;
        $admin = (int)get_admin()->id;
        $actor = (int)$this->assignee->id;
        $other = communication::send($admin, 0, 'Keep this', [], '', $this->task);
        $this->setUser($this->assignee);
        $message = communication::send($actor, 0, 'Erase this', [], '', $this->task);
        personal_plan::update($actor, 0, 'plan_move', 'nextweek', $this->task);
        get_file_storage()->create_file_from_string(['contextid' => \context_system::instance()->id,
            'component' => 'local_ustar', 'filearea' => chat_files::AREA, 'itemid' => $message, 'userid' => $actor,
            'filepath' => '/', 'filename' => 'owned.txt'], 'Synthetic attachment');
        workplace::erase([$actor]);
        $this->assertFalse($DB->record_exists('messages', ['id' => $message]));
        $this->assertTrue($DB->record_exists('messages', ['id' => $other]));
        $this->assertFalse($DB->record_exists('files', ['filearea' => chat_files::AREA, 'itemid' => $message, 'filename' => 'owned.txt']));
        $this->assertSame(0, personal_plan::layout($actor)['revision']);
    }
}
