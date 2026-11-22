<?php
namespace local_ustar\briefing;

use local_ustar\task_workspace\discussion;
use local_ustar\task_workspace\{calendar, service as tasks};

defined('MOODLE_INTERNAL') || die();

/** Forms and screens for the weekly briefing and meeting calendar. */
final class page {
    private int $actor;
    private array $state;

    public function __construct(int $actor, array $state) {
        $this->actor = $actor;
        $this->state = $state;
    }

    private function url(array $params = []): \moodle_url {
        return new \moodle_url('/local/ustar/tasks.php', $params + [
            'view' => $this->state['view'], 'scope' => $this->state['scope'],
        ]);
    }

    private function link(string $label, array $params = [], string $class = 'uw-btn'): string {
        return \html_writer::link($this->url($params), s($label), ['class' => $class]);
    }

    private static function hidden(string $name, $value): string {
        return \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
    }

    private function post(string $action, array $params = []): string {
        return '<form method="post" class="uw-form" action="' . $this->url($params)->out() . '">'
            . self::hidden('sesskey', sesskey()) . self::hidden('action', $action);
    }

    private function field(string $label, string $name, $value, string $type = 'text', string $extra = '', bool $restore = true): string {
        $value = $restore ? ($this->state['input'][$name] ?? $value) : $value;
        return '<label class="uw-field"><span>' . s($label) . '</span><input name="' . s($name) . '" value="' . s((string)$value)
            . '" type="' . s($type) . '" ' . $extra . '></label>';
    }

    private function text(string $label, string $name, string $value, int $limit, bool $gone = false): string {
        $value = $this->state['input'][$name] ?? $value;
        return '<label class="uw-field"><span>' . s($label) . '</span><textarea name="' . s($name) . '" rows="4" maxlength="'
            . $limit . '"' . ($gone ? ' readonly' : '') . '>' . s($value) . '</textarea></label>';
    }

    private static function select(string $label, string $name, array $choices, $value): string {
        return '<label class="uw-field"><span>' . s($label) . '</span>'
            . \html_writer::select($choices, $name, $value, false, ['class' => 'uw-select']) . '</label>';
    }

    private static function button(string $label, bool $primary = true): string {
        return '<button class="uw-btn' . ($primary ? ' uw-primary' : '') . '" type="submit">' . s($label) . '</button>';
    }

    private static function date(int $at, string $format = '%d.%m.%Y · %H:%M'): string {
        return s(userdate($at, $format, service::ZONE));
    }

    public function briefing(): string {
        $section = $this->state['briefsection'];
        $admin = service::admin($this->actor);
        $overview = service::overview_allowed($this->actor);
        $managed = service::manager_departments($this->actor);
        if (!$overview && !$managed) {
            service::deny();
        }
        $reportsection = $section === 'report' || (!$overview && $section !== 'minutes');
        $current = $reportsection ? service::report_week() : service::current_week();
        $week = $this->state['briefweek'] ?: $current;
        try {
            $meeting = service::week_start($week);
        } catch (\invalid_parameter_exception $error) {
            $week = $current;
            $meeting = service::week_start($week);
        }
        $params = ['briefweek' => $week];
        $deadline = $meeting - service::due_minutes() * MINSECS;

        $room = meetings::rooms($this->actor)[(int)get_config('local_ustar', 'briefing_room')] ?? null;
        $out = '<section class="uw-panel"><div class="uw-panel-head"><div><h2>Брифинг руководителей</h2>'
            . '<p>' . self::date($meeting, '%A, %d.%m · %H:%M') . ' МСК'
            . ($room ? ' · ' . s($room->name) : '') . '</p>'
            . ($meeting > time() ? '<p>Сдача отчётов до ' . self::date($deadline) . ' МСК</p>' : '') . '</div>'
            . '<div class="uw-actions uw-brief-controls">'
            . $this->link('← Неделя', ['briefweek' => calendar::date($meeting - WEEKSECS, service::ZONE), 'briefsection' => $section])
            . $this->link('Текущая', ['briefweek' => $current, 'briefsection' => $section])
            . $this->link('Неделя →', ['briefweek' => calendar::date($meeting + WEEKSECS, service::ZONE), 'briefsection' => $section])
            . '</div></div><nav class="uw-filters uw-brief-controls" aria-label="Разделы брифинга">';
        if ($managed) {
            $reportweek = !$reportsection && $week === service::current_week() ? service::report_week() : $week;
            $out .= $this->link('Отчёт отдела', ['briefweek' => $reportweek, 'briefsection' => 'report'], 'uw-chip');
        }
        if ($overview) {
            $out .= $this->link('Сводка', $params + ['briefsection' => 'summary'], 'uw-chip');
        }
        $out .= $this->link('Протокол', $params + ['briefsection' => 'minutes'], 'uw-chip');
        if ($admin) {
            $out .= $this->link('Формы и расписание', $params + ['briefsection' => 'settings'], 'uw-chip');
        }
        $out .= \html_writer::link(new \moodle_url('/local/ustar/notebook.php', ['briefweek' => $week]), 'Заметки недели', ['class' => 'uw-chip'])
            . '</nav></section>';

        if ($section === 'minutes') {
            return $out . $this->minutes($week, $admin);
        }
        if ($section === 'settings' && $admin) {
            return $out . $this->settings($week);
        }
        if ($overview && (!$managed || $section !== 'report')) {
            return $out . $this->summary($week, $section === 'present');
        }
        return $out . $this->report($week, $managed);
    }

    private function report(string $week, array $managed): string {
        $selected = $this->state['briefdept'];
        $department = isset($managed[$selected]) ? $selected : (string)array_key_first($managed);
        $form = service::form($this->actor, $department, $week);
        $params = ['briefweek' => $week, 'briefsection' => 'report', 'briefdept' => $department];
        $out = '<section class="uw-panel"><div class="uw-filters">';
        foreach ($managed as $id => $row) {
            $out .= $this->link($row['name'], ['briefweek' => $week, 'briefsection' => 'report', 'briefdept' => $id],
                'uw-chip' . ($id === $department ? ' is-active' : ''));
        }
        $out .= '</div>';
        if (!$form['fields'] || (!$form['editable'] && !$form['id'])) {
            return $out . '<div class="uw-empty">На выбранную неделю у отдела нет доступного отчёта.</div></section>';
        }
        $status = $form['submittedat'] ? 'Отправлен ' . self::date($form['submittedat']) : ($form['id'] ? 'Черновик' : 'К заполнению');
        $out .= '<div class="uw-panel-head"><h2>' . s($form['title']) . '</h2><span class="uw-badge">' . $status . '</span></div>';
        if ($form['editable']) {
            $out .= $this->post('brief_report', $params)
                . self::hidden('briefrevision', $form['revision']) . self::hidden('templateversion', $form['template']);
            foreach ($form['fields'] as $key => $field) {
                $label = $field['label'] . ($field['required'] ? ' *' : '');
                $value = $form['answers'][$key] ?? '';
                $out .= $field['type'] === 'long' ? $this->text($label, 'briefanswer_' . $key, $value, 12000)
                    : $this->field($label, 'briefanswer_' . $key, $value, 'text', 'maxlength="500"'
                        . ($field['type'] === 'number' ? ' inputmode="decimal"' : ''));
            }
            $out .= '<div class="uw-actions">'
                . ($form['submittedat'] ? '' : '<button name="submitreport" value="0" class="uw-btn">Сохранить черновик</button>')
                . '<button name="submitreport" value="1" class="uw-btn uw-primary">'
                . ($form['submittedat'] ? 'Сохранить изменения' : 'Отправить отчёт') . '</button></div></form>';
        } else {
            $out .= self::answer_list($form['fields'], $form['answers']);
        }
        if ($form['submittedat'] && tasks::can_manage($this->actor)) {
            $out .= '<div class="uw-actions">' . $this->followup($form['id'], $week) . '</div>';
        }
        return $out . '</section>';
    }

    private static function answer_list(array $fields, array $answers): string {
        $out = '<dl class="uw-brief-answers">';
        foreach ($fields as $key => $field) {
            $out .= '<dt>' . s($field['label']) . '</dt><dd>' . s($answers[$key] ?? '') . '</dd>';
        }
        return $out . '</dl>';
    }

    private function followup(int $id, string $week): string {
        $out = $this->link('Создать поручение', ['form' => 'create', 'briefreport' => $id, 'briefweek' => $week]);
        foreach (service::followups($this->actor, $id) as $taskid => $title) {
            $out .= $this->link($title, ['taskid' => $taskid, 'briefweek' => $week], 'uw-chip');
        }
        return $out;
    }

    private function summary(string $week, bool $present): string {
        $departments = service::departments();
        $rows = service::summary($this->actor, $week);
        $submitted = count(array_filter($rows, static fn($row) => $row['submittedat']));
        $archived = service::week_start($week) + service::MEETING_DURATION <= time();
        $out = '<section class="uw-panel uw-brief-summary' . ($present ? ' is-presentation' : '') . '"><div class="uw-panel-head">'
            . '<div><h2>Отчёты отделов</h2><p>Сдано ' . $submitted . ($archived ? '' : ' из ' . count($rows)) . '</p></div>'
            . '<div class="uw-brief-controls">'
            . $this->link($present ? 'К сводке' : 'Режим показа', ['briefweek' => $week, 'briefsection' => $present ? 'summary' : 'present'])
            . ($present ? '' : $this->link('Скачать CSV', ['briefweek' => $week, 'briefexport' => 1]))
            . '</div></div>';
        if (!$rows) {
            $out .= '<div class="uw-empty">' . ($archived ? 'На эту неделю отчётов нет.'
                : 'Сводка появится после включения форм для отделов.') . '</div>';
        }
        $out .= '<nav class="uw-filters uw-brief-controls" aria-label="Отделы">';
        foreach ($rows as $department => $row) {
            $out .= \html_writer::link('#brief-' . $row['template']->id, s($departments[$department]['name'] ?? $department),
                ['class' => 'uw-chip']);
        }
        $out .= '</nav>';
        foreach ($rows as $department => $row) {
            $label = $departments[$department]['name'] ?? $department;
            $status = service::report_status($row);
            $out .= '<article class="uw-brief-department" id="brief-' . $row['template']->id . '"><div class="uw-panel-head">'
                . '<h3>' . s($label) . '</h3><span class="uw-badge' . ($row['submittedat'] ? ' uw-success' : '') . '">'
                . s($status) . '</span></div>';
            if ($row['report']) {
                $schema = service::decode($row['report']->schemajson);
                $out .= self::answer_list($schema['fields'], service::decode($row['report']->answersjson));
                if (!$present && tasks::can_manage($this->actor)) {
                    $out .= '<div class="uw-actions uw-brief-controls">' . $this->followup((int)$row['report']->id, $week) . '</div>';
                }
            }
            $out .= '</article>';
        }
        return $out . '</section>';
    }

    private function minutes(string $week, bool $admin): string {
        $minutes = service::minutes($this->actor, $week);
        $out = '<section class="uw-panel"><div class="uw-panel-head"><h2>Протокол совещания</h2></div>';
        if ($admin) {
            $out .= $this->post('brief_minutes', ['briefweek' => $week, 'briefsection' => 'minutes'])
                . self::hidden('briefrevision', $minutes->revision ?? 0)
                . $this->text('Решения, договорённости и план на следующую неделю', 'briefminutes', $minutes->content ?? '', 24000)
                . '<div class="uw-actions">' . self::button('Сохранить протокол') . '</div></form>';
        } else {
            $out .= '<div class="uw-brief-copy">' . s($minutes->content ?? 'Протокол ещё не опубликован.') . '</div>';
        }
        return $out . '</section>';
    }

    private function settings(string $week): string {
        $departments = service::departments();
        $selected = $this->state['briefdept'];
        $department = isset($departments[$selected]) ? $selected : (string)array_key_first($departments);
        $params = ['briefweek' => $week, 'briefsection' => 'settings'];
        $choices = [0 => 'Без закреплённой переговорной'];
        foreach (meetings::rooms($this->actor) as $room) {
            $choices[$room->id] = $room->name;
        }
        $out = '<section class="uw-panel"><h2>Расписание</h2><p>По понедельникам, с 10:00 до 11:00 МСК</p>'
            . $this->post('brief_schedule', $params)
            . '<div class="uw-form-grid">'
            . $this->field('Сдать за, минут до брифинга', 'deadline', service::due_minutes(), 'number', 'min="0" max="8640" required')
            . self::select('Переговорная на каждую неделю', 'briefroom', $choices, (int)get_config('local_ustar', 'briefing_room'))
            . '</div><div class="uw-actions">' . self::button('Сохранить расписание')
            . $this->link('Настроить переговорные', ['view' => 'meetings', 'meetingsection' => 'rooms']) . '</div></form></section>';
        if (!$department) {
            return $out;
        }

        $template = service::template($department);
        $fields = $template ? service::decode($template->schemajson) : service::defaults();
        if (isset($this->state['input']['brief_label'])) {
            $fields = [];
            foreach (array_slice($this->state['input']['brief_label'], 0, service::MAX_FIELDS) as $key => $label) {
                $fields[] = ['label' => $label, 'type' => $this->state['input']['brief_type'][$key] ?? 'long',
                    'required' => (bool)($this->state['input']['brief_required'][$key] ?? 0)];
            }
        }
        $input = $this->state['input'] ?? [];
        $active = isset($input['brieftitle']) ? !empty($input['briefactive']) : ($template->active ?? true);
        $out .= '<section class="uw-panel"><h2>Форма отдела</h2><form method="get" class="uw-form" action="'
            . (new \moodle_url('/local/ustar/tasks.php'))->out() . '">'
            . self::hidden('view', 'briefing') . self::hidden('scope', $this->state['scope'])
            . self::hidden('briefweek', $week) . self::hidden('briefsection', 'settings')
            . self::select('Отдел', 'briefdept', array_column($departments, 'name', 'id'), $department)
            . '<div class="uw-actions">' . self::button('Открыть форму', false) . '</div></form>'
            . $this->post('brief_template', $params + ['briefdept' => $department])
            . self::hidden('briefrevision', $template->revision ?? 0)
            . '<div class="uw-form-grid">' . $this->field('Заголовок', 'brieftitle', $template->title ?? 'Еженедельный отчёт', 'text',
                'maxlength="200" required')
            . $this->field('Порядок в сводке', 'brieforder', $template->sortorder ?? 100, 'number', 'min="0" max="9999" required')
            . '</div><label class="uw-check"><input name="briefactive" value="1" type="checkbox"' . ($active ? ' checked' : '')
            . '> Показывать форму руководителю</label><div data-brief-fields>';
        foreach ($fields as $field) {
            $out .= $this->template_field($field);
        }
        $out .= '</div><template data-brief-field>' . $this->template_field(['label' => '', 'type' => 'long', 'required' => false])
            . '</template><div class="uw-actions"><button type="button" class="uw-btn" data-add-brief-field>Добавить вопрос</button>'
            . self::button('Сохранить форму') . '</div><p class="uw-muted">Начатые отчёты сохраняют свою версию формы.</p></form></section>';
        return $out;
    }

    private function template_field(array $field): string {
        return '<div class="uw-builder-row uw-brief-field">'
            . $this->field('Вопрос', 'brief_label[]', $field['label'], 'text', 'required maxlength="150"', false)
            . self::select('Тип ответа', 'brief_type[]', service::FIELD_TYPES, $field['type'])
            . self::select('Обязательность', 'brief_required[]', [0 => 'Необязательный', 1 => 'Обязательный'], (int)$field['required'])
            . '<button type="button" class="uw-btn" data-move-up aria-label="Переместить вопрос выше">↑</button>'
            . '<button type="button" class="uw-btn" data-remove-brief-field aria-label="Удалить вопрос">×</button></div>';
    }

    public function meetings(): string {
        $section = $this->state['meetingsection'];
        $date = $this->state['meetingdate'] ?: calendar::date(time(), service::ZONE);
        try {
            $busy = meetings::busy($this->actor, $date);
        } catch (\invalid_parameter_exception $error) {
            $date = calendar::date(time(), service::ZONE);
            $busy = meetings::busy($this->actor, $date);
        }
        $rooms = meetings::rooms($this->actor);
        $id = $this->state['meetingid'];
        $detail = $id ? meetings::detail($this->actor, $id) : null;
        $out = '<section class="uw-panel"><div class="uw-panel-head"><div><h2>Встречи</h2><p>Переговорные и приглашения</p></div>'
            . '<div class="uw-actions">' . $this->link('Мои встречи')
            . $this->link('Календарь задач', ['view' => 'calendar']);
        if (meetings::can_book($this->actor)) {
            $out .= $this->link('Назначить встречу', ['meetingsection' => 'edit', 'meetingdate' => $date], 'uw-btn uw-primary');
        }
        if (service::admin($this->actor)) {
            $out .= $this->link('Настроить переговорные', ['meetingsection' => 'rooms']);
        }
        $out .= '</div></div></section>';
        if ($section === 'rooms' && service::admin($this->actor)) {
            return $out . $this->rooms();
        }
        if ($section === 'edit' && meetings::can_book($this->actor)
                && (!$detail || ($detail['owner'] && !$detail['meeting']->cancelled && $detail['meeting']->startat > time()))) {
            $out .= $this->meeting_form($detail, $rooms, $date);
        } else if ($detail) {
            $out .= $this->meeting_detail($detail);
        }

        $out .= '<section class="uw-panel"><div class="uw-panel-head"><h2>Занятость переговорных</h2></div>'
            . '<form method="get" class="uw-form" action="' . (new \moodle_url('/local/ustar/tasks.php'))->out() . '">'
            . self::hidden('view', 'meetings') . self::hidden('scope', $this->state['scope'])
            . '<div class="uw-actions">' . $this->field('Дата (МСК)', 'meetingdate', $date, 'date', 'required')
            . self::button('Показать занятость', false) . '</div></form>';
        foreach ($rooms as $room) {
            $slots = [];
            foreach ($busy[(int)$room->id] ?? [] as [$start, $end]) {
                $slots[] = self::date($start, '%H:%M') . '–' . (calendar::date($end, service::ZONE) !== $date ? '24:00' : self::date($end, '%H:%M'));
            }
            $out .= '<div class="uw-brief-room"><strong>' . s($room->name) . '</strong><span>'
                . ($slots ? 'Занято ' . implode(', ', $slots) : 'Свободна весь день') . '</span></div>';
            if (meetings::can_book($this->actor)) {
                $out .= $this->booking_slots($date, (int)$room->id, $busy[(int)$room->id] ?? []);
            }
        }
        if (!$rooms) {
            $out .= '<div class="uw-empty">Переговорные пока не настроены.</div>';
        }
        $upcoming = meetings::visible($this->actor, time(), time() + 90 * DAYSECS, true);
        $out .= '</section><section class="uw-panel"><div class="uw-panel-head"><h2>Мои встречи на ближайшие 90 дней</h2></div>';
        foreach ($upcoming as $meeting) {
            $summary = self::date($meeting->startat) . ' МСК · ' . s($meeting->roomname ?? 'Без переговорной');
            $out .= '<div class="uw-brief-room">' . $this->link($meeting->title, ['meetingid' => $meeting->id], 'uw-chip')
                . '<span>' . $summary . '</span><span class="uw-badge">'
                . s($meeting->cancelled ? 'Отменена' : (meetings::RESPONSES[$meeting->rsvp] ?? 'Организатор')) . '</span></div>';
        }
        if (!$upcoming) {
            $out .= '<div class="uw-empty">Предстоящих встреч пока нет.</div>';
        }
        return $out . '</section>';
    }

    private function booking_slots(string $date, int $room, array $busy): string {
        $start = calendar::timestamp($date, '08:00', service::ZONE);
        $end = calendar::timestamp($date, '19:00', service::ZONE);
        $out = '<div class="uw-slot-list" aria-label="Свободные интервалы">';
        $cursor = max($start, (int)(ceil((time() + 1) / (30 * MINSECS)) * 30 * MINSECS));
        foreach (array_merge($busy, [[$end, $end]]) as [$from, $to]) {
            $until = min($from, $end);
            if ($until - $cursor >= 30 * MINSECS) {
                $out .= $this->link('Свободно ' . userdate($cursor, '%H:%M', service::ZONE) . '–'
                    . userdate($until, '%H:%M', service::ZONE), ['meetingsection' => 'edit', 'meetingdate' => $date,
                        'slotroom' => $room, 'slotstart' => $cursor, 'slotend' => min($until, $cursor + HOURSECS)], 'uw-chip');
            }
            $cursor = max($cursor, $to);
        }
        return $out . '</div>';
    }

    private function rooms(): string {
        $out = '<section class="uw-panel"><div class="uw-panel-head"><h2>Настройка переговорных</h2></div>';
        foreach (array_values(meetings::rooms($this->actor, true)) as $room) {
            $out .= $this->room_form($room);
        }
        return $out . '<h3>Новая переговорная</h3>' . $this->room_form(null) . '</section>';
    }

    private function room_form(?\stdClass $room): string {
        return $this->post('meeting_room', ['meetingsection' => 'rooms'])
            . self::hidden('roomid', $room->id ?? 0) . self::hidden('briefrevision', $room->revision ?? 0)
            . '<div class="uw-form-grid">' . $this->field('Название', 'roomname', $room->name ?? '', 'text', 'maxlength="150" required', false)
            . $this->field('Мест (0 — без ограничения)', 'capacity', $room->capacity ?? 0, 'number', 'min="0" max="1000" required', false)
            . '</div><label class="uw-check"><input type="checkbox" name="roomactive" value="1"'
            . (($room->active ?? true) ? ' checked' : '') . '> Доступна для бронирования</label><div class="uw-actions">'
            . self::button($room ? 'Сохранить' : 'Добавить переговорную') . '</div></form>';
    }

    private function meeting_form(?array $detail, array $rooms, string $date): string {
        $row = $detail['meeting'] ?? null;
        $choices = [0 => 'Без переговорной'];
        foreach ($rooms as $room) {
            $choices[$room->id] = $room->name . ($room->capacity ? ' · ' . $room->capacity . ' мест' : '');
        }
        $slotstart = $this->state['slotstart'] ?? 0;
        $slotend = $this->state['slotend'] ?? 0;
        $slotroom = $this->state['slotroom'] ?? 0;
        $defaultstart = max(calendar::timestamp($date, '10:00', service::ZONE),
            (int)(ceil((time() + 1) / (30 * MINSECS)) * 30 * MINSECS));
        $input = $this->state['input'] ?? [];
        $selected = array_map('intval', $input['participants'] ?? array_column($detail['participants'] ?? [], 'userid'));
        $task = !$row && !empty($this->state['taskref'])
            ? discussion::readable($this->actor, (int)$this->state['taskref']) : null;
        if ($task && !$input) {
            $selected = [(int)$task->assigneeid];
        }
        $out = '<section class="uw-panel"><div class="uw-panel-head"><h2>' . ($row ? 'Редактировать встречу' : 'Назначить встречу')
            . '</h2></div>' . $this->post('meeting_save', ['meetingsection' => 'edit', 'meetingid' => $row->id ?? 0, 'meetingdate' => $date])
            . self::hidden('briefrevision', $row->revision ?? 0) . self::hidden('meetingkey', $input['meetingkey'] ?? random_string(32))
            . $this->field('Название', 'meetingtitle', $row->title ?? $task->title ?? '', 'text', 'required maxlength="200"')
            . $this->text('Повестка', 'agenda', $row->agenda ?? '', 12000)
            . '<div class="uw-form-grid">'
            . $this->field('Начало (МСК)', 'starttime', self::local($row->startat ?? ($slotstart ?: $defaultstart)), 'datetime-local', 'required')
            . $this->field('Окончание (МСК)', 'endtime', self::local($row->endat ?? ($slotend ?: $defaultstart + HOURSECS)), 'datetime-local', 'required')
            . self::select('Переговорная', 'roomid', $choices, $input['roomid'] ?? $row->roomid ?? $slotroom)
            . '</div><fieldset class="uw-people"><legend>Участники</legend>'
            . '<label class="uw-field"><span>Найти сотрудника</span><input type="search" data-meeting-person-filter></label>'
            . '<div class="uw-meeting-people">';
        foreach (meetings::eligible_participants($this->actor) as $id => $name) {
            $out .= '<label class="uw-check" data-meeting-person><input type="checkbox" name="participants[]" value="' . $id . '"'
                . (in_array($id, $selected, true) ? ' checked' : '') . '> ' . s($name) . '</label>';
        }
        $api = new \moodle_url('/local/ustar/meeting_availability.php');
        return $out . '</div></fieldset><div class="uw-meeting-check" data-meeting-check="' . $api->out() . '" data-meeting-id="'
            . (int)($row->id ?? 0) . '"><button type="button" class="uw-btn" hidden>Проверить занятость</button>'
            . '<div class="uw-meeting-check-result" role="status"></div></div><div class="uw-actions">'
            . self::button($row ? 'Сохранить встречу' : 'Отправить приглашения')
            . '</div></form></section>';
    }

    private static function local(int $at): string {
        return (new \DateTimeImmutable('@' . $at))->setTimezone(new \DateTimeZone(service::ZONE))->format('Y-m-d\TH:i');
    }

    private function meeting_detail(array $detail): string {
        $row = $detail['meeting'];
        $params = ['meetingid' => $row->id];
        $out = '<section class="uw-panel"><div class="uw-panel-head"><div><h2>' . s($row->title) . '</h2><p>'
            . self::date($row->startat) . ' – ' . self::date($row->endat) . ' МСК · '
            . s(meetings::rooms($this->actor)[$row->roomid]->name ?? 'Без переговорной')
            . '</p><p>Организатор: ' . s(tasks::name((int)$row->ownerid)) . '</p></div>'
            . ($row->cancelled ? '<span class="uw-badge uw-danger">Встреча отменена</span>' : '') . '</div>'
            . '<div class="uw-brief-copy">' . s($row->agenda) . '</div>';
        if ($detail['owner'] && $detail['participants']) {
            $out .= '<h3>Приглашённые</h3>';
            foreach ($detail['participants'] as $person) {
                $out .= '<div class="uw-brief-room"><span>' . s(tasks::name((int)$person->userid)) . '</span><span>'
                    . s(meetings::RESPONSES[$person->rsvp]) . '</span></div>';
            }
        }
        if (!$row->cancelled && $row->startat > time()) {
            if ($detail['owner']) {
                $out .= '<div class="uw-actions">' . $this->link('Редактировать', $params + ['meetingsection' => 'edit'])
                    . $this->post('meeting_cancel', $params) . self::hidden('briefrevision', $row->revision)
                    . self::button('Отменить встречу', false) . '</form></div>';
            } else if ($detail['member']) {
                $out .= $this->post('meeting_rsvp', $params) . self::hidden('briefrevision', $row->revision)
                    . self::hidden('expectedrsvp', $detail['member']->rsvp)
                    . '<p>' . s(meetings::RESPONSES[$detail['member']->rsvp]) . '</p>'
                    . '<div class="uw-actions"><button class="uw-btn uw-primary" name="rsvp" value="accepted">Приму участие</button>'
                    . '<button class="uw-btn" name="rsvp" value="declined">Не смогу</button></div></form>';
            }
        }
        return $out . '</section>';
    }
}
