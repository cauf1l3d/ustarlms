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
        $this->assertFalse(catalog::can_manage($user->id));
        $this->assertFalse(material_studio::can_manage($user->id));
    }

    public function test_empty_evidence_cannot_create_rewardable_cycle(): void {
        $g = $this->getDataGenerator()->get_plugin_generator('local_ustar');
        $user = $this->getDataGenerator()->create_user();
        $point = $g->create_point($g->create_route());
        $version = $g->create_version($point);
        $this->expectException(\invalid_parameter_exception::class);
        completion_cycle::confirm($user->id, $point->id, $version->id, 1000, 0,
            ['mode' => 'evaluated', 'requirements' => []]);
    }

    public function test_foreign_version_cannot_create_completion(): void {
        $g = $this->getDataGenerator()->get_plugin_generator('local_ustar');
        $user = $this->getDataGenerator()->create_user();
        $route = $g->create_route();
        $point = $g->create_point($route);
        $foreign = $g->create_version($g->create_point($route));
        $this->expectException(\invalid_parameter_exception::class);
        completion_cycle::confirm($user->id, $point->id, $foreign->id, 1000, 0,
            ['mode' => 'assessment_lifecycle', 'status' => 'passed', 'cycle' => 1,
                'verifiedcompletedat' => 1000]);
    }

    public function test_catalog_parent_type_cannot_orphan_children(): void {
        global $USER;
        $group = catalog::save(0, ['itemtype' => 'group', 'title' => 'Group'], $USER->id);
        $other = catalog::save(0, ['itemtype' => 'group', 'title' => 'Other'], $USER->id);
        catalog::save(0, ['itemtype' => 'subgroup', 'title' => 'Child', 'parentid' => $group->id], $USER->id);
        $this->expectException(\moodle_exception::class);
        catalog::save($group->id, ['itemtype' => 'subgroup', 'title' => 'Group',
            'parentid' => $other->id, 'expectedmodified' => $group->timemodified], $USER->id);
    }

    public function test_evidence_metadata_does_not_create_another_completion(): void {
        global $DB;
        $g = $this->getDataGenerator()->get_plugin_generator('local_ustar');
        $user = $this->getDataGenerator()->create_user();
        $point = $g->create_point($g->create_route());
        $version = $g->create_version($point);
        $evidence = ['mode' => 'assessment_lifecycle', 'status' => 'passed',
            'cycle' => 1, 'verifiedcompletedat' => 1000];
        $first = completion_cycle::confirm($user->id, $point->id, $version->id, 1000, 0, $evidence);
        // Model an already deployed v1 key; redelivery must preserve its identity.
        $DB->set_field('local_ustar_completion_cycle', 'cyclekey', 'route-cycle-v1:existing', ['id' => $first->id]);
        $DB->set_field('local_ustar_completion_cycle', 'status', 'revoked', ['id' => $first->id]);
        $evidence['title'] = 'Renamed material';
        $repeat = completion_cycle::confirm($user->id, $point->id, $version->id, 1000, 0, $evidence);
        $this->assertSame((int)$first->id, (int)$repeat->id);
        $this->assertSame('route-cycle-v1:existing', $repeat->cyclekey);
        $this->assertSame('revoked', $repeat->status);
        $this->assertSame(1, $DB->count_records('local_ustar_completion_cycle', ['userid' => $user->id]));
    }

    public function test_upload_error_does_not_report_success(): void {
        global $USER;
        $this->expectException(\invalid_parameter_exception::class);
        catalog::save(0, ['itemtype' => 'group', 'title' => 'Failed upload'], $USER->id,
            [catalog::FILEAREA_IMAGE => ['error' => UPLOAD_ERR_INI_SIZE, 'name' => 'photo.png']]);
    }
}
