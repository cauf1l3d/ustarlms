<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(team_quiz::class)]
final class team_quiz_test extends \advanced_testcase {
    public function test_duplicate_labels_have_one_choice_and_both_position_ids_pass(): void {
        $map = [
            'rc_head' => ['name' => 'Зав.склад'],
            'other_head' => ['name' => 'Завсклад'],
            'controller' => ['name' => 'Контролер'],
            'other_controller' => ['name' => 'Контролёр'],
            'worker' => ['name' => 'Работник склада'],
            'deputy' => ['name' => 'Контролер/зам. Завсклада'],
        ];
        $original = $map;
        $subjects = [['positionid' => 'rc_head'], ['positionid' => 'other_head'],
            ['positionid' => 'controller'], ['positionid' => 'other_controller']];
        $choices = team_quiz::options($subjects, $map);
        $this->assertSame(['rc_head', 'controller', 'worker', 'deputy'], array_keys($choices));
        $this->assertTrue(team_quiz::answer_matches('rc_head', 'other_head', $map));
        $this->assertTrue(team_quiz::answer_matches('other_head', 'rc_head', $map));
        $this->assertTrue(team_quiz::answer_matches('controller', 'other_controller', $map));
        $this->assertFalse(team_quiz::answer_matches('deputy', 'rc_head', $map));
        $this->assertFalse(team_quiz::answer_matches('unknown', 'rc_head', $map));
        $this->assertFalse(team_quiz::answer_matches('', 'rc_head', $map));
        $this->assertSame($original, $map);
    }

    public function test_selected_position_wins_and_duplicate_distractors_do_not_exhaust_pool(): void {
        $map = ['duplicate' => ['name' => ' Зав. склад '], 'subject' => ['name' => 'Завсклад']];
        for ($i = 0; $i < 10; $i++) {
            $map['alias_' . $i] = ['name' => 'Завсклад'];
            $map['choice_' . $i] = ['name' => 'Должность ' . $i];
        }
        $choices = team_quiz::options([['positionid' => 'subject']], $map);
        $this->assertCount(8, $choices);
        $this->assertSame('subject', array_key_first($choices));
        $this->assertArrayHasKey('choice_6', $choices);
        $this->assertArrayNotHasKey('duplicate', $choices);
        $this->assertArrayNotHasKey('alias_0', $choices);
    }
}
