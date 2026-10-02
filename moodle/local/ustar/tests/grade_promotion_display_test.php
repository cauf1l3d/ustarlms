<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(grade_promotion::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(grade_ladders::class)]
final class grade_promotion_display_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    public function test_request_display_uses_historical_ladder_labels_and_russian_status(): void {
        global $DB;

        $grades = [
            ['id' => 'trainee', 'name' => 'Стажёр'],
            ['id' => 'junior', 'name' => 'Младший консультант'],
            ['id' => 'consultant', 'name' => 'Консультант'],
        ];
        $json = json_encode($grades, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ladderid = (int)$DB->insert_record('local_ustar_grade_ladders', (object)[
            'name' => 'Тестовая лестница',
            'status' => 'active',
            'draftjson' => $json,
            'revision' => 1,
            'createdby' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $versionid = (int)$DB->insert_record('local_ustar_grade_ladder_ver', (object)[
            'ladderid' => $ladderid,
            'versionno' => 1,
            'gradesjson' => $json,
            'gradehash' => hash('sha256', $json),
            'createdby' => 0,
            'timecreated' => time(),
        ]);

        $approved = grade_promotion::request_display((object)[
            'fromgrade' => 'trainee',
            'tograde' => 'junior',
            'status' => grade_promotion::STATUS_APPROVED,
            'ladderversionid' => $versionid,
            'requirementsjson' => '{}',
        ]);

        $this->assertSame('Стажёр', $approved['fromlabel']);
        $this->assertSame('Младший консультант', $approved['tolabel']);
        $this->assertSame('Согласовано', $approved['statuslabel']);

        $returned = grade_promotion::request_display((object)[
            'fromgrade' => 'junior',
            'tograde' => 'consultant',
            'status' => grade_promotion::STATUS_REJECTED,
            'ladderversionid' => $versionid,
            'requirementsjson' => '{}',
        ]);

        $this->assertSame('Младший консультант', $returned['fromlabel']);
        $this->assertSame('Консультант', $returned['tolabel']);
        $this->assertSame('Возвращено', $returned['statuslabel']);
    }

    public function test_request_display_keeps_unknown_keys_as_safe_fallback(): void {
        $display = grade_promotion::request_display((object)[
            'fromgrade' => 'legacy_a',
            'tograde' => 'legacy_b',
            'status' => 'legacy_status',
            'ladderversionid' => 0,
            'requirementsjson' => '{}',
        ]);

        $this->assertSame('legacy_a', $display['fromlabel']);
        $this->assertSame('legacy_b', $display['tolabel']);
        $this->assertSame('legacy_status', $display['statuslabel']);
    }
}
