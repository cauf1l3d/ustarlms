<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(achievement_summary::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(department_leaderboard::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(department_learning::class)]
final class team_achievements_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
        global $DB;
        if (!$DB->record_exists('user_info_field', ['shortname' => 'ustar_position'])) {
            $this->getDataGenerator()->create_custom_profile_field([
                'shortname' => 'ustar_position', 'name' => 'Position', 'datatype' => 'text']);
        }
    }

    private function employee(string $position): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        people::set_position_id($user->id, $position);
        return $user;
    }

    public function test_ranking_uses_current_department_without_season_and_keeps_ties(): void {
        $first = $this->employee('retail_seller');
        $peer = $this->employee('retail_seller');
        $outsider = $this->employee('');
        $suspended = $this->employee('retail_seller');
        global $DB;
        $DB->set_field('user', 'suspended', 1, ['id' => $suspended->id]);
        $this->setUser($first);
        $this->assertNull(competition::current_for_user($first->id));
        $ranking = department_leaderboard::for_user($first->id, 0);
        $this->assertEqualsCanonicalizing([$first->id, $peer->id], array_column($ranking['rows'], 'id'));
        $this->assertSame([1, 1], array_column($ranking['rows'], 'rank'));
        $this->assertSame((int)$first->id, $ranking['current']['id']);
        $this->assertNull(department_leaderboard::for_user($outsider->id, 0));
    }

    public function test_manager_card_uses_employee_achievements_and_rejects_other_people(): void {
        global $DB;
        $head = $this->employee('retail_head');
        $employee = $this->employee('retail_seller');
        $other = $this->employee('retail_seller');
        $parent = $DB->insert_record('local_ustar_staff_places', (object)[
            'placecode' => 'head-' . random_string(8), 'departmentid' => 'retail', 'positionid' => 'retail_head']);
        $child = $DB->insert_record('local_ustar_staff_places', (object)[
            'placecode' => 'seller-' . random_string(8), 'departmentid' => 'retail',
            'positionid' => 'retail_seller', 'managerplaceid' => $parent]);
        foreach ([$head->id => $parent, $employee->id => $child] as $userid => $placeid) {
            $DB->insert_record('local_ustar_assignments', (object)[
                'userid' => $userid, 'staffplaceid' => $placeid, 'assignmenttype' => 'primary']);
        }
        $context = \context_system::instance();
        $role = create_role('Scoped manager', 'fixture_' . random_string(8), 'Fixture');
        assign_capability('local/ustar:viewteam', CAP_ALLOW, $role, $context->id);
        assign_capability('local/ustar:use', CAP_ALLOW, $role, $context->id);
        role_assign($role, $head->id, $context->id);
        $this->setUser($head);
        $overview = department_learning::detail($head->id, $employee->id)['staffoverview'];
        $expected = achievement_summary::read($employee->id);
        $this->assertSame($expected['xp'], $overview['xp']);
        $this->assertSame($expected['level'], $overview['level']);
        $this->assertSame(employee_profile::skills_for_user($employee->id)['items'], $overview['skillitems']);
        $this->assertArrayNotHasKey('coinbalance', $overview);
        $this->expectException(\required_capability_exception::class);
        department_learning::detail($head->id, $other->id);
    }
}
