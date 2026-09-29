<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(grade_assignment_directory::class)]
final class grade_assignment_directory_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->ensure_position_profile_field();
    }

    public function test_picker_includes_normalized_and_legacy_active_employees(): void {
        global $DB;

        $departmentid = 'fixture_sales';
        $positionid = 'fixture_cashier';
        $structure = structure::default_structure();
        $structure['departments'][] = [
            'id' => $departmentid,
            'name' => 'Fixture sales',
            'cohort' => $departmentid,
        ];
        $structure['positions'][] = [
            'id' => $positionid,
            'department' => $departmentid,
            'name' => 'Fixture cashier',
            'level' => 1,
            'next' => null,
        ];
        $structure['positions'][] = [
            'id' => 'fixture_consultant',
            'department' => $departmentid,
            'name' => 'Fixture consultant',
            'level' => 1,
            'next' => null,
        ];
        structure::save(structure::NAME_STRUCTURE, $structure);

        $legacy = $this->getDataGenerator()->create_user([
            'firstname' => 'Legacy',
            'lastname' => 'Employee',
        ]);
        $this->set_legacy_position((int)$legacy->id, $positionid);

        $departmentpeer = $this->getDataGenerator()->create_user([
            'firstname' => 'Department',
            'lastname' => 'Peer',
        ]);
        $this->set_legacy_position((int)$departmentpeer->id, 'fixture_consultant');

        $canonical = $this->getDataGenerator()->create_user([
            'firstname' => 'Canonical',
            'lastname' => 'Employee',
        ]);
        $this->insert_primary_assignment((int)$canonical->id, $departmentid, $positionid);

        $historyonly = $this->getDataGenerator()->create_user([
            'firstname' => 'History',
            'lastname' => 'Employee',
        ]);
        $this->set_legacy_position((int)$historyonly->id, $positionid);
        $this->insert_ended_assignment((int)$historyonly->id, $departmentid, $positionid);

        $pending = $this->getDataGenerator()->create_user([
            'firstname' => 'Pending',
            'lastname' => 'Employee',
        ]);
        $this->set_legacy_position((int)$pending->id, $positionid);
        $now = time();
        $DB->insert_record('local_ustar_employment', (object)[
            'userid' => (int)$pending->id,
            'status' => employment::PENDING,
            'source' => 'fixture',
            'approvedby' => null,
            'approvedat' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => 0,
        ]);

        $rows = grade_assignment_directory::employees_for_position($departmentid, $positionid);

        $this->assertArrayHasKey((int)$legacy->id, $rows);
        $this->assertArrayHasKey((int)$canonical->id, $rows);
        $this->assertArrayNotHasKey((int)$historyonly->id, $rows);
        $this->assertArrayNotHasKey((int)$pending->id, $rows);

        $this->assertArrayNotHasKey((int)$departmentpeer->id, $rows);

        $departmentrows = grade_assignment_directory::employees_for_scope($departmentid);
        $this->assertFalse($departmentrows['truncated']);
        $this->assertArrayHasKey((int)$legacy->id, $departmentrows['employees']);
        $this->assertArrayHasKey((int)$canonical->id, $departmentrows['employees']);
        $this->assertArrayHasKey((int)$departmentpeer->id, $departmentrows['employees']);
    }

    public function test_bulk_preview_lists_only_people_who_will_receive_initial_grade(): void {
        global $DB;

        $admin = get_admin();
        $this->setAdminUser();

        $departmentid = 'fixture_bulk_department';
        $positionid = 'fixture_bulk_position';
        $structure = structure::default_structure();
        $structure['departments'][] = [
            'id' => $departmentid,
            'name' => 'Fixture bulk department',
            'cohort' => $departmentid,
        ];
        $structure['positions'][] = [
            'id' => $positionid,
            'department' => $departmentid,
            'name' => 'Fixture bulk position',
            'level' => 1,
            'next' => null,
        ];
        structure::save(structure::NAME_STRUCTURE, $structure);

        $grades = [
            ['id' => 'trainee', 'name' => 'Стажёр'],
            ['id' => 'junior', 'name' => 'Младший консультант'],
        ];
        $json = json_encode($grades, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $now = time();

        $ladderid = (int)$DB->insert_record('local_ustar_grade_ladders', (object)[
            'name' => 'Fixture bulk ladder',
            'status' => 'active',
            'draftjson' => $json,
            'revision' => 1,
            'createdby' => (int)$admin->id,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $versionid = (int)$DB->insert_record('local_ustar_grade_ladder_ver', (object)[
            'ladderid' => $ladderid,
            'versionno' => 1,
            'gradesjson' => $json,
            'gradehash' => hash('sha256', $json),
            'createdby' => (int)$admin->id,
            'timecreated' => $now,
        ]);
        $DB->insert_record('local_ustar_grade_bindings', (object)[
            'positionid' => $positionid,
            'ladderversionid' => $versionid,
            'revision' => 1,
            'timemodified' => $now,
            'usermodified' => (int)$admin->id,
        ]);

        $needsgrade = $this->getDataGenerator()->create_user([
            'firstname' => 'Needs',
            'lastname' => 'Grade',
        ]);
        $this->set_legacy_position((int)$needsgrade->id, $positionid);

        $already = $this->getDataGenerator()->create_user([
            'firstname' => 'Already',
            'lastname' => 'Graded',
        ]);
        $this->set_legacy_position((int)$already->id, $positionid);
        $DB->insert_record('local_ustar_employee_grades', (object)[
            'userid' => (int)$already->id,
            'gradekey' => 'trainee',
            'positionid' => $positionid,
            'ladderversionid' => $versionid,
            'revision' => 1,
            'source' => 'initial_hr',
            'requestid' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => (int)$admin->id,
        ]);

        $preview = grade_assignment_directory::initial_assignment_preview(
            $departmentid,
            $positionid
        );

        $this->assertSame(2, $preview['total']);
        $this->assertSame(1, $preview['assignable']);
        $this->assertSame(1, $preview['already']);
        $this->assertFalse($preview['truncated']);
        $this->assertCount(1, $preview['targets']);
        $this->assertSame((int)$needsgrade->id, (int)$preview['targets'][0]['userid']);
        $this->assertSame('Стажёр', (string)$preview['targets'][0]['gradelabel']);
    }

    public function test_picker_rejects_position_from_another_department(): void {
        $structure = structure::default_structure();
        $structure['departments'][] = [
            'id' => 'fixture_a',
            'name' => 'Fixture A',
            'cohort' => 'fixture_a',
        ];
        $structure['departments'][] = [
            'id' => 'fixture_b',
            'name' => 'Fixture B',
            'cohort' => 'fixture_b',
        ];
        $structure['positions'][] = [
            'id' => 'fixture_position',
            'department' => 'fixture_a',
            'name' => 'Fixture position',
            'level' => 1,
            'next' => null,
        ];
        structure::save(structure::NAME_STRUCTURE, $structure);

        $this->assertSame(
            [],
            grade_assignment_directory::employees_for_position('fixture_b', 'fixture_position')
        );
    }

    private function ensure_position_profile_field(): void {
        global $DB;

        if ($DB->record_exists('user_info_field', ['shortname' => 'ustar_position'])) {
            return;
        }

        $category = $DB->get_record('user_info_category', ['name' => 'USTAR'], '*', IGNORE_MISSING);
        if (!$category) {
            $categoryid = (int)$DB->insert_record('user_info_category', (object)[
                'name' => 'USTAR',
                'sortorder' => 1,
            ]);
        } else {
            $categoryid = (int)$category->id;
        }

        $DB->insert_record('user_info_field', (object)[
            'shortname' => 'ustar_position',
            'name' => 'Должность USTAR',
            'datatype' => 'text',
            'description' => '',
            'descriptionformat' => FORMAT_PLAIN,
            'categoryid' => $categoryid,
            'sortorder' => 1,
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

    private function set_legacy_position(int $userid, string $positionid): void {
        global $DB;
        $fieldid = (int)$DB->get_field('user_info_field', 'id', ['shortname' => 'ustar_position']);
        $DB->insert_record('user_info_data', (object)[
            'userid' => $userid,
            'fieldid' => $fieldid,
            'data' => $positionid,
            'dataformat' => 0,
        ]);
    }

    private function insert_primary_assignment(int $userid, string $departmentid, string $positionid): void {
        global $DB;
        $now = time();
        $placeid = (int)$DB->insert_record('local_ustar_staff_places', (object)[
            'placecode' => 'fixture_' . $userid,
            'positionid' => $positionid,
            'departmentid' => $departmentid,
            'managerplaceid' => null,
            'active' => 1,
            'effectivefrom' => 0,
            'effectiveto' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => 0,
        ]);
        $DB->insert_record('local_ustar_assignments', (object)[
            'staffplaceid' => $placeid,
            'userid' => $userid,
            'assignmenttype' => 'primary',
            'status' => 'active',
            'effectivefrom' => 0,
            'effectiveto' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => 0,
            'autorenew' => 0,
        ]);
    }

    private function insert_ended_assignment(int $userid, string $departmentid, string $positionid): void {
        global $DB;
        $now = time();
        $placeid = (int)$DB->insert_record('local_ustar_staff_places', (object)[
            'placecode' => 'fixture_ended_' . $userid,
            'positionid' => $positionid,
            'departmentid' => $departmentid,
            'managerplaceid' => null,
            'active' => 1,
            'effectivefrom' => 0,
            'effectiveto' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => 0,
        ]);
        $DB->insert_record('local_ustar_assignments', (object)[
            'staffplaceid' => $placeid,
            'userid' => $userid,
            'assignmenttype' => 'primary',
            'status' => 'ended',
            'effectivefrom' => 0,
            'effectiveto' => $now - 1,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => 0,
            'autorenew' => 0,
        ]);
    }
}
