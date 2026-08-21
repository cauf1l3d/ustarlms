<?php

require_once(__DIR__ . '/../../config.php');

require_login();

global $DB;

$context = context_system::instance();
require_capability('local/ustar:hr', $context);

$contentid = required_param('contentid', PARAM_INT);

$content = $DB->get_record(
    'local_ustar_content',
    ['id' => $contentid],
    'id,title,ackrequired',
    MUST_EXIST
);

if (empty($content->ackrequired)) {
    throw new moodle_exception('Для материала не требуется подтверждение ознакомления');
}

$report = \local_ustar\content_ack_report::report($contentid);

$safename = clean_filename(
    'ustar_ack_' . $contentid . '_' . $report['versionlabel'] . '_' . gmdate('Ymd_His') . '.csv'
);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $safename . '"');
header('Cache-Control: private, no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$out = fopen('php://output', 'wb');
if ($out === false) {
    throw new moodle_exception('Не удалось сформировать CSV');
}

// UTF-8 BOM keeps Cyrillic readable when the CSV is opened directly in Excel.
fwrite($out, "\xEF\xBB\xBF");

// Neutralize spreadsheet-formula prefixes in user/content controlled cells.
$csvsafe = static function($value): string {
    $value = (string)$value;
    if ($value !== '' && preg_match('/^[=+\-@]/u', $value)) {
        return "'" . $value;
    }
    return $value;
};

fputcsv($out, [
    'content_id',
    'material',
    'version_id',
    'version',
    'user_id',
    'username',
    'fullname',
    'department',
    'position',
    'status',
    'acknowledged_at',
    'method',
], ';', '"', '\\');

foreach ($report['people'] as $person) {
    $acked = !empty($person['acked']);
    $acktime = $acked && !empty($person['acktime'])
        ? userdate((int)$person['acktime'], '%Y-%m-%d %H:%M:%S')
        : '';

    fputcsv($out, [
        (int)$report['contentid'],
        $csvsafe($report['title']),
        (int)$report['versionid'],
        $csvsafe($report['versionlabel']),
        (int)$person['userid'],
        $csvsafe($person['username']),
        $csvsafe($person['fullname']),
        $csvsafe($person['department']),
        $csvsafe($person['position']),
        $acked ? 'acknowledged' : 'pending',
        $csvsafe($acktime),
        $csvsafe($person['ackmethod'] ?? ''),
    ], ';', '"', '\\');
}

fclose($out);
exit;
