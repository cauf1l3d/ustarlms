<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * RSS source registry and importer for the USTAR feed.
 *
 * The importer intentionally does not use feed_service::create(): external
 * sources are system-authored, immutable feed entries and must never inherit
 * a human publisher's capabilities.
 */
final class feed_rss {
    public const PILOT_URL = 'https://vc.ru/rss/all';
    private const FIRST_IMPORT_LIMIT = 10;
    private const REGULAR_IMPORT_LIMIT = 30;
    private const MAX_RESPONSE_BYTES = 2097152;

    public static function validate_url(string $url): string {
        $url = trim($url);
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
                || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new \invalid_parameter_exception('RSS-адрес должен быть абсолютным HTTPS URL без логина и пароля.');
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
                mtrace('USTAR RSS #' . (int)$source->id . ': imported=' . (int)$result['imported']);
            } catch (\Throwable $e) {
                mtrace('USTAR RSS #' . (int)$source->id . ': failed=' . get_class($e));
            }
        }
    }

    public static function import_source(int $sourceid): array {
        global $CFG, $DB;
        $source = $DB->get_record('local_ustar_feed_sources', ['id' => $sourceid], '*', MUST_EXIST);
        $url = self::validate_url((string)$source->url);
        $now = time();
        $DB->set_field('local_ustar_feed_sources', 'lastchecked', $now, ['id' => $sourceid]);

        try {
            require_once($CFG->libdir . '/filelib.php');
            $curl = new \curl();
            $curl->setHeader([
                'Accept: application/rss+xml, application/xml, text/xml;q=0.9, */*;q=0.1',
                'User-Agent: USTAR-Academy-RSS/1.0',
            ]);
            $xml = $curl->get($url, [], [
                'CURLOPT_CONNECTTIMEOUT' => 5,
                'CURLOPT_TIMEOUT' => 15,
                'CURLOPT_FOLLOWLOCATION' => false,
                'CURLOPT_MAXREDIRS' => 0,
            ]);
            $info = $curl->get_info();
            $status = (int)($info['http_code'] ?? 0);
            if ($status < 200 || $status >= 300) {
                throw new \moodle_exception('RSS HTTP status ' . $status);
            }
            if (!is_string($xml) || $xml === '' || strlen($xml) > self::MAX_RESPONSE_BYTES) {
                throw new \moodle_exception('RSS response is empty or too large.');
            }

            $items = self::parse($xml);
            $first = !$DB->record_exists('local_ustar_feed_sourceitem', ['sourceid' => $sourceid]);
            $limit = $first ? self::FIRST_IMPORT_LIMIT : self::REGULAR_IMPORT_LIMIT;
            $items = array_slice($items, 0, $limit);
            $items = array_reverse($items);

            $imported = 0;
            foreach ($items as $item) {
                if (self::publish_item($source, $item)) {
                    $imported++;
                }
            }

            $DB->update_record('local_ustar_feed_sources', (object)[
                'id' => $sourceid,
                'lastchecked' => $now,
                'lastsuccess' => time(),
                'lasterror' => null,
                'timemodified' => time(),
            ]);
            return ['imported' => $imported, 'seen' => count($items)];
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

    public static function parse(string $xml): array {
        $old = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOBLANKS)) {
                throw new \invalid_parameter_exception('Источник вернул некорректный XML.');
            }
            $xpath = new \DOMXPath($document);
            $nodes = $xpath->query('/rss/channel/item');
            if (!$nodes || $nodes->length === 0) {
                throw new \invalid_parameter_exception('Пока поддерживается RSS 2.0 с channel/item.');
            }
            $result = [];
            foreach ($nodes as $node) {
                $title = trim((string)$xpath->evaluate('string(title)', $node));
                $link = trim((string)$xpath->evaluate('string(link)', $node));
                $guid = trim((string)$xpath->evaluate('string(guid)', $node));
                $date = trim((string)$xpath->evaluate('string(pubDate)', $node));
                $description = (string)$xpath->evaluate('string(description)', $node);
                if ($title === '' || $link === '') {
                    continue;
                }
                $scheme = strtolower((string)parse_url($link, PHP_URL_SCHEME));
                if (!in_array($scheme, ['http', 'https'], true)) {
                    continue;
                }
                $guid = $guid !== '' ? $guid : $link;
                $summary = html_entity_decode(strip_tags($description), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $summary = trim((string)preg_replace('/\s+/u', ' ', $summary));
                $summary = \core_text::substr($summary, 0, 1500);
                $published = $date !== '' ? strtotime($date) : false;
                $result[] = [
                    'title' => \core_text::substr($title, 0, 255),
                    'link' => $link,
                    'guid' => $guid,
                    'summary' => $summary,
                    'publishedat' => $published && $published > 0 ? (int)$published : time(),
                ];
            }
            return $result;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }
    }

    private static function publish_item(\stdClass $source, array $item): bool {
        global $DB;
        $guidhash = hash('sha256', (string)$item['guid']);
        if ($DB->record_exists('local_ustar_feed_sourceitem', [
                'sourceid' => (int)$source->id, 'guidhash' => $guidhash])) {
            return false;
        }

        $now = time();
        $transaction = $DB->start_delegated_transaction();
        if ($DB->record_exists('local_ustar_feed_sourceitem', [
                'sourceid' => (int)$source->id, 'guidhash' => $guidhash])) {
            $transaction->allow_commit();
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
            'postid' => $postid, 'scopekind' => 'all', 'scopeid' => 'all',
        ]);
        $DB->insert_record('local_ustar_feed_sourceitem', (object)[
            'sourceid' => (int)$source->id,
            'postid' => $postid,
            'guidhash' => $guidhash,
            'externalguid' => (string)$item['guid'],
            'externalurl' => (string)$item['link'],
            'title' => (string)$item['title'],
            'publishedat' => (int)$item['publishedat'],
            'timecreated' => $now,
        ]);
        $transaction->allow_commit();
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
            "SELECT i.postid AS id, i.externalurl, i.title, i.publishedat,
                    s.id AS sourceid, s.name AS sourcename, s.url AS sourceurl
               FROM {local_ustar_feed_sourceitem} i
               JOIN {local_ustar_feed_sources} s ON s.id = i.sourceid
              WHERE i.postid {$insql}",
            $params
        );
    }
}
