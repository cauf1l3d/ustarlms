<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

use local_ustar\connect\announcements;
use local_ustar\connect\identity;
use local_ustar\connect\identity_service;
use local_ustar\connect\notification_delivery;
use local_ustar\connect\notification_preferences;

#[\PHPUnit\Framework\Attributes\CoversClass(announcements::class)]
final class connect_announcements_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->preventResetByRollback();
        global $CFG;
        $CFG->ustar_connect_channels = ['telegram' => ['tenant' => '123456', 'username' => 'ustarcorp_bot']];
        $CFG->ustar_connect_notifications_enabled = true;
        $CFG->ustar_connect_notify_since = time() - 60;
        $CFG->ustar_connect_notification_presentation_enabled = true;
        $CFG->ustar_connect_ack_enabled = true;
        $CFG->ustar_connect_ack_since = time() - 60;
        $CFG->ustar_connect_announcements_enabled = true;
        unset($CFG->ustar_connect_notify_userids);
    }

    private function recipient(string $subject): int {
        $user = $this->getDataGenerator()->create_user();
        $context = \context_system::instance();
        $role = create_role('Announcement fixture', 'notice_' . random_string(8), 'Fixture');
        assign_capability('local/ustar:use', CAP_ALLOW, $role, $context->id);
        role_assign($role, $user->id, $context->id);
        $this->setUser($user);
        $challenge = identity_service::issue('telegram', '123456');
        identity_service::claim_verified($challenge['code'], new identity('telegram', '123456', $subject),
            hash('sha256', $challenge['code']));
        identity_service::confirm($challenge['code']);
        notification_preferences::save_own(['enabled' => true, 'categories' => ['feed'], 'quiet_start' => '00:00',
            'quiet_end' => '00:00'] + notification_preferences::defaults());
        return (int)$user->id;
    }

    public function test_scope_queue_dedup_and_privacy_of_v3_wire(): void {
        global $DB;
        $recipient = $this->recipient('77777');
        $outsider = $this->recipient('88888');
        $this->assertFalse(announcements::can_manage($outsider));
        $this->setAdminUser();
        $actor = (int)get_admin()->id;
        $post = feed_service::create($actor, 'academy', 'academy', ['user:' . $recipient], 'Private fixture body',
            true, 0, [], bin2hex(random_bytes(16)));
        $preview = announcements::preview($actor, $post);
        $this->assertSame([$recipient], array_column($preview['people'], 'id'));
        $this->assertSame(1, announcements::enqueue($actor, $post, $preview['fingerprint'], [$recipient]));
        $this->assertSame([], announcements::preview($actor, $post)['people']);
        $lease = notification_delivery::lease('tg_delivery', 'telegram', '123456');
        $permit = notification_delivery::begin_v3($lease['delivery'], $lease['lease'], 'tg_delivery', 'telegram', '123456');
        $this->assertSame('feed_announcement', $permit['event']);
        $this->assertSame('77777', $permit['subject']);
        $this->assertStringNotContainsString('Private fixture body', json_encode($permit));
        $this->assertSame(1, $DB->count_records('local_ustar_notify_delivery', ['channel' => 'telegram']));
        $this->expectException(\invalid_parameter_exception::class);
        announcements::enqueue($actor, $post, $preview['fingerprint'], [$recipient]);
    }

    public function test_edited_publication_is_cancelled_at_begin(): void {
        $recipient = $this->recipient('77777');
        $this->setAdminUser();
        $actor = (int)get_admin()->id;
        $post = feed_service::create($actor, 'academy', 'academy', ['user:' . $recipient], 'Old version',
            true, 0, [], bin2hex(random_bytes(16)));
        $preview = announcements::preview($actor, $post);
        announcements::enqueue($actor, $post, $preview['fingerprint'], [$recipient]);
        $lease = notification_delivery::lease('tg_delivery', 'telegram', '123456');
        feed_service::revise($post, $actor, 1, 'Edited version');
        $this->assertSame(['status' => 'cancelled'],
            notification_delivery::begin_v3($lease['delivery'], $lease['lease'], 'tg_delivery', 'telegram', '123456'));
    }
}
