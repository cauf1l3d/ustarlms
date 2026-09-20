<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(analytics::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(route_model::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(route_scope::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(assessment_lifecycle::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(economy::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(user_history_reset::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(route_rewards::class)]
final class stage1_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
        accounts::ensure_profile_field();
    }

    private function fixture(): array {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_ustar');
        $user = $this->getDataGenerator()->create_user();
        $route = $generator->create_route();
        $point = $generator->create_point($route);
        $version = $generator->create_version($point);
        return [$generator, $user, $route, $point, $version];
    }

    public function test_empty_standard_is_unknown_not_qualified_or_a_gap(): void {
        global $DB;
        $position = 'fixture_position';
        structure::save(structure::NAME_STRUCTURE, ['positions' => [['id' => $position, 'name' => 'Fixture']], 'skills' => [], 'matrix' => []]);
        $field = $DB->get_record('user_info_field', ['shortname' => 'ustar_position']);
        if (!$field) {
            $field = $this->getDataGenerator()->create_custom_profile_field(['shortname' => 'ustar_position', 'name' => 'USTAR position', 'datatype' => 'text']);
        }
        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('user_info_data', (object)['userid' => $user->id, 'fieldid' => $field->id, 'data' => $position, 'dataformat' => 0]);
        $summary = analytics::qualification_summary();
        $this->assertSame(1, $summary['total']);
        $this->assertSame(1, $summary['unconfigured']);
        $this->assertSame(0, $summary['qualified']);
        $this->assertSame(0, $summary['withgaps']);
        $this->assertEquals(0, $summary['coverage']);
    }

    public function test_snapshot_keep_expiry_and_mandatory_renewal(): void {
        global $DB;
        [$g, $user, $route, $point, $v1] = $this->fixture();
        $g->create_progress($user, $point, $v1, ['completedat' => time() - 8 * DAYSECS]);
        $DB->set_field('local_ustar_route_versions', 'status', 'archived', ['id' => $v1->id]);
        $v2 = $g->create_version($point, ['versionno' => 2]);
        $this->assertSame(1, route_model::read_only_snapshot($route->positionid, $user->id)['donepoints']);
        $DB->set_field('local_ustar_route_versions', 'renewalpolicy', route_model::RENEW_ALL, ['id' => $v2->id]);
        $this->assertSame(0, route_model::read_only_snapshot($route->positionid, $user->id)['donepoints']);
        $DB->set_field('local_ustar_route_versions', 'renewalpolicy', route_model::RENEW_EXPIRY, ['id' => $v2->id]);
        $DB->set_field('local_ustar_route_versions', 'validdays', 7, ['id' => $v2->id]);
        $this->assertSame(0, route_model::read_only_snapshot($route->positionid, $user->id)['donepoints']);
        $DB->set_field('local_ustar_route_progress', 'completedat', time() - DAYSECS, ['userid' => $user->id]);
        $this->assertSame(1, route_model::read_only_snapshot($route->positionid, $user->id)['donepoints']);
        $this->assertSame(1, $DB->count_records('local_ustar_route_progress', ['userid' => $user->id]));
    }

    public function test_preview_does_not_enrol_or_create_progress(): void {
        global $DB, $SESSION;
        [$g, $user, $route, $point, $version] = $this->fixture();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'completion' => 1]);
        $DB->set_field('local_ustar_route_versions', 'requirementsjson', json_encode([['type' => 'cm', 'sourceid' => $page->cmid, 'required' => true]]), ['id' => $version->id]);
        $SESSION->ustar_view_position = $route->positionid;
        $before = $DB->perf_get_writes();
        $actual = route_model::for_user($route->positionid, $user->id);
        $this->assertSame($before, $DB->perf_get_writes());
        $this->assertSame(1, $actual['totalpoints']);
        $this->assertSame(0, $DB->count_records('user_enrolments', ['userid' => $user->id]));
        $this->assertSame(0, $DB->count_records('local_ustar_route_progress', ['userid' => $user->id]));
    }

    public function test_assessment_preview_returns_only_persisted_state_without_provider(): void {
        global $DB, $SESSION;
        [$g, $user, $route, $point, $version] = $this->fixture();
        $policy = (object)['pointid' => $point->id, 'versionid' => $version->id, 'providerkind' => 'fixture_never_inspected', 'providerref' => 'none', 'active' => 1];
        $policy->id = $DB->insert_record('local_ustar_assess_policy', $policy);
        $SESSION->ustar_view_position = $route->positionid;
        $before = $DB->perf_get_writes();
        $this->assertNull(assessment_lifecycle::sync_policy_user($policy, $user->id, $route->positionid, true));
        $this->assertSame($before, $DB->perf_get_writes());
        $runtimeid = $DB->insert_record('local_ustar_assess_runtime', (object)['userid' => $user->id, 'pointid' => $point->id, 'versionid' => $version->id, 'policyid' => $policy->id, 'status' => 'active']);
        $before = $DB->perf_get_writes();
        $this->assertEquals($runtimeid, assessment_lifecycle::sync_policy_user($policy, $user->id, $route->positionid, true)->id);
        $this->assertSame($before, $DB->perf_get_writes());
    }

    public function test_scope_override_is_isolated_and_revert_preserves_history(): void {
        global $DB, $USER;
        [$g, $user, $route, $point, $version] = $this->fixture();
        $DB->insert_record('local_ustar_route_scope', (object)['pointid' => $point->id, 'scopeid' => 'all', 'state' => 'confirmed']);
        $override = $g->create_point($route, ['sourcepointid' => $point->id, 'inheritstate' => 'override']);
        $ov = $g->create_version($override);
        $DB->insert_record('local_ustar_route_scope', (object)['pointid' => $override->id, 'scopeid' => $route->positionid, 'state' => 'confirmed']);
        $g->create_progress($user, $override, $ov);
        $this->assertEquals([$override->id], array_column(route_scope::points_for_position($route->id, $route->positionid), 'id'));
        $this->assertEquals([$point->id], array_column(route_scope::points_for_position($route->id, 'fixture_other'), 'id'));
        route_scope::revert_override($route->id, $override->id, $route->positionid, $USER->id);
        $this->assertEquals([$point->id], array_column(route_scope::points_for_position($route->id, $route->positionid), 'id'));
        $this->assertSame(1, $DB->count_records('local_ustar_route_progress', ['pointid' => $override->id]));
    }

    public function test_ledger_retry_and_reversal_do_not_duplicate_money(): void {
        global $DB, $USER;
        $user = $this->getDataGenerator()->create_user();
        $this->assertTrue(economy::post($user->id, 10, 'fixture', 'fixture-credit'));
        $this->assertFalse(economy::post($user->id, 10, 'fixture', 'fixture-credit'));
        $this->assertTrue(economy::spend($user->id, 4, 'fixture', 'fixture-debit', 'fixture', 'one', 'Synthetic test', $USER->id));
        $this->assertSame(6, economy::balance($user->id));
        $id = $DB->get_field('local_ustar_coin_ledger', 'id', ['idempotencykey' => 'fixture-debit']);
        $this->assertTrue(economy::reverse($id, 'fixture-reversal', 'Synthetic test', $USER->id));
        $this->assertFalse(economy::reverse($id, 'fixture-reversal-again', 'Synthetic test', $USER->id));
        $this->assertSame(10, economy::balance($user->id));
        $this->assertSame(3, $DB->count_records('local_ustar_coin_ledger', ['userid' => $user->id]));
    }

    public function test_overspend_is_refused(): void {
        global $USER;
        $this->preventResetByRollback();
        $user = $this->getDataGenerator()->create_user();
        economy::post($user->id, 3, 'fixture', 'fixture-credit');
        $this->expectException(\moodle_exception::class);
        economy::spend($user->id, 4, 'fixture', 'fixture-too-large', 'fixture', 'one', 'Synthetic test', $USER->id);
    }

    public function test_real_employee_cannot_be_reset_even_by_admin(): void {
        global $USER;
        $user = $this->getDataGenerator()->create_user();
        $this->expectException(\moodle_exception::class);
        user_history_reset::execute($user->id, $USER->id);
    }

    public function test_reset_requires_registered_owned_test_identity_and_real_actor(): void {
        global $DB, $USER;
        $user = $this->getDataGenerator()->create_user(['username' => route_tester::USERNAME_PREFIX . $USER->id]);
        accounts::set_type($user->id, accounts::TYPE_TEST);
        $DB->insert_record('local_ustar_route_testers', (object)['actorid' => $USER->id, 'sandboxuserid' => $user->id, 'positionid' => 'fixture_position']);
        user_history_reset::assert_allowed($user->id, $USER->id);
        $this->expectException(\moodle_exception::class);
        user_history_reset::assert_allowed($user->id, $USER->id + 1);
    }

    public function test_test_learner_does_not_receive_production_route_reward(): void {
        global $DB;
        [$g, $user, $route, $point, $version] = $this->fixture();
        accounts::set_type($user->id, accounts::TYPE_TEST);
        $this->assertTrue(accounts::learning_enabled($user->id));
        set_config('route_rewards_startedat', time() - 100, 'local_ustar');
        $g->create_progress($user, $point, $version, ['evidencejson' => json_encode(['mode' => 'evaluated', 'requirements' => [['type' => 'cm', 'required' => true, 'satisfied' => true, 'completedat' => time()]]])]);
        route_rewards::try_progress($user->id, $point->id, $version->id);
        $this->assertSame(0, $DB->count_records('local_ustar_coin_ledger', ['userid' => $user->id]));
        $this->assertSame(0, $DB->count_records('local_ustar_evidence_rec', ['userid' => $user->id]));
    }
}
