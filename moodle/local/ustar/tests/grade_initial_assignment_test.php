<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(grade_promotion::class)]
final class grade_initial_assignment_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    public function test_assign_initial_if_bound_creates_first_grade_once(): void {
        global $DB;

        $admin = get_admin();
        $this->setAdminUser();

        $positionid = 'fixture_grade_bound_position';
        $structure = structure::default_structure();
        $structure['departments'][] = [
            'id' => 'fixture_grade_department',
            'name' => 'Fixture grade department',
            'cohort' => 'fixture_grade_department',
        ];
        $structure['positions'][] = [
            'id' => $positionid,
            'department' => 'fixture_grade_department',
            'name' => 'Fixture grade position',
            'level' => 1,
            'next' => null,
        ];
        structure::save(structure::NAME_STRUCTURE, $structure);

        $user = $this->getDataGenerator()->create_user();
        people::set_position_id((int)$user->id, $positionid);
        employment::set_status((int)$user->id, employment::ACTIVE, (int)$admin->id, 'test');

        $grades = [
            ['id' => 'trainee', 'name' => 'Стажёр'],
            ['id' => 'junior', 'name' => 'Младший консультант'],
        ];
        $json = json_encode($grades, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ladderid = (int)$DB->insert_record('local_ustar_grade_ladders', (object)[
            'name' => 'Fixture ladder',
            'status' => 'active',
            'draftjson' => $json,
            'revision' => 1,
            'createdby' => (int)$admin->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $versionid = (int)$DB->insert_record('local_ustar_grade_ladder_ver', (object)[
            'ladderid' => $ladderid,
            'versionno' => 1,
            'gradesjson' => $json,
            'gradehash' => hash('sha256', $json),
            'createdby' => (int)$admin->id,
            'timecreated' => time(),
        ]);
        $DB->insert_record('local_ustar_grade_bindings', (object)[
            'positionid' => $positionid,
            'ladderversionid' => $versionid,
            'revision' => 1,
            'timemodified' => time(),
            'usermodified' => (int)$admin->id,
        ]);

        $first = grade_promotion::assign_initial_if_bound(
            (int)$user->id,
            (int)$admin->id,
            'Fixture registration approval'
        );

        $this->assertNotNull($first);
        $this->assertSame('trainee', (string)$first->gradekey);
        $this->assertSame($positionid, (string)$first->positionid);
        $this->assertSame($versionid, (int)$first->ladderversionid);
        $this->assertSame('initial_hr', (string)$first->source);

        $again = grade_promotion::assign_initial_if_bound(
            (int)$user->id,
            (int)$admin->id,
            'Should be idempotent'
        );

        $this->assertSame((int)$first->id, (int)$again->id);
        $this->assertSame(1, $DB->count_records('local_ustar_employee_grades', [
            'userid' => (int)$user->id,
        ]));
    }

    public function test_assign_initial_if_bound_does_nothing_without_explicit_binding(): void {
        $admin = get_admin();
        $this->setAdminUser();

        $positionid = 'fixture_grade_unbound_position';
        $structure = structure::default_structure();
        $structure['departments'][] = [
            'id' => 'fixture_grade_unbound_department',
            'name' => 'Fixture unbound department',
            'cohort' => 'fixture_grade_unbound_department',
        ];
        $structure['positions'][] = [
            'id' => $positionid,
            'department' => 'fixture_grade_unbound_department',
            'name' => 'Fixture unbound position',
            'level' => 1,
            'next' => null,
        ];
        structure::save(structure::NAME_STRUCTURE, $structure);

        $user = $this->getDataGenerator()->create_user();
        people::set_position_id((int)$user->id, $positionid);
        employment::set_status((int)$user->id, employment::ACTIVE, (int)$admin->id, 'test');

        $this->assertNull(grade_promotion::assign_initial_if_bound(
            (int)$user->id,
            (int)$admin->id
        ));
    }
}
