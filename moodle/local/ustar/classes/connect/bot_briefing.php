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
        return ['label' => $label, 'workurl' => (new \moodle_url('/local/ustar/tasks.php', $params))->out(false)];
    }

    public static function is_work_card(array $view): bool {
        return in_array($view['title'] ?? '', ['Брифинг руководителей', 'Мои встречи'], true);
    }

    public static function verify(array $guard, int $actor): void {
        if (isset($guard['week'])) {
            $overview = briefing::overview_allowed($actor);
            $departments = array_keys(briefing::manager_departments($actor));
            if ((!$overview && !$departments) || $guard['overview'] !== $overview || array_diff($guard['departments'], $departments)
                    || $guard['week'] !== ($overview ? briefing::current_week() : briefing::report_week())
                    || ($departments && $guard['reportweek'] !== briefing::report_week())) {
                throw new \InvalidArgumentException('forbidden_scope');
            }
            return;
        }
        if (!meetings::allowed($actor)) {
            throw new \InvalidArgumentException('forbidden_scope');
        }
        if ($guard['meetings']) {
            $now = time();
            $visible = meetings::visible($actor, $now, $now + 30 * DAYSECS);
            foreach ($guard['meetings'] as $id => $revision) {
                if (!isset($visible[$id]) || (int)$visible[$id]->revision !== $revision) {
                    throw new \InvalidArgumentException('forbidden_scope');
                }
            }
        }
    }

    public static function view(\stdClass $dialog, string $intent): array {
        $actor = (int)$dialog->userid;
        bot_store::state($dialog, 'menu');
        if ($intent === 'work.briefing') {
            if (!briefing::allowed($actor)) {
                throw new \InvalidArgumentException('forbidden_scope');
            }
            $managed = briefing::manager_departments($actor);
            $overview = briefing::overview_allowed($actor);
            $reportweek = briefing::report_week();
            $week = $overview ? briefing::current_week() : $reportweek;
            $rows = [];
            foreach ($managed as $id => $department) {
                $form = briefing::form($actor, $id, $reportweek);
                $rows[] = [$department['name'], $form['submittedat'] ? 'Сдан' : ($form['id'] ? 'Черновик'
                    : ($form['editable'] ? 'К заполнению' : 'Форма закрыта'))];
            }
            if ($overview) {
                $summary = briefing::summary($actor, $week);
                array_unshift($rows, ['Сводка ' . $week, 'Сдано '
                    . count(array_filter($summary, static fn($item) => $item['submittedat']))
                    . (time() < briefing::week_start($week) + briefing::MEETING_DURATION ? ' из ' . count($summary) : '')]);
            }
            $deadline = briefing::week_start($reportweek) - briefing::due_minutes() * MINSECS;
            $text = 'Брифинг ' . userdate(briefing::week_start($week), '%d.%m · %H:%M', briefing::ZONE) . ' МСК.'
                . ($managed ? "\nОтчёт отдела до " . userdate($deadline, '%d.%m · %H:%M', briefing::ZONE) . ' МСК.' : '')
                . "\nВ Академии: Задачи → Брифинг.";
            return bot_store::guarded(bot_store::card('Брифинг руководителей', $text, [
                    self::button('Открыть брифинг', 'briefing'), bot_service::home($dialog),
                ], [], $rows), ['workspace' => ['week' => $week, 'reportweek' => $reportweek,
                    'departments' => array_keys($managed), 'overview' => $overview]]);
        }
        if (!meetings::allowed($actor)) {
            throw new \InvalidArgumentException('forbidden_scope');
        }
        $rows = [];
        $buttons = [];
        $revisions = [];
        $now = time();
        foreach (array_slice(meetings::visible($actor, $now, $now + 30 * DAYSECS), 0, 8, true) as $meeting) {
            $date = userdate($meeting->startat, '%d.%m %H:%M', briefing::ZONE);
            $rows[] = [$date, \core_text::substr($meeting->title, 44) . ' · ' . (meetings::RESPONSES[$meeting->rsvp] ?? 'Организатор')];
            $buttons[] = self::button($date . ' · ' . $meeting->title, 'meetings', (int)$meeting->id);
            $revisions[$meeting->id] = (int)$meeting->revision;
        }
        $buttons[] = self::button('Календарь встреч', 'meetings');
        $buttons[] = bot_service::home($dialog);
        $text = ($rows ? 'Ближайшие встречи · МСК' : 'Предстоящих приглашений пока нет.') . "\nВ Академии: Задачи → Встречи.";
        return bot_store::guarded(bot_store::card('Мои встречи', $text, $buttons, [], $rows),
            ['workspace' => ['meetings' => $revisions]]);
    }
}
