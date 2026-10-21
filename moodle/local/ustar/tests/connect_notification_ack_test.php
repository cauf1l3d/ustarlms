<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

use local_ustar\connect\notification_acknowledgement;

#[\PHPUnit\Framework\Attributes\CoversClass(target_core::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(communication::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(notification_acknowledgement::class)]
final class connect_notification_ack_test extends \advanced_testcase {
    private \stdClass $employee;
    protected function setUp(): void {
        parent::setUp(); $this->resetAfterTest(true); $this->preventResetByRollback();
        $this->employee = $this->getDataGenerator()->create_user();
        $role = $this->getDataGenerator()->create_role();
        assign_capability('local/ustar:use', CAP_ALLOW, $role, \context_system::instance()->id);
        role_assign($role, $this->employee->id, \context_system::instance()->id);
        $this->setUser($this->employee);
    }
    private function notice(?int $userid = null): int {
        return target_core::notify(['userid' => $userid ?? $this->employee->id,
            'eventtype' => 'general', 'severity' => 'action', 'subject' => 'Synthetic receipt',
            'message' => 'Acknowledgement must not change this content.', 'idempotencykey' => random_string(32)]);
    }
    private function own(int $id, string $source = 'local'): array {
        $this->assertTrue(class_exists(notification_acknowledgement::class), 'Own ACK adapter is not implemented');
        return notification_acknowledgement::own($id, $source);
    }
    private function denied(callable $call): void {
        try { $call(); } catch (\moodle_exception | \InvalidArgumentException $error) { return; }
        $this->fail('Expected own-actor acknowledgement denial');
    }
    public function test_first_ack_timestamp_survives_repeated_ack_and_read(): void {
        global $DB;
        $id = $this->notice(); target_core::acknowledge_notification($id, $this->employee->id);
        $DB->set_field('local_ustar_notifications', 'ackat', 1234, ['id' => $id]);
        communication::mark_notification($this->employee->id, $id);
        target_core::acknowledge_notification($id, $this->employee->id);
        $row = $DB->get_record('local_ustar_notifications', ['id' => $id]);
        $this->assertSame('acknowledged', $row->status); $this->assertSame(1234, (int)$row->ackat);
    }
    public function test_mark_all_read_does_not_acknowledge_or_clear_existing_ack(): void {
        global $DB;
        $read = $this->notice(); $ack = $this->notice();
        target_core::acknowledge_notification($ack, $this->employee->id);
        $first = $DB->get_field('local_ustar_notifications', 'ackat', ['id' => $ack]);
        communication::mark_all_notifications($this->employee->id);
        $this->assertSame('read', $DB->get_field('local_ustar_notifications', 'status', ['id' => $read]));
        $this->assertNull($DB->get_field('local_ustar_notifications', 'ackat', ['id' => $read]));
        $this->assertSame('acknowledged', $DB->get_field('local_ustar_notifications', 'status', ['id' => $ack]));
        $this->assertSame($first, $DB->get_field('local_ustar_notifications', 'ackat', ['id' => $ack]));
    }
    public function test_own_ack_works_without_messenger_link_and_keeps_delivery_history(): void {
        global $DB;
        $id = $this->notice();
        $deliveries = $DB->get_records('local_ustar_notify_delivery', ['notificationid' => $id]);
        $result = $this->own($id);
        $this->assertSame(['source' => 'local', 'id' => $id, 'status' => 'acknowledged',
            'acknowledged_at' => (int)$DB->get_field('local_ustar_notifications', 'ackat', ['id' => $id])], $result);
        $this->assertSame($result, $this->own($id));
        $this->assertSame(0, $DB->count_records('local_ustar_conn_links', ['userid' => $this->employee->id]));
        $this->assertEquals($deliveries, $DB->get_records('local_ustar_notify_delivery', ['notificationid' => $id]));
    }
    public function test_already_read_notice_can_be_explicitly_acknowledged(): void {
        $id = $this->notice(); communication::mark_notification($this->employee->id, $id);
        $this->assertSame('acknowledged', $this->own($id)['status']);
    }
    public function test_foreign_recipient_core_source_and_invalid_id_are_denied(): void {
        global $DB;
        $own = $this->notice(); $other = $this->getDataGenerator()->create_user();
        $foreign = $this->notice($other->id);
        foreach ([[$foreign, 'local'], [$own, 'moodle'], [$own, 'unknown'], [0, 'local'], [-1, 'local']] as [$id, $source]) {
            $this->denied(fn() => $this->own($id, $source));
        }
        $this->assertSame(0, $DB->count_records('local_ustar_notifications', ['status' => 'acknowledged']));
    }
    public function test_matching_core_and_local_ids_keep_independent_receipts(): void {
        global $DB;
        $id = $this->notice();
        $coreid = $DB->insert_record('notifications', (object)[
            'useridfrom' => get_admin()->id, 'useridto' => $this->employee->id, 'subject' => 'Synthetic core notice',
            'fullmessage' => 'Core notification', 'fullmessageformat' => FORMAT_PLAIN, 'fullmessagehtml' => '',
            'smallmessage' => '', 'component' => 'moodle', 'eventtype' => 'instantmessage',
            'contexturl' => '', 'contexturlname' => '', 'timeread' => null, 'timecreated' => time()]);
        if ((int)$coreid !== $id) {
            $this->assertFalse($DB->record_exists('notifications', ['id' => $id]));
            $DB->set_field('notifications', 'id', $id, ['id' => $coreid]);
        }
        $this->denied(fn() => $this->own($id, 'moodle'));
        $this->assertNull($DB->get_field('notifications', 'timeread', ['id' => $id]));
        $this->own($id);
        $this->assertSame('acknowledged', $DB->get_field('local_ustar_notifications', 'status', ['id' => $id]));
        $this->assertNull($DB->get_field('notifications', 'timeread', ['id' => $id]));
    }
    public function test_pending_and_terminated_employee_are_denied(): void {
        global $DB;
        $id = $this->notice(); employment::register_pending($this->employee->id);
        foreach (['pending', 'terminated', 'suspended'] as $status) {
            $DB->set_field('local_ustar_employment', 'status', $status, ['userid' => $this->employee->id]);
            $this->denied(fn() => $this->own($id));
        }
        $this->assertNull($DB->get_field('local_ustar_notifications', 'ackat', ['id' => $id]));
    }
    public function test_suspended_deleted_unconfirmed_and_guest_are_denied(): void {
        global $DB;
        $id = $this->notice();
        foreach (['suspended' => 1, 'deleted' => 1, 'confirmed' => 0] as $field => $value) {
            $DB->set_field('user', $field, $value, ['id' => $this->employee->id]);
            $this->denied(fn() => $this->own($id));
            $DB->set_field('user', $field, 1-$value, ['id' => $this->employee->id]);
        }
        $this->setGuestUser(); $this->denied(fn() => $this->own($id));
        $this->assertNull($DB->get_field('local_ustar_notifications', 'ackat', ['id' => $id]));
    }
    public function test_view_as_and_missing_capability_are_denied(): void {
        global $DB, $SESSION;
        $id = $this->notice(); $role = $this->getDataGenerator()->create_role();
        assign_capability('local/ustar:viewas', CAP_ALLOW, $role, \context_system::instance()->id);
        role_assign($role, $this->employee->id, \context_system::instance()->id);
        accesslib_clear_all_caches(true); $this->setUser($this->employee);
        $SESSION->ustar_view_position = 'synthetic'; $this->denied(fn() => $this->own($id));
        unset($SESSION->ustar_view_position);
        assign_capability('local/ustar:use', CAP_PROHIBIT, $role, \context_system::instance()->id);
        accesslib_clear_all_caches(true); $this->setUser($this->employee);
        $this->denied(fn() => $this->own($id));
        $this->assertNull($DB->get_field('local_ustar_notifications', 'ackat', ['id' => $id]));
    }
    public function test_native_ack_is_post_only_with_strict_payload_and_sesskey(): void {
        global $DB;
        $id = $this->notice();
        $this->assertTrue(class_exists(notification_acknowledgement::class), 'Own ACK adapter is not implemented');
        $payload = ['id' => (string)$id, 'source' => 'local', 'sesskey' => sesskey()];
        foreach (['GET', 'PUT', 'DELETE'] as $method) {
            $this->denied(fn() => notification_acknowledgement::handle($method, $payload));
        }
        foreach ([['sesskey' => 'bad'], ['id' => '1e3'], ['source' => 'moodle'], ['userid' => '999']] as $extra) {
            $this->denied(fn() => notification_acknowledgement::handle('POST', $extra + $payload));
        }
        $this->assertNull($DB->get_field('local_ustar_notifications', 'ackat', ['id' => $id]));
        $this->assertSame('acknowledged', notification_acknowledgement::handle('POST', $payload)['status']);
    }
    public function test_inbox_exposes_confirmation_separately_from_read(): void {
        $id = $this->notice();
        $unread = communication::counts($this->employee->id)['notifications'];
        $this->assertGreaterThanOrEqual(1, $unread);
        $rows = array_column(communication::notifications($this->employee->id), null, 'id');
        $this->assertTrue($rows[$id]['canack'] ?? false);
        $this->assertFalse($rows[$id]['acknowledged'] ?? false);
        $this->own($id);
        $this->assertSame($unread - 1, communication::counts($this->employee->id)['notifications']);
        $rows = array_column(communication::notifications($this->employee->id), null, 'id');
        $this->assertTrue($rows[$id]['acknowledged']); $this->assertFalse($rows[$id]['canack']);
        $this->assertNotEmpty($rows[$id]['acktime']);
    }
}
