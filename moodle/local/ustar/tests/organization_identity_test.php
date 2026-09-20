<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(organization_identity::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(organization_model::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(team_access::class)]
final class organization_identity_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
        accounts::ensure_profile_field();
        global $DB;
        if (!$DB->record_exists('user_info_field', ['shortname' => 'ustar_position'])) {
            $this->getDataGenerator()->create_custom_profile_field([
                'shortname' => 'ustar_position', 'name' => 'Position', 'datatype' => 'text']);
        }
    }

    private function employee(string $legacy = 'retail_seller'): \stdClass {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('user_info_data', (object)['userid' => $user->id,
            'fieldid' => $DB->get_field('user_info_field', 'id', ['shortname' => 'ustar_position']),
            'data' => $legacy, 'dataformat' => 0]);
        return $user;
    }

    private function place(string $position = 'retail_seller', int $parent = 0): int {
        global $DB;
        return $DB->insert_record('local_ustar_staff_places', (object)[
            'placecode' => 'fixture_' . random_string(16), 'positionid' => $position,
            'departmentid' => 'retail', 'managerplaceid' => $parent ?: null]);
    }

    private function assign(int $userid, int $place, array $values = []): int {
        global $DB;
        return $DB->insert_record('local_ustar_assignments', (object)($values + [
            'userid' => $userid, 'staffplaceid' => $place, 'assignmenttype' => 'primary']));
    }

    private function grant(int $userid, array $capabilities): void {
        $context = \context_system::instance();
        $role = create_role('Fixture', 'fixture_' . random_string(8));
        foreach ($capabilities as $capability) assign_capability($capability, CAP_ALLOW, $role, $context->id);
        role_assign($role, $userid, $context->id);
        accesslib_clear_all_caches(true);
    }

    public function test_primary_is_shared_by_profile_and_access_without_writes(): void {
        global $DB;
        $user = $this->employee();
        $this->assign($user->id, $this->place('retail_senior'));
        $before = $DB->perf_get_writes();
        $this->assertSame('retail_senior', people::position_id($user->id));
        $this->assertSame('retail_senior', structure::resolve_user($user->id)['position']['id']);
        $this->assertSame('retail_senior', position_access::position_for_user($user->id)['id']);
        $this->assertContains('legacy_position_mismatch', organization_identity::resolve($user->id)['warnings']);
        $this->assertSame($before, $DB->perf_get_writes());
        $this->assertSame('retail_seller', organization_identity::legacy_position_id($user->id));
    }

    public function test_assignment_end_is_exclusive_and_does_not_restore_legacy(): void {
        $user = $this->employee();
        $this->assign($user->id, $this->place(), ['effectivefrom' => 100, 'effectiveto' => 200]);
        $this->assertSame('retail_seller', organization_identity::resolve($user->id, 199)['positionid']);
        $this->assertSame('', organization_identity::resolve($user->id, 200)['positionid']);
        $this->assertSame('', organization_identity::resolve($user->id, 99)['positionid']);
        $this->assertSame('assignment', organization_identity::resolve($user->id, 200)['source']);
    }

    public function test_conflicting_primary_blocks_position_and_management(): void {
        $user = $this->employee('retail_head');
        $this->assign($user->id, $this->place('retail_head'));
        $this->assign($user->id, $this->place('retail_head'));
        $this->assertNull(organization_model::primary_assignment($user->id));
        $this->assertSame('', people::position_id($user->id));
        $this->assertContains('multiple_primary_assignments', organization_identity::resolve($user->id)['conflicts']);
        $this->assertFalse(organization_model::manager_scope($user->id)['allowed']);
    }

    public function test_place_lifetime_and_cycles_block_authority(): void {
        global $DB;
        $user = $this->employee('retail_head');
        $place = $this->place('retail_head');
        $this->assign($user->id, $place);
        $DB->set_field('local_ustar_staff_places', 'effectivefrom', time() + 100, ['id' => $place]);
        $this->assertNull(organization_model::staff_place($place));
        $this->assertSame('', people::position_id($user->id));
        $DB->set_field('local_ustar_staff_places', 'effectivefrom', 0, ['id' => $place]);
        $DB->set_field('local_ustar_staff_places', 'managerplaceid', $place, ['id' => $place]);
        $this->assertContains('invalid_staff_place_hierarchy', organization_identity::resolve($user->id)['conflicts']);
        $this->assertFalse(organization_model::is_manager($user->id));
    }

    public function test_acting_authority_expires_without_changing_primary_position(): void {
        $acting = $this->employee();
        $other = $this->employee();
        $head = $this->place('retail_head');
        $this->assign($acting->id, $this->place());
        $this->assign($other->id, $this->place());
        $this->assign($acting->id, $head, ['assignmenttype' => 'acting', 'effectiveto' => 200]);
        $this->assertSame($acting->id, organization_model::occupant_for_place($head, 199));
        $this->assertSame(0, organization_model::occupant_for_place($head, 200));
        $this->assertSame('retail_seller', organization_identity::resolve($acting->id, 199)['positionid']);
        $this->assign($other->id, $head, ['assignmenttype' => 'acting', 'effectiveto' => 200]);
        $this->assertSame(0, organization_model::occupant_for_place($head, 199));
    }

    public function test_manager_scope_and_api_exclude_other_subtrees(): void {
        $head = $this->employee('retail_head');
        $employee = $this->employee();
        $outsider = $this->employee();
        $place = $this->place('retail_head');
        $this->assign($head->id, $place);
        $this->assign($employee->id, $this->place('retail_seller', $place));
        $this->assign($outsider->id, $this->place());
        $this->assertFalse(team_access::learning_scope($head->id)['allowed']);
        $this->grant($head->id, ['local/ustar:viewteam', 'local/ustar:use']);
        $this->assertEquals([$employee->id], team_access::learning_scope($head->id)['userids']);
        $this->assertSame((int)$head->id, org::manager_id($employee->id));
        $this->setUser($head);
        $result = json_decode(external\get_team::execute()['json'], true);
        $this->assertEquals([$employee->id], array_column($result['team'], 'id'));
    }

    public function test_hr_and_hrd_read_company_but_private_capability_stays_separate(): void {
        foreach (['local/ustar:hr', 'local/ustar:hrmanage', 'local/ustar:executive'] as $capability) {
            $user = $this->employee();
            $this->grant($user->id, [$capability]);
            $this->assertTrue(team_access::company($user->id));
            $this->assertFalse(has_capability('local/ustar:developmentanalytics', \context_system::instance(), $user->id));
        }
        $hrd = $this->employee();
        $this->grant($hrd->id, ['local/ustar:hr', 'local/ustar:developmentanalytics']);
        $this->assertTrue(team_access::company($hrd->id));
        $this->assertTrue(has_capability('local/ustar:developmentanalytics', \context_system::instance(), $hrd->id));
    }

    public function test_suspended_actor_loses_company_and_manager_scope(): void {
        global $DB;
        $user = $this->employee('retail_head');
        $this->assign($user->id, $this->place('retail_head'));
        $this->grant($user->id, ['local/ustar:hr', 'local/ustar:viewteam']);
        $DB->set_field('user', 'suspended', 1, ['id' => $user->id]);
        $this->assertFalse(team_access::company($user->id));
        $this->assertFalse(team_access::learning_scope($user->id)['allowed']);
        $this->assertFalse(organization_model::is_manager($user->id));
    }

    public function test_staffing_command_rejects_forged_actor(): void {
        $user = $this->employee();
        $this->expectException(\invalid_parameter_exception::class);
        staffing_requests::create_hire($user->id, []);
    }

    public function test_reconciliation_is_repeatable_and_read_only(): void {
        global $DB;
        $user = $this->employee();
        $this->assign($user->id, $this->place('retail_senior'));
        $before = $DB->perf_get_writes();
        $first = organization_identity::reconciliation();
        $second = organization_identity::reconciliation();
        $this->assertSame($first['employees'], $second['employees']);
        $this->assertSame($before, $DB->perf_get_writes());
        $this->assertSame('review_legacy_projection', $first['employees'][0]['action']);
    }
}
