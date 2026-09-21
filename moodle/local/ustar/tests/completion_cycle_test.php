<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(completion_cycle::class)]
final class completion_cycle_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
    }

    public function test_redelivery_is_idempotent_and_new_verified_time_creates_new_cycle(): void {
        global $DB;

        $generator = $this->getDataGenerator()->get_plugin_generator('local_ustar');
        $user = $this->getDataGenerator()->create_user();
        $route = $generator->create_route();
        $point = $generator->create_point($route);
        $version = $generator->create_version($point);
        $evidence = [
            'mode' => 'evaluated',
            'requirements' => [[
                'type' => 'assessment',
                'required' => true,
                'satisfied' => true,
                'completedat' => 1000,
            ]],
        ];

        $first = completion_cycle::confirm(
            (int)$user->id,
            (int)$point->id,
            (int)$version->id,
            1000,
            2000,
            $evidence
        );
        $repeat = completion_cycle::confirm(
            (int)$user->id,
            (int)$point->id,
            (int)$version->id,
            1000,
            2000,
            $evidence
        );
        $second = completion_cycle::confirm(
            (int)$user->id,
            (int)$point->id,
            (int)$version->id,
            3000,
            4000,
            $evidence
        );

        $this->assertSame((int)$first->id, (int)$repeat->id);
        $this->assertNotSame((int)$first->id, (int)$second->id);
        $this->assertSame(
            2,
            $DB->count_records('local_ustar_completion_cycle', ['userid' => $user->id])
        );
    }

    public function test_inherited_progress_is_not_a_new_cycle_and_override_uses_parent_identity(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_ustar');
        $user = $this->getDataGenerator()->create_user();
        $route = $generator->create_route();
        $parent = $generator->create_point($route);
        $override = $generator->create_point($route, [
            'sourcepointid' => $parent->id,
            'inheritstate' => 'override',
        ]);
        $version = $generator->create_version($override);

        $this->assertNull(completion_cycle::confirm(
            (int)$user->id,
            (int)$override->id,
            (int)$version->id,
            1000,
            0,
            ['mode' => 'inherited', 'fromprogressid' => 1]
        ));

        $cycle = completion_cycle::confirm(
            (int)$user->id,
            (int)$override->id,
            (int)$version->id,
            2000,
            0,
            ['mode' => 'assessment_lifecycle', 'status' => 'passed', 'cycle' => 1, 'verifiedcompletedat' => 2000]
        );
        $this->assertSame((int)$parent->id, (int)$cycle->logicalpointid);
    }

    public function test_assessment_cycle_rejects_unverified_or_stale_completion(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_ustar');
        $user = $this->getDataGenerator()->create_user();
        $route = $generator->create_route();
        $point = $generator->create_point($route);
        $version = $generator->create_version($point);

        $this->expectException(\invalid_parameter_exception::class);
        completion_cycle::confirm(
            (int)$user->id,
            (int)$point->id,
            (int)$version->id,
            2000,
            0,
            ['mode' => 'assessment_lifecycle', 'status' => 'passed', 'cycle' => 1, 'verifiedcompletedat' => 1999]
        );
    }
}
