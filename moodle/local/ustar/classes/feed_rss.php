<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * External RSS/Atom source registry and importer for the USTAR feed.
 *
 * External posts are system-authored records. Remote media is copied into the
 * Moodle File API so feed ACL, personal-library copying and retention rules
 * remain local to USTAR.
 */
final class feed_rss {
    public const PILOT_URL = 'https://vc.ru/rss/all';
    private const FIRST_IMPORT_LIMIT = 10;
    private const REGULAR_IMPORT_LIMIT = 30;
    private const MAX_RESPONSE_BYTES = 2097152;
    private const MAX_MEDIA_BYTES = 8388608;
    private const MAX_MEDIA_PER_ITEM = 4;
    private const MAX_REDIRECTS = 3;

    public static function validate_url(string $url): string {
        $url = trim($url);
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
                || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new \invalid_parameter_exception(
                'RSS-адрес должен быть абсолютным HTTPS URL без логина и пароля.'
            );
        }
        if (isset($parts['port']) && (int)$parts['port'] !== 443) {
            throw new \invalid_parameter_exception('Для RSS разрешён только стандартный HTTPS-порт 443.');
        }

        $host = strtolower(rtrim((string)$parts['host'], '.'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new \invalid_parameter_exception('Локальные RSS-адреса запрещены.');
        }

        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            foreach (is_array($records) ? $records : [] as $record) {
                if (!empty($record['ip'])) {
                    $ips[] = (string)$record['ip'];
                }
                if (!empty($record['ipv6'])) {
                    $ips[] = (string)$record['ipv6'];
                }
            }
        }
        if (!$ips) {
            throw new \invalid_parameter_exception('Не удалось разрешить адрес RSS-источника.');
        }
        foreach (array_unique($ips) as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \invalid_parameter_exception('RSS-источник не может указывать на внутреннюю сеть.');
            }
        }
        return $url;
    }

    private static function absolute_url(string $base, string $candidate): string {
        $candidate = trim(html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($candidate === '') {
            return '';
        }
        if (preg_match('~^https://~i', $candidate)) {
            return $candidate;
        }
        $baseparts = parse_url($base);
        if (!is_array($baseparts) || empty($baseparts['scheme']) || empty($baseparts['host'])) {
            return '';
        }
        if (str_starts_with($candidate, '//')) {
            return $baseparts['scheme'] . ':' . $candidate;
        }
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $candidate)) {
            return '';
        }

        $origin = $baseparts['scheme'] . '://' . $baseparts['host'];
        if (!empty($baseparts['port'])) {
            $origin .= ':' . (int)$baseparts['port'];
        }
        if (str_starts_with($candidate, '/')) {
            return $origin . $candidate;
        }

        $path = (string)($baseparts['path'] ?? '/');
        $directory = preg_replace('~/[^/]*$~', '/', $path);
        return $origin . ($directory ?: '/') . $candidate;
    }

    /**
     * Fetch one trusted HTTPS resource. Redirects are followed manually so
     * every hop receives the same public-address validation.
     */
    private static function fetch(string $url, int $maxbytes, string $accept): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $current = self::validate_url($url);
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $curl = new \curl();
            $curl->setHeader([
                'Accept: ' . $accept,
                'User-Agent: USTAR-Academy-RSS/2.0',
            ]);
            $body = $curl->get($current, [], [
                'CURLOPT_CONNECTTIMEOUT' => 5,
                'CURLOPT_TIMEOUT' => 15,
                'CURLOPT_FOLLOWLOCATION' => false,
                'CURLOPT_MAXREDIRS' => 0,
            ]);
            $info = $curl->get_info();
            $status = (int)($info['http_code'] ?? 0);

            if ($status >= 300 && $status < 400) {
                $redirect = trim((string)($info['redirect_url'] ?? ''));
                if ($redirect === '' || $hop >= self::MAX_REDIRECTS) {
                    throw new \moodle_exception('Внешний источник вернул неподдерживаемый redirect.');
                }
                $current = self::validate_url(self::absolute_url($current, $redirect));
                continue;
            }

            if ($status < 200 || $status >= 300) {
                throw new \moodle_exception('Внешний источник вернул HTTP ' . $status . '.');
            }
            if (!is_string($body) || $body === '' || strlen($body) > $maxbytes) {
                throw new \moodle_exception('Ответ внешнего источника пуст или превышает допустимый размер.');
            }
            return ['body' => $body, 'url' => $current, 'info' => $info];
        }
        throw new \moodle_exception('Превышено число перенаправлений внешнего источника.');
    }

    public static function create_source(int $actorid, string $name, string $url): int {
        global $DB;
        feed_access::require_manager($actorid);
        $name = trim($name);
        if ($name === '' || \core_text::strlen($name) > 128) {
            throw new \invalid_parameter_exception('Название RSS-источника должно содержать от 1 до 128 символов.');
        }
        $url = self::validate_url($url);
        $urlhash = hash('sha256', $url);
        $existing = (int)$DB->get_field('local_ustar_feed_sources', 'id', ['urlhash' => $urlhash]);
        if ($existing) {
            return $existing;
        }
        $now = time();
        return (int)$DB->insert_record('local_ustar_feed_sources', (object)[
            'name' => $name,
            'url' => $url,
            'urlhash' => $urlhash,
            'enabled' => 0,
            'audiencejson' => json_encode(['all']),
            'lastchecked' => 0,
            'lastsuccess' => 0,
            'lasterror' => null,
            'createdby' => $actorid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    public static function set_enabled(int $actorid, int $sourceid, bool $enabled): void {
        global $DB;
        feed_access::require_manager($actorid);
        $source = $DB->get_record('local_ustar_feed_sources', ['id' => $sourceid], '*', MUST_EXIST);
        if ($enabled) {
            self::validate_url((string)$source->url);
        }
        $DB->set_field('local_ustar_feed_sources', 'enabled', $enabled ? 1 : 0, ['id' => $sourceid]);
        $DB->set_field('local_ustar_feed_sources', 'timemodified', time(), ['id' => $sourceid]);
    }

    public static function import_now(int $actorid, int $sourceid): array {
        feed_access::require_manager($actorid);
        return self::import_source($sourceid);
    }

    public static function import_enabled(): void {
        global $DB;
        $sources = $DB->get_records('local_ustar_feed_sources', ['enabled' => 1], 'id ASC');
        foreach ($sources as $source) {
            try {
                $result = self::import_source((int)$source->id);
                mtrace('USTAR RSS #' . (int)$source->id . ': imported=' . (int)$result['imported']
                    . ' refreshed=' . (int)$result['refreshed']);
            } catch (\Throwable $e) {
                mtrace('USTAR RSS #' . (int)$source->id . ': failed=' . get_class($e));
            }
        }
    }

    public static function import_source(int $sourceid): array {
        global $DB;
        $source = $DB->get_record('local_ustar_feed_sources', ['id' => $sourceid], '*', MUST_EXIST);
        $url = self::validate_url((string)$source->url);
        $now = time();
        $DB->set_field('local_ustar_feed_sources', 'lastchecked', $now, ['id' => $sourceid]);

        try {
            $response = self::fetch(
                $url,
                self::MAX_RESPONSE_BYTES,
                'application/rss+xml, application/atom+xml, application/xml, text/xml;q=0.9, */*;q=0.1'
            );
            $items = self::parse((string)$response['body']);
            $first = !$DB->record_exists('local_ustar_feed_sourceitem', ['sourceid' => $sourceid]);
            $limit = $first ? self::FIRST_IMPORT_LIMIT : self::REGULAR_IMPORT_LIMIT;
            $items = array_slice($items, 0, $limit);
            $items = array_reverse($items);

            $imported = 0;
            $refreshed = 0;
            foreach ($items as $item) {
                if (self::publish_item($source, $item)) {
                    $imported++;
                } else {
                    $refreshed++;
                }
            }

            $DB->update_record('local_ustar_feed_sources', (object)[
                'id' => $sourceid,
                'lastchecked' => $now,
                'lastsuccess' => time(),
                'lasterror' => null,
                'timemodified' => time(),
            ]);
            return ['imported' => $imported, 'refreshed' => $refreshed, 'seen' => count($items)];
        } catch (\Throwable $e) {
            $DB->update_record('local_ustar_feed_sources', (object)[
                'id' => $sourceid,
                'lastchecked' => $now,
                'lasterror' => \core_text::substr($e->getMessage(), 0, 1000),
                'timemodified' => time(),
            ]);
            throw $e;
        }
    }

    private static function text_from_html(string $html, int $limit): string {
        if ($html === '') {
            return '';
        }
        $html = preg_replace(
            '~<(?:br\s*/?|/p|/div|/li|/h[1-6]|/blockquote)\s*>~iu',
            "\n",
            $html
        );
        $text = html_entity_decode(strip_tags((string)$html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/ *\n */u', "\n", (string)$text);
        $text = preg_replace('/\n{3,}/u', "\n\n", (string)$text);
        return \core_text::substr(trim((string)$text), 0, $limit);
    }

    private static function image_urls(string $base, array $nodes, string ...$htmlblocks): array {
        $urls = [];
        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $url = trim((string)$node->getAttribute('url'));
            $type = strtolower(trim((string)$node->getAttribute('type')));
            $medium = strtolower(trim((string)$node->getAttribute('medium')));
            if ($url !== '' && ($type === '' || str_starts_with($type, 'image/') || $medium === 'image')) {
                $urls[] = self::absolute_url($base, $url);
            }
        }
        foreach ($htmlblocks as $html) {
            if ($html === '') {
                continue;
            }
            if (preg_match_all('~<img\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1~isu', $html, $matches)) {
                foreach ($matches[2] as $url) {
                    $urls[] = self::absolute_url($base, (string)$url);
                }
            }
        }
        return array_slice(array_values(array_unique(array_filter($urls))), 0, self::MAX_MEDIA_PER_ITEM);
    }

    public static function parse(string $xml): array {
        $old = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOBLANKS)) {
                throw new \invalid_parameter_exception('Источник вернул некорректный XML.');
            }
            $xpath = new \DOMXPath($document);
            $rssnodes = $xpath->query('/rss/channel/item');
            $atomnodes = $xpath->query('/*[local-name()="feed"]/*[local-name()="entry"]');
            $isrss = $rssnodes && $rssnodes->length > 0;
            $nodes = $isrss ? $rssnodes : $atomnodes;
            if (!$nodes || $nodes->length === 0) {
                throw new \invalid_parameter_exception('Поддерживаются RSS 2.0 и Atom 1.0.');
            }

            $result = [];
            foreach ($nodes as $node) {
                if ($isrss) {
                    $title = trim((string)$xpath->evaluate('string(title)', $node));
                    $link = trim((string)$xpath->evaluate('string(link)', $node));
                    $guid = trim((string)$xpath->evaluate('string(guid)', $node));
                    $date = trim((string)$xpath->evaluate('string(pubDate)', $node));
                    $description = (string)$xpath->evaluate('string(description)', $node);
                    $content = (string)$xpath->evaluate('string(*[local-name()="encoded"])', $node);
                    $medianodes = [];
                    foreach ($xpath->query('./*[local-name()="enclosure" or local-name()="content" or local-name()="thumbnail"]',
                            $node) ?: [] as $medianode) {
                        $medianodes[] = $medianode;
                    }
                } else {
                    $title = trim((string)$xpath->evaluate('string(*[local-name()="title"])', $node));
                    $link = trim((string)$xpath->evaluate(
                        'string(*[local-name()="link" and (not(@rel) or @rel="alternate")][1]/@href)',
                        $node
                    ));
                    $guid = trim((string)$xpath->evaluate('string(*[local-name()="id"])', $node));
                    $date = trim((string)$xpath->evaluate(
                        'string((*[local-name()="published"]|*[local-name()="updated"])[1])',
                        $node
                    ));
                    $description = (string)$xpath->evaluate('string(*[local-name()="summary"])', $node);
                    $content = (string)$xpath->evaluate('string(*[local-name()="content"])', $node);
                    $medianodes = [];
                    foreach ($xpath->query('./*[local-name()="link" and @rel="enclosure"]'
                            . '|./*[local-name()="content" and @url]'
                            . '|./*[local-name()="thumbnail"]', $node) ?: [] as $medianode) {
                        if ($medianode instanceof \DOMElement && !$medianode->hasAttribute('url')
                                && $medianode->hasAttribute('href')) {
                            $medianode->setAttribute('url', $medianode->getAttribute('href'));
                        }
                        $medianodes[] = $medianode;
                    }
                }

                if ($title === '' || $link === '') {
                    continue;
                }
                $scheme = strtolower((string)parse_url($link, PHP_URL_SCHEME));
                if (!in_array($scheme, ['http', 'https'], true)) {
                    continue;
                }

                $guid = $guid !== '' ? $guid : $link;
                $fulltext = self::text_from_html($content !== '' ? $content : $description, 20000);
                $summary = self::text_from_html($description !== '' ? $description : $content, 1500);
                if ($summary === '') {
                    $summary = \core_text::substr($fulltext, 0, 1500);
                }
                if ($fulltext === '') {
                    $fulltext = $summary;
                }

                $published = $date !== '' ? strtotime($date) : false;
                $result[] = [
                    'title' => \core_text::substr($title, 0, 255),
                    'link' => $link,
                    'guid' => $guid,
                    'summary' => $summary,
                    'contenttext' => $fulltext,
                    'media' => self::image_urls($link, $medianodes, $content, $description),
                    'publishedat' => $published && $published > 0 ? (int)$published : time(),
                ];
            }
            return $result;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }
    }

    private static function media_extension(int $imagetype): string {
        return match ($imagetype) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_GIF => 'gif',
            IMAGETYPE_WEBP => 'webp',
            default => '',
        };
    }

    private static function sync_media(int $postid, array $urls): void {
        $urls = array_slice(array_values(array_unique(array_filter($urls))), 0, self::MAX_MEDIA_PER_ITEM);
        if (!$urls) {
            return;
        }

        $fs = get_file_storage();
        $contextid = \context_system::instance()->id;
        foreach ($urls as $url) {
            try {
                $response = self::fetch(
                    self::validate_url((string)$url),
                    self::MAX_MEDIA_BYTES,
                    'image/avif,image/webp,image/apng,image/svg+xml,image/*;q=0.8,*/*;q=0.1'
                );
                $bytes = (string)$response['body'];
                $info = @getimagesizefromstring($bytes);
                $imagetype = is_array($info) ? (int)($info[2] ?? 0) : 0;
                $extension = self::media_extension($imagetype);
                if ($extension === '') {
                    continue;
                }
                $filename = 'rss-' . substr(hash('sha256', (string)$url), 0, 20) . '.' . $extension;
                if ($fs->get_file($contextid, 'local_ustar', feed_files::AREA,
                        $postid, '/v1/', $filename)) {
                    continue;
                }
                $fs->create_file_from_string([
                    'contextid' => $contextid,
                    'component' => 'local_ustar',
                    'filearea' => feed_files::AREA,
                    'itemid' => $postid,
                    'filepath' => '/v1/',
                    'filename' => $filename,
                ], $bytes);
            } catch (\Throwable $e) {
                // A broken remote image must never abort the article import.
                debugging('USTAR RSS media skipped: ' . get_class($e), DEBUG_DEVELOPER);
            }
        }
    }

    private static function refresh_existing(\stdClass $record, array $item): void {
        global $DB;
        $post = $DB->get_record('local_ustar_feed_posts', ['id' => (int)$record->postid]);
        if (!$post || $post->publishertype !== 'external') {
            return;
        }

        $body = trim((string)$item['summary']);
        if ($body === '') {
            $body = (string)$item['title'];
        }
        $changed = false;
        $newbody = \core_text::substr($body, 0, 5000);
        if ((string)$post->body !== $newbody) {
            $post->body = $newbody;
            $changed = true;
        }
        if ($changed) {
            $post->timemodified = time();
            $post->version++;
            $DB->update_record('local_ustar_feed_posts', $post);
        }

        $DB->update_record('local_ustar_feed_sourceitem', (object)[
            'id' => (int)$record->id,
            'externalurl' => (string)$item['link'],
            'title' => (string)$item['title'],
            'contenttext' => (string)$item['contenttext'],
            'publishedat' => (int)$item['publishedat'],
        ]);
        self::sync_media((int)$record->postid, $item['media'] ?? []);
    }

    private static function publish_item(\stdClass $source, array $item): bool {
        global $DB;
        $guidhash = hash('sha256', (string)$item['guid']);
        $existing = $DB->get_record('local_ustar_feed_sourceitem', [
            'sourceid' => (int)$source->id,
            'guidhash' => $guidhash,
        ]);
        if ($existing) {
            self::refresh_existing($existing, $item);
            return false;
        }

        $now = time();
        $transaction = $DB->start_delegated_transaction();
        $existing = $DB->get_record('local_ustar_feed_sourceitem', [
            'sourceid' => (int)$source->id,
            'guidhash' => $guidhash,
        ]);
        if ($existing) {
            $transaction->allow_commit();
            self::refresh_existing($existing, $item);
            return false;
        }

        $body = trim((string)$item['summary']);
        if ($body === '') {
            $body = (string)$item['title'];
        }
        $postid = (int)$DB->insert_record('local_ustar_feed_posts', (object)[
            'actoruserid' => 0,
            'publishertype' => 'external',
            'publisherid' => (string)$source->id,
            'status' => 'published',
            'body' => \core_text::substr($body, 0, 5000),
            'version' => 1,
            'audienceversion' => 1,
            'requestkey' => null,
            'sourcepostid' => null,
            'publishedat' => (int)$item['publishedat'],
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->insert_record('local_ustar_feed_audience', (object)[
            'postid' => $postid,
            'scopekind' => 'all',
            'scopeid' => 'all',
        ]);
        $DB->insert_record('local_ustar_feed_sourceitem', (object)[
            'sourceid' => (int)$source->id,
            'postid' => $postid,
            'guidhash' => $guidhash,
            'externalguid' => (string)$item['guid'],
            'externalurl' => (string)$item['link'],
            'title' => (string)$item['title'],
            'contenttext' => (string)$item['contenttext'],
            'publishedat' => (int)$item['publishedat'],
            'timecreated' => $now,
        ]);
        $transaction->allow_commit();

        self::sync_media($postid, $item['media'] ?? []);
        return true;
    }

    public static function metadata_for_posts(array $postids): array {
        global $DB;
        $postids = array_values(array_unique(array_filter(array_map('intval', $postids))));
        if (!$postids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($postids, SQL_PARAMS_NAMED, 'rsspost');
        return $DB->get_records_sql(
            "SELECT i.postid AS id, i.externalurl, i.title, i.contenttext, i.publishedat,
                    s.id AS sourceid, s.name AS sourcename, s.url AS sourceurl
               FROM {local_ustar_feed_sourceitem} i
               JOIN {local_ustar_feed_sources} s ON s.id = i.sourceid
              WHERE i.postid {$insql}",
            $params
        );
    }
}
