<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(feed_rss::class)]
final class feed_rss_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    public function test_parse_rss_extracts_safe_card_fields(): void {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <title>Example</title>
    <item>
      <title>Новый материал</title>
      <link>https://example.com/post/1</link>
      <guid>post-1</guid>
      <pubDate>Sun, 28 Sep 2026 10:00:00 +0300</pubDate>
      <description><![CDATA[<p>Короткое <strong>описание</strong>.</p>]]></description>
    </item>
  </channel>
</rss>
XML;
        $items = feed_rss::parse($xml);
        $this->assertCount(1, $items);
        $this->assertSame('Новый материал', $items[0]['title']);
        $this->assertSame('https://example.com/post/1', $items[0]['link']);
        $this->assertSame('post-1', $items[0]['guid']);
        $this->assertSame('Короткое описание.', $items[0]['summary']);
        $this->assertGreaterThan(0, $items[0]['publishedat']);
    }

    public function test_parse_rejects_document_without_rss_items(): void {
        $this->expectException(\invalid_parameter_exception::class);
        feed_rss::parse('<?xml version="1.0"?><root><item>not-rss</item></root>');
    }

    public function test_metadata_for_posts_joins_source_and_external_item(): void {
        global $DB;
        $sourceid = (int)$DB->insert_record('local_ustar_feed_sources', (object)[
            'name' => 'vc.ru',
            'url' => feed_rss::PILOT_URL,
            'urlhash' => hash('sha256', feed_rss::PILOT_URL),
            'enabled' => 0,
            'audiencejson' => json_encode(['all']),
            'lastchecked' => 0,
            'lastsuccess' => 0,
            'lasterror' => null,
            'createdby' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $postid = (int)$DB->insert_record('local_ustar_feed_posts', (object)[
            'actoruserid' => 0,
            'publishertype' => 'external',
            'publisherid' => (string)$sourceid,
            'status' => 'published',
            'body' => 'Описание',
            'version' => 1,
            'audienceversion' => 1,
            'requestkey' => null,
            'sourcepostid' => null,
            'publishedat' => time(),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('local_ustar_feed_sourceitem', (object)[
            'sourceid' => $sourceid,
            'postid' => $postid,
            'guidhash' => hash('sha256', 'g1'),
            'externalguid' => 'g1',
            'externalurl' => 'https://vc.ru/example',
            'title' => 'Материал VC',
            'publishedat' => time(),
            'timecreated' => time(),
        ]);

        $rows = feed_rss::metadata_for_posts([$postid]);
        $this->assertArrayHasKey($postid, $rows);
        $this->assertSame('vc.ru', $rows[$postid]->sourcename);
        $this->assertSame('Материал VC', $rows[$postid]->title);
        $this->assertSame('https://vc.ru/example', $rows[$postid]->externalurl);
    }
}
