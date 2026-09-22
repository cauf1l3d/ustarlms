<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(completion_cycle::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(catalog::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(capabilities::class)]
final class release_review_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
    }

    public function test_revoked_cycle_overrides_stale_complete_projection(): void {
        global $DB;
        $g = $this->getDataGenerator()->get_plugin_generator('local_ustar');
        $user = $this->getDataGenerator()->create_user();
        $point = $g->create_point($g->create_route());
        $version = $g->create_version($point);
        $evidence = ['mode' => 'evaluated', 'requirements' => [
            ['type' => 'cm', 'required' => true, 'satisfied' => true, 'completedat' => 1000],
        ]];
        $g->create_progress($user, $point, $version, [
            'completedat' => 1000, 'evidencejson' => json_encode($evidence),
        ]);
        $cycle = completion_cycle::confirm($user->id, $point->id, $version->id, 1000, 1100, $evidence);
        // Expiration still permits retraining; revocation must not.
        $this->assertNotNull(completion_cycle::prior_verified($user->id, $point->id, $version->id));
        $DB->set_field('local_ustar_completion_cycle', 'status', 'revoked', ['id' => $cycle->id]);
        $gate = new \ReflectionMethod(forced_retraining::class, 'prior_confirmed_material_completion');
        $this->assertNull($gate->invoke(null, $user->id, $point->id, $version->id));
    }

    public function test_unverified_legacy_projection_is_not_completion_evidence(): void {
        $g = $this->getDataGenerator()->get_plugin_generator('local_ustar');
        $user = $this->getDataGenerator()->create_user();
        $point = $g->create_point($g->create_route());
        $version = $g->create_version($point);
        $g->create_progress($user, $point, $version);
        $this->assertNull(completion_cycle::prior_verified($user->id, $point->id, $version->id));
    }

    public function test_catalog_rejects_stale_revision_even_in_same_second(): void {
        global $USER;
        $input = ['itemtype' => catalog::TYPE_GROUP, 'title' => 'Group'];
        $original = catalog::save(0, $input, $USER->id);
        $input['expectedmodified'] = $original->timemodified;
        $updated = catalog::save($original->id, $input, $USER->id);
        $this->assertGreaterThan($original->timemodified, $updated->timemodified);
        $this->expectException(\moodle_exception::class);
        catalog::save($original->id, $input, $USER->id);
    }

    public function test_suspended_account_cannot_use_business_capability(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $role = $this->getDataGenerator()->create_role();
        assign_capability('local/ustar:hrmanage', CAP_ALLOW, $role, \context_system::instance()->id);
        role_assign($role, $user->id, \context_system::instance()->id);
        $this->assertTrue(capabilities::has($user->id, capabilities::HR_WRITE));
        $DB->set_field('user', 'suspended', 1, ['id' => $user->id]);
        $this->assertFalse(capabilities::has($user->id, capabilities::HR_WRITE));
    }
}
