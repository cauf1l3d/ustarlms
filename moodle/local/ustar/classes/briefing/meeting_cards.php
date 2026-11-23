<?php
namespace local_ustar\briefing;

use local_ustar\organization_identity;
use local_ustar\task_workspace\{calendar, service as tasks};

defined('MOODLE_INTERNAL') || die();

/** Invitation UI shared by the dashboard, meeting list and detail. */
final class meeting_cards {
    public static function organizers(array $ownerids): array {
        $out = tasks::names($ownerids);
        $departments = service::departments();
        foreach ($out as $id => &$name) {
            $department = organization_identity::resolve((int)$id)['departmentid'];
            if (isset($departments[$department]['name'])) {
                $name .= ' · ' . $departments[$department]['name'];
            }
        }
        return $out;
    }

    public static function metadata(\stdClass $row, string $organizer): string {
        $sameday = calendar::date($row->startat, service::ZONE) === calendar::date($row->endat, service::ZONE);
        $endformat = $sameday ? '%H:%M' : '%d.%m.%Y · %H:%M';
        return '<p class="uw-meeting-when">' . s(userdate($row->startat, '%d.%m.%Y · %H:%M', service::ZONE))
            . '–' . s(userdate($row->endat, $endformat, service::ZONE)) . ' МСК · '
            . s($row->roomname ?? 'Без переговорной') . '</p>'
            . '<p class="uw-footnote">Организатор: ' . s($organizer) . '</p>';
    }

    public static function response(\stdClass $row, int $now, string $surface = '', int $page = 0): string {
        $rsvp = $row->rsvp ?? null;
        $label = $row->cancelled ? 'Отменена' : (meetings::RESPONSES[$rsvp] ?? 'Вы организатор');
        if (!$row->cancelled && $rsvp === 'pending' && $row->startat <= $now) {
            $label = 'Ответ не получен';
        }
        $badge = $rsvp === 'pending' && !$row->cancelled && $row->startat > $now ? ' uw-meeting-pending' : '';
        $out = '<span class="uw-badge' . $badge . '">' . s($label) . '</span>';
        if ($row->cancelled || $row->startat <= $now || !isset(meetings::RESPONSES[$rsvp])) {
            return $out;
        }
        $url = new \moodle_url('/local/ustar/tasks.php', ['view' => 'meetings', 'meetingid' => $row->id]);
        $form = '<form method="post" class="uw-actions uw-meeting-response" action="' . $url->out() . '">';
        foreach (['sesskey' => sesskey(), 'action' => 'meeting_rsvp', 'briefrevision' => $row->revision,
                'expectedrsvp' => $rsvp, 'meetingsurface' => $surface, 'meetingpage' => $page] as $key => $value) {
            $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]);
        }
        if ($rsvp !== 'accepted') {
            $form .= '<button class="uw-btn uw-primary" name="rsvp" value="accepted">Приму участие</button>';
        }
        if ($rsvp !== 'declined') {
            $form .= '<button class="uw-btn" name="rsvp" value="declined">Не смогу</button>';
        }
        $form .= '</form>';
        return $out . ($rsvp === 'pending' ? $form
            : '<details class="uw-meeting-change"><summary>Изменить ответ</summary>' . $form . '</details>');
    }

    public static function render(array $rows, int $now, string $surface, int $page = 0): string {
        if (!$rows) {
            return '<p class="uw-empty">Предстоящих встреч пока нет.</p>';
        }
        $organizers = self::organizers(array_column($rows, 'ownerid'));
        $out = '<div class="uw-meeting-list">';
        foreach ($rows as $row) {
            $url = new \moodle_url('/local/ustar/tasks.php', ['view' => 'meetings', 'meetingid' => $row->id]);
            $out .= '<article class="uw-meeting-card" id="meeting-' . (int)$row->id . '">'
                . '<h3>' . \html_writer::link($url, s($row->title)) . '</h3>'
                . self::metadata($row, $organizers[$row->ownerid] ?? '—')
                . self::response($row, $now, $surface, $page) . '</article>';
        }
        return $out . '</div>';
    }
}
