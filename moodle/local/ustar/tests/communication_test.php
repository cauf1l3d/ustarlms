<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(communication::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(chat_groups::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(chat_files::class)]
final class communication_test extends \advanced_testcase {
    private array $users;
    protected function setUp(): void {
        global $PAGE;
        parent::setUp(); $this->resetAfterTest(true); $this->setAdminUser();
        set_config('messaging', 1); set_config('messagingallusers', 1);
        $PAGE->set_context(\context_system::instance());
        $PAGE->set_url(new \moodle_url('/local/ustar/messages.php'));
        $role = $this->getDataGenerator()->create_role();
        foreach (['local/ustar:use', 'moodle/site:sendmessage'] as $cap) {
            assign_capability($cap, CAP_ALLOW, $role, \context_system::instance()->id);
        }
        $this->users = [];
        for ($i = 0; $i < 4; $i++) {
            $user = $this->getDataGenerator()->create_user(['firstname' => 'Chatfixture', 'lastname' => 'Person' . $i]);
            role_assign($role, $user->id, \context_system::instance()->id);
            $this->users[] = $user;
        }
        $this->setUser($this->users[0]);
    }
    private function group(): int {
        return chat_groups::create($this->users[0]->id, 'Рабочая группа', [$this->users[1]->id, $this->users[2]->id]);
    }
    public function test_multiple_native_groups_and_member_management(): void {
        global $DB;
        $id = $this->group(); $second = $this->group();
        $this->assertNotSame($id, $second);
        $this->assertSame(3, \core_message\api::count_conversation_members($id));
        chat_groups::change($this->users[0]->id, $id, 'rename', 'Новый заголовок');
        chat_groups::change($this->users[0]->id, $id, 'add', '', [$this->users[3]->id, $this->users[3]->id]);
        $this->assertSame(4, \core_message\api::count_conversation_members($id));
        $this->assertSame('Новый заголовок', $DB->get_field('message_conversations', 'name', ['id' => $id]));
        $message = communication::send($this->users[0]->id, $id, 'Файл', [], str_repeat('a', 32));
        get_file_storage()->create_file_from_string(['contextid' => \context_system::instance()->id,
            'component' => 'local_ustar', 'filearea' => chat_files::AREA, 'itemid' => $message,
            'filepath' => '/', 'filename' => 'fixture.txt'], 'Private fixture');
        $this->assertTrue(chat_files::can_read($this->users[3]->id, $message));
        chat_groups::change($this->users[0]->id, $id, 'remove', '', [$this->users[3]->id]);
        $this->assertFalse(chat_files::can_read($this->users[3]->id, $message));
        $this->assertFalse(chat_files::can_read($this->users[3]->id, $message + 100000));
    }
    public function test_member_cannot_manage_group(): void {
        $id = $this->group(); $this->setUser($this->users[1]);
        $this->expectException(\invalid_parameter_exception::class);
        chat_groups::change($this->users[1]->id, $id, 'rename', 'Intrusion');
    }
    public function test_creator_cannot_remove_self(): void {
        $id = $this->group();
        $this->expectException(\invalid_parameter_exception::class);
        chat_groups::change($this->users[0]->id, $id, 'remove', '', [$this->users[0]->id]);
    }
    public function test_native_course_group_is_not_managed_by_workchat_controller(): void {
        $conversation = \core_message\api::create_conversation(\core_message\api::MESSAGE_CONVERSATION_TYPE_GROUP,
            [$this->users[0]->id, $this->users[1]->id], 'Course group');
        $this->assertFalse(chat_groups::can_manage($this->users[0]->id, $conversation->id));
    }
    public function test_retry_receipt_deduplicates_and_detects_changed_payload(): void {
        global $DB;
        $id = $this->group(); $token = str_repeat('b', 32);
        $first = communication::send($this->users[0]->id, $id, 'Once', [], $token);
        $this->assertSame($first, communication::send($this->users[0]->id, $id, 'Once', [], $token));
        $this->assertSame(1, $DB->count_records('messages', ['conversationid' => $id]));
        $this->expectException(\invalid_parameter_exception::class);
        communication::send($this->users[0]->id, $id, 'Changed', [], $token);
    }
    public function test_history_opens_latest_page_and_native_deletion_hides_files(): void {
        global $DB;
        $id = $this->group(); $ids = [];
        for ($i = 0; $i < 75; $i++) {
            $ids[] = $DB->insert_record('messages', (object)['useridfrom' => $this->users[0]->id,
                'conversationid' => $id, 'fullmessage' => 'Message ' . $i, 'fullmessageformat' => FORMAT_PLAIN,
                'smallmessage' => 'Message ' . $i, 'timecreated' => 10000 + $i]);
        }
        $page = communication::conversation($this->users[0]->id, $id);
        $this->assertCount(50, $page['messages']);
        $this->assertSame((int)$ids[74], end($page['messages'])['id']);
        $this->assertTrue($page['hasolder']);
        $older = communication::conversation($this->users[0]->id, $id, 50);
        $this->assertCount(25, $older['messages']); $this->assertFalse($older['hasolder']);
        $this->assertTrue(chat_files::can_read($this->users[1]->id, $ids[74]));
        \core_message\api::delete_message($this->users[1]->id, $ids[74]);
        $this->assertFalse(chat_files::can_read($this->users[1]->id, $ids[74]));
    }
    public function test_outsider_cannot_read_even_with_admin_authority(): void {
        $id = $this->group(); $this->setAdminUser();
        $this->expectException(\invalid_parameter_exception::class);
        communication::conversation(get_admin()->id, $id);
    }
    public function test_actor_id_cannot_impersonate_another_user(): void {
        $id = $this->group();
        $this->expectException(\invalid_parameter_exception::class);
        communication::send($this->users[1]->id, $id, 'Impersonation');
    }
    public function test_disabled_conversation_rejects_send(): void {
        global $DB;
        $id = $this->group(); $DB->set_field('message_conversations', 'enabled', 0, ['id' => $id]);
        $this->expectException(\invalid_parameter_exception::class);
        communication::send($this->users[0]->id, $id, 'Disabled');
    }
    public function test_pending_employee_cannot_create_group(): void {
        employment::register_pending($this->users[0]->id);
        $this->expectException(\invalid_parameter_exception::class); $this->group();
    }
    public function test_private_recipient_is_not_invited(): void {
        set_user_preference('message_blocknoncontacts', \core_message\api::MESSAGE_PRIVACY_CONTACTS, $this->users[2]->id);
        $this->expectException(\invalid_parameter_exception::class); $this->group();
    }
    public function test_local_path_cannot_be_supplied_as_an_upload(): void {
        $this->expectException(\invalid_parameter_exception::class);
        chat_files::validate([['filename' => 'fixture.txt', 'size' => 1, 'tmp' => __FILE__]]);
    }
}
