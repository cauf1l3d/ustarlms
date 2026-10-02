<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(hr_access::class)]
final class recruiter_access_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    private function role(string $shortname, array $caps): int {
        global $DB;
        $context = \context_system::instance();
        $roleid = (int)$DB->get_field('role', 'id', ['shortname' => $shortname]);
        if (!$roleid) {
            $roleid = (int)create_role('Fixture ' . $shortname, $shortname, 'Fixture role');
            set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        }
        foreach ($caps as $cap) {
            assign_capability($cap, CAP_ALLOW, $roleid, $context->id, true);
        }
        return $roleid;
    }

    public function test_recruiter_keeps_operational_hr_but_not_hrd_or_structure_authority(): void {
        $user = $this->getDataGenerator()->create_user();
        $context = \context_system::instance();

        $hr = $this->role('ustar_hr', [
            'local/ustar:use',
            'local/ustar:hr',
            'local/ustar:hrmanage',
            'local/ustar:viewteam',
            'local/ustar:gradeassessments',
            'local/ustar:requeststaff',
        ]);
        role_assign($hr, (int)$user->id, $context->id);
        accesslib_clear_all_caches(true);

        $this->assertTrue(hr_access::is_recruiter((int)$user->id));
        $this->assertTrue(hr_access::can_grade((int)$user->id));
        $this->assertFalse(hr_access::can_manage_structure((int)$user->id));
        $this->assertFalse(hr_access::can_view_hrd_escalations((int)$user->id));
    }

    public function test_hrd_retains_structure_and_escalation_authority(): void {
        $user = $this->getDataGenerator()->create_user();
        $context = \context_system::instance();

        $hrd = $this->role('ustar_hrd', [
            'local/ustar:use',
            'local/ustar:hr',
            'local/ustar:hrmanage',
            'local/ustar:viewteam',
            'local/ustar:gradeassessments',
        ]);
        role_assign($hrd, (int)$user->id, $context->id);
        accesslib_clear_all_caches(true);

        $this->assertFalse(hr_access::is_recruiter((int)$user->id));
        $this->assertTrue(hr_access::can_manage_structure((int)$user->id));
        $this->assertTrue(hr_access::can_view_hrd_escalations((int)$user->id));
    }
}
