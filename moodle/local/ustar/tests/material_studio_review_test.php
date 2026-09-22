<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(material_studio::class)]
final class material_studio_review_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
    }

    private function create_assessment(): array {
        global $USER;
        return material_studio::save(0, ['kind' => 'assessment', 'title' => 'Fixture assessment',
            'questions' => 'Question | Yes | No | 1'], $USER->id);
    }

    public function test_preview_does_not_write_a_submission(): void {
        global $DB, $USER;
        $item = $this->create_assessment();
        $result = material_studio::submit_assessment($item['id'], $USER->id, ['Yes'], $item['sourceversion'], true);
        $this->assertTrue($result['passed']);
        $this->assertTrue($result['preview']);
        $this->assertFalse($DB->record_exists('local_ustar_workflow_events', ['entitytype' => 'studio_assessment']));
    }

    public function test_learner_projection_omits_answers_and_inactive_users_lose_access(): void {
        global $DB;
        $item = $this->create_assessment();
        $DB->set_field('local_ustar_content', 'status', content::STATUS_PUBLISHED, ['id' => $item['id']]);
        $learner = $this->getDataGenerator()->create_user();
        $view = material_studio::by_content($item['id'], $learner->id);
        $this->assertArrayNotHasKey('answer', $view['questions'][0]);
        $this->assertSame('', $view['questionslines']);
        $this->assertFalse($view['editable_source']);
        $DB->set_field('user', 'suspended', 1, ['id' => $learner->id]);
        $this->expectException(\required_capability_exception::class);
        material_studio::by_content($item['id'], $learner->id);
    }

    public function test_draft_cannot_record_a_real_result_even_for_author(): void {
        global $USER;
        $item = $this->create_assessment();
        $this->expectException(\moodle_exception::class);
        material_studio::submit_assessment($item['id'], $USER->id, ['Yes'], $item['sourceversion']);
    }

    public function test_source_revision_is_required_before_grading(): void {
        global $USER;
        $item = $this->create_assessment();
        $this->expectException(\moodle_exception::class);
        material_studio::submit_assessment($item['id'], $USER->id, ['Yes'], $item['sourceversion'] + 1, true);
    }

    public function test_duplicate_submission_is_one_fact_and_source_change_is_distinct(): void {
        global $DB, $USER;
        $item = $this->create_assessment();
        $DB->set_field('local_ustar_content', 'status', content::STATUS_PUBLISHED, ['id' => $item['id']]);
        $first = material_studio::submit_assessment($item['id'], $USER->id, ['Yes'], $item['sourceversion']);
        $again = material_studio::submit_assessment($item['id'], $USER->id, ['Yes'], $item['sourceversion']);
        $this->assertSame($first['attemptkey'], $again['attemptkey']);
        $this->assertSame(1, $DB->count_records('local_ustar_workflow_events', ['entitytype' => 'studio_assessment']));
        $next = material_studio::save($item['id'], ['kind' => 'assessment', 'title' => 'Updated assessment',
            'questions' => 'Different question | Yes | No | 2', 'expectedmodified' => $item['expectedmodified']], $USER->id);
        $second = material_studio::submit_assessment($item['id'], $USER->id, ['Yes'], $next['sourceversion']);
        $this->assertNotSame($first['attemptkey'], $second['attemptkey']);
        $this->assertFalse($second['passed']);
        $this->assertSame(2, $DB->count_records('local_ustar_workflow_events', ['entitytype' => 'studio_assessment']));
    }
}
