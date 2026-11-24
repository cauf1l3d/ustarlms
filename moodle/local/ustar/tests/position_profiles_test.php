<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(position_profiles::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(route_scope::class)]
final class position_profiles_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
    }

    private function employee(string $position): array {
        global $DB;
        $uid = (int)$this->getDataGenerator()->create_user()->id;
        $place = $DB->insert_record('local_ustar_staff_places', (object)[
            'placecode' => 'personal-fixture-' . $uid, 'departmentid' => 'retail', 'positionid' => $position,
        ]);
        $assignment = (int)$DB->insert_record('local_ustar_assignments', (object)[
            'staffplaceid' => $place, 'userid' => $uid, 'assignmenttype' => 'primary',
        ]);
        return [$uid, $assignment, $place];
    }

    private function activity(string $name): int {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $module = $this->getDataGenerator()->create_module('scorm', ['course' => $course->id, 'name' => $name,
            'completion' => 2, 'completionscorerequired' => 50]);
        return (int)$module->cmid;
    }

    public function test_two_employees_share_common_route_and_have_independent_development_steps(): void {
        global $DB, $USER;
        $actor = (int)$USER->id;
        $position = 'retail_seller';
        structure::save(structure::NAME_STRUCTURE, structure::default_structure());
        [$first, $firststaffing, $firstplace] = $this->employee($position);
        [$second, $secondstaffing] = $this->employee($position);
        [$third] = $this->employee($position);
        $generator = $this->getDataGenerator()->get_plugin_generator('local_ustar');
        $base = $generator->create_route(['positionid' => $position]);
        $common = $generator->create_point($base, ['pointkey' => 'common']);
        $generator->create_version($common, ['requirementsjson' => json_encode([
            ['type' => 'native', 'sourcekey' => native_learning::TEAM_STRUCTURE, 'required' => true],
        ])]);
        $ladder = grade_ladders::create('IT levels', [['id' => 'junior', 'name' => 'Junior'],
            ['id' => 'senior', 'name' => 'Senior']], $actor);
        $version = grade_ladders::publish((int)$ladder->id, (int)$ladder->revision, $actor);
        grade_ladders::bind($position, (int)$version->id, 0, $actor, 'Fixture');
        $firstmodel = position_profiles::save($position, 0, 0, 'Independent', 'junior', ['product_know' => 4], $actor);
        $secondmodel = position_profiles::save($position, 0, 0, 'Advanced', 'senior', [], $actor);
        $one = $DB->get_record(position_profiles::TABLE, ['id' => $firstmodel], '*', MUST_EXIST);
        $two = $DB->get_record(position_profiles::TABLE, ['id' => $secondmodel], '*', MUST_EXIST);
        $activity1 = $this->activity('Development one');
        $activity2 = $this->activity('Development two');
        position_profiles::change_route($position, $firstmodel, route_model::revision((int)$one->routeid), 'add', $activity1, $actor);
        position_profiles::change_route($position, $secondmodel, route_model::revision((int)$two->routeid), 'add', $activity2, $actor);
        position_profiles::assign($position, $first, $firstmodel, 0, $firststaffing, $actor);
        position_profiles::assign($position, $second, $secondmodel, 0, $secondstaffing, $actor);
        $point1 = route_model::points((int)$one->routeid)[0];
        $point2 = route_model::points((int)$two->routeid)[0];
        $snapshot = route_model::read_only_snapshot($position, $first);
        $this->assertEquals([(int)$common->id, (int)$point1->id], array_column($snapshot['points'], 'id'));
        $this->assertEquals([(int)$common->id, (int)$point2->id],
            array_column(route_model::read_only_snapshot($position, $second)['points'], 'id'));
        $this->assertEquals([(int)$common->id],
            array_column(route_model::read_only_snapshot($position, $third)['points'], 'id'));
        $this->assertTrue($snapshot['points'][1]['locked']);
        $this->assertTrue(route_scope::applies_to_user((int)$point1->id, $position, $first));
        $this->assertFalse(route_scope::applies_to_user((int)$point1->id, $position, $second));
        $this->assertSame('Senior', position_profiles::goal($two));
        $this->assertSame(['product_know' => 4], position_profiles::targets($one));
        $this->assertFalse($DB->record_exists('local_ustar_employee_grades', ['userid' => $second]));
        $this->assertEquals([(int)$common->id], array_column(route_model::points((int)$base->id), 'id'));

        position_profiles::assign($position, $first, 0, 1, $firststaffing, $actor);
        $this->assertNull(position_profiles::for_user($position, $first));
        $this->assertTrue($DB->record_exists('local_ustar_route_points', ['id' => $point1->id]));
        position_profiles::assign($position, $first, $firstmodel, 2, $firststaffing, $actor);
        $DB->set_field('local_ustar_assignments', 'status', 'ended', ['id' => $firststaffing]);
        $DB->insert_record('local_ustar_assignments', (object)[
            'staffplaceid' => $firstplace, 'userid' => $first, 'assignmenttype' => 'primary',
        ]);
        $this->assertNull(position_profiles::for_user($position, $first));
    }

    public function test_model_cannot_be_assigned_across_positions(): void {
        global $USER;
        $actor = (int)$USER->id;
        [$employee, $assignment] = $this->employee('retail_seller');
        $model = position_profiles::save('opt_manager', 0, 0, 'Foreign model', '', [], $actor);
        $this->expectException(\dml_missing_record_exception::class);
        position_profiles::assign('retail_seller', $employee, $model, 0, $assignment, $actor);
    }

    public function test_staff_member_cannot_write_model_as_hr(): void {
        $actor = (int)get_admin()->id;
        [$employee] = $this->employee('retail_seller');
        $this->setUser($employee);
        $this->expectException(\required_capability_exception::class);
        position_profiles::save('retail_seller', 0, 0, 'Forged actor', '', [], $actor);
    }
}
