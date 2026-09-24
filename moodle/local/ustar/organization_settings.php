<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/ustar:hrmanage', $context);
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    try {
        \local_ustar\organization_structure_editor::change(
            (int)$USER->id,
            required_param('action', PARAM_ALPHANUMEXT),
            [
                'id' => optional_param('id', '', PARAM_ALPHANUMEXT),
                'name' => required_param('name', PARAM_TEXT),
                'block' => optional_param('block', '', PARAM_ALPHANUMEXT),
                'companyrole' => optional_param('companyrole', '', PARAM_ALPHANUMEXT),
            ],
            required_param('revision', PARAM_INT)
        );
        redirect(new moodle_url('/local/ustar/organization_settings.php', ['saved' => 1]));
    } catch (\Throwable $e) {
        $notice = $e->getMessage();
    }
}

$structure = \local_ustar\structure::get(\local_ustar\structure::NAME_STRUCTURE);
$revision = \local_ustar\organization_structure_editor::revision();
$blocks = \local_ustar\organization_structure_editor::blocks($structure);
$companyroles = \local_ustar\organization_structure_editor::COMPANY_ROLES;
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/organization_settings.php'));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Подразделения и блоки | USTAR Academy');
$PAGE->set_heading('USTAR Academy');
$PAGE->requires->css(new moodle_url('/local/ustar/stage6.css'));

echo $OUTPUT->header();
echo html_writer::tag('h1', 'Подразделения и блоки');
echo html_writer::tag('p', 'Настройте состав компании. Изменение названия или блока сохраняет должности, сотрудников и историю назначений.');
if ($notice !== '') {
    echo $OUTPUT->notification(s($notice), 'notifyproblem');
}
if (optional_param('saved', 0, PARAM_BOOL)) {
    echo $OUTPUT->notification('Оргструктура сохранена.', 'notifysuccess');
}

$blockselect = static function(string $selected, string $id) use ($blocks): string {
    $options = html_writer::tag('option', 'Выберите блок', ['value' => '']);
    foreach ($blocks as $key => $label) {
        $options .= html_writer::tag('option', s($label), [
            'value' => $key, 'selected' => $key === $selected ? 'selected' : null,
        ]);
    }
    return html_writer::tag('select', $options, ['id' => $id, 'name' => 'block',
        'required' => 'required', 'class' => 'form-select']);
};
$hidden = static function(string $action, string $id = '') use ($revision): string {
    $html = '';
    $fields = ['sesskey' => sesskey(), 'revision' => $revision, 'action' => $action];
    if ($id !== '') { $fields['id'] = $id; }
    foreach ($fields as $name => $value) {
        $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
    }
    return $html;
};

echo html_writer::tag('h2', 'Блоки компании');
echo html_writer::tag('p', 'Переименование блока сохраняет привязанные подразделения и сотрудников.');
echo html_writer::start_div('u-structure-grid');
foreach ($blocks as $blockid => $blockname) {
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'u-stage6-card']);
    echo $hidden('updateblock', $blockid);
    echo html_writer::tag('label', 'Название блока', ['for' => 'block-' . $blockid]);
    echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'name',
        'id' => 'block-' . $blockid, 'required' => 'required', 'maxlength' => 120,
        'value' => $blockname, 'class' => 'form-control']);
    echo html_writer::tag('p', 'Подразделений: ' . count(array_filter($structure['departments'] ?? [],
        static fn(array $department): bool => (string)($department['block'] ?? '') === $blockid)));
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Сохранить название',
        'class' => 'u-btn u-btn--primary']);
    echo html_writer::end_tag('form');
}
echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'u-stage6-card']);
echo $hidden('createblock');
echo html_writer::tag('label', 'Новый блок', ['for' => 'new-block-name']);
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'name', 'id' => 'new-block-name',
    'required' => 'required', 'maxlength' => 120, 'class' => 'form-control']);
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Добавить блок',
    'class' => 'u-btn u-btn--primary']);
echo html_writer::end_tag('form');
echo html_writer::end_div();

echo html_writer::tag('h2', 'Подразделения');
echo html_writer::start_div('u-structure-grid');
foreach ($structure['departments'] ?? [] as $department) {
    $id = (string)($department['id'] ?? '');
    if ($id === '') { continue; }
    echo html_writer::start_div('u-stage6-card');
    echo html_writer::tag('h2', s((string)($department['name'] ?? $id)));
    echo html_writer::start_tag('form', ['method' => 'post']);
    echo $hidden('updatedepartment', $id);
    echo html_writer::tag('label', 'Название подразделения', ['for' => 'department-' . $id]);
    echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'name',
        'id' => 'department-' . $id, 'required' => 'required', 'maxlength' => 120,
        'value' => (string)($department['name'] ?? ''), 'class' => 'form-control']);
    echo html_writer::tag('label', 'Блок компании', ['for' => 'department-block-' . $id]);
    echo $blockselect((string)($department['block'] ?? ''), 'department-block-' . $id);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Сохранить подразделение',
        'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
    echo html_writer::tag('p', 'Должностей: ' . count(array_filter($structure['positions'] ?? [],
        static fn(array $position): bool => (string)($position['department'] ?? '') === $id)));
    $roleforms = '';
    foreach ($structure['positions'] ?? [] as $position) {
        if ((string)($position['department'] ?? '') !== $id) { continue; }
        $positionid = (string)($position['id'] ?? '');
        $roleoptions = '';
        foreach ($companyroles as $roleid => $rolelabel) {
            $attrs = ['value' => $roleid];
            if ((string)($position['companyrole'] ?? '') === $roleid) {
                $attrs['selected'] = 'selected';
            }
            $roleoptions .= html_writer::tag('option', s($rolelabel), $attrs);
        }
        $roleforms .= html_writer::start_tag('form', ['method' => 'post']);
        $roleforms .= $hidden('updateposition', $positionid);
        $roleforms .= html_writer::tag('label', 'Должность', ['for' => 'position-' . $positionid]);
        $roleforms .= html_writer::empty_tag('input', ['type' => 'text', 'name' => 'name',
            'id' => 'position-' . $positionid,
            'required' => 'required', 'maxlength' => 120, 'class' => 'form-control',
            'value' => (string)($position['name'] ?? '')]);
        $roleforms .= html_writer::tag('label', 'Роль в схеме компании', ['for' => 'role-' . $positionid]);
        $roleforms .= html_writer::tag('select', $roleoptions,
            ['id' => 'role-' . $positionid, 'name' => 'companyrole',
                'required' => 'required', 'class' => 'form-select']);
        $roleforms .= html_writer::empty_tag('input', ['type' => 'submit',
            'value' => 'Сохранить должность', 'class' => 'btn btn-primary']);
        $roleforms .= html_writer::end_tag('form');
    }
    if ($roleforms !== '') {
        echo html_writer::tag('details', html_writer::tag('summary', 'Редактировать должности') . $roleforms);
    }
    echo html_writer::end_div();
}
echo html_writer::end_div();

echo html_writer::start_div('u-stage6-card');
echo html_writer::tag('h2', 'Добавить подразделение');
echo html_writer::start_tag('form', ['method' => 'post']);
echo $hidden('createdepartment');
echo html_writer::tag('label', 'Название', ['for' => 'new-department-name']);
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'name', 'required' => 'required',
    'id' => 'new-department-name', 'maxlength' => 120, 'class' => 'form-control']);
echo html_writer::tag('label', 'Блок компании', ['for' => 'new-department-block']);
echo $blockselect('', 'new-department-block');
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Добавить подразделение',
    'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');
echo html_writer::end_div();

echo html_writer::start_div('u-stage6-card');
echo html_writer::tag('h2', 'Добавить должность');
echo html_writer::start_tag('form', ['method' => 'post']);
echo $hidden('createposition');
echo html_writer::tag('label', 'Подразделение', ['for' => 'new-position-department']);
$positions = html_writer::tag('option', 'Выберите подразделение', ['value' => '']);
foreach ($structure['departments'] ?? [] as $department) {
    $positions .= html_writer::tag('option', s((string)$department['name']),
        ['value' => (string)$department['id']]);
}
echo html_writer::tag('select', $positions,
    ['id' => 'new-position-department', 'name' => 'id', 'required' => 'required', 'class' => 'form-select']);
echo html_writer::tag('label', 'Название должности', ['for' => 'new-position-name']);
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'name',
    'id' => 'new-position-name', 'required' => 'required', 'maxlength' => 120, 'class' => 'form-control']);
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Добавить должность',
    'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');
echo html_writer::tag('p', 'После создания укажите для должности требования и маршрут в разделе «Должности».');
echo html_writer::end_div();

echo html_writer::tag('p', html_writer::link(new moodle_url('/local/ustar/positions.php'), 'Перейти к должностям →'));
echo $OUTPUT->footer();
