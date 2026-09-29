<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(grade_rules::class)]
final class grade_rules_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
    }

    public function test_transition_rule_pins_exact_published_route_versions(): void {
        global $USER;
        $g = $this->getDataGenerator()->get_plugin_generator('local_ustar');
        $positionid = 'fixture_grade_position';
        $route = $g->create_route(['positionid' => $positionid]);
        $p1 = $g->create_point($route, ['sortorder' => 10]);
        $p2 = $g->create_point($route, ['sortorder' => 20]);
        $v1 = $g->create_version($p1, ['versionno' => 1, 'title' => 'First evidence']);
        $v2 = $g->create_version($p2, ['versionno' => 1, 'title' => 'Second evidence']);

        $rule1 = grade_rules::publish_transition(
            $positionid, 'trainee', [(int)$p1->id], (int)$USER->id
        );
        $req1 = grade_rules::requirements($rule1);
        $policy1 = grade_rules::submission_policy($rule1);
        $this->assertSame(grade_rules::MODE_MANUAL, $policy1['mode']);
        $this->assertSame(0, $policy1['triggerpointid']);
        $this->assertSame(1, (int)$rule1->versionno);
        $this->assertSame('junior', (string)$rule1->tograde);
        $this->assertSame((int)$v1->id, (int)$req1[0]['versionid']);

        $g->create_version($p1, ['versionno' => 2, 'title' => 'First evidence v2']);
        $rule2 = grade_rules::publish_transition(
            $positionid, 'trainee', [(int)$p1->id, (int)$p2->id], (int)$USER->id
        );
        $req2 = grade_rules::requirements($rule2);
        $this->assertSame(2, (int)$rule2->versionno);
        $this->assertNotSame((string)$rule1->rulehash, (string)$rule2->rulehash);
        $this->assertCount(2, $req2);
        $this->assertSame((int)$v2->id, (int)$req2[1]['versionid']);
        $this->assertSame((int)$v1->id, (int)$req1[0]['versionid']);

        $current = grade_rules::published($positionid, 'trainee', 'junior');
        $this->assertSame((int)$rule2->id, (int)$current->id);

        // A rule belongs to one exact transition. The next grade must stay
        // blocked until its own rule is explicitly published.
        $this->assertNull(grade_rules::published($positionid, 'junior', 'middle'));
    }

    public function test_automatic_submission_policy_is_pinned_to_selected_published_point(): void {
        global $USER;
        $g = $this->getDataGenerator()->get_plugin_generator('local_ustar');
        $positionid = 'fixture_grade_auto_position';
        $route = $g->create_route(['positionid' => $positionid]);
        $p1 = $g->create_point($route, ['sortorder' => 10]);
        $p2 = $g->create_point($route, ['sortorder' => 20]);
        $g->create_version($p1, ['versionno' => 1, 'title' => 'Первый шаг']);
        $v2 = $g->create_version($p2, ['versionno' => 1, 'title' => 'Контрольный шаг']);

        $rule = grade_rules::publish_transition(
            $positionid,
            'trainee',
            [(int)$p1->id, (int)$p2->id],
            (int)$USER->id,
            grade_rules::MODE_AUTO,
            (int)$p2->id
        );

        $policy = grade_rules::submission_policy($rule);
        $this->assertSame(grade_rules::MODE_AUTO, $policy['mode']);
        $this->assertSame((int)$p2->id, $policy['triggerpointid']);
        $this->assertSame((int)$v2->id, $policy['triggerversionid']);
        $this->assertSame('Контрольный шаг', $policy['triggertitle']);
    }

    public function test_automatic_submission_rejects_trigger_outside_selected_requirements(): void {
        global $USER;
        $g = $this->getDataGenerator()->get_plugin_generator('local_ustar');
        $positionid = 'fixture_grade_auto_invalid';
        $route = $g->create_route(['positionid' => $positionid]);
        $selected = $g->create_point($route, ['sortorder' => 10]);
        $trigger = $g->create_point($route, ['sortorder' => 20]);
        $g->create_version($selected, ['versionno' => 1]);
        $g->create_version($trigger, ['versionno' => 1]);

        $this->expectException(\invalid_parameter_exception::class);
        grade_rules::publish_transition(
            $positionid,
            'trainee',
            [(int)$selected->id],
            (int)$USER->id,
            grade_rules::MODE_AUTO,
            (int)$trigger->id
        );
    }

    public function test_rule_rejects_point_from_another_route(): void {
        global $USER;
        $g = $this->getDataGenerator()->get_plugin_generator('local_ustar');
        $route = $g->create_route(['positionid' => 'fixture_grade_position_a']);
        $g->create_version($g->create_point($route));
        $foreign = $g->create_route(['positionid' => 'fixture_grade_position_b']);
        $foreignpoint = $g->create_point($foreign);
        $g->create_version($foreignpoint);

        $this->expectException(\invalid_parameter_exception::class);
        grade_rules::publish_transition(
            'fixture_grade_position_a', 'trainee', [(int)$foreignpoint->id], (int)$USER->id
        );
    }
}
