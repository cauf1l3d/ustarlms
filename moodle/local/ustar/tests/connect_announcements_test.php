<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

use local_ustar\connect\announcements;
use local_ustar\connect\bot_service;
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
        $CFG->ustar_connect_bot_enabled = true;
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

    public function test_direct_text_and_file_are_fenced_to_one_eligible_recipient(): void {
        global $DB;
        $recipient = $this->recipient('77777');
        $outsider = $this->recipient('88888');
        $this->assertFalse(announcements::can_manage($outsider));
        $this->setAdminUser();
        $actor = (int)get_admin()->id;
        $post = feed_service::create($actor, 'academy', 'academy', ['user:' . $recipient], 'Private fixture body',
            true, 0, [], bin2hex(random_bytes(16)));
        $file = get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id, 'component' => 'local_ustar',
            'filearea' => feed_files::AREA, 'itemid' => $post, 'filepath' => '/v1/', 'filename' => 'schedule.txt',
        ], 'Approved file contents');
        $preview = announcements::preview($actor, $post);
        $this->assertSame([$recipient], array_column($preview['people'], 'id'));
        $this->assertSame(1, announcements::enqueue($actor, $post, $preview['fingerprint'], [$recipient]));
        $this->assertSame([], announcements::preview($actor, $post)['people']);
        $this->assertNull(notification_delivery::lease('tg_delivery', 'telegram', '123456'));
        $lease = notification_delivery::lease('tg_delivery', 'telegram', '123456', null, true);
        $this->assertSame('Private fixture body', $lease['broadcast']['text']);
        $this->assertSame('77777', $lease['broadcast']['subject']);
        $this->assertSame([(int)$file->get_id()], array_column($lease['broadcast']['files'], 'id'));
        $read = notification_delivery::broadcast_file($lease['delivery'], $lease['lease'], (int)$file->get_id(),
            'tg_delivery', 'telegram', '123456');
        $this->assertSame('Approved file contents', $read->get_content());
        $permit = notification_delivery::begin_v3($lease['delivery'], $lease['lease'], 'tg_delivery', 'telegram', '123456',
            null, $lease['broadcast']['stamp']);
        $this->assertSame('feed_broadcast', $permit['event']);
        $this->assertSame($lease['broadcast']['stamp'], $permit['stamp']);
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
        $lease = notification_delivery::lease('tg_delivery', 'telegram', '123456', null, true);
        feed_service::revise($post, $actor, 1, 'Edited version');
        $this->assertSame(['status' => 'cancelled'],
            notification_delivery::begin_v3($lease['delivery'], $lease['lease'], 'tg_delivery', 'telegram', '123456',
                null, $lease['broadcast']['stamp']));
    }

    public function test_legacy_notice_cannot_be_promoted_to_direct_delivery(): void {
        $recipient = $this->recipient('77777');
        $this->setAdminUser();
        $actor = (int)get_admin()->id;
        $post = feed_service::create($actor, 'academy', 'academy', ['user:' . $recipient], 'Private old body',
            true, 0, [], bin2hex(random_bytes(16)));
        workflow_notifications::enqueue($recipient, 'feed_announcement', 'Уведомление', 'Старый формат',
            '/local/ustar/feed.php?postid=' . $post, 'feed_announcement:' . $post . ':1:' . $recipient);
        $this->assertSame([$recipient], array_column(announcements::preview($actor, $post)['people'], 'id'));
        $lease = notification_delivery::lease('tg_delivery', 'telegram', '123456', null, true);
        $this->assertArrayNotHasKey('broadcast', $lease);
        $permit = notification_delivery::begin_v3($lease['delivery'], $lease['lease'], 'tg_delivery', 'telegram', '123456');
        $this->assertSame('feed_announcement', $permit['event']);
        $this->assertArrayNotHasKey('stamp', $permit);
        $this->assertStringNotContainsString('Private old body', json_encode($permit));
    }

    public function test_linked_operator_can_queue_only_explicitly_selected_people_from_bot(): void {
        global $DB;
        $recipient = $this->recipient('77777');
        $operator = $this->recipient('88888');
        $other = $this->recipient('99999');
        $context = \context_system::instance();
        $role = create_role('Broadcast operator', 'broadcast_operator', '');
        assign_capability('local/ustar:admin', CAP_ALLOW, $role, $context->id);
        assign_capability('local/ustar:feedmanage', CAP_ALLOW, $role, $context->id);
        role_assign($role, $operator, $context->id);
        $this->setAdminUser();
        feed_service::create((int)get_admin()->id, 'academy', 'academy',
            ['user:' . $recipient, 'user:' . $other], 'Broadcast fixture', true, 0, [], bin2hex(random_bytes(16)));
        $sender = new identity('telegram', '123456', '88888');
        $event = 1;
        $update = static function(string $text, string $callback = '') use ($sender, &$event): array {
            return bot_service::update($sender, ['auth_date' => time(), 'provider' => 'telegram', 'tenant' => '123456',
                'subject' => '88888', 'message' => '1', 'event' => (string)$event++, 'text' => $text,
                'media' => '', 'callback' => $callback]);
        };
        $button = static function(array $view, string $label): string {
            foreach ($view['buttons'] as $action) {
                if (str_contains($action['label'], $label)) {
                    return $action['callback'];
                }
            }
            throw new \coding_exception('Broadcast button missing: ' . $label);
        };
        $list = $update('', $button($update('/start'), 'Рассылки'));
        $select = $update('', $button($list, 'Broadcast fixture'));
        $name = fullname($DB->get_record('user', ['id' => $recipient], '*', MUST_EXIST));
        $selected = $update('', $button($select, $name));
        $review = $update('', $button($selected, 'Проверить рассылку'));
        $send = $button($review, 'Отправить');
        $update('', $send);
        $update('', $send);
        $this->assertSame([$recipient], array_map('intval',
            $DB->get_fieldset_select('local_ustar_notifications', 'userid', 'eventtype = ?', ['feed_broadcast'])));
        $this->assertSame($operator, (int)$DB->get_field('local_ustar_feed_events', 'actoruserid',
            ['action' => 'telegram_broadcast'], MUST_EXIST));
    }
}
