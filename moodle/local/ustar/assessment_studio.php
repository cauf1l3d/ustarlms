<?php
require_once(__DIR__.'/../../config.php');
require_login();
\local_ustar\assessment_authoring::require_manage();
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/assessment_studio.php'));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Конструктор аттестаций | USTAR');
$PAGE->set_heading('Конструктор аттестаций');
$PAGE->requires->css(new moodle_url('/local/ustar/styles/ux_tools.css'));
$PAGE->requires->js(new moodle_url('/local/ustar/assessment_studio.js'));
if (optional_param('download', '', PARAM_ALPHA) === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="USTAR_QUESTIONS.csv"');
    echo "\xEF\xBB\xBF".implode(';',\local_ustar\assessment_authoring_input::HEADER)."\r\n";
    echo "choice;Сколько будет два плюс два?;Три;Четыре;Пять;;2;1;Два плюс два равно четыре.\r\n";
    echo "essay;Опишите порядок действий при возврате товара.;;;;;;5;\r\n";
    exit;
}
$SESSION->ustar_authoring_tokens = array_filter((array)($SESSION->ustar_authoring_tokens ?? []),
    static fn($time)=>$time>time()-3600);
$action = optional_param('action','',PARAM_ALPHA);
$token = optional_param('token','',PARAM_ALPHANUM);
$input = ['title'=>'','pass'=>80,'minutes'=>30,'questions'=>[],'bankids'=>[]];
$preview = false;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    try {
        if (empty($SESSION->ustar_authoring_tokens[$token])) {throw new invalid_parameter_exception('Форма устарела. Откройте конструктор заново.');}
        if ($action === 'create' || $action === 'edit') {
            $payload = required_param('payload', PARAM_RAW);
            if (strlen($payload)>524288) {throw new invalid_parameter_exception('Форма слишком большая.');}
            $input = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($input)) {throw new invalid_parameter_exception('Неверная форма.');}
        } else {
            $rows = $_POST['questions'] ?? [];
            if (!is_array($rows)) {throw new invalid_parameter_exception('Неверные вопросы.');}
            // Empty manual cards are not questions; every nonempty card is validated.
            $rows = array_values(array_filter($rows, static fn($r)=>!is_array($r) || trim((string)($r['text'] ?? ''))!==''));
            $input = ['title'=>required_param('title', PARAM_TEXT), 'pass'=>required_param('pass',PARAM_TEXT),
                'minutes'=>required_param('minutes',PARAM_TEXT),'questions'=>$rows,
                'bankids'=>optional_param_array('bankids',[],PARAM_INT)];
            $csv = optional_param('csvtext','',PARAM_RAW);
            if (!empty($_FILES['csvfile']) && $_FILES['csvfile']['error'] !== UPLOAD_ERR_NO_FILE) {
                $f = $_FILES['csvfile'];
                if ($f['error']!==UPLOAD_ERR_OK || $f['size']>262144 || !is_uploaded_file($f['tmp_name'])) {
                    throw new invalid_parameter_exception('Не удалось загрузить CSV. Максимум 256 КБ.');
                }
                if (trim($csv)!=='') {throw new invalid_parameter_exception('Выберите один способ импорта: файл или текст CSV.');}
                $csv = file_get_contents($f['tmp_name']);
            }
            if (trim($csv)!=='') {$input['questions']=array_merge($rows,\local_ustar\assessment_authoring_input::csv($csv));}
        }
        $input = \local_ustar\assessment_authoring::validate($input);
        if ($action === 'create') {
            $created = \local_ustar\assessment_authoring::create($input,$token);
            redirect(new moodle_url('/local/ustar/material.php',['id'=>$created['contentid']]),
                'Аттестация создана как черновик. Настройте доступ и опубликуйте материал; затем добавьте его в маршрут.',
                null,\core\output\notification::NOTIFY_SUCCESS);
        }
        $preview = $action !== 'edit';
    } catch (\Throwable $e) {
        $error = $e instanceof invalid_parameter_exception ? $e->getMessage()
            : 'Не удалось обработать форму. Данные не опубликованы. Повторите попытку; при повторении ошибки обратитесь к администратору.';
        debugging('USTAR assessment authoring: '.get_class($e),DEBUG_DEVELOPER);
    }
}
if (empty($SESSION->ustar_authoring_tokens[$token])) {
    $token=bin2hex(random_bytes(16));
    $SESSION->ustar_authoring_tokens=array_slice($SESSION->ustar_authoring_tokens,-29,null,true);
    $SESSION->ustar_authoring_tokens[$token]=time();
}
$bank = \local_ustar\assessment_authoring::bank();
function ustar_authoring_card(int $index, array $row=[]): void {
    $type = (string)($row['type'] ?? 'choice');
    echo '<fieldset class="u-ux-question"><legend>Вопрос <span data-number>'.($index+1).'</span></legend>';
    echo '<label>Тип<select name="questions['.$index.'][type]" data-question-type><option value="choice">Один правильный ответ</option><option value="essay" '.($type==='essay'?'selected':'').'>Открытый ответ — проверяет HR</option></select></label>';
    echo '<label>Текст вопроса<textarea name="questions['.$index.'][text]" rows="3" maxlength="6000">'.s((string)($row['text']??'')).'</textarea></label>';
    echo '<div data-options class="u-ux-grid">';
    foreach (range(1,4) as $n) {echo '<label>Вариант '.$n.'<input type="text" name="questions['.$index.'][option'.$n.']" maxlength="6000" value="'.s((string)($row['option'.$n]??'')).'"></label>';}
    echo '<label>Номер правильного ответа<select name="questions['.$index.'][correct]"><option value="">Выберите</option>';
    foreach (range(1,4) as $n) {echo '<option value="'.$n.'" '.((string)($row['correct']??'')===(string)$n?'selected':'').'>'.$n.'</option>';}
    echo '</select></label></div><label>Баллы<input type="number" min="0.1" max="100" step="0.1" name="questions['.$index.'][points]" value="'.s((string)($row['points']??1)).'"></label>';
    echo '<label>Пояснение ответа<textarea name="questions['.$index.'][feedback]" maxlength="6000">'.s((string)($row['feedback']??'')).'</textarea></label><button type="button" class="u-btn u-btn--small" data-remove-question>Удалить вопрос</button></fieldset>';
}
echo $OUTPUT->header();
echo '<main class="u-product-page u-ux-tools"><header class="u-product-head"><div><h1>Конструктор аттестаций</h1><p>Соберите вопросы, проверьте состав и сохраните аттестацию в материалах.</p></div></header>';
if ($error!=='') {echo '<div class="alert alert-danger" role="alert">'.s($error).'</div>';}
if ($preview) {
    echo '<section class="u-panel u-ux-form"><h2>Предпросмотр: '.s($input['title']).'</h2><p>Проходной балл: '.s($input['pass']).'%. Время: '.(int)$input['minutes'].' мин. (0 — без ограничения). До трёх попыток; переобучение и эскалации настраиваются в точке маршрута.</p>';
    foreach ($input['questions'] as $i=>$r) {
        echo '<article class="u-ux-question"><h3>'.($i+1).'. '.s($r['text']).'</h3><p>Баллы: '.s($r['points']).'</p>';
        if ($r['type']==='essay') {echo '<p>Открытый ответ — требуется ручная проверка.</p>';}
        else {foreach (range(1,4) as $n) {if ($r['option'.$n]!=='') {echo '<p>'.($n===(int)$r['correct']?'✓ ':'').s($r['option'.$n]).'</p>';}}}
        echo '</article>';
    }
    foreach ($input['bankids'] as $qid) {echo '<p>Из банка: '.s((string)($bank[$qid]->name??('Вопрос '.$qid))).'</p>';}
    echo '<form method="post"><input type="hidden" name="sesskey" value="'.sesskey().'"><input type="hidden" name="token" value="'.s($token).'"><input type="hidden" name="payload" value="'.s(json_encode($input,JSON_UNESCAPED_UNICODE)).'"><div class="u-ux-actions"><button class="u-btn" name="action" value="edit">Вернуться к редактированию</button><button class="u-btn u-btn--primary" name="action" value="create">Создать черновик аттестации</button></div></form></section>';
} else {
    echo '<form method="post" enctype="multipart/form-data" class="u-panel u-ux-form" id="ustar-assessment-authoring"><input type="hidden" name="sesskey" value="'.sesskey().'"><input type="hidden" name="token" value="'.s($token).'"><input type="hidden" name="action" value="preview">';
    echo '<label>Название аттестации<input name="title" type="text" maxlength="150" required value="'.s((string)($input['title']??'')).'"></label><div class="u-ux-grid"><label>Проходной балл, %<input name="pass" type="number" min="1" max="100" step="0.1" required value="'.s((string)($input['pass']??80)).'"></label><label>Время, минуты (0 — без ограничения)<input name="minutes" type="number" min="0" max="240" required value="'.s((string)($input['minutes']??30)).'"></label></div>';
    echo '<h2>Вопросы вручную</h2><div id="ustar-authoring-questions">';
    $rows=$input['questions']??[];
    foreach (($rows ?: [[]]) as $i=>$r) {if (is_array($r)) {ustar_authoring_card((int)$i,$r);}}
    echo '</div><button type="button" class="u-btn" id="ustar-add-question">Добавить вопрос</button><template id="ustar-question-template">';
    ustar_authoring_card(99999);
    echo '</template><details class="u-ux-question"><summary>Импорт из CSV</summary><p>UTF-8, разделитель «;», до 100 вопросов. Сначала скачайте шаблон; строка choice — выбор ответа, essay — открытый ответ.</p><a href="?download=csv">Скачать шаблон CSV</a><label>Файл<input type="file" name="csvfile" accept=".csv,text/csv"></label><label>Или вставьте CSV<textarea name="csvtext" rows="6"></textarea></label></details>';
    echo '<details class="u-ux-question"><summary>Добавить из банка вопросов</summary><p>Последние 500 вопросов из аттестаций в каталоге USTAR. Выбирается конкретная версия вопроса.</p><label>Фильтр<input type="search" id="ustar-bank-search" placeholder="Название вопроса"></label><div class="u-ux-bank">';
    foreach ($bank as $q) {echo '<label data-bank-row><input type="checkbox" name="bankids[]" value="'.(int)$q->id.'" '.(in_array((int)$q->id,$input['bankids']??[],true)?'checked':'').'> '.s($q->name).' · версия '.(int)$q->version.' · '.s($q->defaultmark).' балл.</label>';}
    if (!$bank) {echo '<p>Банк пока пуст. Вопросы созданных здесь аттестаций появятся в нём автоматически.</p>';}
    echo '</div></details><div class="u-ux-actions"><button class="u-btn u-btn--primary" type="submit">Проверить и посмотреть</button><a class="u-btn" href="'.(new moodle_url('/local/ustar/materials.php'))->out(false).'">К материалам</a></div></form>';
}
echo '</main>'.$OUTPUT->footer();
