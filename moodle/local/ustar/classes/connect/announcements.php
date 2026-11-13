<?php
namespace local_ustar\connect;

defined('MOODLE_INTERNAL') || die();

use local_ustar\feed_access;
use local_ustar\feed_files;
use local_ustar\team_access;
use local_ustar\view_as;
use local_ustar\workflow_notifications;

/** An operator-controlled Telegram broadcast of a published Academy feed item. */
final class announcements {
    public const EVENT = 'feed_announcement';
    public const BROADCAST_EVENT = 'feed_broadcast';

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
            && self::operator_capability($actorid);
    }

    public static function operator_capability(int $actorid): bool {
        return (is_siteadmin($actorid) || team_access::active_actor($actorid))
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

    public static function recent(int $actorid, int $offset, int $limit): array {
        global $DB;
        self::require_manager($actorid);
        return $DB->get_records_select('local_ustar_feed_posts',
            "status = :published AND publishertype IN ('academy', 'department') AND COALESCE(sourcepostid, 0) = 0",
            ['published' => 'published'], 'publishedat DESC, id DESC', 'id,body,publishedat', $offset, $limit);
    }

    public static function post_for_notice(\stdClass $notice): ?\stdClass {
        $event = $notice->eventtype;
        $uid = (int)$notice->userid;
        if (!self::enabled() || !in_array($event, [self::EVENT, self::BROADCAST_EVENT], true)) {
            return null;
        }
        $stamp = $event === self::BROADCAST_EVENT ? ':[A-Za-z0-9_-]{43}' : '';
        if (!preg_match('/\A'.$event.':([1-9][0-9]*):([1-9][0-9]*):'.$uid.$stamp.'\z/',
                $notice->idempotencykey, $matches)) {
            return null;
        }
        try {
            $post = feed_access::readable((int)$matches[1], $uid, false);
            return self::matches($post) && (int)$post->version === (int)$matches[2] ? $post : null;
        } catch (\required_capability_exception $e) {
            return null;
        }
    }

    private static function deliveries(\stdClass $post, string $event): array {
        global $DB;
        $prefix = $event . ':' . (int)$post->id . ':' . (int)$post->version . ':';
        return $DB->get_records_sql('SELECT d.id, d.status, d.attempts, d.nextattempt, d.lasterror,
                    u.id AS userid, u.firstname, u.lastname, u.middlename, u.firstnamephonetic, u.lastnamephonetic, u.alternatename
              FROM {local_ustar_notifications} n
              JOIN {local_ustar_notify_delivery} d ON d.notificationid = n.id AND d.channel = :channel
              JOIN {user} u ON u.id = n.userid
             WHERE n.eventtype = :event AND ' . $DB->sql_like('n.idempotencykey', ':prefix') . ' ORDER BY d.id DESC',
            ['channel' => 'telegram', 'event' => $event, 'prefix' => $DB->sql_like_escape($prefix) . '%']);
    }

    private static function preview_post(\stdClass $post): array {
        if (!self::matches($post)) {
            throw new \invalid_parameter_exception('Выберите опубликованное объявление Академии или отдела.');
        }
        $deliveries = self::deliveries($post, self::BROADCAST_EVENT);
        $attempted = array_fill_keys(array_column($deliveries, 'userid'), true);
        $legacy = self::deliveries($post, self::EVENT);
        $error = '';
        $stamp = '';
        try {
            $stamp = broadcast_content::snapshot($post)['stamp'];
        } catch (\invalid_parameter_exception $e) {
            $error = $e->debuginfo;
        }
        $eligible = [];
        if (self::enabled() && $stamp !== '') {
            foreach (notification_delivery::announcement_candidates() as $userid => $user) {
                if (!isset($attempted[$userid]) && notification_delivery::can_enqueue_announcement($userid,
                        broadcast_content::key($post, $userid, $stamp), self::BROADCAST_EVENT)) {
                    $eligible[$userid] = ['id' => $userid, 'name' => fullname($user)];
                }
            }
        }
        $ids = array_keys($eligible);
        sort($ids, SORT_NUMERIC);
        return [
            'id' => (int)$post->id, 'body' => (string)$post->body,
            'people' => array_values($eligible), 'peoplecount' => count($ids),
            'hasdeliveries' => (bool)$deliveries, 'deliveries' => self::counts($deliveries),
            'legacydeliveries' => self::counts($legacy),
            'history' => array_map(static fn(\stdClass $row): array => self::delivery_view($row), array_values($deliveries)),
            'broadcast_error' => $error, 'sendable' => $stamp !== '',
            'fingerprint' => hash('sha256', $post->id . ':' . $post->version . ':' . $stamp . ':' . implode(',', $ids)),
            'stamp' => $stamp,
        ];
    }

    private static function counts(array $deliveries): array {
        $counts = [];
        foreach ($deliveries as $delivery) {
            $label = self::status_label($delivery);
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }
        $status = [];
        foreach ($counts as $label => $count) {
            $status[] = ['label' => $label, 'count' => $count];
        }
        return $status;
    }

    private static function status_label(\stdClass $row): string {
        return match ($row->status) {
            'pending' => (int)$row->nextattempt > time() ? 'Запланировано' : 'В очереди',
            'leased' => 'Подготавливается', 'sending' => 'Отправляется',
            'retry_wait' => 'Ожидает повтора', 'provider_accepted' => 'Принято Telegram',
            'failed' => 'Ошибка доставки', 'uncertain' => 'Результат неизвестен', 'cancelled' => 'Отменено',
            default => 'Другой статус',
        };
    }

    private static function delivery_view(\stdClass $row): array {
        $reason = match ($row->lasterror) {
            'quiet_hours' => 'Тихие часы получателя',
            'recipient_interval' => 'Интервал между уведомлениями',
            'recipient_busy' => 'Предыдущая отправка ещё обрабатывается',
            'attachment_unavailable' => 'Не удалось подготовить вложение',
            'permit_unavailable' => 'Не удалось подтвердить разрешение на отправку',
            'worker_scope' => 'Получатель вне разрешённого контура бота',
            'publication_changed' => 'Публикация изменилась или убрана из Ленты',
            'expired' => 'Истёк срок доставки',
            'no_longer_eligible' => 'Изменился доступ, привязка или настройка уведомлений',
            'forbidden' => 'Telegram закрыл боту доступ к чату',
            'api_rejected' => 'Telegram отклонил сообщение',
            'rate_limited' => 'Telegram временно ограничил отправку',
            'unauthorized' => 'Службе нужно восстановить доступ к Telegram',
            'max_attempts' => 'Исчерпаны попытки доставки',
            default => '',
        };
        if ($row->status === 'uncertain') {
            $reason = 'Подтверждение отправки отсутствует. Проверьте чат получателя перед новой отправкой.';
        }
        if ($row->status === 'pending' && (int)$row->nextattempt > 0 && (int)$row->nextattempt <= time() - 60) {
            $reason = 'Служба доставки ещё не начала обработку. Обновите статус.';
        }
        $next = in_array($row->status, ['pending', 'retry_wait'], true) && (int)$row->nextattempt > time()
            ? userdate((int)$row->nextattempt, '%d.%m.%Y %H:%M') : '';
        return ['name' => fullname($row), 'status' => self::status_label($row), 'reason' => $reason, 'next' => $next];
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
            if (!$preview['sendable'] || !hash_equals($preview['fingerprint'], $fingerprint)
                    || array_diff($recipientids, $eligible)) {
                throw new \invalid_parameter_exception('Публикация или список получателей изменились. Проверьте предпросмотр.');
            }
            $url = (new \moodle_url('/local/ustar/feed.php', ['postid' => $postid]))->out(false);
            foreach ($recipientids as $userid) {
                workflow_notifications::enqueue((int)$userid, self::BROADCAST_EVENT, 'Объявление Академии',
                    'Откройте публикацию, чтобы прочитать объявление и посмотреть вложения.',
                    $url, broadcast_content::key($post, (int)$userid, $preview['stamp']));
            }
            $deliveries = self::deliveries($post, self::BROADCAST_EVENT);
            if (array_diff($recipientids, array_column($deliveries, 'userid'))) {
                throw new \invalid_parameter_exception('Настройки доставки изменились. Проверьте список получателей.');
            }
            $DB->insert_record('local_ustar_feed_events', (object)[
                'postid' => $postid, 'actoruserid' => $actorid, 'action' => 'telegram_broadcast',
                'reason' => (string)count($recipientids), 'timecreated' => time(),
            ]);
            $tx->allow_commit();
            return count($recipientids);
        } catch (\Throwable $error) {
            $tx->rollback($error);
        }
    }
}
