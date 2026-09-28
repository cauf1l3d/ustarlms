<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(target_core::class)]
final class notification_delivery_test extends \advanced_testcase {
    public function test_critical_notification_has_local_delivery_and_disabled_bitrix(): void {
        global $DB;
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        $data = [
            'userid' => (int)$user->id, 'severity' => 'critical',
            'eventtype' => 'fixture', 'subject' => 'Subject', 'message' => 'Body',
            'idempotencykey' => 'fixture:critical',
        ];
        $id = target_core::notify($data);
        $this->assertSame($id, target_core::notify($data));
        $this->assertSame(1, $DB->count_records('local_ustar_notify_delivery', [
            'notificationid' => $id, 'channel' => 'ustar', 'status' => 'delivered',
        ]));
        $bitrix = $DB->get_record('local_ustar_notify_delivery', [
            'notificationid' => $id, 'channel' => 'bitrix',
        ], '*', MUST_EXIST);
        $this->assertSame('disabled', $bitrix->status);
        $this->assertNull($bitrix->nextattempt);
        $this->expectException(\coding_exception::class);
        target_core::notify(array_replace($data, ['eventtype' => 'different_event']));
    }
}
