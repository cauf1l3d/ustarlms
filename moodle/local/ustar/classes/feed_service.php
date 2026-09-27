<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Feed mutations. Controller handles sesskey; every public method rechecks ACL. */
final class feed_service {
    private static function body(string $text): string {
        $text = trim($text);
        if ($text === '' || \core_text::strlen($text) > 5000) {
            throw new \invalid_parameter_exception('Текст должен содержать от 1 до 5000 символов.');
        }
        return $text;
    }

    private static function event(int $postid, int $actorid, string $action, string $reason = ''): void {
        global $DB;
        $DB->insert_record('local_ustar_feed_events', (object)[
            'postid' => $postid, 'actoruserid' => $actorid, 'action' => $action,
            'reason' => $reason === '' ? null : $reason, 'timecreated' => time(),
        ]);
    }

    public static function create(int $actorid, string $type, string $publisherid,
            array $audience, string $body, bool $publish, int $sourceid = 0,
            array $uploads = [], string $requestkey = ''): int {
        global $DB;
        if (!preg_match('/^[a-f0-9]{32}$/D', $requestkey)) {
            throw new \invalid_parameter_exception('Неверный ключ отправки публикации.');
        }
        $audience = array_values(array_map('strval', $audience));
        feed_access::assert_publisher($actorid, $type, $publisherid, $audience);
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')
            ->get_lock('feed-create:' . $actorid . ':' . $requestkey, 10);
        if (!$lock) {
            throw new \moodle_exception('Не удалось получить блокировку публикации.');
        }
        try {
        $existing = (int)$DB->get_field('local_ustar_feed_posts', 'id',
            ['actoruserid' => $actorid, 'requestkey' => $requestkey]);
        if ($existing) {
            return $existing;
        }
        $source = null;
        if ($sourceid > 0) {
            $source = feed_access::readable($sourceid, $actorid);
            if ($source->sourcepostid) {
                throw new \invalid_parameter_exception('Можно репостить только исходную публикацию.');
            }
        }
        $body = self::body($body);
        $now = time();
        $transaction = $DB->start_delegated_transaction();
        $id = (int)$DB->insert_record('local_ustar_feed_posts', (object)[
            'actoruserid' => $actorid, 'publishertype' => $type, 'publisherid' => $publisherid,
            'status' => $publish ? 'published' : 'draft', 'body' => $body,
            'version' => 1, 'audienceversion' => 1,
            'requestkey' => $requestkey,
            'sourcepostid' => $source ? $sourceid : null, 'publishedat' => $publish ? $now : null,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        foreach ($audience as $scopeid) {
            $DB->insert_record('local_ustar_feed_audience', (object)[
                'postid' => $id, 'scopekind' => $scopeid === 'all' ? 'all' : 'department',
                'scopeid' => $scopeid,
            ]);
        }
        feed_files::store($id, $uploads);
        self::event($id, $actorid, $publish ? 'publish' : 'draft');
        $transaction->allow_commit();
        return $id;
        } finally {
            $lock->release();
        }
    }

    public static function revise(int $postid, int $actorid, int $version, string $body,
            bool $publish = false): void {
        global $DB;
        feed_access::require_writer($actorid);
        $body = self::body($body);
        $transaction = $DB->start_delegated_transaction();
        $post = $DB->get_record_sql('SELECT * FROM {local_ustar_feed_posts} WHERE id = :id FOR UPDATE',
            ['id' => $postid], MUST_EXIST);
        if ((int)$post->actoruserid !== $actorid || !in_array($post->status, ['draft', 'published'], true)
                || (int)$post->version !== $version) {
            throw new \invalid_parameter_exception('Публикация изменилась или недоступна.');
        }
        $audience = array_map(static fn($row) => (string)$row->scopeid,
            array_values($DB->get_records('local_ustar_feed_audience', ['postid' => $postid])));
        feed_access::assert_publisher($actorid, $post->publishertype, $post->publisherid, $audience);
        if ($post->sourcepostid) {
            feed_access::readable((int)$post->sourcepostid, $actorid);
        }
        $now = time();
        $post->body = $body;
        $wasdraft = $post->status === 'draft';
        $post->status = $publish ? 'published' : $post->status;
        $post->publishedat = $post->publishedat ?: ($publish ? $now : null);
        $post->timemodified = $now;
        $post->version++;
        $DB->update_record('local_ustar_feed_posts', $post);
        self::event($postid, $actorid, $publish && $wasdraft ? 'publish' : 'edit');
        $transaction->allow_commit();
    }

    public static function remove(int $postid, int $actorid, int $version, string $reason = ''): void {
        global $DB;
        feed_access::require_writer($actorid);
        $transaction = $DB->start_delegated_transaction();
        $post = $DB->get_record_sql('SELECT * FROM {local_ustar_feed_posts} WHERE id = :id FOR UPDATE',
            ['id' => $postid], MUST_EXIST);
        $moderator = has_capability('local/ustar:feedmoderate', \context_system::instance(), $actorid);
        if ((int)$post->actoruserid !== $actorid && !$moderator) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/ustar:feedmoderate', 'nopermissions', '');
        }
        if ((int)$post->actoruserid !== $actorid && trim($reason) === '') {
            throw new \invalid_parameter_exception('Укажите причину модерации.');
        }
        if ((int)$post->version !== $version || !in_array($post->status, ['draft', 'published'], true)) {
            throw new \invalid_parameter_exception('Публикация уже изменена.');
        }
        $post->status = $moderator && (int)$post->actoruserid !== $actorid ? 'hidden' : 'deleted';
        $post->version++;
        $post->timemodified = time();
        $DB->update_record('local_ustar_feed_posts', $post);
        self::event($postid, $actorid, $moderator && (int)$post->actoruserid !== $actorid ? 'moderate' : 'delete',
            trim($reason));
        $transaction->allow_commit();
    }

    public static function like(int $postid, int $userid, bool $liked): void {
        global $DB;
        feed_access::require_actor($userid);
        feed_access::readable($postid, $userid);
        $conditions = ['postid' => $postid, 'userid' => $userid, 'kind' => 'like'];
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')
            ->get_lock('feed-like:' . $postid . ':' . $userid, 10);
        if (!$lock) {
            throw new \moodle_exception('Не удалось получить блокировку реакции.');
        }
        try {
            if ($liked && !$DB->record_exists('local_ustar_feed_reactions', $conditions)) {
                $DB->insert_record('local_ustar_feed_reactions',
                    (object)($conditions + ['timecreated' => time()]));
            } else if (!$liked) {
                $DB->delete_records('local_ustar_feed_reactions', $conditions);
            }
        } finally {
            $lock->release();
        }
    }

    public static function comment(int $postid, int $userid, string $body, int $parentid = 0): int {
        global $DB;
        feed_access::require_actor($userid);
        $post = feed_access::readable($postid, $userid);
        $body = self::body($body);
        $parent = null;
        if ($parentid) {
            $parent = $DB->get_record('local_ustar_feed_comments', ['id' => $parentid,
                'postid' => $postid, 'status' => 'visible'], '*', MUST_EXIST);
            if ($parent->parentid) {
                throw new \invalid_parameter_exception('Допускается один уровень ответов.');
            }
        }
        $now = time();
        $transaction = $DB->start_delegated_transaction();
        $commentid = (int)$DB->insert_record('local_ustar_feed_comments', (object)[
            'postid' => $postid, 'parentid' => $parentid ?: null,
            'actoruserid' => $userid, 'body' => $body, 'status' => 'visible',
            'version' => 1, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $recipient = $parent ? (int)$parent->actoruserid : (int)$post->actoruserid;
        if ($recipient !== $userid && accounts::participates($recipient)) {
            // Recipient ACL is checked at creation AND when the link is opened.
            try {
                feed_access::readable($postid, $recipient);
                $eventtype = $parent ? 'feed_reply' : 'feed_comment';
                workflow_notifications::enqueue($recipient, $eventtype,
                    $parent ? 'Ответ на ваш комментарий' : 'Комментарий к публикации',
                    'Откройте обсуждение в ленте Академии.',
                    (new \moodle_url('/local/ustar/feed.php', ['postid' => $postid]))->out(false),
                    $eventtype . ':' . $postid . ':' . $recipient . ':' . intdiv($now, 600));
            } catch (\required_capability_exception $e) {
                // An author transferred out of the audience must not learn that
                // the private discussion is still active.
            }
        }
        $transaction->allow_commit();
        return $commentid;
    }

    public static function revise_comment(int $commentid, int $postid, int $userid,
            int $version, string $body, bool $delete = false): void {
        global $DB;
        feed_access::require_actor($userid);
        feed_access::readable($postid, $userid);
        $transaction = $DB->start_delegated_transaction();
        $comment = $DB->get_record_sql('SELECT * FROM {local_ustar_feed_comments}
            WHERE id = :id AND postid = :postid FOR UPDATE',
            ['id' => $commentid, 'postid' => $postid], MUST_EXIST);
        if ((int)$comment->actoruserid !== $userid || (int)$comment->version !== $version
                || $comment->status !== 'visible') {
            throw new \invalid_parameter_exception('Комментарий уже изменён или недоступен.');
        }
        $comment->body = $delete ? '' : self::body($body);
        $comment->status = $delete ? 'deleted' : 'visible';
        $comment->version++;
        $comment->timemodified = time();
        $DB->update_record('local_ustar_feed_comments', $comment);
        self::event($postid, $userid, $delete ? 'comment_delete' : 'comment_edit',
            'comment:' . $commentid);
        $transaction->allow_commit();
    }

    public static function report(int $postid, int $userid, string $reason): void {
        global $DB;
        feed_access::require_actor($userid);
        feed_access::readable($postid, $userid);
        $reason = self::body($reason);
        if ($DB->record_exists('local_ustar_feed_reports',
                ['postid' => $postid, 'reporterid' => $userid, 'status' => 'open'])) {
            return;
        }
        $now = time();
        $DB->insert_record('local_ustar_feed_reports', (object)[
            'postid' => $postid, 'reporterid' => $userid, 'reason' => $reason,
            'status' => 'open', 'resolvedby' => null, 'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    public static function moderate(int $postid, int $userid, int $version,
            string $decision, string $reason): void {
        global $DB, $USER;
        feed_access::require_reader($userid);
        view_as::assert_writable();
        if ((int)$USER->id !== $userid || !has_capability('local/ustar:feedmoderate',
                \context_system::instance(), $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/ustar:feedmoderate', 'nopermissions', '');
        }
        if (!in_array($decision, ['hide', 'restore', 'dismiss'], true)) {
            throw new \invalid_parameter_exception('Неизвестное решение модерации.');
        }
        $reason = self::body($reason);
        $transaction = $DB->start_delegated_transaction();
        $post = $DB->get_record_sql('SELECT * FROM {local_ustar_feed_posts} WHERE id = :id FOR UPDATE',
            ['id' => $postid], MUST_EXIST);
        if ((int)$post->version !== $version || !in_array($post->status,
                ['published', 'hidden'], true)) {
            throw new \invalid_parameter_exception('Публикация уже изменена.');
        }
        if ($decision !== 'dismiss') {
            if (($decision === 'hide' && $post->status !== 'published')
                    || ($decision === 'restore' && $post->status !== 'hidden')) {
                throw new \invalid_parameter_exception('Решение не соответствует состоянию публикации.');
            }
            $post->status = $decision === 'hide' ? 'hidden' : 'published';
            $post->version++;
            $post->timemodified = time();
            $DB->update_record('local_ustar_feed_posts', $post);
        }
        $DB->execute("UPDATE {local_ustar_feed_reports} SET status = :status,
            resolvedby = :actor, timemodified = :modified WHERE postid = :postid AND status = :open", [
            'status' => $decision === 'dismiss' ? 'dismissed' : 'resolved',
            'actor' => $userid, 'modified' => time(), 'postid' => $postid, 'open' => 'open',
        ]);
        self::event($postid, $userid, 'moderate_' . $decision, $reason);
        $transaction->allow_commit();
    }
}
