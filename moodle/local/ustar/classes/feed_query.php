<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Audience-filtered keyset page; caller never sees a closed repost. */
final class feed_query {
    public static function page(int $userid, string $filter = 'all', int $beforetime = 0,
            int $beforeid = 0, int $limit = 20): array {
        global $DB;
        if (!in_array($filter, ['all', 'academy', 'department', 'mine'], true)) {
            throw new \invalid_parameter_exception('Неизвестный фильтр ленты.');
        }
        $limit = max(1, min(30, $limit));
        $params = ['published' => 'published'];
        $where = 'p.status = :published AND ' . feed_access::audience_sql($userid, 'p', $params);
        // Repost visibility is the intersection of both posts at query time,
        // before the limit; no private source appears as a card or count.
        $where .= " AND (p.sourcepostid IS NULL OR EXISTS
                     (SELECT 1 FROM {local_ustar_feed_posts} src
                       WHERE src.id = p.sourcepostid AND src.status = :sourcepublished AND ";
        $params['sourcepublished'] = 'published';
        $where .= feed_access::audience_sql($userid, 'src', $params, 'source') . '))';
        if ($beforetime > 0 && $beforeid > 0) {
            $where .= ' AND (p.publishedat < :beforetime OR
                            (p.publishedat = :equaltime AND p.id < :beforeid))';
            $params += ['beforetime' => $beforetime, 'equaltime' => $beforetime, 'beforeid' => $beforeid];
        }
        if ($filter === 'academy') {
            $where .= ' AND p.publishertype = :filtertype';
            $params['filtertype'] = 'academy';
        } else if ($filter === 'department') {
            $where .= ' AND p.publishertype = :filtertype AND p.publisherid = :filterdept';
            $params['filtertype'] = 'department';
            $params['filterdept'] = feed_access::department_id($userid);
        } else if ($filter === 'mine') {
            $where .= ' AND p.actoruserid = :filteractor';
            $params['filteractor'] = $userid;
        }
        $rows = array_values($DB->get_records_sql("SELECT p.* FROM {local_ustar_feed_posts} p
                WHERE {$where} ORDER BY p.publishedat DESC, p.id DESC", $params, 0, $limit + 1));
        $more = count($rows) > $limit;
        if ($more) {
            array_pop($rows);
        }
        $last = end($rows);
        return ['posts' => $rows, 'hasmore' => $more,
            'nexttime' => $last ? (int)$last->publishedat : 0,
            'nextid' => $last ? (int)$last->id : 0];
    }
}
