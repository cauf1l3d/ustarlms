<?php
namespace local_ustar\task_workspace;
use local_ustar\{learning_tasks, forced_retraining};
defined('MOODLE_INTERNAL') || die();

/** Presentation only. Every mutation is a POST to the domain commands. */
final class page {
    private static array $state;
    private static int $actor;
    private static array $status = ['assigned' => 'Назначена', 'in_progress' => 'В работе',
        'in_review' => 'На проверке', 'completed' => 'Принята', 'cancelled' => 'Отменена'];

    public static function render(int $actor, array $state): string {
        self::$actor = $actor; self::$state = $state;
        $manage = service::can_manage($actor);
        $out = '<div class="u-workspace"><header class="uw-heading"><div><div class="uw-eyebrow">Рабочее пространство</div>
            <h1>Задачи и контроль</h1><p>Поручения, ежедневные проверки и результаты команды.</p></div>';
        if ($manage) { $out .= self::link('Создать задачу', ['form' => 'create', 'taskid' => 0], 'uw-btn uw-primary'); }
        $out .= '</header><nav class="uw-tabs" aria-label="Разделы задач">';
        $tabs = ['overview' => $manage ? 'Обзор' : 'Мой день', 'calendar' => 'Календарь', 'checklists' => 'Чек-листы'];
        if ($manage) { $tabs['control'] = 'Контроль'; }
        $tabs['analytics'] = $manage ? 'Аналитика' : 'Мои результаты';
        if ($manage) { $tabs['rules'] = 'Правила'; }
        foreach ($tabs as $key => $label) {
            $out .= self::link($label, ['view' => $key, 'form' => '', 'taskid' => 0, 'pageno' => 0],
                'uw-tab' . ($state['view'] === $key ? ' is-active' : ''));
        }
        $out .= '</nav><div class="uw-scope">';
        if ($manage) {
            foreach (['mine' => 'Назначено мне', 'team' => 'Область управления', 'outgoing' => 'Мои поручения'] as $key => $label) {
                $out .= self::link($label, ['scope' => $key, 'pageno' => 0], 'uw-chip' . ($state['scope'] === $key ? ' is-active' : ''));
            }
        } else { $out .= '<span>Мои задачи</span>'; }
        $out .= '<span class="uw-scope-links"><a href="' . (new \moodle_url('/local/ustar/tasks.php', ['tab' => 'notebook']))->out() . '">Личный блокнот</a>
            <a href="' . (new \moodle_url('/local/ustar/tasks.php', ['tab' => 'checklists']))->out() . '">Адаптационные листы</a></span></div>';
        if (!isset($tabs[$state['view']])) { $out .= '<div class="uw-panel">Раздел недоступен для вашей роли.</div>'; }
        else {
            switch ($state['view']) {
                case 'calendar': $out .= self::calendar(); break;
                case 'checklists': $out .= self::checklists(); break;
                case 'control': $out .= self::control(); break;
                case 'analytics': $out .= self::analytics(); break;
                case 'rules': $out .= self::rules(); break;
                default: $out .= self::overview();
            }
        }
        if ($state['form'] === 'create' && $manage) { $out .= self::create_form(); }
        if ($state['form'] === 'template' && $manage) { $out .= self::template_form(); }
        if ($state['taskid']) {
            try { $out .= self::detail($state['taskid']); }
            catch (\required_capability_exception $e) { $out .= '<div class="uw-notice">Задача недоступна в вашей текущей области.</div>'; }
        }
        return $out . '</div>';
    }

    private static function url(array $changes = []): \moodle_url {
        $s = self::$state;
        $params = ['view' => $s['view'], 'scope' => $s['scope'], 'filter' => $s['filter'], 'month' => $s['month'],
            'q' => $s['q'], 'pageno' => $s['page']];
        return new \moodle_url('/local/ustar/tasks.php', array_merge($params, $changes));
    }
    private static function link(string $label, array $params, string $class = 'uw-btn'): string {
        return \html_writer::link(self::url($params), s($label), ['class' => $class]);
    }
    private static function hidden(string $name, $value): string {
        return \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
    }
    private static function form(string $action): string {
        return '<form method="post" enctype="multipart/form-data" action="' . self::url()->out() . '" class="uw-form">'
            . self::hidden('sesskey', sesskey()) . self::hidden('action', $action);
    }
    private static function field(string $label, string $name, $value = '', string $type = 'text', string $extra = ''): string {
        if (isset(self::$state['input'][$name]) && is_string(self::$state['input'][$name]) && $type !== 'file') { $value = self::$state['input'][$name]; }
        return '<label class="uw-field"><span>' . s($label) . '</span><input type="' . s($type) . '" name="' . s($name)
            . '" value="' . s((string)$value) . '" ' . $extra . '></label>';
    }
    private static function select(string $label, string $name, array $options, $selected): string {
        if (isset(self::$state['input'][$name]) && is_string(self::$state['input'][$name])) { $selected = self::$state['input'][$name]; }
        return '<label class="uw-field"><span>' . s($label) . '</span>'
            . \html_writer::select($options, $name, $selected, false, ['class' => 'uw-select']) . '</label>';
    }
    private static function textarea(string $label, string $name, string $value = ''): string {
        if (isset(self::$state['input'][$name]) && is_string(self::$state['input'][$name])) { $value = self::$state['input'][$name]; }
        return '<label class="uw-field"><span>' . s($label) . '</span><textarea name="' . s($name) . '" rows="3">' . s($value) . '</textarea></label>';
    }
    private static function buttons(string $label): string { return '<div class="uw-actions"><button type="submit" class="uw-btn uw-primary">' . s($label) . '</button></div>'; }
    private static function badge(array $task): string {
        $class = ($task['late'] || $task['reviewlate']) ? 'uw-danger' : ($task['status'] === 'completed' ? 'uw-success' : ($task['status'] === 'in_review' ? 'uw-info' : ''));
        return '<span class="uw-badge ' . $class . '">' . s($task['reviewlate'] ? 'Просрочена проверка' : ($task['late'] ? 'Просрочена' : (self::$status[$task['status']] ?? $task['status']))) . '</span>';
    }
    private static function due(array $task): string {
        return $task['dueat'] ? userdate($task['dueat'], '%d.%m · %H:%M', $task['timezone']) : 'Без срока';
    }
    private static function row(array $task): string {
        $type = ['checklist' => 'Чек-лист', 'task' => 'Поручение', 'retraining' => 'Обучение'][$task['kind']] ?? 'Поручение';
        $icon = $task['kind'] === 'checklist' ? '☷' : ($task['kind'] === 'retraining' ? '↻' : '✓');
        return '<a class="uw-task" href="' . self::url(['taskid' => $task['id']])->out() . '"><span class="uw-task-icon" aria-hidden="true">' . $icon . '</span>
            <span class="uw-grow"><strong>' . s($task['title']) . '</strong><span class="uw-task-meta">' . s($task['assignee']) . ' · '
            . s(self::due($task)) . ' · ' . $type . ($task['photo'] ? ' · Фотоотчёт' : '') . '</span></span>' . self::badge($task) . '</a>';
    }
    private static function pagination(array $rows): string {
        $out = '<div class="uw-pagination"><span>Всего: ' . $rows['total'] . ' · страница ' . ($rows['page'] + 1) . '</span>';
        if ($rows['page']) { $out .= self::link('Назад', ['pageno' => $rows['page'] - 1]); }
        if ($rows['hasmore']) { $out .= self::link('Следующие 50', ['pageno' => $rows['page'] + 1]); }
        return $out . '</div>';
    }
    private static function stats(array $summary): string {
        return '<div class="uw-stats"><div><span>В работе</span><strong>' . $summary['active'] . '</strong><small>Активные поручения</small></div>
            <div><span>На проверке</span><strong>' . $summary['review'] . '</strong><small>Ожидают решения</small></div>
            <div><span>Требуют внимания</span><strong class="uw-red-text">' . $summary['late'] . '</strong><small>Просрочено исполнение</small></div></div>';
    }
    private static function overview(): string {
        $s = self::$state;
        $rows = service::rows(self::$actor, $s['scope'], $s['filter'], $s['page'], 0, 0, $s['q']);
        $out = self::stats(service::summary(self::$actor, $s['scope']));
        $out .= '<section class="uw-panel"><div class="uw-panel-head"><h2>Рабочие задачи</h2><span class="uw-muted">' . $rows['total'] . '</span></div><div class="uw-filters">';
        foreach (['all' => 'Все', 'active' => 'В работе', 'review' => 'На проверке', 'late' => 'Просроченные', 'done' => 'Завершённые'] as $key => $label) {
            $out .= self::link($label, ['filter' => $key, 'pageno' => 0], 'uw-chip' . ($s['filter'] === $key ? ' is-active' : ''));
        }
        $out .= '</div><form method="get" class="uw-search">' . self::hidden('scope', $s['scope']) . self::hidden('view', 'overview')
            . self::field('Найти задачу', 'q', $s['q'], 'search') . '<button class="uw-btn">Найти</button></form>';
        foreach ($rows['items'] as $task) { $out .= self::row($task); }
        if (!$rows['items']) { $out .= '<div class="uw-empty">Задач по выбранному фильтру пока нет.</div>'; }
        return $out . '</section>' . self::pagination($rows);
    }
    private static function month(): array {
        $value = self::$state['month'];
        if (!preg_match('/^\d{4}-\d{2}$/', $value)) { $value = date('Y-m'); }
        try { $start = calendar::timestamp($value . '-01', '00:00', service::policy_for(self::$actor)['timezone']); }
        catch (\invalid_parameter_exception $e) { $value = date('Y-m'); $start = calendar::timestamp($value . '-01', '00:00', 'Europe/Moscow'); }
        $tz = calendar::timezone(service::policy_for(self::$actor)['timezone']);
        $date = (new \DateTimeImmutable('@' . $start))->setTimezone($tz);
        return [$date, $date->modify('+1 month')->getTimestamp()];
    }
    private static function calendar(): string {
        [$first, $end] = self::month(); $s = self::$state;
        $rows = service::rows(self::$actor, $s['scope'], 'all', $s['page'], $first->getTimestamp(), $end);
        $events = [];
        foreach ($rows['items'] as $task) { $day = calendar::date($task['dueat'], $first->getTimezone()->getName()); $events[$day][] = $task; }
        $out = '<section class="uw-panel"><div class="uw-panel-head"><div><h2>' . s(userdate($first->getTimestamp(), '%B %Y', $first->getTimezone()->getName())) . '</h2>
            <small>Сроки в часовом поясе ' . s($first->getTimezone()->getName()) . '</small></div><div class="uw-actions">'
            . self::link('‹', ['month' => $first->modify('-1 month')->format('Y-m'), 'pageno' => 0])
            . self::link('Сегодня', ['month' => date('Y-m'), 'pageno' => 0])
            . self::link('›', ['month' => $first->modify('+1 month')->format('Y-m'), 'pageno' => 0]) . '</div></div><div class="uw-calendar">';
        foreach (['ПН','ВТ','СР','ЧТ','ПТ','СБ','ВС'] as $day) { $out .= '<div class="uw-dayhead">' . $day . '</div>'; }
        $offset = (int)$first->format('N') - 1; $count = (int)(ceil(($offset + (int)$first->format('t')) / 7) * 7);
        for ($i = 0; $i < $count; $i++) {
            $date = $first->modify(($i - $offset) . ' days'); $key = $date->format('Y-m-d');
            $out .= '<div class="uw-day' . ($key === calendar::date(time(), $first->getTimezone()->getName()) ? ' is-today' : '')
                . ($date->format('m') !== $first->format('m') ? ' is-outside' : '') . '"><span class="uw-daynum">' . $date->format('j') . '</span>';
            foreach ($events[$key] ?? [] as $task) {
                $out .= '<a class="uw-event ' . ($task['late'] ? 'is-late' : '') . '" href="' . self::url(['taskid' => $task['id']])->out() . '">'
                    . s(userdate($task['dueat'], '%H:%M', $first->getTimezone()->getName())) . ' · ' . s($task['title']) . '</a>';
            }
            $out .= '</div>';
        }
        $out .= '</div></section>' . self::pagination($rows);
        if ($rows['hasmore']) { $out .= '<div class="uw-notice">В этом месяце больше 50 задач. Перейдите к следующей странице, чтобы увидеть остальные.</div>'; }
        return $out;
    }
    private static function checklists(): string {
        $s = self::$state;
        $rows = service::rows(self::$actor, $s['scope'], 'all', $s['page'], 0, 0, '', 'checklist');
        $out = '<div class="uw-panel-head"><div><h2>Чек-листы рабочего дня</h2><p>Каждое выполнение — отдельный отчёт.</p></div>';
        if (service::can_manage(self::$actor)) { $out .= self::link('Новый шаблон', ['form' => 'template', 'templateid' => 0]); }
        $out .= '</div><section class="uw-panel">';
        foreach ($rows['items'] as $task) { $out .= self::row($task); }
        if (!$rows['items']) { $out .= '<div class="uw-empty">Назначенных рабочих чек-листов пока нет.</div>'; }
        $out .= '</section>' . self::pagination($rows);
        if (service::can_manage(self::$actor)) {
            $out .= '<h2 class="uw-section-title">Мои шаблоны</h2><div class="uw-template-grid">';
            foreach (service::templates(self::$actor) as $template) {
                $out .= '<article class="uw-panel uw-template"><span class="uw-badge">Шаблон · v' . $template->revision . '</span><h3>' . s($template->title) . '</h3>
                    <div class="uw-actions">' . self::link('Редактировать', ['form' => 'template', 'templateid' => $template->id])
                    . self::link('Назначить', ['form' => 'create', 'templateid' => $template->id], 'uw-btn uw-primary') . '</div></article>';
            }
            $out .= '</div><h2 class="uw-section-title">Повторяемые серии</h2><div class="uw-panel">';
            foreach (service::series(self::$actor) as $series) {
                $out .= '<div class="uw-series"><div><strong>' . s($series->title) . '</strong><p>' . s(service::name($series->assigneeid)) . ' · '
                    . s(['active'=>'Активна','paused'=>'Приостановлена','cancelled'=>'Отменена','finished'=>'Завершена'][$series->status]) . ' · до ' . s($series->enddate) . '</p></div>';
                if (!in_array($series->status, ['cancelled','finished'], true)) {
                    $out .= self::form('series') . self::hidden('seriesid', $series->id) . self::hidden('revision', $series->revision)
                        . '<div class="uw-actions"><button name="seriesstatus" value="' . ($series->status === 'active' ? 'paused' : 'active') . '" class="uw-btn">'
                        . ($series->status === 'active' ? 'Приостановить' : 'Возобновить') . '</button><button name="seriesstatus" value="cancelled" class="uw-btn">Остановить серию</button></div></form>';
                }
                $out .= '</div>';
            }
            $out .= '</div><p class="uw-footnote">Остановка серии прекращает новые назначения. Уже созданные выполнения отменяются отдельно с причиной.</p>';
        }
        return $out;
    }
    private static function control(): string {
        $s = self::$state;
        $out = '<h2 class="uw-section-title">Результаты к проверке</h2><section class="uw-panel">';
        $rows = service::rows(self::$actor, $s['scope'], 'review', $s['page']);
        foreach ($rows['items'] as $task) { $out .= self::row($task); }
        if (!$rows['items']) { $out .= '<div class="uw-empty">Нет отчётов, ожидающих решения.</div>'; }
        $out .= '</section>' . self::pagination($rows) . '<h2 class="uw-section-title">Просрочено исполнение</h2><section class="uw-panel">';
        $late = service::rows(self::$actor, $s['scope'], 'late', $s['page']);
        foreach ($late['items'] as $task) { $out .= self::row($task); }
        if (!$late['items']) { $out .= '<div class="uw-empty">Просроченных задач нет.</div>'; }
        return $out . '</section>' . self::pagination($late) . '<div class="uw-notice">Срок проверки учитывается отдельно. Задержка проверяющего не превращает вовремя сданный отчёт в просрочку исполнителя.</div>';
    }
    private static function analytics(): string {
        [$first, $end] = self::month();
        $rows = service::analytics(self::$actor, self::$state['scope'], $first->getTimestamp(), $end);
        $planned = array_sum(array_column($rows, 'plannedweight')); $accepted = array_sum(array_column($rows, 'acceptedweight'));
        $out = '<div class="uw-panel-head"><h2>Результаты за ' . s($first->format('m.Y')) . '</h2><div class="uw-actions">'
            . self::link('‹', ['month' => $first->modify('-1 month')->format('Y-m')]) . self::link('›', ['month' => $first->modify('+1 month')->format('Y-m')]) . '</div></div>';
        $out .= '<div class="uw-stats"><div><span>Выполнение KPI</span><strong>' . ($planned ? round($accepted / $planned * 100) : '—') . ($planned ? '%' : '') . '</strong><small>'
            . $accepted . ' из ' . $planned . ' баллов к текущему сроку</small></div><div><span>Принято</span><strong>' . array_sum(array_column($rows,'accepted'))
            . '</strong><small>Подтверждённые результаты</small></div><div><span>На проверке</span><strong>' . array_sum(array_column($rows,'review')) . '</strong><small>Не включены в принятые баллы</small></div></div>';
        $out .= '<div class="uw-panel uw-table-wrap"><table class="uw-table"><thead><tr><th>Сотрудник</th><th>Принято / к сроку</th><th>Вовремя принято</th><th>На проверке</th><th>Просрочено</th></tr></thead><tbody>';
        foreach ($rows as $row) { $out .= '<tr><td>' . s($row['fullname']) . '</td><td>' . $row['accepted'] . ' / ' . $row['due'] . '</td><td>' . $row['ontime']
            . '</td><td>' . $row['review'] . '</td><td>' . $row['late'] . '</td></tr>'; }
        if (!$rows) { $out .= '<tr><td colspan="5">Нет назначений с наступившим сроком.</td></tr>'; }
        return $out . '</tbody></table></div><div class="uw-notice">Учитываются задачи с наступившим сроком. Отменённые и будущие назначения исключены. Приватный блокнот не участвует в аналитике.</div>';
    }
    private static function weekdays(array $days): string {
        if (isset(self::$state['input']['weekdays']) && is_array(self::$state['input']['weekdays'])) { $days = array_map('intval', self::$state['input']['weekdays']); }
        $out = '<div class="uw-weekdays"><span>Рабочие дни</span>';
        foreach ([1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб',7=>'Вс'] as $i=>$label) {
            $out .= '<label><input type="checkbox" name="weekdays[]" value="' . $i . '" ' . (in_array($i,$days,true)?'checked':'') . '> ' . $label . '</label>';
        }
        return $out . '</div>';
    }
    private static function rules(): string {
        global $DB;
        $p = service::policy_for(self::$actor);
        $record = $DB->get_record('local_ustar_task_settings',['ownerid'=>self::$actor]);
        $out = '<section class="uw-panel uw-editor"><h2>Моё правило контроля</h2><p class="uw-footnote">Правило закрепляется в новых задачах и сериях. Уже назначенные задачи сохраняют прежнюю версию.</p>'
            . self::form('rules') . self::hidden('revision',$record ? $record->revision : 0)
            . '<div class="uw-form-grid">' . self::field('Часовой пояс','timezone',$p['timezone'])
            . self::select('Учёт времени','mode',['work'=>'По рабочему календарю','calendar'=>'Календарное время'],$p['mode'])
            . self::field('Начало рабочего дня','startclock',$p['startclock'],'time') . self::field('Окончание рабочего дня','endclock',$p['endclock'],'time')
            . self::field('Напомнить исполнителю за минут · 0 выключает','remindminutes',$p['remindminutes'],'number','min="0" max="43200"')
            . self::field('Срок проверки, минут','reviewminutes',$p['reviewminutes'],'number','min="15" max="43200"') . '</div>'
            . self::weekdays($p['weekdays']) . self::textarea('Исключённые даты · ГГГГ-ММ-ДД, по одной на строке','excludedates',implode("\n",$p['excludedates']))
            . '<h3>Цепочка эскалации</h3><div data-rule-levels>';
        if (isset(self::$state['input']['levelminutes']) && is_array(self::$state['input']['levelminutes'])) {
            $p['levels'] = [];
            foreach (array_slice(self::$state['input']['levelminutes'],0,5) as $i => $delay) {
                $p['levels'][] = ['minutes'=>(int)$delay, 'recipient'=>self::$state['input']['levelrecipient'][$i]??'parent'];
            }
        }
        foreach ($p['levels'] as $level) { $out .= self::level_row($level); }
        $out .= '</div><button type="button" class="uw-btn" data-add-level>Добавить уровень</button><template data-level-template>'
            . self::level_row(['recipient'=>'parent','minutes'=>1440]) . '</template><div class="uw-notice">Для проверки цепочка начинается с руководителя проверяющего. Если руководитель не найден, уведомление поступает участникам очереди HRD с явным полномочием.</div>'
            . self::buttons('Сохранить правило') . '</form></section>';
        return $out;
    }
    private static function level_row(array $level): string {
        return '<div class="uw-builder-row">' . self::select('Получатель','levelrecipient[]',
            ['direct'=>'Прямой руководитель','parent'=>'Следующий руководитель','hrd'=>'HRD · исключения'],$level['recipient'])
            . self::field('Через минут после срока','levelminutes[]',$level['minutes'],'number','min="1" max="43200"')
            . '<button type="button" class="uw-btn" data-remove-row aria-label="Удалить уровень">×</button></div>';
    }
    private static function create_form(): string {
        $s = self::$state; $p = service::policy_for(self::$actor);
        $assignee = (int)$s['assigneeid'];
        $department = $s['departmentid'] ?? ''; $position = $s['positionid'] ?? '';
        $options = recipients::options(self::$actor, $department, $position);
        $out = '<section class="uw-editor uw-panel" id="uw-editor"><div class="uw-panel-head"><h2>Новая задача</h2>'
            . self::link('Закрыть', ['form'=>'']) . '</div><form method="get" class="uw-form" data-recipient-filter>'
            . self::hidden('form','create') . self::hidden('scope',$s['scope'])
            . self::hidden('parentid',$s['parentid']) . self::hidden('templateid',$s['templateid'])
            . '<div class="uw-form-grid">'
            . self::select('1. Отдел', 'departmentid', [''=>'Выберите отдел'] + $options['departments'], $department)
            . self::select('2. Должность', 'positionid', [''=>'Выберите должность'] + $options['positions'], $position)
            . self::select('3. Сотрудник', 'assigneeid', [0=>'Выберите сотрудника'] + $options['people'], $assignee)
            . '</div><button class="uw-btn">Применить выбор</button></form>';
        if (!isset($options['people'][$assignee])) {
            return $out . '<div class="uw-empty">Выберите отдел, должность и действующего сотрудника.</div></section>';
        }
        $out .= '<p class="uw-notice">Исполнитель: ' . s($options['people'][$assignee]) . '</p>';
        $topics = [];
        try { foreach (forced_retraining::topics_for_user(self::$actor,$assignee) as $t) { $topics[$t['policyid']] = strip_tags($t['label']); } }
        catch (\required_capability_exception $e) { /* A company read role does not imply remediation authority. */ }
        $templates = [];
        foreach (service::templates(self::$actor) as $t) { $templates[$t->id] = $t->title . ' · v' . $t->revision; }
        $out .= self::form('create') . self::hidden('departmentid',$department) . self::hidden('positionid',$position) . self::hidden('assigneeid',$assignee) . self::hidden('parentid',$s['parentid'])
            . '<div class="uw-notice">Исполнитель: ' . s(service::name($assignee)) . ($s['parentid']?' · связанное поручение #'.$s['parentid']:'') . '</div>'
            . self::select('Тип задачи','kind',['task'=>'Поручение','checklist'=>'Чек-лист','retraining'=>'Повторное обучение'],$s['templateid']?'checklist':'task')
            . self::field('Название','title','', 'text','required maxlength="255"')
            . '<div data-kind="checklist">' . self::select('Шаблон','templateid',[0=>'Выберите шаблон']+$templates,$s['templateid']) . '</div>'
            . '<div data-kind="retraining">' . self::select('Пройденный материал и его аттестация','policyid',[0=>'Выберите тему']+$topics,0)
            . '<small>Доступны только подтверждённые ранее прохождения выбранного сотрудника. Если список пуст, назначение недоступно.</small></div>'
            . self::textarea('Описание и критерий результата','description')
            . '<div class="uw-form-grid">' . self::field('Дата исполнения','duedate',calendar::date(time()+DAYSECS,$p['timezone']),'date','required')
            . self::field('Время исполнения','dueclock','17:00','time','required') . self::field('Часовой пояс','timezone',$p['timezone'])
            . self::field('KPI: баллы за принятие','kpiweight',10,'number','min="0" max="10000"') . '</div>'
            . '<label class="uw-check"><input type="checkbox" name="requirephoto" value="1" ' . (!empty(self::$state['input']['requirephoto'])?'checked':'') . '> Фотоотчёт обязателен</label>'
            . '<div data-repeat-section><label class="uw-check"><input type="checkbox" name="repeat" value="1" ' . (!empty(self::$state['input']['repeat'])?'checked':'') . '> Создать повторяемую серию</label>'
            . self::field('Завершить серию','enddate',calendar::date(time()+30*DAYSECS,$p['timezone']),'date') . self::weekdays($p['weekdays'])
            . self::textarea('Исключённые даты серии · по одной на строке','excludedates',implode("\n",$p['excludedates'])) . '</div>'
            . self::field('Вложения к поручению','attachments[]','','file','multiple')
            . '<div class="uw-notice">Результат проверяет действующий прямой руководитель. Повторения используют выбранные дни и исключения; график смен сотрудника автоматически не подставляется.</div>'
            . self::buttons('Назначить задачу') . '</form></section>';
        return $out;
    }
    private static function template_form(): string {
        global $DB;
        $id = (int)self::$state['templateid']; $row = $id ? $DB->get_record('local_ustar_task_templates',['id'=>$id,'ownerid'=>self::$actor],'*',MUST_EXIST) : null;
        $fields = [['key'=>'field_1','label'=>'Проверить готовность рабочего места','type'=>'check','required'=>true]];
        $weight = 10; $photo = false;
        if ($row) { $v = $DB->get_record('local_ustar_task_tpl_versions',['id'=>$row->versionid],'*',MUST_EXIST);
            $fields = json_decode($v->definitionjson,true); $weight=$v->kpiweight; $photo=!empty($v->requirephoto); }
        if (isset(self::$state['input'])) { $photo = !empty(self::$state['input']['requirephoto']); }
        if (isset(self::$state['input']['fieldlabel']) && is_array(self::$state['input']['fieldlabel'])) {
            $fields=[];
            foreach(array_slice(self::$state['input']['fieldlabel'],0,40) as $i=>$label){
                $fields[]=['key'=>self::$state['input']['fieldkey'][$i]??('field_'.$i),'label'=>$label,
                    'type'=>self::$state['input']['fieldtype'][$i]??'check','required'=>!empty(self::$state['input']['fieldrequired'][$i])];
            }
        }
        $out = '<section class="uw-panel uw-editor" id="uw-editor"><div class="uw-panel-head"><h2>Редактор чек-листа</h2>' . self::link('Закрыть',['form'=>'']) . '</div>'
            . self::form('template') . self::hidden('templateid',$id) . self::hidden('revision',$row?$row->revision:0)
            . self::field('Название шаблона','title',$row?$row->title:'','text','required maxlength="255"') . '<div data-builder>';
        foreach ($fields as $field) { $out .= self::builder_row($field); }
        $out .= '</div><button type="button" class="uw-btn" data-add-field>Добавить поле</button><template data-field-template>'
            . self::builder_row(['key'=>'','label'=>'','type'=>'check','required'=>true]) . '</template>'
            . self::field('Баллы за принятое выполнение','kpiweight',$weight,'number','min="0" max="10000"')
            . '<label class="uw-check"><input type="checkbox" name="requirephoto" value="1" ' . ($photo?'checked':'') . '> Фотоотчёт обязателен</label>'
            . '<div class="uw-notice">Сохраняется новая версия. Старые назначения и отчёты сохраняют прежние поля.</div>'
            . self::buttons('Сохранить версию') . '</form></section>';
        return $out;
    }
    private static function builder_row(array $field): string {
        return '<div class="uw-builder-row">' . self::hidden('fieldkey[]',$field['key']) . self::field('Название поля','fieldlabel[]',$field['label'])
            . self::select('Тип','fieldtype[]',['check'=>'Отметка','text'=>'Текст','number'=>'Число','photo'=>'Фото'],$field['type'])
            . self::select('Обязательность','fieldrequired[]',[1=>'Обязательно',0=>'Необязательно'],$field['required']?1:0)
            . '<div class="uw-actions"><button type="button" class="uw-btn" data-move-up aria-label="Переместить поле выше">↑</button>
            <button type="button" class="uw-btn" data-remove-row aria-label="Удалить поле">×</button></div></div>';
    }
    private static function detail(int $id): string {
        $t = service::detail($id,self::$actor); $out='<section class="uw-panel uw-detail" id="uw-detail"><div class="uw-panel-head"><div><small>Задача #'.$id.'</small><h2>'.s($t['title']).'</h2></div>'
            . self::link('Закрыть',['taskid'=>0]).'</div><div class="uw-detail-body"><div class="uw-panel-head">'.self::badge($t).'<span>'.s(self::due($t)).'</span></div>'
            . '<p>'.nl2br(s($t['description'])).'</p><div class="uw-form-grid"><div><small>Исполнитель</small><p>'.s($t['assignee']).'</p></div><div><small>Проверяющий</small><p>'.s($t['reviewer']).'</p></div></div>';
        if ($t['parentid']) { $out .= self::link('Родительская задача #'.$t['parentid'],['taskid'=>$t['parentid']]); }
        if ($t['reviewdueat'] && $t['status']==='in_review') { $out.='<div class="uw-notice">Срок проверки: '.s(userdate($t['reviewdueat'],'%d.%m.%Y %H:%M',$t['timezone'])).'</div>'; }
        if ($t['assigneeid']===self::$actor && $t['status']==='assigned') {
            $out.=self::form('transition').self::hidden('taskid',$id).self::hidden('version',$t['version'])
                .self::hidden('taskaction','start').self::buttons('Взять в работу').'</form>';
        }
        if ($t['kind']==='retraining') {
            $out.='<div class="uw-notice">Для завершения требуется новая попытка материала и подтверждённая аттестация. Исходное прохождение сохраняется.</div>';
            if ($t['assigneeid']===self::$actor && !in_array($t['status'],['completed','cancelled'],true)) {
                $out.='<a class="uw-btn uw-primary" href="'.(new \moodle_url('/local/ustar/forced_retraining.php'))->out().'">Открыть повторный курс</a>';
            }
        } else if ($t['assigneeid']===self::$actor && in_array($t['status'],['assigned','in_progress'],true)) {
            if ($t['meta']) {
                $latest=$t['reports'][0]??null; $answers=$latest?json_decode($latest->answersjson,true):[];
                $out.=self::form('report').self::hidden('taskid',$id).self::hidden('version',$t['version']);
                foreach($t['fields'] as $f) {
                    $name='answer_'.$f['key']; $value=isset(self::$state['input'])?(self::$state['input'][$name]??''):($answers[$f['key']]??''); $label=$f['label'].($f['required']?' · обязательно':'');
                    if($f['type']==='check'){$out.='<label class="uw-check"><input type="checkbox" name="'.s($name).'" value="1" '.($value?'checked':'').'> '.s($label).'</label>';}
                    else if($f['type']==='text'){$out.=self::textarea($label,$name,(string)$value);}
                    else if($f['type']==='number'){$out.=self::field($label,$name,$value,'number','step="any"');}
                    else{$out.='<p class="uw-footnote">'.s($label).' · приложите фото в отчёт</p>';}
                }
                $out.=self::textarea('Отчёт о выполнении','comment',$latest?$latest->commenttext:'')
                    .self::field('Фотографии результата · до 5 JPG / PNG / WebP, до 8 МБ каждое','attachments[]','','file','multiple accept="image/jpeg,image/png,image/webp"')
                    .'<div data-photo-preview class="uw-photos"></div><div class="uw-actions"><button name="final" value="0" class="uw-btn">Сохранить черновик</button>
                    <button name="final" value="1" class="uw-btn uw-primary">Отправить на проверку</button></div></form>';
            } else {
                $out.=self::form('transition').self::hidden('taskid',$id).self::hidden('version',$t['version']).self::hidden('taskaction','submit')
                    .self::textarea('Результат','comment').self::buttons('Завершить / отправить на проверку').'</form>';
            }
        }
        if ($t['canreview'] && $t['status']==='in_review') {
            $out.=self::form('transition').self::hidden('taskid',$id).self::hidden('version',$t['version'])
                .self::textarea('Комментарий · обязателен при возврате','comment')
                .'<div class="uw-actions"><button name="taskaction" value="return" class="uw-btn">Вернуть на доработку</button>
                <button name="taskaction" value="approve" class="uw-btn uw-primary">Принять результат</button></div></form>';
        }
        if ($t['canedit'] && !in_array($t['status'],['completed','cancelled'],true)) {
            if (in_array($t['status'],['assigned','in_progress'],true)) {
                $out.=self::form('revise').self::hidden('taskid',$id).self::hidden('version',$t['version'])
                    .'<h3>Изменить срок</h3><div class="uw-form-grid">'.self::field('Дата','duedate',calendar::date($t['dueat']?:time(),$t['timezone']),'date')
                    .self::field('Время','dueclock',userdate($t['dueat']?:time(),'%H:%M',$t['timezone']),'time').'</div>'.self::buttons('Сохранить срок').'</form>';
            }
            $out.='<details><summary>Отменить назначение</summary>'.self::form('transition').self::hidden('taskid',$id).self::hidden('version',$t['version'])
                .self::hidden('taskaction','cancel').self::textarea('Причина отмены','comment').self::buttons('Отменить задачу').'</form></details>';
        }
        if(service::can_manage(self::$actor)&&in_array($t['status'],['assigned','in_progress'],true)&&($t['assigneeid']===self::$actor||$t['canedit'])) {
            $out.=self::link('Создать связанное поручение',['form'=>'create','taskid'=>0,'parentid'=>$id]);
        }
        $out.='<h3 class="uw-section-title">Отчёты и вложения</h3>';
        foreach($t['reports'] as $report) {
            $out.='<details class="uw-report"><summary>'.($report->status==='draft'?'Черновик':'Отправленный отчёт').' · версия '.$report->taskversion.' · '.s(userdate($report->timecreated,'%d.%m %H:%M',$t['timezone'])).'</summary><p>'.nl2br(s($report->commenttext)).'</p>';
            $answers=json_decode($report->answersjson,true);
            foreach($t['fields'] as $field) {$value=$answers[$field['key']]??'';if(in_array($field['type'],['check','photo'],true)){$value=$value?'Да':'Нет';}$out.='<p>'.s($field['label']).': '.s((string)$value).'</p>';}
            $out.='</details>';
        }
        $out.='<div class="uw-photos">';
        foreach($t['files'] as $file) {
            $out.='<a class="uw-file" href="'.s($file['url']).'">';
            if($file['image']){$out.='<img loading="lazy" src="'.s($file['url']).'" alt="'.s($file['name']).'"><br>';}
            $out.=s($file['label'].' · '.$file['name'].($file['version']?' · v'.$file['version']:'')).'</a>';
        }
        $out.='</div><h3 class="uw-section-title">История</h3><div class="uw-history">';
        $labels=['task_assigned'=>'Назначена','task_started'=>'Начата','task_sent_for_review'=>'Отчёт отправлен','task_completed'=>'Выполнена',
            'task_approved'=>'Результат принят','task_returned'=>'Возвращена на доработку','task_cancelled'=>'Отменена','task_revised'=>'Срок / исполнитель изменён',
            'report_draft_saved'=>'Черновик отчёта сохранён','task_reminder'=>'Напоминание исполнителю','task_escalated'=>'Эскалация','task_learning_result'=>'Получен учебный результат'];
        foreach($t['events'] as $event){$out.='<p><span>'.s(userdate($event['time'],'%d.%m %H:%M',$t['timezone'])).'</span> · '.s($labels[$event['event']]??$event['event']).($event['comment']?' · '.s($event['comment']):'').'</p>';}
        return $out.'</div></div></section>';
    }
}
