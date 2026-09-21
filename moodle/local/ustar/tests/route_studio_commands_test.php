<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(route_commands::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(route_model::class)]
final class route_studio_commands_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
        accounts::ensure_profile_field();
    }

    /** @return array{0:local_ustar_generator,1:stdClass,2:stdClass,3:stdClass,4:stdClass} */
    private function fixture(): array {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_ustar');
        $user = $this->getDataGenerator()->create_user();
        $route = $generator->create_route();
        $point = $generator->create_point($route, [
            'sortorder' => 10,
            'phase' => route_model::PHASE_ADAPTATION,
        ]);
        $version = $generator->create_version($point, [
            'requirementsjson' => json_encode([
                ['type' => 'native', 'sourcekey' => 'fixture_start', 'required' => true],
            ]),
        ]);
        return [$generator, $user, $route, $point, $version];
    }

    public function test_failed_publish_rolls_back_point_metadata_and_version(): void {
        global $DB, $USER;
        [, , $route, $point, $version] = $this->fixture();
        $draftcontentid = $DB->insert_record('local_ustar_content', (object)[
            'type' => 'document',
            'title' => 'Черновой материал',
            'summary' => '',
            'category' => 'fixture',
            'status' => content::STATUS_DRAFT,
            'sourcekind' => content::SOURCE_FILE,
            'ackrequired' => 0,
            'sortorder' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
            'usermodified' => (int)$USER->id,
        ]);
        $beforepoint = $DB->get_record(
            'local_ustar_route_points',
            ['id' => $point->id],
            '*',
            MUST_EXIST
        );
        $beforeversions = $DB->count_records(
            'local_ustar_route_versions',
            ['pointid' => $point->id]
        );

        try {
            route_commands::save_version([
                'routeid' => $route->id,
                'pointid' => $point->id,
                'phase' => route_model::PHASE_GATE,
                'active' => false,
                'versiondata' => [
                    'title' => 'Новая версия',
                    'summary' => 'Не должна сохраниться',
                    'requirements' => [[
                        'type' => 'content',
                        'sourceid' => $draftcontentid,
                        'required' => true,
                    ]],
                    'status' => route_model::STATUS_PUBLISHED,
                ],
                'actorid' => (int)$USER->id,
                'expectedmodified' => (int)$beforepoint->timemodified,
            ]);
            $this->fail('Публикация чернового материала должна быть отклонена');
        } catch (\moodle_exception $exception) {
            $this->assertStringContainsString('опубликовать', $exception->getMessage());
        }

        $afterpoint = $DB->get_record(
            'local_ustar_route_points',
            ['id' => $point->id],
            '*',
            MUST_EXIST
        );
        $this->assertSame((int)$beforepoint->timemodified, (int)$afterpoint->timemodified);
        $this->assertSame((string)$beforepoint->phase, (string)$afterpoint->phase);
        $this->assertSame((int)$beforepoint->active, (int)$afterpoint->active);
        $this->assertSame(
            $beforeversions,
            $DB->count_records('local_ustar_route_versions', ['pointid' => $point->id])
        );
        $this->assertSame(
            (int)$version->id,
            (int)$DB->get_field('local_ustar_route_versions', 'id', [
                'pointid' => $point->id,
                'versionno' => 1,
            ])
        );
    }

    public function test_stale_point_edit_is_rejected_before_any_write(): void {
        global $DB, $USER;
        [, , $route, $point] = $this->fixture();
        $oldmodified = (int)$point->timemodified;
        $DB->set_field(
            'local_ustar_route_points',
            'timemodified',
            $oldmodified + 10,
            ['id' => $point->id]
        );
        $beforeversions = $DB->count_records(
            'local_ustar_route_versions',
            ['pointid' => $point->id]
        );

        $this->expectException(\moodle_exception::class);
        route_commands::save_version([
            'routeid' => $route->id,
            'pointid' => $point->id,
            'phase' => route_model::PHASE_GATE,
            'active' => true,
            'versiondata' => [
                'title' => 'Устаревшая форма',
                'requirements' => [[
                    'type' => 'native',
                    'sourcekey' => 'fixture_gate',
                    'required' => true,
                ]],
                'status' => route_model::STATUS_DRAFT,
            ],
            'actorid' => (int)$USER->id,
            'expectedmodified' => $oldmodified,
        ]);

        $this->assertSame(
            $beforeversions,
            $DB->count_records('local_ustar_route_versions', ['pointid' => $point->id])
        );
    }

    public function test_publish_archives_previous_version_and_is_idempotent(): void {
        global $DB, $USER;
        [$generator, , $route, $point, $first] = $this->fixture();
        $second = $generator->create_version($point, [
            'versionno' => 2,
            'title' => 'Вторая версия',
            'requirementsjson' => json_encode([
                ['type' => 'native', 'sourcekey' => 'fixture_second', 'required' => true],
            ]),
            'status' => route_model::STATUS_DRAFT,
        ]);
        $currentpoint = $DB->get_record(
            'local_ustar_route_points',
            ['id' => $point->id],
            '*',
            MUST_EXIST
        );

        $published = route_commands::publish_version(
            $route->id,
            $point->id,
            $second->id,
            (int)$USER->id,
            (int)$currentpoint->timemodified
        );
        $this->assertSame(route_model::STATUS_PUBLISHED, (string)$published->status);
        $this->assertSame(
            route_model::STATUS_ARCHIVED,
            (string)$DB->get_field('local_ustar_route_versions', 'status', ['id' => $first->id])
        );
        $this->assertSame($second->id, route_model::current_published_version($point->id)->id);

        $afterpublish = $DB->get_record(
            'local_ustar_route_points',
            ['id' => $point->id],
            '*',
            MUST_EXIST
        );
        $repeat = route_commands::publish_version(
            $route->id,
            $point->id,
            $second->id,
            (int)$USER->id,
            (int)$afterpublish->timemodified
        );
        $this->assertSame($second->id, $repeat->id);
        $this->assertSame(
            1,
            $DB->count_records('local_ustar_route_versions', [
                'pointid' => $point->id,
                'status' => route_model::STATUS_PUBLISHED,
            ])
        );
    }

    public function test_reorder_requires_complete_list_and_current_revision(): void {
        global $USER;
        $generator = $this->getDataGenerator()->get_plugin_generator('local_ustar');
        $route = $generator->create_route();
        $first = $generator->create_point($route, ['sortorder' => 10]);
        $second = $generator->create_point($route, ['sortorder' => 20]);
        $revision = route_model::revision($route->id);

        try {
            route_commands::reorder(
                $route->id,
                [$first->id],
                (int)$USER->id,
                $revision
            );
            $this->fail('Неполный список шагов не должен сохраняться');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString('полный список', $exception->getMessage());
        }

        route_commands::reorder(
            $route->id,
            [$second->id, $first->id],
            (int)$USER->id,
            $revision
        );
        global $DB;
        $this->assertSame(10, (int)$DB->get_field('local_ustar_route_points', 'sortorder', ['id' => $second->id]));
        $this->assertSame(20, (int)$DB->get_field('local_ustar_route_points', 'sortorder', ['id' => $first->id]));

        $this->expectException(\moodle_exception::class);
        route_commands::reorder(
            $route->id,
            [$first->id, $second->id],
            (int)$USER->id,
            $revision
        );
    }
}
