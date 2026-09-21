<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(target_core::class)]
final class evidence_lifecycle_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
    }

    public function test_every_evidence_type_can_be_renewed_revoked_and_restored(): void {
        global $DB, $USER;

        $user = $this->getDataGenerator()->create_user();
        $types = [
            'learning' => 'completed',
            'assessment' => 'passed',
            'practice' => 'observed',
            'manager_review' => 'passed',
            'checklist' => 'completed',
            'certification' => 'passed',
        ];

        foreach ($types as $type => $outcome) {
            $originalid = target_core::record_evidence([
                'userid' => (int)$user->id,
                'skillid' => 'skill_' . $type,
                'positionid' => 'retail',
                'evidencetype' => $type,
                'sourcekind' => 'fixture',
                'sourceid' => $type . '_v1',
                'outcome' => $outcome,
                'idempotencykey' => 'fixture:' . $type . ':v1',
                'validfrom' => time() - DAYSECS,
                'expiresat' => time() + DAYSECS,
            ], (int)$USER->id);

            $replacementid = target_core::renew_evidence($originalid, [
                'sourceid' => $type . '_v2',
                'idempotencykey' => 'fixture:' . $type . ':v2',
                'reason' => 'Scheduled renewal',
                'validfrom' => time(),
                'expiresat' => time() + (30 * DAYSECS),
            ], (int)$USER->id);

            $this->assertNotSame($originalid, $replacementid);
            $this->assertFalse(target_core::evidence_is_valid($originalid));
            $this->assertTrue(target_core::evidence_is_valid($replacementid));
            $this->assertSame(
                $replacementid,
                target_core::renew_evidence($originalid, [
                    'sourceid' => $type . '_v2',
                    'idempotencykey' => 'fixture:' . $type . ':v2',
                    'reason' => 'Scheduled renewal',
                    'validfrom' => time(),
                    'expiresat' => time() + (30 * DAYSECS),
                ], (int)$USER->id)
            );

            target_core::append_evidence_event(
                $replacementid,
                'revoked',
                'Source was withdrawn',
                (int)$USER->id
            );
            $this->assertFalse(target_core::evidence_is_valid($replacementid));

            target_core::restore_evidence(
                $replacementid,
                'Source was verified again',
                (int)$USER->id
            );
            $this->assertTrue(target_core::evidence_is_valid($replacementid));
            $this->assertSame(
                3,
                $DB->count_records_select(
                    'local_ustar_evidence_evt',
                    'evidenceid IN (:originalid,:replacementid)',
                    ['originalid' => $originalid, 'replacementid' => $replacementid]
                )
            );
        }
    }

    public function test_restore_rejects_non_revoked_or_expired_evidence(): void {
        global $USER;

        $user = $this->getDataGenerator()->create_user();
        $id = target_core::record_evidence([
            'userid' => (int)$user->id,
            'evidencetype' => 'learning',
            'sourcekind' => 'fixture',
            'sourceid' => 'expired',
            'outcome' => 'completed',
            'idempotencykey' => 'fixture:expired',
            'validfrom' => time() - (2 * DAYSECS),
            'expiresat' => time() - DAYSECS,
        ], (int)$USER->id);

        $this->assertFalse(target_core::evidence_is_valid($id));
        $this->expectException(\invalid_parameter_exception::class);
        target_core::restore_evidence($id, 'Invalid restore', (int)$USER->id);
    }
}
