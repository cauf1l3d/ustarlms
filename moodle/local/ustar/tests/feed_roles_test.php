<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Exact ACL checks for the independently granted publisher and moderator roles. */
#[\PHPUnit\Framework\Attributes\CoversClass(feed_access::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(feed_service::class)]
final class feed_roles_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    private function grant(int $userid, array $allows, array $prohibits = []): void {
        $context = \context_system::instance();
        $roleid = create_role('Feed test role', 'feed_test_' . random_string(10), 'Isolated grant');
        foreach ($allows as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $context->id);
        }
        foreach ($prohibits as $capability) {
            assign_capability($capability, CAP_PROHIBIT, $roleid, $context->id);
        }
        role_assign($roleid, $userid, $context->id);
        accesslib_clear_all_caches(true);
    }

    public function test_ordinary_employee_can_author_personal_posts_without_elevated_rights(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $context = \context_system::instance();
        $this->assertTrue(has_capability('local/ustar:feedcreate', $context));
        $this->assertFalse(has_capability('local/ustar:feededit', $context));
        $this->assertFalse(has_capability('local/ustar:feedpublish', $context));
        $this->assertFalse(has_capability('local/ustar:feedpublishacademy', $context));
        $this->assertFalse(has_capability('local/ustar:feedmoderate', $context));
        $this->assertFalse(has_capability('local/ustar:feedmanage', $context));

        $postid = feed_service::create((int)$user->id, 'person', (string)$user->id,
            ['all'], 'Личная публикация сотрудника', true, 0, [], md5('ordinary-feed-author'));
        global $DB;
        $this->assertSame('published',
            $DB->get_field('local_ustar_feed_posts', 'status', ['id' => $postid]));
    }

    public function test_personal_recipient_is_enforced_for_feed_and_direct_read(): void {
        $author = $this->getDataGenerator()->create_user();
        $recipient = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->setUser($author);
        $id = feed_service::create((int)$author->id, 'person', (string)$author->id,
            ['user:' . $recipient->id], 'Адресное сообщение', true, 0, [], md5('private-feed-person'));
        $this->assertSame($id, (int)feed_access::readable($id, (int)$author->id)->id);
        $this->setUser($recipient);
        $this->assertSame($id, (int)feed_access::readable($id, (int)$recipient->id)->id);
        $this->setUser($other);
        $this->assertSame([], feed_query::page((int)$other->id)['posts']);
        $this->expectException(\required_capability_exception::class);
        feed_access::readable($id, (int)$other->id);
    }

    public function test_academy_can_target_position_without_leaking_to_unassigned_employee(): void {
        global $USER;
        $this->setAdminUser();
        $positions = people::position_map(structure::get(structure::NAME_STRUCTURE));
        $positionid = (string)array_key_first($positions);
        $this->assertNotSame('', $positionid);
        $id = feed_service::create((int)$USER->id, 'academy', 'academy',
            ['position:' . $positionid], 'Для должности', true, 0, [], md5('position-feed'));
        $employee = $this->getDataGenerator()->create_user();
        $this->setUser($employee);
        $this->assertSame([], feed_query::page((int)$employee->id)['posts']);
        $this->expectException(\required_capability_exception::class);
        feed_access::readable($id, (int)$employee->id);
    }

    public function test_site_admin_can_open_feed_management_without_employee_participation(): void {
        global $USER;
        $this->setAdminUser();
        $this->assertTrue(feed_access::can_manage((int)$USER->id));
        feed_access::require_reader((int)$USER->id);
        $this->assertTrue(true);
    }

    public function test_feed_attachment_is_saved_once_in_owner_library_and_survives_source_removal(): void {
        global $USER, $DB;
        $owner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->setUser($owner);
        $postid = feed_service::create((int)$owner->id, 'person', (string)$owner->id,
            ['all'], 'Документ отдела', true, 0, [], md5('feed-library-copy'));
        $file = get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id, 'component' => 'local_ustar',
            'filearea' => feed_files::AREA, 'itemid' => $postid,
            'filepath' => '/v1/', 'filename' => 'guide.pdf',
        ], 'Private attachment');
        $savedid = feed_files::save_to_library($postid, (int)$owner->id, (int)$file->get_id());
        $this->assertSame($savedid,
            feed_files::save_to_library($postid, (int)$owner->id, (int)$file->get_id()));
        $this->assertSame(1, $DB->count_records('local_ustar_feed_saves', ['userid' => (int)$owner->id]));
        feed_service::remove($postid, (int)$owner->id, 1);
        $this->assertSame('guide.pdf', feed_files::saved_for_library((int)$owner->id)['items'][0]['title']);
        $this->setUser($other);
        $this->expectException(\invalid_parameter_exception::class);
        feed_files::remove_from_library($savedid, (int)$other->id);
    }

    public function test_editor_can_revise_another_author_but_cannot_publish_their_draft(): void {
        global $DB;
        $author = $this->getDataGenerator()->create_user();
        $editor = $this->getDataGenerator()->create_user();

        $this->setUser($author);
        $postid = feed_service::create((int)$author->id, 'person', (string)$author->id,
            ['all'], 'Черновик автора', false, 0, [], md5('editor-feed-draft'));

        $this->grant((int)$editor->id, ['local/ustar:use', 'local/ustar:feededit'],
            ['local/ustar:feedpublish']);
        $this->setUser($editor);
        feed_service::revise($postid, (int)$editor->id, 1, 'Отредактированный черновик', false);
        $this->assertSame('Отредактированный черновик',
            $DB->get_field('local_ustar_feed_posts', 'body', ['id' => $postid]));

        $this->expectException(\required_capability_exception::class);
        feed_service::revise($postid, (int)$editor->id, 2, 'Попытка публикации', true);
    }

    public function test_department_role_without_current_manager_scope_cannot_publish(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->grant((int)$user->id, ['local/ustar:use', 'local/ustar:feedpublish',
            'local/ustar:feedpublishdepartment']);
        $this->setUser($user);
        $this->assertSame([], feed_access::manageable_departments((int)$user->id));
        $this->expectException(\required_capability_exception::class);
        feed_access::assert_publisher((int)$user->id, 'department', 'retail', ['retail']);
    }

    public function test_academy_role_is_explicit_and_pending_user_is_denied(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->grant((int)$user->id, ['local/ustar:use', 'local/ustar:feedpublish',
            'local/ustar:feedpublishacademy', 'local/ustar:feedsetaudience']);
        $this->setUser($user);
        feed_access::assert_publisher((int)$user->id, 'academy', 'academy', ['all']);
        employment::register_pending((int)$user->id);
        $this->expectException(\required_capability_exception::class);
        feed_access::assert_publisher((int)$user->id, 'academy', 'academy', ['all']);
    }

    public function test_moderator_can_remove_without_publish_permission(): void {
        global $DB;
        $author = $this->getDataGenerator()->create_user();
        $moderator = $this->getDataGenerator()->create_user();
        $this->grant((int)$author->id, ['local/ustar:use', 'local/ustar:feedcreate']);
        $this->grant((int)$moderator->id, ['local/ustar:use', 'local/ustar:feedmoderate'],
            ['local/ustar:feedpublish']);
        $this->setUser($author);
        $postid = feed_service::create((int)$author->id, 'person', (string)$author->id,
            ['all'], 'Проверка модерации', true, 0, [], md5('feed-role-test'));
        $this->setUser($moderator);
        $this->assertFalse(has_capability('local/ustar:feedpublish', \context_system::instance()));
        feed_service::remove($postid, (int)$moderator->id, 1, 'Нарушение правил');
        $this->assertSame('hidden', $DB->get_field('local_ustar_feed_posts', 'status', ['id' => $postid]));
    }
}
