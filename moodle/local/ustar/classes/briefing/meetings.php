<?php
namespace local_ustar\briefing;

use local_ustar\{accounts, team_access, view_as, workflow_notifications};
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
            throw new \invalid_parameter_exception('Укажите название и вместимость переговорной.');
        }
        service::write('bookings', static function() use ($DB, $id, $revision, $name, $capacity, $active) {
            $old = $id ? $DB->get_record('local_ustar_meeting_rooms', ['id' => $id], '*', MUST_EXIST) : null;
            service::assert_revision($old, $revision);
            if (!$active && $id && ((int)get_config('local_ustar', 'briefing_room') === $id
                    || $DB->record_exists_select('local_ustar_meetings', 'roomid = :room AND cancelled = 0 AND endat > :now',
                        ['room' => $id, 'now' => time()]))) {
                throw new \invalid_parameter_exception('Сначала перенесите предстоящие бронирования этой переговорной.');
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
            throw new \invalid_parameter_exception('Укажите срок сдачи в минутах до брифинга, от 0 до 8 640.');
        }
        service::write('bookings', static function() use ($DB, $deadline, $roomid) {
            if ($roomid) {
                $DB->get_record('local_ustar_meeting_rooms', ['id' => $roomid, 'active' => 1], '*', MUST_EXIST);
                $bookings = $DB->get_recordset_select('local_ustar_meetings', 'roomid = :room AND cancelled = 0 AND endat > :now',
                    ['room' => $roomid, 'now' => time()], '', 'id,startat,endat');
                try {
                    foreach ($bookings as $booking) {
                        if (self::overlaps_briefing($booking->startat, $booking->endat)) {
                            throw new \invalid_parameter_exception('В переговорной уже есть бронирование на время брифинга.');
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

    public static function eligible_participants(int $actor): array {
        global $DB;
        if (!self::can_book($actor)) {
            service::deny();
        }
        $out = [];
        $fields = 'id,' . implode(',', \core_user\fields::get_name_fields());
        $users = $DB->get_records_select('user', 'id > 1 AND id <> :actor AND deleted = 0 AND suspended = 0',
            ['actor' => $actor], 'lastname ASC, firstname ASC, id ASC', $fields);
        foreach ($users as $person) {
            if (accounts::participates((int)$person->id) && team_access::active_actor((int)$person->id)) {
                $out[(int)$person->id] = fullname($person);
            }
        }
        return $out;
    }

    private static function member(int $meetingid, int $actor): ?\stdClass {
        global $DB;
        return $DB->get_record('local_ustar_meeting_members', ['meetingid' => $meetingid, 'userid' => $actor]) ?: null;
    }

    public static function detail(int $actor, int $id): array {
        global $DB;
        if (!self::allowed($actor)) {
            service::deny();
        }
        $row = $DB->get_record('local_ustar_meetings', ['id' => $id]);
        $member = $row ? self::member($id, $actor) : null;
        if (!$row || ((int)$row->ownerid !== $actor && !$member)) {
            service::deny();
        }
        $owner = (int)$row->ownerid === $actor;
        return ['meeting' => $row, 'member' => $member, 'owner' => $owner,
            'participants' => $DB->get_records('local_ustar_meeting_members', ['meetingid' => $id], 'id ASC')];
    }

    public static function save(int $actor, int $id, int $revision, string $requestkey, string $title, string $agenda,
            int $roomid, int $start, int $end, array $participants): int {
        global $DB;
        tasks::actor($actor);
        if (!self::can_book($actor)) {
            service::deny();
        }
        $title = trim($title);
        if ($title === '' || \core_text::strlen($title) > 200 || \core_text::strlen($agenda) > 12000
                || !preg_match('/\A[A-Za-z0-9]{20,64}\z/', $requestkey)) {
            throw new \invalid_parameter_exception('Проверьте название и повестку встречи.');
        }
        if ($start <= time() || $start > time() + YEARSECS || $end <= $start || $end - $start > DAYSECS) {
            throw new \invalid_parameter_exception('Выберите будущий интервал до 24 часов в пределах года.');
        }
        $participants = array_values(array_unique(array_filter(array_map('intval', $participants), static fn($id) => $id !== $actor)));
        if (count($participants) > 100) {
            throw new \invalid_parameter_exception('Добавьте до 100 участников.');
        }
        return service::write('bookings', static function() use ($DB, $actor, $id, $revision, $requestkey, $title,
                $agenda, $roomid, $start, $end, $participants) {
            if (!$id && $revision !== 0) {
                throw new conflict();
            }
            if ($id) {
                $previous = self::detail($actor, $id);
                if (!$previous['owner'] || $previous['meeting']->cancelled || $previous['meeting']->startat <= time()) {
                    service::deny();
                }
                service::assert_revision($previous['meeting'], $revision);
            } else {
                $existing = $DB->get_record('local_ustar_meetings', ['requestkey' => $requestkey, 'ownerid' => $actor]);
                if ($existing) {
                    return (int)$existing->id;
                }
                $previous = null;
            }
            foreach ($participants as $participant) {
                if (!accounts::participates($participant) || !team_access::active_actor($participant)) {
                    throw new \invalid_parameter_exception('Один из участников больше не работает в Академии.');
                }
            }
            if ($roomid) {
                $room = $DB->get_record('local_ustar_meeting_rooms', ['id' => $roomid, 'active' => 1], '*', MUST_EXIST);
                if ($room->capacity && count($participants) + 1 > $room->capacity) {
                    throw new \invalid_parameter_exception('Участников больше, чем мест в переговорной.');
                }
                if (((int)get_config('local_ustar', 'briefing_room') === $roomid && self::overlaps_briefing($start, $end))
                        || $DB->record_exists_select('local_ustar_meetings',
                            'roomid = :room AND cancelled = 0 AND startat < :endtime AND endat > :starttime AND id <> :meeting',
                            ['room' => $roomid, 'endtime' => $end, 'starttime' => $start, 'meeting' => $id])) {
                    throw new \invalid_parameter_exception('Переговорная занята в выбранное время.');
                }
            }
            $row = (object)['ownerid' => $actor, 'roomid' => $roomid, 'title' => $title, 'agenda' => trim($agenda),
                'startat' => $start, 'endat' => $end, 'cancelled' => 0, 'revision' => $revision + 1];
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
                    $DB->insert_record('local_ustar_meeting_members',
                        (object)['meetingid' => $id, 'userid' => $participant, 'rsvp' => 'pending']);
                } else if ($changedslot) {
                    $DB->set_field('local_ustar_meeting_members', 'rsvp', 'pending', ['id' => $old[$participant]->id]);
                }
                self::notify($participant, $id, $row->revision, isset($old[$participant]) ? 'updated' : 'invited');
            }
            foreach (array_diff(array_keys($old), $participants) as $removed) {
                $DB->delete_records('local_ustar_meeting_members', ['id' => $old[$removed]->id]);
            }
            return $id;
        });
    }

    public static function cancel(int $actor, int $id, int $revision): void {
        global $DB;
        tasks::actor($actor);
        service::write('bookings', static function() use ($DB, $actor, $id, $revision) {
            $detail = self::detail($actor, $id);
            if (!$detail['owner'] || $detail['meeting']->cancelled || $detail['meeting']->startat <= time()) {
                service::deny();
            }
            service::assert_revision($detail['meeting'], $revision);
            $DB->update_record('local_ustar_meetings', (object)['id' => $id, 'cancelled' => 1, 'revision' => $revision + 1]);
            foreach ($detail['participants'] as $person) {
                self::notify((int)$person->userid, $id, $revision + 1, 'cancelled');
            }
        });
    }

    public static function respond(int $actor, int $id, int $revision, string $response): void {
        global $DB;
        tasks::actor($actor);
        if (!in_array($response, ['accepted', 'declined'], true)) {
            throw new \invalid_parameter_exception('Выберите ответ на приглашение.');
        }
        service::write('bookings', static function() use ($DB, $actor, $id, $revision, $response) {
            $detail = self::detail($actor, $id);
            if (!$detail['member'] || $detail['meeting']->cancelled || $detail['meeting']->startat <= time()) {
                service::deny();
            }
            service::assert_revision($detail['meeting'], $revision);
            $DB->set_field('local_ustar_meeting_members', 'rsvp', $response, ['id' => $detail['member']->id]);
        });
    }

    private static function notify(int $participant, int $meeting, int $revision, string $action): void {
        $label = ['invited' => 'Приглашение на встречу', 'updated' => 'Встреча изменена', 'cancelled' => 'Встреча отменена'][$action];
        workflow_notifications::enqueue($participant, 'meeting_' . $action, $label, '',
            '/local/ustar/tasks.php?view=meetings&meetingid=' . $meeting, 'meeting:' . $meeting . ':' . $revision . ':' . $participant);
    }

    public static function visible(int $actor, int $from, int $to, bool $includeinactive = false): array {
        global $DB;
        if (!self::allowed($actor)) {
            return [];
        }
        $active = $includeinactive ? '' : " AND m.cancelled = 0 AND (p.rsvp IS NULL OR p.rsvp <> 'declined')";
        return $DB->get_records_sql("SELECT m.*, p.rsvp, r.name AS roomname
              FROM {local_ustar_meetings} m
         LEFT JOIN {local_ustar_meeting_members} p ON p.meetingid = m.id AND p.userid = :participant
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

    public static function calendar_items(int $actor, int $from, int $to): array {
        $items = [];
        foreach (self::visible($actor, $from, $to) as $meeting) {
            $items[] = ['title' => $meeting->title, 'startat' => max((int)$meeting->startat, $from),
                'params' => ['view' => 'meetings', 'meetingid' => (int)$meeting->id]];
        }
        return $items;
    }
}
