<?php
namespace local_ustar\task_workspace;
use local_ustar\view_as;
defined('MOODLE_INTERNAL') || die();
final class home_cards {
    public static function render(int $userid): string {
        if (!service::available() || !\local_ustar\team_access::active_actor($userid)) { return ''; }
        $out = '<div class="u-workspace uw-home-flows">';
        foreach (['other'=>'Мои задачи', 'checklist'=>'Мои чек-листы'] as $kind=>$label) {
            $rows = service::rows($userid,'mine','active',0,0,0,'',$kind);
            $url = new \moodle_url('/local/ustar/tasks.php',['scope'=>'mine','view'=>$kind==='checklist'?'checklists':'overview']);
            $out .= '<section class="uw-panel"><div class="uw-panel-head"><h2>'.s($label).'</h2><a class="uw-btn" href="'.$url->out().'">Все · '.$rows['total'].'</a></div>';
            if (!$rows['total']) { $out .= '<p class="uw-empty">Текущих назначений нет.</p>'; }
            foreach (array_slice($rows['items'],0,5) as $row) {
                $url = new \moodle_url('/local/ustar/tasks.php',['scope'=>'mine','taskid'=>$row['id']]);
                $out .= '<article class="uw-home-card"><div class="uw-panel-head"><strong>'.s($row['title']).'</strong><a href="'.$url->out().'">Открыть</a></div><p class="uw-footnote">'
                    .s($row['dueat']?userdate($row['dueat'],'%d.%m · %H:%M',$row['timezone']):'Без срока')
                    .($row['late']?' · Просрочено':'').' · '.$row['kpiweight'].' KPI</p>';
                if ($row['kind']==='retraining') {
                    $out .= '<a class="uw-btn" href="'.(new \moodle_url('/local/ustar/forced_retraining.php'))->out().'">Пройти повторный курс</a>';
                } else if (!view_as::active()) {
                    $t=service::detail($row['id'],$userid); $latest=$t['reports'][0]??null;
                    $answers=$latest?(json_decode($latest->answersjson,true)?:[]):[];
                    $out.='<details><summary class="uw-btn">Заполнить отчёт</summary><form class="uw-form" method="post" enctype="multipart/form-data" action="'.$url->out().'">';
                    foreach (['sesskey'=>sesskey(),'action'=>$t['meta']?'report':'transition','taskaction'=>'submit','returnhome'=>1,
                            'taskid'=>$t['id'],'version'=>$t['version'],'final'=>1] as $name=>$value) {
                        $out.=\html_writer::empty_tag('input',['type'=>'hidden','name'=>$name,'value'=>$value]);
                    }
                    foreach ($t['fields'] as $f) {
                        $name='answer_'.$f['key']; $v=$answers[$f['key']]??'';
                        $out.='<label class="uw-field"><span>'.s($f['label']).($f['required']?' · обязательно':'').'</span>';
                        if ($f['type']==='check') { $out.=\html_writer::empty_tag('input',['type'=>'checkbox','name'=>$name,'value'=>1]+($v?['checked'=>'checked']:[])); }
                        else if ($f['type']==='text') { $out.='<textarea name="'.s($name).'">'.s((string)$v).'</textarea>'; }
                        else if ($f['type']==='number') { $out.=\html_writer::empty_tag('input',['type'=>'number','step'=>'any','name'=>$name,'value'=>$v]); }
                        else { $out.='<small>Приложите фото ниже</small>'; }
                        $out.='</label>';
                    }
                    $out.='<label class="uw-field"><span>Результат</span><textarea name="comment">'.s($latest?$latest->commenttext:'').'</textarea></label>';
                    if ($t['meta']) { $out.='<label class="uw-field"><span>Фото · до 5 файлов, 8 МБ каждый'.($t['photo']?' · обязательно':'').'</span><input name="attachments[]" type="file" multiple accept="image/jpeg,image/png,image/webp"></label>'; }
                    $out.='<button class="uw-btn uw-primary">Отправить на проверку</button></form></details>';
                }
                $out.='</article>';
            }
            $out.='</section>';
        }
        return $out.'</div>';
    }
}
