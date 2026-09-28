<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(route_commands::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(route_model::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(native_learning::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(route_intro_transfer::class)]
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

    public function test_native_completion_uses_the_warehouse_point_version_instead_of_retail_ids(): void {
        global $DB;
        $structure = structure::get(structure::NAME_STRUCTURE);
        $structure['departments'][] = ['id' => 'warehouse', 'name' => 'Склад'];
        $structure['positions'][] = ['id' => 'warehouse_worker', 'department' => 'warehouse',
            'name' => 'Сотрудник склада', 'level' => 1];
        structure::save(structure::NAME_STRUCTURE, $structure);
        $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'ustar_position']);
        if (!$fieldid) {
            $field = $this->getDataGenerator()->create_custom_profile_field([
                'shortname' => 'ustar_position', 'name' => 'Должность', 'datatype' => 'text']);
            $fieldid = $field->id;
        }
        $employee = $this->getDataGenerator()->create_user();
        $DB->insert_record('user_info_data', (object)['userid' => $employee->id,
            'fieldid' => $fieldid, 'data' => 'warehouse_worker', 'dataformat' => 0]);
        $generator = $this->getDataGenerator()->get_plugin_generator('local_ustar');
        $route = $generator->create_route(['positionid' => 'warehouse_worker']);
        $point = $generator->create_point($route, ['pointkey' => 'warehouse_team']);
        $version = $generator->create_version($point, ['requirementsjson' => json_encode([
            ['type' => 'native', 'sourcekey' => native_learning::TEAM_STRUCTURE, 'required' => true],
        ])]);
        $this->setUser($employee);
        $available = native_learning::availability((int)$employee->id, native_learning::TEAM_STRUCTURE);
        $this->assertTrue($available['reachable']);
        $this->assertSame((int)$point->id, $available['pointid']);
        $eventid = native_learning::record((int)$employee->id, native_learning::TEAM_STRUCTURE,
            ['score' => 4]);
        $this->assertGreaterThan(0, $eventid);
        $this->assertSame($eventid, native_learning::record((int)$employee->id,
            native_learning::TEAM_STRUCTURE, ['score' => 4]));
        $this->assertNotNull(native_learning::fact((int)$employee->id, (int)$point->id,
            (int)$version->id, native_learning::TEAM_STRUCTURE));
        $this->assertSame((int)$version->id,
            (int)$DB->get_field('local_ustar_workflow_events', 'entityid', ['id' => $eventid]));
    }

    public function test_six_published_steps_transfer_as_drafts_without_rewriting_source(): void {
        global $DB, $USER;
        $generator = $this->getDataGenerator()->get_plugin_generator('local_ustar');
        $source = $generator->create_route(['positionid' => 'source_role']);
        $target = $generator->create_route(['positionid' => 'warehouse_role']);
        $ids = [];
        $versions = [];
        for ($i = 1; $i <= 6; $i++) {
            $point = $generator->create_point($source, ['pointkey' => 'introduction_' . $i,
                'sortorder' => $i * 10]);
            $version = $generator->create_version($point, ['title' => 'Шаг ' . $i,
                'requirementsjson' => json_encode([
                    ['type' => 'previous_adaptation', 'required' => true],
                ]), 'status' => route_model::STATUS_PUBLISHED]);
            $ids[] = (int)$point->id;
            $versions[] = (int)$version->id;
        }
        $preview = route_intro_transfer::preview((int)$source->id, (int)$target->id, $ids);
        $this->assertTrue($preview['ready']);
        $this->assertCount(6, $preview['rows']);
        $created = route_intro_transfer::create_drafts((int)$source->id, (int)$target->id,
            $ids, $preview['fingerprint'], (int)$USER->id);
        $this->assertCount(6, $created);
        $this->assertSame($created, route_intro_transfer::create_drafts((int)$source->id,
            (int)$target->id, $ids, $preview['fingerprint'], (int)$USER->id));
        foreach ($created as $id) {
            $this->assertSame(route_model::STATUS_DRAFT,
                (string)route_model::latest_version($id)->status);
        }
        foreach ($versions as $versionid) {
            $this->assertSame(route_model::STATUS_PUBLISHED,
                (string)$DB->get_field('local_ustar_route_versions', 'status', ['id' => $versionid]));
        }
        $this->assertCount(6, route_model::points((int)$target->id));
    }

    public function test_version_diff_reports_payload_and_requirement_changes(): void {
        $published = (object)[
            'versionno' => 3,
            'title' => 'Старый шаг',
            'summary' => 'Прежнее описание',
            'renewalpolicy' => route_model::RENEW_KEEP,
            'validdays' => 0,
            'requirementsjson' => json_encode([
                ['type' => 'content', 'sourceid' => 10, 'label' => 'Видео', 'required' => true],
                ['type' => 'native', 'sourcekey' => 'legacy_gate', 'label' => 'Старое условие', 'required' => false],
            ]),
        ];
        $draft = (object)[
            'versionno' => 4,
            'title' => 'Новый шаг',
            'summary' => 'Обновлённое описание',
            'renewalpolicy' => route_model::RENEW_EXPIRY,
            'validdays' => 30,
            'requirementsjson' => json_encode([
                ['type' => 'content', 'sourceid' => 10, 'label' => 'Видео', 'required' => true],
                ['type' => 'course', 'sourceid' => 20, 'label' => 'Курс', 'required' => true],
            ]),
        ];

        $diff = route_model::version_diff($published, $draft);

        $this->assertTrue($diff['available']);
        $this->assertTrue($diff['haschanges']);
        $this->assertSame(3, $diff['publishedversion']);
        $this->assertSame(4, $diff['draftversion']);
        $this->assertSame(
            ['Название', 'Описание', 'Повторное прохождение', 'Срок действия'],
            array_column($diff['rows'], 'label')
        );
        $this->assertSame(['Курс · обязательно'], $diff['requirementsadded']);
        $this->assertSame(['Старое условие'], $diff['requirementsremoved']);
        $this->assertTrue($diff['hasrequirementsadded']);
        $this->assertTrue($diff['hasrequirementsremoved']);
    }

    public function test_version_diff_without_draft_does_not_invent_changes(): void {
        $published = (object)[
            'versionno' => 1,
            'title' => 'Опубликовано',
            'summary' => '',
            'renewalpolicy' => route_model::RENEW_KEEP,
            'validdays' => 0,
            'requirementsjson' => '[]',
        ];

        $diff = route_model::version_diff($published, null);

        $this->assertFalse($diff['available']);
        $this->assertFalse($diff['haschanges']);
        $this->assertSame([], $diff['rows']);
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

    public function test_override_commands_are_idempotent_and_revert_preserves_history(): void {
        global $DB, $USER;
        [, , $route, $point] = $this->fixture();

        $overrideid = route_commands::create_override(
            (int)$route->id,
            (int)$point->id,
            (string)$route->positionid,
            (int)$USER->id
        );
        $this->assertNotSame((int)$point->id, $overrideid);
        $this->assertSame(
            [$overrideid],
            array_map(
                'intval',
                array_column(
                    route_scope::points_for_position(
                        (int)$route->id,
                        (string)$route->positionid,
                        false,
                        true
                    ),
                    'id'
                )
            )
        );

        $repeat = route_commands::create_override(
            (int)$route->id,
            (int)$point->id,
            (string)$route->positionid,
            (int)$USER->id
        );
        $this->assertSame($overrideid, $repeat);

        $sourceid = route_commands::revert_override(
            (int)$route->id,
            $overrideid,
            (string)$route->positionid,
            (int)$USER->id
        );
        $this->assertSame((int)$point->id, $sourceid);
        $this->assertSame(
            [(int)$point->id],
            array_map(
                'intval',
                array_column(
                    route_scope::points_for_position(
                        (int)$route->id,
                        (string)$route->positionid
                    ),
                    'id'
                )
            )
        );
        $this->assertSame(
            'reverted',
            (string)$DB->get_field('local_ustar_route_scope', 'state', [
                'pointid' => $overrideid,
                'scopeid' => (string)$route->positionid,
            ])
        );
        $this->assertNotNull(route_model::latest_version($overrideid));
    }

    public function test_override_rejects_position_from_another_route(): void {
        global $DB, $USER;
        [$generator, , $route, $point] = $this->fixture();
        $other = $generator->create_route();
        $before = $DB->count_records('local_ustar_route_points', ['routeid' => $route->id]);

        try {
            route_commands::create_override((int)$route->id, (int)$point->id,
                (string)$other->positionid, (int)$USER->id);
            $this->fail('Нельзя создать переопределение чужой должности');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertStringContainsString('не относится', $exception->getMessage());
        }

        $this->assertSame($before,
            $DB->count_records('local_ustar_route_points', ['routeid' => $route->id]));
    }

}
