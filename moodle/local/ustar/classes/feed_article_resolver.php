<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Bounded HTML enrichment for external feed items.
 *
 * RSS/Atom remains the source of truth for discovery. This resolver only
 * expands already imported items whose feeds do not provide useful full text.
 * It never executes JavaScript and never changes normal feed ACL/actions.
 */
final class feed_article_resolver {
    private const MAX_HTML_BYTES = 5242880;      // 5 MiB.
    private const MAX_ITEMS_PER_RUN = 5;
    private const MAX_ATTEMPTS = 3;
    private const MIN_FEED_FULL_CHARS = 1800;
    private const MIN_EXTRACTED_CHARS = 500;
    private const MAX_CONTENT_CHARS = 60000;
    private const HOST_DELAY_US = 750000;

    /** A reasonably complete feed body should not trigger page scraping. */
    public static function needs_enrichment(string $summary, string $content): bool {
        $summary = trim($summary);
        $content = trim($content);
        if ($content === '') {
            return true;
        }
        $contentlen = \core_text::strlen($content);
        $summarylen = \core_text::strlen($summary);
        if ($contentlen < self::MIN_FEED_FULL_CHARS) {
            return true;
        }
        if ($summarylen > 0 && $contentlen <= max($summarylen + 350, (int)ceil($summarylen * 1.25))) {
            return true;
        }
        return false;
    }

    public static function set_source_enabled(int $actorid, int $sourceid, bool $enabled): int {
        global $DB;
        feed_access::require_manager($actorid);
        $source = $DB->get_record('local_ustar_feed_sources', ['id' => $sourceid], '*', MUST_EXIST);
        $DB->set_field('local_ustar_feed_sources', 'resolverenabled', $enabled ? 1 : 0, ['id' => $sourceid]);
        $DB->set_field('local_ustar_feed_sources', 'timemodified', time(), ['id' => $sourceid]);

        if (!$enabled) {
            return 0;
        }
        return self::queue_source($sourceid, false);
    }

    /**
     * Queue items with only short feed content. Failed/limited records are not
     * retried unless explicitly requested by a manager.
     */
    public static function queue_source(int $sourceid, bool $retryterminal = false): int {
        global $DB;
        $source = $DB->get_record('local_ustar_feed_sources', ['id' => $sourceid], '*', MUST_EXIST);
        if (empty($source->resolverenabled)) {
            return 0;
        }

        $records = $DB->get_records_sql(
            "SELECT i.*, p.body AS summary
               FROM {local_ustar_feed_sourceitem} i
               JOIN {local_ustar_feed_posts} p ON p.id = i.postid
              WHERE i.sourceid = :sourceid
           ORDER BY i.publishedat DESC, i.id DESC",
            ['sourceid' => $sourceid]
        );

        $queued = 0;
        foreach ($records as $record) {
            $feedcontent = (string)($record->feedcontenttext ?? $record->contenttext ?? '');
            if (!self::needs_enrichment((string)$record->summary, $feedcontent)) {
                if ((string)$record->enrichstatus !== 'done') {
                    $DB->update_record('local_ustar_feed_sourceitem', (object)[
                        'id' => (int)$record->id,
                        'enrichstatus' => 'feed',
                        'enrichnexttry' => 0,
                        'enricherror' => null,
                    ]);
                }
                continue;
            }

            $status = (string)($record->enrichstatus ?? 'disabled');
            if (!$retryterminal && in_array($status, ['done', 'failed', 'limited'], true)) {
                continue;
            }

            $DB->update_record('local_ustar_feed_sourceitem', (object)[
                'id' => (int)$record->id,
                'enrichstatus' => 'pending',
                'enrichattempts' => $retryterminal ? 0 : (int)($record->enrichattempts ?? 0),
                'enrichnexttry' => 0,
                'enricherror' => null,
            ]);
            $queued++;
        }
        return $queued;
    }

    /** Manager-only bounded immediate batch for acceptance/debugging. */
    public static function run_now(int $actorid, int $sourceid): array {
        feed_access::require_manager($actorid);
        self::queue_source($sourceid, true);
        return self::run_pending($sourceid, 2);
    }

    /** Cron entrypoint. At most five network page fetches are attempted. */
    public static function run_pending(?int $sourceid = null, int $limit = self::MAX_ITEMS_PER_RUN): array {
        global $DB;
        $limit = max(1, min(self::MAX_ITEMS_PER_RUN, $limit));

        $factory = \core\lock\lock_config::get_lock_factory('local_ustar');
        $lock = $factory->get_lock('feed-article-resolver', 0);
        if (!$lock) {
            return ['processed' => 0, 'done' => 0, 'failed' => 0, 'limited' => 0, 'busy' => 1];
        }

        try {
            $params = [
                'enabled' => 1,
                'resolverenabled' => 1,
                'now' => time(),
                'pending' => 'pending',
                'retry' => 'retry',
            ];
            $sourcefilter = '';
            if ($sourceid !== null) {
                $sourcefilter = ' AND i.sourceid = :sourceid';
                $params['sourceid'] = $sourceid;
            }

            $records = array_values($DB->get_records_sql(
                "SELECT i.*, s.url AS sourceurl
                   FROM {local_ustar_feed_sourceitem} i
                   JOIN {local_ustar_feed_sources} s ON s.id = i.sourceid
                  WHERE s.enabled = :enabled
                    AND s.resolverenabled = :resolverenabled
                    AND i.enrichstatus IN (:pending, :retry)
                    AND i.enrichnexttry <= :now
                    {$sourcefilter}
               ORDER BY i.publishedat DESC, i.id DESC",
                $params,
                0,
                $limit
            ));

            $stats = ['processed' => 0, 'done' => 0, 'failed' => 0, 'limited' => 0, 'busy' => 0];
            $lasthost = '';
            foreach ($records as $record) {
                $host = strtolower((string)parse_url((string)$record->externalurl, PHP_URL_HOST));
                if ($lasthost !== '' && $host !== '' && $host === $lasthost) {
                    usleep(self::HOST_DELAY_US);
                }
                $lasthost = $host;

                $stats['processed']++;
                $state = self::resolve_record($record);
                if (isset($stats[$state])) {
                    $stats[$state]++;
                }
            }
            return $stats;
        } finally {
            $lock->release();
        }
    }

    private static function backoff_seconds(int $attempts): int {
        return match ($attempts) {
            1 => 900,   // 15 min.
            2 => 3600,  // 1 hour.
            default => 21600, // 6 hours.
        };
    }

    private static function same_site_host(string $a, string $b): bool {
        $a = strtolower(rtrim($a, '.'));
        $b = strtolower(rtrim($b, '.'));
        if ($a === '' || $b === '') {
            return false;
        }
        return $a === $b || str_ends_with($a, '.' . $b) || str_ends_with($b, '.' . $a);
    }

    private static function resolve_record(\stdClass $record): string {
        global $DB;
        $attempts = (int)$record->enrichattempts + 1;
        try {
            $articleurl = feed_rss::validate_url((string)$record->externalurl);
            $response = feed_rss::fetch_external(
                $articleurl,
                self::MAX_HTML_BYTES,
                'text/html,application/xhtml+xml;q=0.9,*/*;q=0.1',
                12
            );

            $originhost = (string)parse_url($articleurl, PHP_URL_HOST);
            $finalhost = (string)parse_url((string)$response['url'], PHP_URL_HOST);
            if (!self::same_site_host($originhost, $finalhost)) {
                throw new \moodle_exception('Страница перенаправила материал на другой сайт.');
            }

            $contenttype = strtolower((string)($response['info']['content_type'] ?? ''));
            if ($contenttype !== '' && !str_contains($contenttype, 'text/html')
                    && !str_contains($contenttype, 'application/xhtml+xml')) {
                throw new \moodle_exception('Ссылка материала вернула не HTML.');
            }

            $result = self::extract((string)$response['body'], (string)$response['url']);
            $current = trim((string)($record->feedcontenttext ?? $record->contenttext ?? ''));
            $resolved = trim((string)$result['text']);

            if ($resolved === '' || \core_text::strlen($resolved) < self::MIN_EXTRACTED_CHARS
                    || \core_text::strlen($resolved) <= \core_text::strlen($current) + 250) {
                $DB->update_record('local_ustar_feed_sourceitem', (object)[
                    'id' => (int)$record->id,
                    'enrichstatus' => 'limited',
                    'enrichattempts' => $attempts,
                    'enrichnexttry' => 0,
                    'enrichedat' => time(),
                    'enricherror' => 'Полный текст не найден или не длиннее содержимого RSS.',
                    'resolvedurl' => (string)$response['url'],
                ]);
                return 'limited';
            }

            $text = \core_text::substr($resolved, 0, self::MAX_CONTENT_CHARS);
            $html = (string)$result['html'];
            $hash = hash('sha256', $text);

            $DB->update_record('local_ustar_feed_sourceitem', (object)[
                'id' => (int)$record->id,
                'contenttext' => $text,
                'contenthtml' => $html,
                'enrichstatus' => 'done',
                'enrichattempts' => $attempts,
                'enrichnexttry' => 0,
                'enrichedat' => time(),
                'enricherror' => null,
                'resolvedurl' => (string)$response['url'],
                'contenthash' => $hash,
            ]);

            feed_rss::sync_external_media((int)$record->postid, $result['media'] ?? []);
            return 'done';
        } catch (\Throwable $e) {
            $terminal = $attempts >= self::MAX_ATTEMPTS;
            $DB->update_record('local_ustar_feed_sourceitem', (object)[
                'id' => (int)$record->id,
                'enrichstatus' => $terminal ? 'failed' : 'retry',
                'enrichattempts' => $attempts,
                'enrichnexttry' => $terminal ? 0 : time() + self::backoff_seconds($attempts),
                'enricherror' => \core_text::substr($e->getMessage(), 0, 1000),
            ]);
            return 'failed';
        }
    }

    private static function normalise_text(string $text): string {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = preg_replace('/ *\n */u', "\n", (string)$text);
        $text = preg_replace('/\n{3,}/u', "\n\n", (string)$text);
        return trim((string)$text);
    }

    private static function jsonld_articles(mixed $value): array {
        $out = [];
        if (!is_array($value)) {
            return $out;
        }
        $type = $value['@type'] ?? null;
        $types = is_array($type) ? $type : [$type];
        foreach ($types as $candidate) {
            if (in_array((string)$candidate, ['Article', 'NewsArticle', 'BlogPosting', 'Report'], true)) {
                $out[] = $value;
                break;
            }
        }
        foreach ($value as $child) {
            if (is_array($child)) {
                $out = array_merge($out, self::jsonld_articles($child));
            }
        }
        return $out;
    }

    private static function jsonld_images(mixed $value): array {
        $out = [];
        if (is_string($value)) {
            return [$value];
        }
        if (!is_array($value)) {
            return [];
        }
        foreach (['url', 'contentUrl'] as $key) {
            if (!empty($value[$key]) && is_string($value[$key])) {
                $out[] = $value[$key];
            }
        }
        foreach ($value as $child) {
            if (is_array($child)) {
                $out = array_merge($out, self::jsonld_images($child));
            } else if (is_string($child) && preg_match('~^https://~i', $child)) {
                $out[] = $child;
            }
        }
        return $out;
    }

    private static function remove_noise(\DOMXPath $xpath): void {
        $queries = [
            '//script|//style|//nav|//footer|//form|//iframe|//object|//embed|//button|//input'
                . '|//select|//textarea|//svg|//canvas|//template|//noscript|//aside',
        ];
        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
            if (!$nodes) {
                continue;
            }
            foreach (iterator_to_array($nodes) as $node) {
                if ($node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }

        $tokens = [
            'comment', 'share', 'social', 'advert', 'advertisement', 'banner', 'promo',
            'recommend', 'related', 'subscribe', 'sidebar', 'paywall', 'navigation',
        ];
        $nodes = $xpath->query('//div|//section');
        if (!$nodes) {
            return;
        }
        foreach (iterator_to_array($nodes) as $node) {
            if (!$node instanceof \DOMElement || !$node->parentNode) {
                continue;
            }
            $marker = strtolower($node->getAttribute('class') . ' ' . $node->getAttribute('id'));
            foreach ($tokens as $token) {
                if ($marker !== '' && str_contains($marker, $token)) {
                    $node->parentNode->removeChild($node);
                    break;
                }
            }
        }
    }

    private static function candidate_score(\DOMElement $node, int $bonus): int {
        $text = self::normalise_text((string)$node->textContent);
        $length = \core_text::strlen($text);
        if ($length < 250) {
            return -1;
        }

        $links = $node->getElementsByTagName('a');
        $linkchars = 0;
        foreach ($links as $link) {
            $linkchars += \core_text::strlen(self::normalise_text((string)$link->textContent));
        }
        $paragraphs = $node->getElementsByTagName('p')->length;
        $linkpenalty = min($length, $linkchars * 2);
        return $bonus + min($length, self::MAX_CONTENT_CHARS) + ($paragraphs * 90) - $linkpenalty;
    }

    private static function best_dom_candidate(\DOMXPath $xpath): ?\DOMElement {
        $candidates = [];
        $seen = [];

        $register = static function(?\DOMNodeList $nodes, int $bonus) use (&$candidates, &$seen): void {
            if (!$nodes) {
                return;
            }
            foreach ($nodes as $node) {
                if (!$node instanceof \DOMElement) {
                    continue;
                }
                $id = spl_object_id($node);
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $candidates[] = [$node, $bonus];
            }
        };

        $register($xpath->query('//*[@itemprop="articleBody"]'), 7000);
        $register($xpath->query('//article'), 6000);
        $register($xpath->query('//main'), 1500);

        $generic = $xpath->query('//div|//section');
        if ($generic) {
            foreach ($generic as $node) {
                if (!$node instanceof \DOMElement) {
                    continue;
                }
                $marker = strtolower($node->getAttribute('class') . ' ' . $node->getAttribute('id'));
                if ($marker === '') {
                    continue;
                }
                foreach (['article', 'story', 'post-content', 'entry-content', 'article-content',
                        'article-body', 'content-body', 'text-content'] as $token) {
                    if (str_contains($marker, $token)) {
                        $id = spl_object_id($node);
                        if (!isset($seen[$id])) {
                            $seen[$id] = true;
                            $candidates[] = [$node, 4500];
                        }
                        break;
                    }
                }
            }
        }

        $best = null;
        $bestscore = -1;
        foreach ($candidates as [$node, $bonus]) {
            $score = self::candidate_score($node, $bonus);
            if ($score > $bestscore) {
                $bestscore = $score;
                $best = $node;
            }
        }
        return $best;
    }

    private static function safe_href(string $base, string $href): string {
        $url = feed_rss::resolve_url($base, $href);
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) ? $url : '';
    }

    private static function node_html(\DOMNode $node, string $base, array &$media): string {
        if ($node instanceof \DOMText) {
            return s($node->nodeValue ?? '');
        }
        if (!$node instanceof \DOMElement) {
            $html = '';
            foreach ($node->childNodes as $child) {
                $html .= self::node_html($child, $base, $media);
            }
            return $html;
        }

        $tag = strtolower($node->tagName);
        if ($tag === 'img') {
            $src = trim($node->getAttribute('src'));
            if ($src === '') {
                $src = trim($node->getAttribute('data-src'));
            }
            if ($src !== '') {
                $resolved = self::safe_href($base, $src);
                if ($resolved !== '') {
                    $media[] = $resolved;
                }
            }
            return '';
        }

        $inner = '';
        foreach ($node->childNodes as $child) {
            $inner .= self::node_html($child, $base, $media);
        }

        if ($tag === 'a') {
            // Keep readable text but not arbitrary third-party navigation inside the article body.
            return $inner;
        }
        if ($tag === 'br') {
            return '<br>';
        }
        if ($tag === 'hr') {
            return '<hr>';
        }

        $map = [
            'p' => 'p', 'h1' => 'h2', 'h2' => 'h2', 'h3' => 'h3', 'h4' => 'h4',
            'h5' => 'h5', 'h6' => 'h6', 'ul' => 'ul', 'ol' => 'ol', 'li' => 'li',
            'blockquote' => 'blockquote', 'strong' => 'strong', 'b' => 'strong',
            'em' => 'em', 'i' => 'em', 'code' => 'code', 'pre' => 'pre',
            'table' => 'table', 'thead' => 'thead', 'tbody' => 'tbody', 'tfoot' => 'tfoot',
            'tr' => 'tr', 'th' => 'th', 'td' => 'td', 'caption' => 'caption',
            'figcaption' => 'p',
        ];
        if (isset($map[$tag])) {
            $safe = $map[$tag];
            return '<' . $safe . '>' . $inner . '</' . $safe . '>';
        }
        return $inner;
    }

    private static function plain_from_safe_html(string $html): string {
        $text = preg_replace(
            '~<(?:br\s*/?|/p|/li|/h[1-6]|/blockquote|/tr|/pre)\s*>~iu',
            "\n",
            $html
        );
        return self::normalise_text(strip_tags((string)$text));
    }

    /**
     * Deterministic extractor used by tests and the network worker.
     * Returns only locally safe HTML plus external image URLs for File API caching.
     */
    public static function extract(string $html, string $url): array {
        $old = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8">' . $html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT
            );
            if (!$loaded) {
                throw new \invalid_parameter_exception('HTML материала не удалось разобрать.');
            }
            $xpath = new \DOMXPath($document);

            $jsontext = '';
            $jsonmedia = [];
            $scripts = $xpath->query('//script[contains(translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"ld+json")]');
            if ($scripts) {
                foreach ($scripts as $script) {
                    $decoded = json_decode(trim((string)$script->textContent), true);
                    if (!is_array($decoded)) {
                        continue;
                    }
                    foreach (self::jsonld_articles($decoded) as $article) {
                        $body = self::normalise_text((string)($article['articleBody'] ?? ''));
                        if (\core_text::strlen($body) > \core_text::strlen($jsontext)) {
                            $jsontext = $body;
                            $jsonmedia = self::jsonld_images($article['image'] ?? []);
                        }
                    }
                }
            }

            self::remove_noise($xpath);
            $candidate = self::best_dom_candidate($xpath);
            $domhtml = '';
            $dommedia = [];
            $domtext = '';
            if ($candidate) {
                $domhtml = self::node_html($candidate, $url, $dommedia);
                $domtext = self::plain_from_safe_html($domhtml);
            }

            if (\core_text::strlen($jsontext) > \core_text::strlen($domtext) + 250) {
                $paragraphs = preg_split('/\n{2,}/u', $jsontext, -1, PREG_SPLIT_NO_EMPTY);
                if (!$paragraphs) {
                    $paragraphs = [$jsontext];
                }
                $safehtml = '';
                foreach ($paragraphs as $paragraph) {
                    $safehtml .= '<p>' . s(trim((string)$paragraph)) . '</p>';
                }
                $text = $jsontext;
                $media = $jsonmedia;
            } else {
                $safehtml = $domhtml;
                $text = $domtext;
                $media = $dommedia;
            }

            $media = array_values(array_unique(array_filter(array_map(
                static fn(string $candidate): string => self::safe_href($url, $candidate),
                $media
            ))));
            $media = array_slice($media, 0, 8);

            return [
                'text' => \core_text::substr($text, 0, self::MAX_CONTENT_CHARS),
                'html' => clean_text($safehtml, FORMAT_HTML),
                'media' => $media,
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }
    }

    public static function source_stats(int $sourceid): array {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT enrichstatus AS id, COUNT(id) AS total
               FROM {local_ustar_feed_sourceitem}
              WHERE sourceid = :sourceid
           GROUP BY enrichstatus",
            ['sourceid' => $sourceid]
        );
        $out = ['pending' => 0, 'retry' => 0, 'done' => 0, 'failed' => 0, 'limited' => 0, 'feed' => 0];
        foreach ($rows as $row) {
            $out[(string)$row->id] = (int)$row->total;
        }
        return $out;
    }
}
