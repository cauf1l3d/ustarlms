<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(grade_promotion::class)]
final class grade_initial_assignment_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->ensure_position_profile_field();
    }

    private function ensure_position_profile_field(): void {
        global $DB;

        if ($DB->record_exists('user_info_field', ['shortname' => 'ustar_position'])) {
            return;
        }

        $category = $DB->get_record(
            'user_info_category',
            ['name' => 'USTAR'],
            '*',
            IGNORE_MISSING
        );
        if (!$category) {
            $categoryid = (int)$DB->insert_record('user_info_category', (object)[
                'name' => 'USTAR',
                'sortorder' => (int)$DB->get_field_sql(
                    'SELECT COALESCE(MAX(sortorder), 0) FROM {user_info_category}'
                ) + 1,
            ]);
        } else {
            $categoryid = (int)$category->id;
        }

        $DB->insert_record('user_info_field', (object)[
            'shortname' => 'ustar_position',
            'name' => 'Должность USTAR',
            'datatype' => 'text',
            'description' => 'Fixture for the legacy position projection used by people::set_position_id().',
            'descriptionformat' => FORMAT_PLAIN,
            'categoryid' => $categoryid,
            'sortorder' => (int)$DB->get_field_sql(
                'SELECT COALESCE(MAX(sortorder), 0) FROM {user_info_field} WHERE categoryid = :categoryid',
                ['categoryid' => $categoryid]
            ) + 1,
            'required' => 0,
            'locked' => 0,
            'visible' => 0,
            'forceunique' => 0,
            'signup' => 0,
            'defaultdata' => '',
            'defaultdataformat' => 0,
            'param1' => '100',
            'param2' => '2048',
            'param3' => '0',
            'param4' => '',
            'param5' => '',
        ]);
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
