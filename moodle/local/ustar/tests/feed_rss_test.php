<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(feed_rss::class)]
final class feed_rss_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    public function test_parse_rss_extracts_expanded_text_and_media(): void {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
     xmlns:content="http://purl.org/rss/1.0/modules/content/"
     xmlns:media="http://search.yahoo.com/mrss/">
  <channel>
    <title>Example</title>
    <item>
      <title>Новый материал</title>
      <link>https://example.com/post/1</link>
      <guid>post-1</guid>
      <pubDate>Sun, 28 Sep 2026 10:00:00 +0300</pubDate>
      <description><![CDATA[<p>Короткое <strong>описание</strong>.</p>]]></description>
      <content:encoded><![CDATA[
        <p>Полный первый абзац.</p>
        <p>Полный второй абзац.</p>
        <img src="https://cdn.example.com/inside.webp">
      ]]></content:encoded>
      <enclosure url="https://cdn.example.com/cover.jpg" type="image/jpeg" length="12345" />
      <media:thumbnail url="https://cdn.example.com/thumb.png" />
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
        $this->assertStringContainsString('Полный первый абзац.', $items[0]['contenttext']);
        $this->assertStringContainsString('Полный второй абзац.', $items[0]['contenttext']);
        $this->assertContains('https://cdn.example.com/cover.jpg', $items[0]['media']);
        $this->assertContains('https://cdn.example.com/thumb.png', $items[0]['media']);
        $this->assertContains('https://cdn.example.com/inside.webp', $items[0]['media']);
        $this->assertGreaterThan(0, $items[0]['publishedat']);
    }

    public function test_parse_atom_extracts_content_and_enclosure(): void {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>Example Atom</title>
  <entry>
    <title>Atom material</title>
    <id>tag:example.com,2026:42</id>
    <link rel="alternate" href="https://example.com/atom/42" />
    <link rel="enclosure" type="image/jpeg" href="https://cdn.example.com/atom.jpg" />
    <updated>2026-09-28T10:00:00+03:00</updated>
    <summary type="html">&lt;p&gt;Atom summary&lt;/p&gt;</summary>
    <content type="html">&lt;p&gt;Atom full content&lt;/p&gt;</content>
  </entry>
</feed>
XML;
        $items = feed_rss::parse($xml);
        $this->assertCount(1, $items);
        $this->assertSame('Atom material', $items[0]['title']);
        $this->assertSame('tag:example.com,2026:42', $items[0]['guid']);
        $this->assertSame('https://example.com/atom/42', $items[0]['link']);
        $this->assertSame('Atom summary', $items[0]['summary']);
        $this->assertSame('Atom full content', $items[0]['contenttext']);
        $this->assertSame(['https://cdn.example.com/atom.jpg'], $items[0]['media']);
    }

    public function test_parse_rejects_document_without_supported_feed_items(): void {
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
            'resolverenabled' => 0,
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
            'feedcontenttext' => 'Полный текст материала',
            'contenttext' => 'Полный текст материала',
            'contenthtml' => null,
            'enrichstatus' => 'feed',
            'enrichattempts' => 0,
            'enrichnexttry' => 0,
            'enrichedat' => 0,
            'enricherror' => null,
            'resolvedurl' => null,
            'contenthash' => hash('sha256', 'Полный текст материала'),
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
