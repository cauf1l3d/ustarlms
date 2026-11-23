<?php
namespace local_ustar\task_workspace;
use local_ustar\{learning_tasks, team_access, view_as};
use local_ustar\briefing\{meeting_cards, meetings};
defined('MOODLE_INTERNAL') || die();
final class home_cards {
    public static function render(int $userid): string {
        if (!service::available() || !team_access::active_actor($userid)) { return ''; }
        $context = \context_system::instance();
        if (!has_capability('local/ustar:use', $context, $userid) && !team_access::company($userid)) {
            return '';
        }
        $out = self::meeting_widget($userid);
        foreach (['other'=>'Мои задачи', 'checklist'=>'Мои чек-листы'] as $kind=>$label) {
            $rows = service::rows($userid,'mine','active',0,0,0,'',$kind, limit: 5);
            $url = new \moodle_url('/local/ustar/tasks.php',['scope'=>'mine','view'=>$kind==='checklist'?'checklists':'overview']);
            $out .= '<section class="u-home-widget u-workspace uw-home-flow uw-panel" data-home-block="'.($kind==='checklist'?'checklists':'tasks').'" data-home-label="'.s($label).'"><div class="uw-panel-head"><h2>'.s($label).'</h2><a class="uw-btn" href="'.$url->out().'">Все · '.$rows['total'].'</a></div>';
            if (!$rows['total']) { $out .= '<p class="uw-empty">Текущих назначений нет.</p>'; }
            if ($kind === 'other' && !view_as::active()) {
                $out .= '<nav class="uw-home-shortcuts uw-actions" aria-label="Переходы по задачам">'
                    . self::link('Мой план', ['view' => 'plan', 'scope' => 'mine'])
                    . \html_writer::link(new \moodle_url('/local/ustar/messages.php', ['taskchats' => 1]), 'Чаты задач')
                    . '</nav>';
            }
            $inputs = [];
            if (!view_as::active()) {
                $reportable = array_filter($rows['items'], static fn($row) => $row['kind'] !== 'retraining');
                $inputs = service::home_report_inputs($userid, array_column($reportable, 'id'));
            }
            foreach ($rows['items'] as $row) {
                $url = new \moodle_url('/local/ustar/tasks.php',['scope'=>'mine','taskid'=>$row['id']]);
                $out .= '<article class="uw-home-card"><div class="uw-panel-head"><strong>'.s($row['title']).'</strong><a href="'.$url->out().'">Открыть</a></div><p class="uw-footnote">'
                    .s($row['dueat']?userdate($row['dueat'],'%d.%m · %H:%M',$row['timezone']):'Без срока')
                    . ($row['late'] ? ' · Просрочено' : '') . ' · '
                    . ($row['status'] === 'assigned' ? 'Назначена' : 'В работе') . '</p>';
                if ($row['kind']==='retraining') {
                    $out .= '<a class="uw-btn" href="'.(new \moodle_url('/local/ustar/forced_retraining.php'))->out().'">Пройти повторный курс</a>';
                } else if (isset($inputs[$row['id']])) {
                    $t = $inputs[$row['id']];
                    $answers = json_decode((string)$t->answersjson, true) ?: [];
                    $out.='<details><summary class="uw-btn">Заполнить отчёт</summary><form class="uw-form" method="post" enctype="multipart/form-data" action="'.$url->out().'">';
                    foreach (['sesskey'=>sesskey(),'action'=>$t->metaid?'report':'transition','taskaction'=>'submit','returnhome'=>1,
                            'taskid'=>$t->id,'version'=>$t->version,'final'=>1] as $name=>$value) {
                        $out.=\html_writer::empty_tag('input',['type'=>'hidden','name'=>$name,'value'=>$value]);
                    }
                    foreach (json_decode((string)$t->definitionjson, true) ?: [] as $f) {
                        $name='answer_'.$f['key']; $v=$answers[$f['key']]??'';
                        $out.='<label class="uw-field"><span>'.s($f['label']).($f['required']?' · обязательно':'').'</span>';
                        if ($f['type']==='check') { $out.=\html_writer::empty_tag('input',['type'=>'checkbox','name'=>$name,'value'=>1]+($v?['checked'=>'checked']:[])); }
                        else if ($f['type']==='text') { $out.='<textarea name="'.s($name).'">'.s((string)$v).'</textarea>'; }
                        else if ($f['type']==='number') { $out.=\html_writer::empty_tag('input',['type'=>'number','step'=>'any','name'=>$name,'value'=>$v]); }
                        else { $out.='<small>Приложите фото ниже</small>'; }
                        $out.='</label>';
                    }
                    $out.='<label class="uw-field"><span>Результат</span><textarea name="comment">'.s((string)$t->commenttext).'</textarea></label>';
                    if ($t->metaid) { $out.='<label class="uw-field"><span>Фото · до 5 файлов, 8 МБ каждый'.($t->requirephoto?' · обязательно':'').'</span><input name="attachments[]" type="file" multiple accept="image/jpeg,image/png,image/webp"></label>'; }
                    $out.='<button class="uw-btn uw-primary">Отправить на проверку</button></form></details>';
                }
                $out.='</article>';
            }
            $out.='</section>';
        }
        if (!view_as::active()) {
            $url=(new \moodle_url('/local/ustar/notebook.php'))->out();
            $out.='<section class="u-home-widget u-workspace uw-home-flow uw-panel" data-home-block="notes" data-home-label="Личные заметки"><div class="uw-panel-head"><h2>Личные заметки</h2><a class="uw-btn" href="'.$url.'">На доску →</a></div>';
            $notes=learning_tasks::notes_for_owner($userid, 0, 'all', '', 5);
            if (!$notes) { $out.='<p class="uw-empty">Запишите идею или добавьте заметку на личную доску.</p>'; }
            foreach ($notes as $note) {
                $out.='<article class="uw-home-card"><a href="'.$url.'#note-'.$note['id'].'"><strong>'.s($note['titleplain']).'</strong></a><p class="uw-footnote">'.s(\core_text::substr($note['descriptionplain'],0,160)).'</p></article>';
            }
            $out.='</section>';
        }
        return $out;
    }

    private static function meeting_widget(int $userid): string {
        if (!meetings::allowed($userid)) {
            return '';
        }
        $now = time();
        $rows = meetings::upcoming($userid, $now, 90, 0, 3, false);
        return '<section id="home-meetings" class="u-home-widget u-workspace uw-home-flow uw-panel"'
            . ' data-home-block="meetings" data-home-label="Ближайшие встречи">'
            . '<div class="uw-panel-head"><h2>Ближайшие встречи</h2><nav class="uw-actions" aria-label="Встречи">'
            . self::link('Все встречи', ['view' => 'meetings'])
            . self::link('Календарь', ['view' => 'calendar', 'scope' => 'mine'])
            . '</nav></div>' . meeting_cards::render($rows, $now, 'home') . '</section>';
    }

    private static function link(string $label, array $params): string {
        return \html_writer::link(new \moodle_url('/local/ustar/tasks.php', $params), $label);
    }
}
