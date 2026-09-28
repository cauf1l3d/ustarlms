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

    public function test_site_admin_can_open_feed_management_without_employee_participation(): void {
        global $USER;
        $this->setAdminUser();
        $this->assertTrue(feed_access::can_manage((int)$USER->id));
        feed_access::require_reader((int)$USER->id);
        $this->assertTrue(true);
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
