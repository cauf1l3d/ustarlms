<?php
namespace local_ustar\briefing;

use local_ustar\{accounts, employment, team_access, view_as, workflow_notifications};
use local_ustar\task_workspace\{calendar, service as tasks};

defined('MOODLE_INTERNAL') || die();

/** Shared room occupancy and participant calendars; meeting details are private to participants. */
final class meetings {
    public const RESPONSES = ['pending' => 'Ожидает ответа', 'accepted' => 'Принято', 'declined' => 'Отклонено'];

    public static function allowed(int $actor): bool {
        return service::available() && !view_as::active() && team_access::active_actor($actor);
    }

    public static function can_book(int $actor): bool {
        return self::allowed($actor) && service::allowed($actor);
    }

    public static function rooms(int $actor, bool $includeinactive = false): array {
        global $DB;
        if (!self::allowed($actor) || ($includeinactive && !service::admin($actor))) {
            service::deny();
        }
        return $DB->get_records('local_ustar_meeting_rooms', $includeinactive ? [] : ['active' => 1], 'name ASC, id ASC');
    }

    public static function save_room(int $actor, int $id, int $revision, string $name, int $capacity, bool $active): void {
        global $DB;
        tasks::actor($actor);
        if (!service::admin($actor)) {
            service::deny();
        }
        $name = trim($name);
        if ($name === '' || \core_text::strlen($name) > 150 || $capacity < 0 || $capacity > 1000) {
            throw new input_error('Укажите название и вместимость переговорной.');
        }
        service::write('bookings', static function() use ($DB, $id, $revision, $name, $capacity, $active) {
            $old = $id ? $DB->get_record('local_ustar_meeting_rooms', ['id' => $id], '*', MUST_EXIST) : null;
            service::assert_revision($old, $revision);
            if (!$active && $id && ((int)get_config('local_ustar', 'briefing_room') === $id
                    || $DB->record_exists_select('local_ustar_meetings', 'roomid = :room AND cancelled = 0 AND endat > :now',
                        ['room' => $id, 'now' => time()]))) {
                throw new input_error('Сначала перенесите предстоящие бронирования этой переговорной.');
            }
            $row = (object)['name' => $name, 'capacity' => $capacity, 'active' => (int)$active, 'revision' => $revision + 1];
            if ($old) {
                $row->id = $old->id;
                $DB->update_record('local_ustar_meeting_rooms', $row);
            } else {
                $DB->insert_record('local_ustar_meeting_rooms', $row);
            }
        });
    }

    public static function schedule(int $actor, int $deadline, int $roomid): void {
        global $DB;
        tasks::actor($actor);
        if (!service::admin($actor)) {
            service::deny();
        }
        if ($deadline < 0 || $deadline > 6 * 24 * 60) {
            throw new input_error('Укажите срок сдачи в минутах до брифинга, от 0 до 8 640.');
        }
        service::write('bookings', static function() use ($DB, $deadline, $roomid) {
            if ($roomid) {
                $DB->get_record('local_ustar_meeting_rooms', ['id' => $roomid, 'active' => 1], '*', MUST_EXIST);
                $bookings = $DB->get_recordset_select('local_ustar_meetings', 'roomid = :room AND cancelled = 0 AND endat > :now',
                    ['room' => $roomid, 'now' => time()], '', 'id,startat,endat');
                try {
                    foreach ($bookings as $booking) {
                        if (self::overlaps_briefing($booking->startat, $booking->endat)) {
                            throw new input_error('В переговорной уже есть бронирование на время брифинга.');
                        }
                    }
                } finally {
                    $bookings->close();
                }
            }
            set_config('briefing_deadline_minutes', $deadline, 'local_ustar');
            set_config('briefing_room', $roomid, 'local_ustar');
        });
    }

    public static function overlaps_briefing(int $start, int $end): bool {
        $anchor = service::week_start(service::current_week($start));
        if ($anchor + service::MEETING_DURATION <= $start) {
            $anchor += WEEKSECS;
        }
        return $start < $anchor + service::MEETING_DURATION && $end > $anchor;
    }

    public static function eligible_participants(int $actor, ?array $selected = null): array {
        global $DB;
        if (!self::can_book($actor)) {
            service::deny();
        }
        if ($selected === []) {
            return [];
        }
        $out = [];
        $fields = 'u.id, u.' . implode(', u.', \core_user\fields::get_name_fields());
        $params = ['actor' => $actor, 'accountfield' => accounts::FIELD, 'active' => employment::ACTIVE];
        $where = 'u.id > 1 AND u.id <> :actor AND u.deleted = 0 AND u.suspended = 0';
        if ($selected !== null) {
            [$in, $ids] = $DB->get_in_or_equal($selected, SQL_PARAMS_NAMED, 'invitee');
            $where .= ' AND u.id ' . $in;
            $params += $ids;
        }
        $users = $DB->get_records_sql("SELECT $fields, d.data AS accounttype
                  FROM {user} u
             LEFT JOIN {local_ustar_employment} e ON e.userid = u.id
             LEFT JOIN {user_info_field} f ON f.shortname = :accountfield
             LEFT JOIN {user_info_data} d ON d.userid = u.id AND d.fieldid = f.id
                 WHERE $where AND (e.id IS NULL OR e.status = :active)
              ORDER BY u.lastname, u.firstname, u.id", $params);
        foreach ($users as $person) {
            if (!is_siteadmin($person->id) && !in_array(trim((string)$person->accounttype),
                    [accounts::TYPE_SERVICE, accounts::TYPE_TEST], true)) {
                $out[(int)$person->id] = fullname($person);
            }
        }
        return $out;
    }

    private static function member(int $meetingid, int $actor): ?\stdClass {
        global $DB;
        return $DB->get_record('local_ustar_meeting_members', ['meetingid' => $meetingid, 'userid' => $actor]) ?: null;
    }

    private static function participants(int $id): array {
        global $DB;
        return $DB->get_records_select('local_ustar_meeting_members', "meetingid = :id AND rsvp <> 'removed'", ['id' => $id], 'id ASC');
    }

    public static function detail(int $actor, int $id): array {
        global $DB;
        if (!self::allowed($actor)) {
            service::deny();
        }
        $row = $DB->get_record('local_ustar_meetings', ['id' => $id]);
        $member = $row ? self::member($id, $actor) : null;
        if (!$row || ((int)$row->ownerid !== $actor && (!$member || $member->rsvp === 'removed'))) {
            service::deny();
        }
        $owner = (int)$row->ownerid === $actor;
        return ['meeting' => $row, 'member' => $member, 'owner' => $owner,
            'participants' => self::participants($id)];
    }

    private static function same_details(\stdClass $row, array $proposed, array $participants): bool {
        $saved = array_map('intval', array_column(self::participants((int)$row->id), 'userid'));
        sort($saved, SORT_NUMERIC);
        return (string)$row->title === $proposed['title'] && (string)$row->agenda === $proposed['agenda']
            && (int)$row->roomid === $proposed['roomid'] && (int)$row->startat === $proposed['startat']
            && (int)$row->endat === $proposed['endat'] && $saved === $participants;
    }

    private static function busy_people(array $userids, int $start, int $end, int $exclude): array {
        global $DB;
        [$owners, $ownerids] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'owner');
        [$members, $memberids] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'member');
        $busy = $DB->get_fieldset_sql("SELECT m.ownerid AS userid
              FROM {local_ustar_meetings} m
             WHERE m.ownerid $owners AND m.cancelled = 0
                   AND m.startat < :ownerend AND m.endat > :ownerstart AND m.id <> :ownerexclude
             UNION
            SELECT p.userid
              FROM {local_ustar_meeting_members} p
              JOIN {local_ustar_meetings} m ON m.id = p.meetingid
             WHERE p.userid $members AND p.rsvp = 'accepted' AND m.cancelled = 0
                   AND m.startat < :memberend AND m.endat > :memberstart AND m.id <> :memberexclude",
            $ownerids + $memberids + ['ownerstart' => $start, 'ownerend' => $end, 'ownerexclude' => $exclude,
                'memberstart' => $start, 'memberend' => $end, 'memberexclude' => $exclude]);
        return array_fill_keys($busy, true);
    }

    private static function assert_free(int $actor, int $start, int $end, int $exclude): void {
        if (self::busy_people([$actor], $start, $end, $exclude)) {
            throw new input_error('В вашем календаре на это время уже есть встреча.');
        }
    }

    private static function room_busy(int $roomid, int $start, int $end, int $exclude): bool {
        global $DB;
        return ((int)get_config('local_ustar', 'briefing_room') === $roomid && self::overlaps_briefing($start, $end))
            || $DB->record_exists_select('local_ustar_meetings',
                'roomid = :room AND cancelled = 0 AND startat < :endtime AND endat > :starttime AND id <> :meeting',
                ['room' => $roomid, 'endtime' => $end, 'starttime' => $start, 'meeting' => $exclude]);
    }

    private static function validate_interval(int $start, int $end): void {
        $now = time();
        if ($start <= $now || $start > $now + YEARSECS || $end <= $start || $end - $start > DAYSECS) {
            throw new input_error('Выберите будущий интервал до 24 часов в пределах года.');
        }
    }

    /** A privacy-preserving preflight. The booking lock still arbitrates all writes. */
    public static function availability(int $actor, int $meetingid, int $roomid, int $start, int $end, array $selected): array {
        tasks::actor($actor);
        if (!self::can_book($actor) || ($meetingid && !self::detail($actor, $meetingid)['owner'])) {
            service::deny();
        }
        self::validate_interval($start, $end);
        $ids = array_values(array_unique(array_map('intval', $selected)));
        if (count($ids) > 100) {
            throw new input_error('Добавьте до 100 участников.');
        }
        $eligible = self::eligible_participants($actor, $ids);
        if (count($eligible) !== count($ids)) {
            throw new input_error('Проверьте состав участников. Часть сотрудников недоступна.');
        }
        $room = $roomid ? (self::rooms($actor)[$roomid] ?? null) : null;
        if ($roomid && !$room) {
            throw new input_error('Переговорная больше недоступна.');
        }
        $busy = self::busy_people([$actor, ...$ids], $start, $end, $meetingid);
        return ['organizerfree' => !isset($busy[$actor]), 'busy' => array_values(array_intersect_key($eligible, $busy)),
            'roomfree' => !$roomid || !self::room_busy($roomid, $start, $end, $meetingid),
            'capacityok' => !$room || !$room->capacity || count($ids) + 1 <= $room->capacity];
    }

    public static function save(int $actor, int $id, int $revision, string $requestkey, string $title, string $agenda,
            int $roomid, int $start, int $end, array $participants): int {
        global $DB;
        tasks::actor($actor);
        if (!self::can_book($actor)) {
            service::deny();
        }
        $title = trim($title);
        $agenda = trim($agenda);
        if ($title === '' || \core_text::strlen($title) > 200 || \core_text::strlen($agenda) > 12000
                || !preg_match('/\A[A-Za-z0-9]{20,64}\z/', $requestkey)) {
            throw new input_error('Проверьте название и повестку встречи.');
        }
        $participants = array_values(array_unique(array_filter(array_map('intval', $participants), static fn($id) => $id !== $actor)));
        sort($participants, SORT_NUMERIC);
        if (count($participants) > 100) {
            throw new input_error('Добавьте до 100 участников.');
        }
        return service::write('bookings', static function() use ($DB, $actor, $id, $revision, $requestkey, $title,
                $agenda, $roomid, $start, $end, $participants) {
            $proposed = ['title' => $title, 'agenda' => $agenda, 'roomid' => $roomid, 'startat' => $start, 'endat' => $end];
            if (!$id && $revision !== 0) {
                throw new conflict();
            }
            if ($id) {
                $previous = self::detail($actor, $id);
                if (!$previous['owner'] || $previous['meeting']->cancelled) {
                    service::deny();
                }
                $stored = (int)$previous['meeting']->revision;
                if (($stored === $revision || $stored === $revision + 1)
                        && self::same_details($previous['meeting'], $proposed, $participants)) {
                    return $id;
                }
                service::assert_revision($previous['meeting'], $revision);
                if ($previous['meeting']->startat <= time()) {
                    service::deny();
                }
            } else {
                $existing = $DB->get_record('local_ustar_meetings', ['requestkey' => $requestkey, 'ownerid' => $actor]);
                if ($existing) {
                    if (!$existing->cancelled && self::same_details($existing, $proposed, $participants)) {
                        return (int)$existing->id;
                    }
                    throw new conflict();
                }
                $previous = null;
            }
            self::validate_interval($start, $end);
            if (count(self::eligible_participants($actor, $participants)) !== count($participants)) {
                throw new input_error('Один из участников больше не работает в Академии.');
            }
            if ($roomid) {
                $room = $DB->get_record('local_ustar_meeting_rooms', ['id' => $roomid, 'active' => 1]);
                if (!$room) {
                    throw new input_error('Переговорная больше недоступна. Выберите другую.');
                }
                if ($room->capacity && count($participants) + 1 > $room->capacity) {
                    throw new input_error('Участников больше, чем мест в переговорной.');
                }
                if (self::room_busy($roomid, $start, $end, $id)) {
                    throw new input_error('Переговорная занята в выбранное время.');
                }
            }
            self::assert_free($actor, $start, $end, $id);
            $row = (object)($proposed + ['ownerid' => $actor, 'cancelled' => 0, 'revision' => $revision + 1]);
            if ($previous) {
                $row->id = $id;
                $DB->update_record('local_ustar_meetings', $row);
            } else {
                $row->requestkey = $requestkey;
                $id = (int)$DB->insert_record('local_ustar_meetings', $row);
            }
            $old = $previous ? array_column($previous['participants'], null, 'userid') : [];
            $changedslot = $previous && ((int)$previous['meeting']->startat !== $start
                || (int)$previous['meeting']->endat !== $end || (int)$previous['meeting']->roomid !== $roomid);
            foreach ($participants as $participant) {
                if (!isset($old[$participant])) {
                    $removed = self::member($id, $participant);
                    if ($removed) {
                        $DB->set_field('local_ustar_meeting_members', 'rsvp', 'pending', ['id' => $removed->id]);
                    } else {
                        $DB->insert_record('local_ustar_meeting_members',
                            (object)['meetingid' => $id, 'userid' => $participant, 'rsvp' => 'pending']);
                    }
                } else if ($changedslot) {
                    $DB->set_field('local_ustar_meeting_members', 'rsvp', 'pending', ['id' => $old[$participant]->id]);
                }
                self::notify($participant, $id, $row->revision, isset($old[$participant]) ? 'updated' : 'invited');
            }
            foreach (array_diff(array_keys($old), $participants) as $removed) {
                $DB->set_field('local_ustar_meeting_members', 'rsvp', 'removed', ['id' => $old[$removed]->id]);
                self::notify((int)$removed, $id, $row->revision, 'removed');
            }
            return $id;
        });
    }

    public static function cancel(int $actor, int $id, int $revision): void {
        global $DB;
        tasks::actor($actor);
        service::write('bookings', static function() use ($DB, $actor, $id, $revision) {
            $detail = self::detail($actor, $id);
            if (!$detail['owner']) {
                service::deny();
            }
            if ($detail['meeting']->cancelled && (int)$detail['meeting']->revision === $revision + 1) {
                return;
            }
            if ($detail['meeting']->cancelled || $detail['meeting']->startat <= time()) {
                service::deny();
            }
            service::assert_revision($detail['meeting'], $revision);
            $DB->update_record('local_ustar_meetings', (object)['id' => $id, 'cancelled' => 1, 'revision' => $revision + 1]);
            foreach ($detail['participants'] as $person) {
                self::notify((int)$person->userid, $id, $revision + 1, 'cancelled');
            }
        });
    }

    public static function respond(int $actor, int $id, int $revision, string $response, ?string $expected = null): void {
        global $DB;
        tasks::actor($actor);
        if (!in_array($response, ['accepted', 'declined'], true)) {
            throw new input_error('Выберите ответ на приглашение.');
        }
        service::write('bookings', static function() use ($DB, $actor, $id, $revision, $response, $expected) {
            $detail = self::detail($actor, $id);
            if (!$detail['member'] || $detail['meeting']->cancelled || $detail['meeting']->startat <= time()) {
                service::deny();
            }
            service::assert_revision($detail['meeting'], $revision);
            if ($detail['member']->rsvp === $response) {
                return;
            }
            if ($expected !== null && $detail['member']->rsvp !== $expected) {
                throw new conflict();
            }
            if ($response === 'accepted') {
                self::assert_free($actor, (int)$detail['meeting']->startat, (int)$detail['meeting']->endat, $id);
            }
            $DB->set_field('local_ustar_meeting_members', 'rsvp', $response, ['id' => $detail['member']->id]);
        });
    }

    private static function notify(int $participant, int $meeting, int $revision, string $action): void {
        $label = ['invited' => 'Приглашение на встречу', 'updated' => 'Встреча изменена',
            'cancelled' => 'Встреча отменена', 'removed' => 'Приглашение на встречу отозвано'][$action];
        workflow_notifications::enqueue($participant, 'meeting_' . $action, $label, '',
            '/local/ustar/tasks.php?view=meetings' . ($action === 'removed' ? '' : '&meetingid=' . $meeting),
            'meeting:' . $meeting . ':' . $revision . ':' . $participant);
    }

    public static function visible(int $actor, int $from, int $to, bool $includeinactive = false): array {
        global $DB;
        if (!self::allowed($actor)) {
            return [];
        }
        $active = $includeinactive ? '' : " AND m.cancelled = 0 AND (p.rsvp IS NULL OR p.rsvp <> 'declined')";
        return $DB->get_records_sql("SELECT m.*, p.rsvp, r.name AS roomname
              FROM {local_ustar_meetings} m
         LEFT JOIN {local_ustar_meeting_members} p ON p.meetingid = m.id AND p.userid = :participant AND p.rsvp <> 'removed'
         LEFT JOIN {local_ustar_meeting_rooms} r ON r.id = m.roomid
             WHERE (m.ownerid = :actor OR p.id IS NOT NULL) AND m.startat < :endtime AND m.endat > :starttime
                   $active
          ORDER BY m.startat ASC, m.id ASC",
            ['participant' => $actor, 'actor' => $actor, 'starttime' => $from, 'endtime' => $to]);
    }

    public static function busy(int $actor, string $date): array {
        global $DB;
        if (!self::allowed($actor)) {
            service::deny();
        }
        $start = calendar::timestamp($date, '00:00', service::ZONE);
        $end = $start + DAYSECS;
        $out = [];
        $rows = $DB->get_records_select('local_ustar_meetings',
            'roomid > 0 AND cancelled = 0 AND startat < :endtime AND endat > :starttime',
            ['endtime' => $end, 'starttime' => $start], 'startat ASC, id ASC', 'id,roomid,startat,endat');
        foreach ($rows as $row) {
            $out[(int)$row->roomid][] = [max($start, (int)$row->startat), min($end, (int)$row->endat)];
        }
        $room = (int)get_config('local_ustar', 'briefing_room');
        if ($room && service::current_week($start) === $date) {
            $meeting = service::week_start($date);
            $out[$room][] = [$meeting, $meeting + service::MEETING_DURATION];
            sort($out[$room]);
        }
        return $out;
    }

    public static function calendar_items(int $actor, int $from, int $to, string $timezone = service::ZONE): array {
        $items = [];
        $zone = calendar::timezone($timezone);
        foreach (self::visible($actor, $from, $to) as $meeting) {
            $start = max((int)$meeting->startat, $from);
            $end = min((int)$meeting->endat, $to);
            $day = (new \DateTimeImmutable('@' . $start))->setTimezone($zone);
            while ($day->getTimestamp() < $end) {
                $items[] = ['title' => $meeting->title, 'startat' => $day->getTimestamp(),
                    'continued' => $day->getTimestamp() > (int)$meeting->startat,
                    'params' => ['view' => 'meetings', 'meetingid' => (int)$meeting->id]];
                $day = $day->setTime(0, 0)->modify('+1 day');
            }
        }
        return $items;
    }
}
