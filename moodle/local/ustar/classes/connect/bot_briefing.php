<?php
namespace local_ustar\connect;

use local_ustar\briefing\{meetings, service as briefing};

defined('MOODLE_INTERNAL') || die();

/** Current briefing status and meeting invitations for the linked Academy account. */
final class bot_briefing {
    private static function button(string $label, string $view, int $meeting = 0): array {
        $params = ['view' => $view];
        if ($meeting) {
            $params['meetingid'] = $meeting;
        }
        return ['label' => $label, 'url' => (new \moodle_url('/local/ustar/tasks.php', $params))->out(false)];
    }

    public static function view(\stdClass $dialog, string $intent): array {
        $actor = (int)$dialog->userid;
        bot_store::state($dialog, 'menu');
        if ($intent === 'work.briefing') {
            if (!briefing::allowed($actor)) {
                throw new \InvalidArgumentException('forbidden_scope');
            }
            $week = briefing::current_week();
            $rows = [];
            foreach (briefing::manager_departments($actor) as $id => $department) {
                $form = briefing::form($actor, $id, $week);
                $rows[] = [$department['name'], $form['submittedat'] ? 'Сдан' : ($form['id'] ? 'Черновик'
                    : ($form['editable'] ? 'К заполнению' : 'Форма закрыта'))];
            }
            if (briefing::overview_allowed($actor)) {
                $summary = briefing::summary($actor, $week);
                $rows[] = ['Отчёты отдела', 'Сдано ' . count(array_filter($summary, static fn($item) => $item['submittedat']))
                    . ' из ' . count($summary)];
            }
            $deadline = briefing::week_start($week) - briefing::due_minutes() * MINSECS;
            return bot_store::card('Брифинг руководителей', 'Брифинг ' . $week . ' в 10:00 МСК. Сдать отчёт до '
                . userdate($deadline, '%d.%m %H:%M', briefing::ZONE) . ' МСК.', [
                    self::button('Открыть брифинг', 'briefing'), bot_service::home($dialog),
                ], [], $rows);
        }
        if (!meetings::allowed($actor)) {
            throw new \InvalidArgumentException('forbidden_scope');
        }
        $rows = [];
        $buttons = [];
        foreach (array_slice(meetings::visible($actor, time(), time() + 30 * DAYSECS), 0, 8, true) as $meeting) {
            $date = userdate($meeting->startat, '%d.%m %H:%M', briefing::ZONE);
            $rows[] = [$date, meetings::RESPONSES[$meeting->rsvp] ?? 'Организатор'];
            $buttons[] = self::button($date . ' · ' . $meeting->title, 'meetings', (int)$meeting->id);
        }
        $buttons[] = self::button('Календарь встреч', 'meetings');
        $buttons[] = bot_service::home($dialog);
        return bot_store::card('Мои встречи', $rows ? 'Ближайшие встречи · МСК' : 'Предстоящих приглашений пока нет.', $buttons, [], $rows);
    }
}
