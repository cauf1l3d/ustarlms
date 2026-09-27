<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Read-only aggregate review of the owner's feed retention periods. */
final class feed_retention {
    /**
     * Counts are candidates for human review, not permission to delete them.
     * No personal content or identifiers leave this report.
     *
     * @return array<string,int>
     */
    public static function review_counts(int $now): array {
        global $DB;

        $draftcutoff = strtotime('-90 days', $now);
        $noticecutoff = strtotime('-76 days', $now);
        $closedcutoff = strtotime('-30 days', $now);
        $auditcutoff = strtotime('-12 months', $now);
        $publishedcutoff = strtotime('-1 year', $now);

        return [
            'published_review' => $DB->count_records_select('local_ustar_feed_posts',
                'status = :status AND publishedat <= :cutoff',
                ['status' => 'published', 'cutoff' => $publishedcutoff]),
            'draft_notice' => $DB->count_records_select('local_ustar_feed_posts',
                'status = :status AND timemodified <= :cutoff AND timemodified > :older',
                ['status' => 'draft', 'cutoff' => $noticecutoff, 'older' => $draftcutoff]),
            'draft_review' => $DB->count_records_select('local_ustar_feed_posts',
                'status = :status AND timemodified <= :cutoff',
                ['status' => 'draft', 'cutoff' => $draftcutoff]),
            'closed_post_review' => $DB->count_records_select('local_ustar_feed_posts',
                'status IN (:hidden, :deleted) AND timemodified <= :cutoff',
                ['hidden' => 'hidden', 'deleted' => 'deleted', 'cutoff' => $closedcutoff]),
            'deleted_comment_review' => $DB->count_records_select('local_ustar_feed_comments',
                'status = :status AND timemodified <= :cutoff',
                ['status' => 'deleted', 'cutoff' => $closedcutoff]),
            'closed_report_review' => $DB->count_records_select('local_ustar_feed_reports',
                'status IN (:resolved, :dismissed) AND timemodified <= :cutoff',
                ['resolved' => 'resolved', 'dismissed' => 'dismissed', 'cutoff' => $auditcutoff]),
            'old_event_review' => $DB->count_records_select('local_ustar_feed_events',
                'timecreated <= :cutoff', ['cutoff' => $auditcutoff]),
        ];
    }
}
