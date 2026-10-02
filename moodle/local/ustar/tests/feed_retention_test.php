<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** The retention report must never mutate posts or include personal content. */
#[\PHPUnit\Framework\Attributes\CoversClass(feed_retention::class)]
final class feed_retention_test extends \advanced_testcase {
    public function test_review_counts_respect_cutoffs_without_deleting(): void {
        global $DB;
        $this->resetAfterTest(true);
        $now = strtotime('2026-09-28 00:00:00 UTC');
        $draftcutoff = strtotime('-90 days', $now);
        $noticecutoff = strtotime('-76 days', $now);
        foreach ([$draftcutoff, $noticecutoff, $noticecutoff + 1] as $modified) {
            $DB->insert_record('local_ustar_feed_posts', (object)[
                'actoruserid' => 2, 'publishertype' => 'person', 'publisherid' => '2',
                'status' => 'draft', 'body' => 'private body', 'version' => 1,
                'audienceversion' => 1, 'requestkey' => 'draft' . $modified,
                'sourcepostid' => null, 'publishedat' => null,
                'timecreated' => $modified, 'timemodified' => $modified,
            ]);
        }
        $counts = feed_retention::review_counts($now);
        $this->assertSame(1, $counts['draft_review']);
        $this->assertSame(1, $counts['draft_notice']);
        $this->assertSame(3, $DB->count_records('local_ustar_feed_posts'));
        $this->assertStringNotContainsString('private body', json_encode($counts));
    }
}
