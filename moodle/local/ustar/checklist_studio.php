<?php
require_once(__DIR__ . '/../../config.php');

require_login();

$context = context_system::instance();
if (!has_capability('local/ustar:hrmanage', $context) && !has_capability('local/ustar:admin', $context)) {
    require_capability('local/ustar:hrmanage', $context);
}

$id = optional_param('id', '', PARAM_ALPHANUMEXT);
$saved = optional_param('saved', 0, PARAM_BOOL);

$payload = \local_ustar\native_data::hr_checklists();
$definitions = $payload['definitions'] ?? \local_ustar\checklists::get();
$items = array_values($definitions['items'] ?? []);

$findCurrent = static function(array $items, string $id): ?array {
    foreach ($items as $item) {
        if ((string)($item['id'] ?? '') === $id) {
            return $item;
        }
    }
    return null;
};

$current = $id !== '' ? $findCurrent($items, $id) : ($items[0] ?? null);
if ($id === '' && $current) {
    $id = (string)$current['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    \local_ustar\view_as::assert_writable();
    $originalid = optional_param('originalid', '', PARAM_ALPHANUMEXT);
    $newid = required_param('checklistid', PARAM_ALPHANUMEXT);
    $title = required_param('title', PARAM_TEXT);
    $description = optional_param('description', '', PARAM_TEXT);
    $recurrence = required_param('recurrence', PARAM_ALPHANUMEXT);
    $active = optional_param('active', 0, PARAM_BOOL);
    $positionids = optional_param_array('positionids', [], PARAM_ALPHANUMEXT);
    $outline = trim(optional_param('outline', '', PARAM_RAW_TRIMMED));

    $old = $originalid !== '' ? $findCurrent($items, $originalid) : null;
    $oldSectionIds = [];
    $oldItemIds = [];
    foreach (($old['sections'] ?? []) as $section) {
        $oldSectionIds[trim((string)($section['title'] ?? ''))] = (string)($section['id'] ?? '');
        foreach (($section['items'] ?? []) as $item) {
            $oldItemIds[trim((string)($item['title'] ?? ''))] = (string)($item['id'] ?? '');
        }
    }

    $sections = [];
    $sectionIndex = -1;
    foreach (preg_split('/\R/u', $outline) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (str_starts_with($line, '##')) {
            $sectionTitle = trim(substr($line, 2)) ?: 'Раздел';
            $sectionId = $oldSectionIds[$sectionTitle] ?? ('section_' . substr(sha1($sectionTitle), 0, 10));
            $sections[] = ['id' => $sectionId, 'title' => $sectionTitle, 'items' => []];
            $sectionIndex = count($sections) - 1;
            continue;
        }
        if ($sectionIndex < 0) {
            $sections[] = ['id' => 'section_main', 'title' => 'Основное', 'items' => []];
            $sectionIndex = 0;
        }
        $itemTitle = preg_replace('/^[-*]\s*/u', '', $line);
        $itemId = $oldItemIds[$itemTitle] ?? ('item_' . substr(sha1($newid . '|' . $itemTitle), 0, 12));
        $sections[$sectionIndex]['items'][] = ['id' => $itemId, 'title' => $itemTitle];
    }

    $new = [
        'id' => $newid,
        'title' => $title,
        'description' => $description,
        'active' => (bool)$active,
        'recurrence' => in_array($recurrence, ['daily', 'weekly', 'manual'], true) ? $recurrence : 'daily',
        'positionIds' => array_values(array_unique($positionids)),
        'sections' => $sections,
    ];

    $updated = [];
    $replaced = false;
    foreach ($items as $item) {
        if ($originalid !== '' && (string)$item['id'] === $originalid) {
            $updated[] = $new;
            $replaced = true;
        } else {
            $updated[] = $item;
        }
    }
    if (!$replaced) {
        $updated[] = $new;
    }

    $definitions['items'] = $updated;
    \local_ustar\native_data::save_checklists($definitions);
    redirect(new moodle_url('/local/ustar/checklist_studio.php', ['id' => $newid, 'saved' => 1]));
}

if (!$current) {
    $current = [
        'id' => '', 'title' => '', 'description' => '', 'active' => true,
        'recurrence' => 'daily', 'positionIds' => [], 'sections' => [],
    ];
}

$list = [];
foreach ($items as $item) {
    $itemid = (string)$item['id'];
    $list[] = [
        'id' => $itemid,
        'title' => (string)$item['title'],
        'active' => !empty($item['active']),
        'selected' => $itemid === (string)$current['id'],
        'url' => (new moodle_url('/local/ustar/checklist_studio.php', ['id' => $itemid]))->out(false),
    ];
}

$outlineLines = [];
foreach (($current['sections'] ?? []) as $section) {
    $outlineLines[] = '## ' . (string)($section['title'] ?? 'Раздел');
    foreach (($section['items'] ?? []) as $item) {
        $outlineLines[] = '- ' . (string)($item['title'] ?? '');
    }
    $outlineLines[] = '';
}

$positionOptions = [];
$selectedPositions = array_fill_keys(array_map('strval', $current['positionIds'] ?? []), true);
foreach (($payload['positions'] ?? []) as $position) {
    $positionOptions[] = [
        'id' => (string)$position['id'],
        'name' => (string)$position['name'],
        'selected' => isset($selectedPositions[(string)$position['id']]),
    ];
}

$recurrenceOptions = [];
foreach (['daily' => 'Ежедневно', 'weekly' => 'Еженедельно', 'manual' => 'Вручную'] as $rid => $label) {
    $recurrenceOptions[] = ['id' => $rid, 'label' => $label, 'selected' => (string)$current['recurrence'] === $rid];
}

$recent = array_values($payload['recent'] ?? []);
foreach ($recent as &$run) {
    $run['statuslabel'] = (string)$run['status'] === 'completed' ? 'Завершён' : 'В процессе';
    $run['completed'] = (string)$run['status'] === 'completed';
}
unset($run);

$data = [
    'saved' => $saved,
    'items' => $list,
    'hasitems' => !empty($list),
    'templatecount' => count($list),
    'current' => $current,
    'isnew' => empty($current['id']),
    'outline' => implode("\n", $outlineLines),
    'positionoptions' => $positionOptions,
    'recurrenceoptions' => $recurrenceOptions,
    'sesskey' => sesskey(),
    'newurl' => (new moodle_url('/local/ustar/checklist_studio.php', ['id' => 'new']))->out(false),
    'todaydate' => (string)($payload['today']['date'] ?? ''),
    'todayruns' => (int)($payload['today']['runs'] ?? 0),
    'todaycompleted' => (int)($payload['today']['completed'] ?? 0),
    'recent' => $recent,
    'hasrecent' => !empty($recent),
    'checkicon' => \local_ustar\ui::icon('check', 'u-feature-icon'),
];

if ($id === 'new') {
    $data['current'] = [
        'id' => '', 'title' => '', 'description' => '', 'active' => true,
        'recurrence' => 'daily', 'positionIds' => [], 'sections' => [],
    ];
    $data['outline'] = "## Основное\n- Первый пункт";
    foreach ($data['recurrenceoptions'] as &$option) {
        $option['selected'] = $option['id'] === 'daily';
    }
    unset($option);
    foreach ($data['positionoptions'] as &$option) {
        $option['selected'] = false;
    }
    unset($option);
    $data['isnew'] = true;
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/checklist_studio.php', $id !== '' ? ['id' => $id] : []));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Редактор чек-листов | Центр управления USTAR');
$PAGE->set_heading('Центр управления USTAR');

$output = $PAGE->get_renderer('local_ustar');
echo $output->header();
echo $output->render_from_template('local_ustar/checklist_studio', $data);
echo $output->footer();
