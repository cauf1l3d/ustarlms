<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(feed_external_content::class)]
final class feed_external_content_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    public function test_payload_returns_resolved_html_for_readable_external_post(): void {
        global $DB;

        $admin = get_admin();
        $this->setUser($admin);
        $now = time();

        $sourceid = (int)$DB->insert_record('local_ustar_feed_sources', (object)[
            'name' => 'Example',
            'url' => 'https://example.com/rss.xml',
            'urlhash' => hash('sha256', 'https://example.com/rss.xml'),
            'enabled' => 1,
            'resolverenabled' => 1,
            'audiencejson' => json_encode(['all']),
            'lastchecked' => 0,
            'lastsuccess' => 0,
            'lasterror' => null,
            'createdby' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $postid = (int)$DB->insert_record('local_ustar_feed_posts', (object)[
            'actoruserid' => 0,
            'publishertype' => 'external',
            'publisherid' => (string)$sourceid,
            'status' => 'published',
            'body' => 'Краткий анонс',
            'version' => 1,
            'audienceversion' => 1,
            'requestkey' => null,
            'sourcepostid' => null,
            'publishedat' => $now,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->insert_record('local_ustar_feed_audience', (object)[
            'postid' => $postid,
            'scopekind' => 'all',
            'scopeid' => 'all',
        ]);
        $DB->insert_record('local_ustar_feed_sourceitem', (object)[
            'sourceid' => $sourceid,
            'postid' => $postid,
            'guidhash' => hash('sha256', 'item'),
            'externalguid' => 'item',
            'externalurl' => 'https://example.com/article',
            'title' => 'Материал',
            'feedcontenttext' => 'Краткий анонс',
            'contenttext' => 'Полный текст материала',
            'contenthtml' => '<p><strong>Полный</strong> текст материала</p>',
            'enrichstatus' => 'done',
            'enrichattempts' => 1,
            'enrichnexttry' => 0,
            'enrichedat' => $now,
            'enricherror' => null,
            'resolvedurl' => 'https://example.com/article',
            'contenthash' => hash('sha256', 'Полный текст материала'),
            'publishedat' => $now,
            'timecreated' => $now,
        ]);

        $payload = feed_external_content::payload($postid, (int)$admin->id);
        $this->assertTrue($payload['available']);
        $this->assertSame('done', $payload['status']);
        $this->assertStringContainsString('<strong>Полный</strong>', $payload['html']);
    }
}
