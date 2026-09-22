<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(learning_tasks::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(catalog::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(completion_cycle::class)]
final class stage6_product_services_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
    }

    public function test_personal_note_never_crosses_its_owner_boundary(): void {
        global $DB;

        $owner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $note = learning_tasks::create_note((int)$owner->id, 'Личный план', 'Только для владельца');

        $visible = learning_tasks::view((int)$note['id'], (int)$owner->id);
        $this->assertSame('Личный план', $visible['title']);
        $this->assertTrue($visible['private']);

        $event = $DB->get_record('local_ustar_learning_task_events', ['taskid' => (int)$note['id']], '*', MUST_EXIST);
        $this->assertSame('[]', (string)$event->datajson);
        $this->assertStringNotContainsString('Личный план', (string)$event->datajson);

        $this->expectException(\required_capability_exception::class);
        learning_tasks::view((int)$note['id'], (int)$other->id);
    }

    public function test_catalog_enforces_hierarchy_and_keeps_revision_history(): void {
        global $DB, $USER;

        try {
            catalog::save(0, [
                'itemtype' => catalog::TYPE_PRODUCT,
                'title' => 'Неверный корневой товар',
            ], (int)$USER->id);
            $this->fail('A root product must be rejected.');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('корне', $e->getMessage());
        }

        $group = catalog::save(0, [
            'itemtype' => catalog::TYPE_GROUP,
            'title' => 'Кофе',
        ], (int)$USER->id);
        $subgroup = catalog::save(0, [
            'itemtype' => catalog::TYPE_SUBGROUP,
            'parentid' => (int)$group->id,
            'title' => 'Зерно',
        ], (int)$USER->id);
        $card = catalog::save(0, [
            'itemtype' => catalog::TYPE_PRODUCT,
            'parentid' => (int)$subgroup->id,
            'title' => 'Эспрессо',
            'attributes' => "Объём: 250 г\nСтрана: Бразилия",
        ], (int)$USER->id);
        $updated = catalog::save((int)$card->id, [
            'itemtype' => catalog::TYPE_PRODUCT,
            'parentid' => (int)$subgroup->id,
            'title' => 'Эспрессо 250 г',
            'expectedmodified' => (int)$card->timemodified,
        ], (int)$USER->id);

        $this->assertSame('Эспрессо 250 г', (string)$updated->title);
        $this->assertGreaterThanOrEqual(
            3,
            $DB->count_records('local_ustar_catalog_versions', ['catalogid' => (int)$card->id])
        );
    }

    public function test_revoked_completion_cannot_pass_the_retraining_gate(): void {
        global $DB;

        $generator = $this->getDataGenerator()->get_plugin_generator('local_ustar');
        $user = $this->getDataGenerator()->create_user();
        $route = $generator->create_route();
        $point = $generator->create_point($route);
        $version = $generator->create_version($point);
        $cycle = completion_cycle::confirm(
            (int)$user->id,
            (int)$point->id,
            (int)$version->id,
            1000,
            0,
            ['mode' => 'evaluated', 'requirements' => [
                ['type' => 'cm', 'required' => true, 'satisfied' => true, 'completedat' => 1000],
            ]]
        );

        $this->assertNotNull(completion_cycle::latest_confirmed((int)$user->id, (int)$point->id));
        $DB->set_field('local_ustar_completion_cycle', 'status', 'revoked', ['id' => (int)$cycle->id]);
        $this->assertNull(completion_cycle::latest_confirmed((int)$user->id, (int)$point->id));
    }
}
