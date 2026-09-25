<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Exercise the real Moodle quiz/question-bank creation path on a disposable DB. */
#[\PHPUnit\Framework\Attributes\CoversClass(assessment_authoring::class)]
final class assessment_authoring_review_test extends \advanced_testcase {
    public function test_create_persists_a_quiz_and_replaying_the_form_does_not_duplicate_it(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $token = bin2hex(random_bytes(16));
        $input = [
            'title' => 'Проверка товарного ассортимента',
            'pass' => '80',
            'minutes' => '15',
            'bankids' => [],
            'questions' => [
                [
                    'type' => 'choice', 'text' => 'Что относится к ассортименту?',
                    'option1' => 'Товар', 'option2' => 'Отпуск', 'option3' => '', 'option4' => '',
                    'correct' => '1', 'points' => '1', 'feedback' => '',
                ],
                [
                    'type' => 'essay', 'text' => 'Объясните способ выкладки.',
                    'option1' => '', 'option2' => '', 'option3' => '', 'option4' => '',
                    'correct' => '', 'points' => '2', 'feedback' => '',
                ],
            ],
        ];

        $created = assessment_authoring::create($input, $token);
        $this->assertGreaterThan(0, $created['contentid']);
        $content = $DB->get_record('local_ustar_content', ['id' => $created['contentid']], '*', MUST_EXIST);
        $this->assertSame(content::STATUS_DRAFT, $content->status);
        $this->assertSame(content::SOURCE_MOODLE, $content->sourcekind);
        $this->assertSame((int)$created['cmid'], (int)$content->cmid);
        $this->assertSame(2, $DB->count_records('quiz_slots', ['quizid' => $created['quizid']]));

        $replayed = assessment_authoring::create($input, $token);
        $this->assertSame($created['contentid'], $replayed['contentid']);
        $this->assertSame(1, $DB->count_records('course', ['shortname' => 'USTAR-AT-' . $token]));
    }
}
