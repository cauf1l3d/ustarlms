<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(material_studio::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(route_model::class)]
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

    public function test_scorm_photo_is_kept_by_page_key_without_accepting_posted_image_data(): void {
        $key = str_repeat('a', 32);
        $original = 'data:image/png;base64,aGVsbG8=';
        $pages = (new \ReflectionMethod(material_studio::class, 'scorm_pages'))->invoke(null,
            [['title' => 'Страница', 'body' => 'Текст', 'imagekey' => $key,
                'image' => 'data:image/svg+xml;base64,PHN2Zz4=']],
            [], [['title' => 'Страница', 'body' => 'Текст', 'imagekey' => $key, 'image' => $original]]);
        $this->assertSame($original, $pages[0]['image']);
        $this->assertSame($key, $pages[0]['imagekey']);
        $package = studio_scorm_package::html(['title' => 'Страница', 'pages' => $pages]);
        $this->assertStringContainsString(str_replace('/', '\\/', $original), $package);
        $this->assertStringNotContainsString('image/svg+xml', $package);
    }

    public function test_preview_does_not_write_a_submission(): void {
        global $DB, $USER;
        $item = $this->create_assessment();
        $result = material_studio::submit_assessment($item['id'], $USER->id, ['Yes'], $item['sourceversion'], true);
        $this->assertTrue($result['passed']);
        $this->assertTrue($result['preview']);
        $this->assertFalse($DB->record_exists('local_ustar_workflow_events', ['entitytype' => 'studio_assessment']));
    }

    public function test_player_option_index_is_graded_without_text_round_trip(): void {
        global $DB, $USER;
        $item = $this->create_assessment();
        $DB->set_field('local_ustar_content', 'status', content::STATUS_PUBLISHED, ['id' => $item['id']]);

        $result = material_studio::submit_assessment(
            (int)$item['id'], (int)$USER->id, [1], (int)$item['sourceversion']
        );

        $this->assertTrue($result['passed']);
        $this->assertSame(100, $result['score']);
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
        content_admin::unpublish((int)$item['id'], (int)$USER->id);
        $draft = material_studio::by_content((int)$item['id'], (int)$USER->id);
        $next = material_studio::save($item['id'], ['kind' => 'assessment', 'title' => 'Updated assessment',
            'questions' => 'Different question | Yes | No | 2', 'expectedmodified' => $draft['expectedmodified']], $USER->id);
        content_admin::publish((int)$item['id'], (int)$USER->id);
        $second = material_studio::submit_assessment($item['id'], $USER->id, ['Yes'], $next['sourceversion']);
        $this->assertNotSame($first['attemptkey'], $second['attemptkey']);
        $this->assertFalse($second['passed']);
        $this->assertSame(2, $DB->count_records('local_ustar_workflow_events', ['entitytype' => 'studio_assessment']));
    }

    public function test_only_passed_current_source_version_is_route_completion_evidence(): void {
        global $DB, $USER;
        $item = $this->create_assessment();
        content_admin::publish((int)$item['id'], (int)$USER->id);

        $failed = material_studio::submit_assessment(
            (int)$item['id'],
            (int)$USER->id,
            ['No'],
            (int)$item['sourceversion']
        );
        $this->assertFalse($failed['passed']);
        $this->assertNull(material_studio::completion_for_user(
            (int)$item['id'],
            (int)$USER->id,
            (int)$item['sourceversion']
        ));

        $passed = material_studio::submit_assessment(
            (int)$item['id'],
            (int)$USER->id,
            ['Yes'],
            (int)$item['sourceversion']
        );
        $completion = material_studio::completion_for_user(
            (int)$item['id'],
            (int)$USER->id,
            (int)$item['sourceversion']
        );
        $this->assertNotNull($completion);
        $this->assertSame($passed['attemptkey'], $completion['attemptkey']);

        content_admin::unpublish((int)$item['id'], (int)$USER->id);
        $draft = material_studio::by_content((int)$item['id'], (int)$USER->id);
        $updated = material_studio::save((int)$item['id'], [
            'kind' => 'assessment',
            'title' => 'Updated assessment',
            'questions' => 'New question | Yes | No | 1',
            'expectedmodified' => (int)$draft['expectedmodified'],
        ], (int)$USER->id);

        $this->assertNull(material_studio::completion_for_user(
            (int)$item['id'],
            (int)$USER->id,
            (int)$updated['sourceversion']
        ));
        $this->assertSame(
            2,
            $DB->count_records('local_ustar_workflow_events', [
                'entitytype' => 'studio_assessment',
                'entityid' => (int)$item['id'],
                'eventtype' => 'studio_assessment_submitted',
            ])
        );
    }

    public function test_route_content_uses_studio_assessment_pass_and_never_scorm_open_as_completion(): void {
        global $USER;
        $g = $this->getDataGenerator()->get_plugin_generator('local_ustar');
        $route = $g->create_route();
        $point = $g->create_point($route);
        $version = $g->create_version($point);

        $assessment = $this->create_assessment();
        content_admin::publish((int)$assessment['id'], (int)$USER->id);

        $method = new \ReflectionMethod(route_model::class, 'requirement_result');
        $requirement = [
            'type' => 'content',
            'sourceid' => (int)$assessment['id'],
            'required' => true,
            'completionmode' => 'open',
        ];
        $before = $method->invoke(null, $requirement, (int)$USER->id, (string)$route->positionid, [], $version);
        $this->assertTrue($before['configured']);
        $this->assertFalse($before['satisfied']);

        material_studio::submit_assessment(
            (int)$assessment['id'],
            (int)$USER->id,
            ['Yes'],
            (int)$assessment['sourceversion']
        );
        $after = $method->invoke(null, $requirement, (int)$USER->id, (string)$route->positionid, [], $version);
        $this->assertTrue($after['configured']);
        $this->assertTrue($after['satisfied']);
        $this->assertGreaterThan(0, (int)$after['completedat']);

        $scorm = material_studio::save(0, [
            'kind' => 'scorm',
            'title' => 'Studio SCORM without runtime',
        ], (int)$USER->id);
        $scormresult = $method->invoke(null, [
            'type' => 'content',
            'sourceid' => (int)$scorm['id'],
            'required' => true,
            'completionmode' => 'open',
        ], (int)$USER->id, (string)$route->positionid, [], $version);
        $this->assertFalse($scormresult['configured']);
        $this->assertFalse($scormresult['satisfied']);
        $this->assertStringContainsString('опубликованная версия', (string)$scormresult['detail']);
    }


    public function test_route_rejects_studio_scorm_without_runtime(): void {
        global $DB, $USER;
        $g = $this->getDataGenerator()->get_plugin_generator('local_ustar');
        $route = $g->create_route();
        $point = $g->create_point($route);
        $g->create_version($point);

        $scorm = material_studio::save(0, [
            'kind' => 'scorm',
            'title' => 'Studio SCORM attachment',
        ], (int)$USER->id);
        $pointrow = $DB->get_record('local_ustar_route_points', ['id' => $point->id], '*', MUST_EXIST);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('Studio SCORM');
        route_commands::save_version([
            'routeid' => (int)$route->id,
            'pointid' => (int)$point->id,
            'phase' => route_model::PHASE_ADAPTATION,
            'active' => true,
            'versiondata' => [
                'title' => 'SCORM step',
                'requirements' => [[
                    'type' => 'content',
                    'sourceid' => (int)$scorm['id'],
                    'required' => true,
                ]],
                'status' => route_model::STATUS_PUBLISHED,
            ],
            'actorid' => (int)$USER->id,
            'expectedmodified' => (int)$pointrow->timemodified,
        ]);
    }


    public function test_passed_studio_assessment_flows_into_canonical_route_completion(): void {
        global $DB, $USER;
        $g = $this->getDataGenerator()->get_plugin_generator('local_ustar');
        $route = $g->create_route();
        $point = $g->create_point($route);
        $version = $g->create_version($point);

        $assessment = $this->create_assessment();
        content_admin::publish((int)$assessment['id'], (int)$USER->id);
        $DB->set_field(
            'local_ustar_route_versions',
            'requirementsjson',
            json_encode([[
                'type' => 'content',
                'sourceid' => (int)$assessment['id'],
                'required' => true,
                'completionmode' => 'open',
            ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ['id' => (int)$version->id]
        );
        $version = $DB->get_record('local_ustar_route_versions', ['id' => (int)$version->id], '*', MUST_EXIST);

        material_studio::submit_assessment(
            (int)$assessment['id'],
            (int)$USER->id,
            ['Yes'],
            (int)$assessment['sourceversion']
        );

        $method = new \ReflectionMethod(route_model::class, 'evaluate_point');
        $state = $method->invoke(
            null,
            $point,
            $version,
            (int)$USER->id,
            (string)$route->positionid,
            []
        );

        $this->assertTrue($state['satisfied']);
        $progress = $DB->get_record('local_ustar_route_progress', [
            'userid' => (int)$USER->id,
            'pointid' => (int)$point->id,
            'versionid' => (int)$version->id,
        ], '*', MUST_EXIST);
        $this->assertSame('complete', (string)$progress->status);

        $cycle = $DB->get_record('local_ustar_completion_cycle', [
            'userid' => (int)$USER->id,
            'pointid' => (int)$point->id,
            'versionid' => (int)$version->id,
        ], '*', MUST_EXIST);
        $this->assertSame('confirmed', (string)$cycle->status);
        $evidence = json_decode((string)$cycle->evidencejson, true);
        $this->assertSame('evaluated', (string)($evidence['mode'] ?? ''));
        $this->assertSame('content', (string)($evidence['requirements'][0]['type'] ?? ''));
        $this->assertTrue(!empty($evidence['requirements'][0]['satisfied']));
    }


    public function test_published_studio_source_must_be_unpublished_before_editing(): void {
        global $USER;
        $item = $this->create_assessment();
        content_admin::publish((int)$item['id'], (int)$USER->id);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('нельзя менять на лету');
        material_studio::save((int)$item['id'], [
            'kind' => 'assessment',
            'title' => 'Unsafe live edit',
            'questions' => 'Question | Yes | No | 1',
            'expectedmodified' => (int)$item['expectedmodified'],
        ], (int)$USER->id);
    }

}
