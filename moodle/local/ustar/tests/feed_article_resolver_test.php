<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(feed_article_resolver::class)]
final class feed_article_resolver_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    public function test_needs_enrichment_only_for_short_or_summary_like_content(): void {
        $summary = 'Короткое описание материала.';
        $this->assertTrue(feed_article_resolver::needs_enrichment($summary, $summary));
        $this->assertTrue(feed_article_resolver::needs_enrichment($summary, str_repeat('Короткий текст. ', 20)));
        $this->assertFalse(feed_article_resolver::needs_enrichment($summary, str_repeat('Полный материал. ', 180)));
    }

    public function test_extract_prefers_jsonld_article_body_when_it_is_richer(): void {
        $body = str_repeat(
            'Это содержательный абзац полного материала с достаточным количеством текста. ',
            20
        );
        $html = '<html><head><script type="application/ld+json">'
            . json_encode([
                '@context' => 'https://schema.org',
                '@type' => 'NewsArticle',
                'headline' => 'Материал',
                'articleBody' => $body,
                'image' => ['https://cdn.example.com/cover.jpg'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . '</script></head><body><main><p>Короткий блок.</p></main></body></html>';

        $result = feed_article_resolver::extract($html, 'https://example.com/news/1');
        $this->assertStringContainsString('Это содержательный абзац', $result['text']);
        $this->assertStringContainsString('<p>', $result['html']);
        $this->assertContains('https://cdn.example.com/cover.jpg', $result['media']);
    }

    public function test_extract_uses_semantic_article_and_removes_noise(): void {
        $html = <<<'HTML'
<!doctype html>
<html>
<body>
<nav>Навигация, которой не должно быть в статье</nav>
<article>
  <h1>Заголовок внутри статьи</h1>
  <p>Первый основной абзац материала. Он относится непосредственно к публикации.</p>
  <div class="social-share">Поделиться в социальной сети</div>
  <p>Второй основной абзац с дополнительным содержанием и деталями материала.</p>
  <blockquote>Цитата автора публикации.</blockquote>
  <img src="/media/photo.webp">
  <script>alert(1)</script>
</article>
<section class="comments">Комментарии пользователей</section>
</body>
</html>
HTML;

        $result = feed_article_resolver::extract($html, 'https://example.com/posts/42');
        $this->assertStringContainsString('Первый основной абзац', $result['text']);
        $this->assertStringContainsString('Второй основной абзац', $result['text']);
        $this->assertStringContainsString('<blockquote>', $result['html']);
        $this->assertStringNotContainsString('Навигация', $result['text']);
        $this->assertStringNotContainsString('Поделиться в социальной сети', $result['text']);
        $this->assertStringNotContainsString('Комментарии пользователей', $result['text']);
        $this->assertStringNotContainsString('<script', $result['html']);
        $this->assertContains('https://example.com/media/photo.webp', $result['media']);
    }

    public function test_queue_source_respects_terminal_states_until_explicit_retry(): void {
        global $DB;

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
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $postid = (int)$DB->insert_record('local_ustar_feed_posts', (object)[
            'actoruserid' => 0,
            'publishertype' => 'external',
            'publisherid' => (string)$sourceid,
            'status' => 'published',
            'body' => 'Короткий анонс.',
            'version' => 1,
            'audienceversion' => 1,
            'requestkey' => null,
            'sourcepostid' => null,
            'publishedat' => time(),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $itemid = (int)$DB->insert_record('local_ustar_feed_sourceitem', (object)[
            'sourceid' => $sourceid,
            'postid' => $postid,
            'guidhash' => hash('sha256', 'item-1'),
            'externalguid' => 'item-1',
            'externalurl' => 'https://example.com/posts/1',
            'title' => 'Материал',
            'feedcontenttext' => 'Короткий анонс.',
            'contenttext' => 'Короткий анонс.',
            'contenthtml' => null,
            'enrichstatus' => 'disabled',
            'enrichattempts' => 0,
            'enrichnexttry' => 0,
            'enrichedat' => 0,
            'enricherror' => null,
            'resolvedurl' => null,
            'contenthash' => null,
            'publishedat' => time(),
            'timecreated' => time(),
        ]);

        $this->assertSame(1, feed_article_resolver::queue_source($sourceid, false));
        $this->assertSame(
            'pending',
            $DB->get_field('local_ustar_feed_sourceitem', 'enrichstatus', ['id' => $itemid])
        );

        $DB->update_record('local_ustar_feed_sourceitem', (object)[
            'id' => $itemid,
            'enrichstatus' => 'failed',
            'enrichattempts' => 3,
        ]);
        $this->assertSame(0, feed_article_resolver::queue_source($sourceid, false));
        $this->assertSame(
            'failed',
            $DB->get_field('local_ustar_feed_sourceitem', 'enrichstatus', ['id' => $itemid])
        );

        $this->assertSame(1, feed_article_resolver::queue_source($sourceid, true));
        $record = $DB->get_record('local_ustar_feed_sourceitem', ['id' => $itemid]);
        $this->assertSame('pending', $record->enrichstatus);
        $this->assertSame(0, (int)$record->enrichattempts);
    }
}
