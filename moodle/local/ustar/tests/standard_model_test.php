<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(standard_model::class)]
final class standard_model_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
    }

    public function test_publishing_new_version_archives_previous_without_mutating_it(): void {
        global $DB, $USER;

        $standard = standard_model::create(
            'retail_safety',
            'Безопасная работа в торговом зале',
            'Обязательные подтверждения допуска',
            (int)$USER->id
        );
        $first = standard_model::save_version((int)$standard->id, [
            'requirements' => [[
                'type' => 'learning',
                'outcome' => 'completed',
                'sourcekind' => 'course',
                'sourceid' => '101',
            ]],
            'renewalpolicy' => route_model::RENEW_EXPIRY,
            'validdays' => 365,
        ], (int)$USER->id);
        $publishedfirst = standard_model::publish(
            (int)$standard->id,
            (int)$first->id,
            (int)$USER->id
        );
        $this->assertSame(standard_model::STATUS_PUBLISHED, (string)$publishedfirst->status);
        $this->assertSame(
            (int)$first->id,
            (int)standard_model::current('retail_safety')->id
        );

        $second = standard_model::save_version((int)$standard->id, [
            'requirements' => [
                [
                    'type' => 'learning',
                    'outcome' => 'completed',
                    'sourcekind' => 'course',
                    'sourceid' => '101',
                ],
                [
                    'type' => 'manager_review',
                    'outcome' => 'passed',
                    'sourcekind' => 'checklist',
                    'sourceid' => 'retail_safety_review',
                ],
            ],
            'renewalpolicy' => route_model::RENEW_MANUAL,
        ], (int)$USER->id);
        standard_model::publish((int)$standard->id, (int)$second->id, (int)$USER->id);

        $this->assertSame(
            standard_model::STATUS_ARCHIVED,
            (string)$DB->get_field('local_ustar_standard_ver', 'status', ['id' => $first->id])
        );
        $this->assertSame(
            standard_model::STATUS_PUBLISHED,
            (string)$DB->get_field('local_ustar_standard_ver', 'status', ['id' => $second->id])
        );
        $this->assertSame(2, (int)$second->versionno);
        $this->assertSame(
            (int)$second->id,
            (int)standard_model::current('retail_safety')->id
        );
        $this->assertSame(
            (int)$second->id,
            (int)standard_model::publish(
                (int)$standard->id,
                (int)$second->id,
                (int)$USER->id
            )->id
        );
    }

    public function test_invalid_requirement_does_not_create_a_version(): void {
        global $DB, $USER;

        $standard = standard_model::create(
            'invalid_fixture',
            'Invalid fixture',
            '',
            (int)$USER->id
        );
        try {
            standard_model::save_version((int)$standard->id, [
                'requirements' => [[
                    'type' => 'unknown',
                    'outcome' => 'completed',
                    'sourcekind' => 'fixture',
                    'sourceid' => 'x',
                ]],
            ], (int)$USER->id);
            $this->fail('Invalid standard requirement must be rejected');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString('at least one', $exception->getMessage());
        }
        $this->assertSame(
            0,
            $DB->count_records('local_ustar_standard_ver', ['standardid' => $standard->id])
        );
    }
}
