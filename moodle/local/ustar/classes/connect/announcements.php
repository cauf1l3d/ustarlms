<?php
namespace local_ustar\connect;

defined('MOODLE_INTERNAL') || die();

use local_ustar\feed_access;
use local_ustar\feed_files;
use local_ustar\team_access;
use local_ustar\view_as;
use local_ustar\workflow_notifications;

/** An operator-controlled Telegram fanout of a published Academy feed item. */
final class announcements {
    public const EVENT = 'feed_announcement';

    public static function enabled(): bool {
        global $CFG;
        return ($CFG->ustar_connect_announcements_enabled ?? false) === true
            && ($CFG->ustar_connect_notification_presentation_enabled ?? false) === true
            && ($CFG->ustar_connect_ack_enabled ?? false) === true;
    }

    public static function can_manage(int $actorid): bool {
        global $USER;
        return $actorid > 1 && (int)$USER->id === $actorid && isloggedin() && !isguestuser()
            && !\core\session\manager::is_loggedinas() && !view_as::active()
            && (is_siteadmin($actorid) || team_access::active_actor($actorid))
            && has_capability('local/ustar:admin', \context_system::instance(), $actorid)
            && feed_access::can_manage($actorid);
    }

    public static function require_manager(int $actorid): void {
        if (!self::can_manage($actorid)) {
            throw new \required_capability_exception(\context_system::instance(), 'local/ustar:admin', 'nopermissions', '');
        }
    }

    public static function matches(\stdClass $post): bool {
        return $post->status === 'published' && !$post->sourcepostid
            && in_array($post->publishertype, ['academy', 'department'], true);
    }

    private static function prefix(\stdClass $post): string {
        return self::EVENT . ':' . (int)$post->id . ':' . (int)$post->version . ':';
    }

    private static function deliveries(\stdClass $post): array {
        global $DB;
        return $DB->get_records_sql('SELECT n.userid, d.status
              FROM {local_ustar_notifications} n
              JOIN {local_ustar_notify_delivery} d ON d.notificationid = n.id AND d.channel = :channel
             WHERE n.eventtype = :event AND ' . $DB->sql_like('n.idempotencykey', ':prefix'),
            ['channel' => 'telegram', 'event' => self::EVENT, 'prefix' => $DB->sql_like_escape(self::prefix($post)) . '%']);
    }

    private static function preview_post(\stdClass $post): array {
        if (!self::matches($post)) {
            throw new \invalid_parameter_exception('Выберите опубликованное объявление Академии или отдела.');
        }
        $deliveries = self::deliveries($post);
        $eligible = [];
        if (self::enabled()) {
            foreach (notification_delivery::announcement_candidates() as $userid => $user) {
                if (!isset($deliveries[$userid]) && notification_delivery::can_enqueue_announcement($userid,
                        self::prefix($post) . $userid)) {
                    $eligible[$userid] = ['id' => $userid, 'name' => fullname($user)];
                }
            }
        }
        $statuslabels = [
            'pending' => 'В очереди', 'leased' => 'В очереди', 'sending' => 'Отправляется',
            'retry_wait' => 'Ожидает повтора', 'provider_accepted' => 'Принято Telegram',
            'failed' => 'Ошибка доставки', 'uncertain' => 'Результат неизвестен', 'cancelled' => 'Отменено',
        ];
        $counts = [];
        foreach ($deliveries as $delivery) {
            $label = $statuslabels[$delivery->status] ?? 'Другой статус';
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }
        $status = [];
        foreach ($counts as $label => $count) {
            $status[] = ['label' => $label, 'count' => $count];
        }
        $ids = array_keys($eligible);
        sort($ids, SORT_NUMERIC);
        return [
            'id' => (int)$post->id, 'body' => (string)$post->body,
            'people' => array_values($eligible), 'peoplecount' => count($ids), 'deliveries' => $status,
            'fingerprint' => hash('sha256', self::prefix($post) . implode(',', $ids)),
        ];
    }

    public static function preview(int $actorid, int $postid): array {
        self::require_manager($actorid);
        $post = feed_access::readable($postid, $actorid);
        return self::preview_post($post) + [
            'posturl' => (new \moodle_url('/local/ustar/feed.php', ['postid' => $postid]))->out(false),
            'files' => feed_files::list_for($postid, $actorid),
        ];
    }

    public static function enqueue(int $actorid, int $postid, string $fingerprint, array $recipientids): int {
        global $DB;
        self::require_manager($actorid);
        if (!self::enabled() || !$recipientids || count($recipientids) !== count(array_unique($recipientids))) {
            throw new \invalid_parameter_exception('Рассылки закрыты или получатели не выбраны.');
        }
        $tx = $DB->start_delegated_transaction();
        try {
            $post = $DB->get_record_sql('SELECT * FROM {local_ustar_feed_posts} WHERE id = :id FOR UPDATE',
                ['id' => $postid], MUST_EXIST);
            $preview = self::preview_post($post);
            $eligible = array_column($preview['people'], 'id');
            if (!hash_equals($preview['fingerprint'], $fingerprint) || array_diff($recipientids, $eligible)) {
                throw new \invalid_parameter_exception('Публикация или список получателей изменились. Проверьте предпросмотр.');
            }
            $url = (new \moodle_url('/local/ustar/feed.php', ['postid' => $postid]))->out(false);
            foreach ($recipientids as $userid) {
                workflow_notifications::enqueue((int)$userid, self::EVENT, 'Объявление Академии',
                    'Откройте публикацию, чтобы прочитать объявление и посмотреть вложения.',
                    $url, self::prefix($post) . $userid);
            }
            $deliveries = self::deliveries($post);
            if (array_diff($recipientids, array_keys($deliveries))) {
                throw new \invalid_parameter_exception('Настройки доставки изменились. Проверьте список получателей.');
            }
            $DB->insert_record('local_ustar_feed_events', (object)[
                'postid' => $postid, 'actoruserid' => $actorid, 'action' => 'telegram_announcement',
                'reason' => (string)count($recipientids), 'timecreated' => time(),
            ]);
            $tx->allow_commit();
            return count($recipientids);
        } catch (\Throwable $error) {
            $tx->rollback($error);
        }
    }
}
