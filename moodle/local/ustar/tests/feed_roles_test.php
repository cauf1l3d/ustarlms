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
        $this->grant((int)$author->id, ['local/ustar:use', 'local/ustar:feedpublish']);
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
