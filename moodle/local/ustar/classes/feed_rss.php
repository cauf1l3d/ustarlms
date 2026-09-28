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

    private static function validated_target(string $url): array {
        $url = trim($url);
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
                || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new \invalid_parameter_exception(
                'Внешний адрес должен быть абсолютным HTTPS URL без логина и пароля.'
            );
        }
        if (isset($parts['port']) && (int)$parts['port'] !== 443) {
            throw new \invalid_parameter_exception('Для внешних источников разрешён только HTTPS-порт 443.');
        }

        $host = strtolower(rtrim((string)$parts['host'], '.'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new \invalid_parameter_exception('Локальные внешние адреса запрещены.');
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
        $ips = array_values(array_unique($ips));
        if (!$ips) {
            throw new \invalid_parameter_exception('Не удалось разрешить адрес внешнего источника.');
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \invalid_parameter_exception('Внешний источник не может указывать на внутреннюю сеть.');
            }
        }

        usort($ips, static function(string $a, string $b): int {
            $av4 = filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 0 : 1;
            $bv4 = filter_var($b, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 0 : 1;
            return $av4 <=> $bv4 ?: strcmp($a, $b);
        });
        return ['url' => $url, 'host' => $host, 'ips' => $ips];
    }

    public static function validate_url(string $url): string {
        return (string)self::validated_target($url)['url'];
    }

    public static function resolve_url(string $base, string $candidate): string {
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
    public static function fetch_external(string $url, int $maxbytes, string $accept,
            int $timeout = 15): array {
        $maxbytes = max(1024, $maxbytes);
        $timeout = max(5, min(20, $timeout));
        $current = self::validate_url($url);

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = self::validated_target($current);
            $host = (string)$target['host'];
            $ip = (string)$target['ips'][0];

            $ch = curl_init();
            if ($ch === false) {
                throw new \moodle_exception('Не удалось инициализировать HTTPS-клиент.');
            }

            $buffer = '';
            $oversized = false;
            $location = '';
            try {
                $options = [
                    CURLOPT_URL => $current,
                    CURLOPT_RETURNTRANSFER => false,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_USERAGENT => 'USTAR-Academy-RSS/3.0',
                    CURLOPT_HTTPHEADER => ['Accept: ' . $accept],
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_HEADERFUNCTION => static function($curl, string $header) use (&$location): int {
                        $length = strlen($header);
                        if (stripos($header, 'Location:') === 0) {
                            $location = trim(substr($header, 9));
                        }
                        return $length;
                    },
                    CURLOPT_WRITEFUNCTION => static function($curl, string $chunk)
                            use (&$buffer, &$oversized, $maxbytes): int {
                        if (strlen($buffer) + strlen($chunk) > $maxbytes) {
                            $oversized = true;
                            return 0;
                        }
                        $buffer .= $chunk;
                        return strlen($chunk);
                    },
                ];
                if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
                    $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
                }
                if (defined('CURLOPT_RESOLVE') && !filter_var($host, FILTER_VALIDATE_IP)) {
                    $resolvedip = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
                    $options[CURLOPT_RESOLVE] = [$host . ':443:' . $resolvedip];
                }
                curl_setopt_array($ch, $options);

                $ok = curl_exec($ch);
                $errno = curl_errno($ch);
                $error = curl_error($ch);
                $info = curl_getinfo($ch);
            } finally {
                curl_close($ch);
            }

            if ($oversized) {
                throw new \moodle_exception('Ответ внешнего источника превышает допустимый размер.');
            }
            if ($ok === false) {
                throw new \moodle_exception(
                    'Ошибка HTTPS внешнего источника (' . $errno . '): '
                    . \core_text::substr($error, 0, 240)
                );
            }

            $status = (int)($info['http_code'] ?? 0);
            if ($status >= 300 && $status < 400) {
                if ($location === '' || $hop >= self::MAX_REDIRECTS) {
                    throw new \moodle_exception('Внешний источник вернул неподдерживаемый redirect.');
                }
                $next = self::resolve_url($current, $location);
                if ($next === '') {
                    throw new \moodle_exception('Внешний источник вернул некорректный redirect.');
                }
                $current = self::validate_url($next);
                continue;
            }

            if ($status < 200 || $status >= 300) {
                throw new \moodle_exception('Внешний источник вернул HTTP ' . $status . '.');
            }
            if ($buffer === '') {
                throw new \moodle_exception('Ответ внешнего источника пуст.');
            }

            return [
                'body' => $buffer,
                'url' => $current,
                'info' => [
                    'http_code' => $status,
                    'content_type' => (string)($info['content_type'] ?? ''),
                    'primary_ip' => (string)($info['primary_ip'] ?? $ip),
                ],
            ];
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
            'resolverenabled' => 0,
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
            $response = self::fetch_external(
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
                $urls[] = self::resolve_url($base, $url);
            }
        }
        foreach ($htmlblocks as $html) {
            if ($html === '') {
                continue;
            }
            if (preg_match_all('~<img\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1~isu', $html, $matches)) {
                foreach ($matches[2] as $url) {
                    $urls[] = self::resolve_url($base, (string)$url);
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

    public static function sync_external_media(int $postid, array $urls): void {
        $urls = array_slice(array_values(array_unique(array_filter($urls))), 0, self::MAX_MEDIA_PER_ITEM);
        if (!$urls) {
            return;
        }

        $fs = get_file_storage();
        $contextid = \context_system::instance()->id;
        $existingfiles = $fs->get_area_files(
            $contextid,
            'local_ustar',
            feed_files::AREA,
            $postid,
            'id ASC',
            false
        );
        $stored = count(array_filter($existingfiles, static fn($file): bool =>
            str_starts_with((string)$file->get_filename(), 'rss-')));
        foreach ($urls as $url) {
            if ($stored >= self::MAX_MEDIA_PER_ITEM) {
                break;
            }
            try {
                $response = self::fetch_external(
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
                $stored++;
            } catch (\Throwable $e) {
                // A broken remote image must never abort the article import.
                debugging('USTAR RSS media skipped: ' . get_class($e), DEBUG_DEVELOPER);
            }
        }
    }

    private static function refresh_existing(\stdClass $source, \stdClass $record, array $item): void {
        global $DB;
        $post = $DB->get_record('local_ustar_feed_posts', ['id' => (int)$record->postid]);
        if (!$post || $post->publishertype !== 'external') {
            return;
        }

        $body = trim((string)$item['summary']);
        if ($body === '') {
            $body = (string)$item['title'];
        }
        $newbody = \core_text::substr($body, 0, 5000);
        if ((string)$post->body !== $newbody) {
            $post->body = $newbody;
            $post->timemodified = time();
            $post->version++;
            $DB->update_record('local_ustar_feed_posts', $post);
        }

        $feedcontent = (string)$item['contenttext'];
        $needs = feed_article_resolver::needs_enrichment($newbody, $feedcontent);
        $status = (string)($record->enrichstatus ?? 'disabled');
        $update = (object)[
            'id' => (int)$record->id,
            'externalurl' => (string)$item['link'],
            'title' => (string)$item['title'],
            'feedcontenttext' => $feedcontent,
            'publishedat' => (int)$item['publishedat'],
        ];

        if (!$needs) {
            $update->contenttext = $feedcontent;
            $update->contenthtml = null;
            $update->enrichstatus = 'feed';
            $update->enrichattempts = 0;
            $update->enrichnexttry = 0;
            $update->enrichedat = 0;
            $update->enricherror = null;
            $update->resolvedurl = null;
            $update->contenthash = hash('sha256', $feedcontent);
        } else if (!empty($source->resolverenabled)) {
            if ($status === 'done') {
                // Keep already resolved full text; only refresh feed metadata.
            } else {
                $update->contenttext = $feedcontent;
                $update->contenthtml = null;
                if (!in_array($status, ['failed', 'limited'], true)) {
                    $update->enrichstatus = 'pending';
                    $update->enrichnexttry = 0;
                    $update->enricherror = null;
                }
            }
        } else if ($status !== 'done') {
            $update->contenttext = $feedcontent;
            $update->contenthtml = null;
            $update->enrichstatus = 'disabled';
            $update->enrichnexttry = 0;
        }

        $DB->update_record('local_ustar_feed_sourceitem', $update);
        self::sync_external_media((int)$record->postid, $item['media'] ?? []);
    }

    private static function publish_item(\stdClass $source, array $item): bool {
        global $DB;
        $guidhash = hash('sha256', (string)$item['guid']);
        $existing = $DB->get_record('local_ustar_feed_sourceitem', [
            'sourceid' => (int)$source->id,
            'guidhash' => $guidhash,
        ]);
        if ($existing) {
            self::refresh_existing($source, $existing, $item);
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
            self::refresh_existing($source, $existing, $item);
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
            'feedcontenttext' => (string)$item['contenttext'],
            'contenttext' => (string)$item['contenttext'],
            'contenthtml' => null,
            'enrichstatus' => (!empty($source->resolverenabled)
                && feed_article_resolver::needs_enrichment($body, (string)$item['contenttext']))
                ? 'pending'
                : (!empty($source->resolverenabled) ? 'feed' : 'disabled'),
            'enrichattempts' => 0,
            'enrichnexttry' => 0,
            'enrichedat' => 0,
            'enricherror' => null,
            'resolvedurl' => null,
            'contenthash' => hash('sha256', (string)$item['contenttext']),
            'publishedat' => (int)$item['publishedat'],
            'timecreated' => $now,
        ]);
        $transaction->allow_commit();

        self::sync_external_media($postid, $item['media'] ?? []);
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
            "SELECT i.postid AS id, i.externalurl, i.title, i.contenttext, i.contenthtml,
                    i.enrichstatus, i.enrichedat, i.publishedat,
                    s.id AS sourceid, s.name AS sourcename, s.url AS sourceurl,
                    s.resolverenabled
               FROM {local_ustar_feed_sourceitem} i
               JOIN {local_ustar_feed_sources} s ON s.id = i.sourceid
              WHERE i.postid {$insql}",
            $params
        );
    }
}
