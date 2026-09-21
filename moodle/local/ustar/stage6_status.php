<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
$canview = is_siteadmin((int)$USER->id)
    || has_capability('local/ustar:admin', $context)
    || has_capability('local/ustar:hr', $context)
    || has_capability('local/ustar:hrmanage', $context);
if (!$canview) {
    throw new required_capability_exception($context, 'local/ustar:hr', 'nopermissions', '');
}

$summary = \local_ustar\stage6_metrics::summary();
$rows = [
    ['Материалы', 'Курсы', (int)$summary['materials']['courses']],
    ['Материалы', 'SCORM-курсы', (int)$summary['materials']['scorm']],
    ['Материалы', 'SCORM с импортированным ZIP', (int)$summary['materials']['importedscorm']],
    ['Материалы', 'Аттестации', (int)$summary['materials']['assessments']],
    ['Грейды', 'Ожидают решения руководителя', (int)$summary['grades']['pending']],
    ['Грейды', 'Согласовано', (int)$summary['grades']['approved']],
    ['Каталог', 'Разделы', (int)$summary['catalog']['groups']],
    ['Каталог', 'Подгруппы', (int)$summary['catalog']['subgroups']],
    ['Каталог', 'Активные карточки', (int)$summary['catalog']['cards']],
    ['Задачи', 'Активные назначенные задачи', (int)$summary['tasks']['assignedactive']],
    ['Задачи', 'На проверке', (int)$summary['tasks']['review']],
    ['Задачи', 'Выполненные назначенные задачи', (int)$summary['tasks']['completed']],
    ['Архив досок', 'Сохранённые личные архивы', (int)$summary['boards']['archived']],
];

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/stage6_status.php'));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Состояние сервисов | USTAR Academy');
$PAGE->set_heading('USTAR Academy');
$PAGE->requires->css(new moodle_url('/local/ustar/stage6.css'));

echo $OUTPUT->header();
echo $OUTPUT->heading('Состояние сервисов');
echo html_writer::tag(
    'p',
    'Операционные показатели обновляются по рабочим данным и используют только индексированные счётчики.'
);
echo $OUTPUT->notification(
    'Личные заметки сотрудников намеренно не входят в показатели, поиск, экспорт и аналитику.',
    'notifyinfo'
);
$table = new html_table();
$table->head = ['Контур', 'Показатель', 'Значение'];
$table->data = $rows;
echo html_writer::table($table);
echo $OUTPUT->footer();
