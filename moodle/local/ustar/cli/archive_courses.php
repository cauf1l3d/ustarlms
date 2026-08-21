<?php
/**
 * Create Moodle-native .mbz backups plus a machine-readable manifest.
 * Production courses are never modified or deleted.
 */
define('CLI_SCRIPT', true);
require(dirname(__DIR__, 3) . '/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

[$options, $unrecognized] = cli_get_params([
    'output' => null,
    'courseid' => 0,
    'help' => false,
], ['h'=>'help']);

if ($options['help']) {
    echo "USTAR Moodle course archive\n\n";
    echo "php local/ustar/cli/archive_courses.php [--output=/absolute/path] [--courseid=10]\n";
    echo "Default output: moodledata/temp/ustar_course_archive_<timestamp>\n";
    exit(0);
}

$output = $options['output'] ?: ($CFG->dataroot . '/temp/ustar_course_archive_' . gmdate('Ymd_His'));
$output = rtrim((string)$output, '/');
if (!is_dir($output) && !mkdir($output, 0770, true)) {
    cli_error('Cannot create output directory: ' . $output);
}
if (!is_writable($output)) {
    cli_error('Output directory is not writable: ' . $output);
}

$admin = get_admin();
if (!$admin) cli_error('Moodle admin account not found');

$params = ['siteid'=>SITEID];
$where = 'id <> :siteid';
if ((int)$options['courseid'] > 0) {
    $where .= ' AND id = :courseid';
    $params['courseid'] = (int)$options['courseid'];
}
$courses = $DB->get_records_select('course', $where, $params, 'id ASC');
if (!$courses) cli_error('No courses found');

$manifest = [
    'generated_at' => gmdate('c'),
    'moodle_release' => (string)($CFG->release ?? ''),
    'courses' => [],
];
$failed = 0;

foreach ($courses as $course) {
    echo 'Backing up #' . $course->id . ' ' . format_string($course->fullname) . ' ... ';
    $entry = [
        'courseid'=>(int)$course->id,
        'categoryid'=>(int)$course->category,
        'fullname'=>(string)$course->fullname,
        'shortname'=>(string)$course->shortname,
        'status'=>'pending',
    ];
    try {
        $bc = new backup_controller(
            backup::TYPE_1COURSE,
            (int)$course->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            (int)$admin->id
        );
        $bc->execute_plan();
        $results = $bc->get_results();
        $file = $results['backup_destination'] ?? null;
        if (!$file instanceof stored_file) {
            throw new moodle_exception('backup destination file missing');
        }
        $safe = clean_filename('course_' . (int)$course->id . '_' . $course->shortname . '.mbz');
        $destination = $output . '/' . $safe;
        if (!$file->copy_content_to($destination)) {
            throw new moodle_exception('cannot copy backup file');
        }
        $bc->destroy();

        $modulecounts = $DB->get_records_sql_menu(
            "SELECT m.name, COUNT(cm.id) AS cnt
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :courseid
           GROUP BY m.name
           ORDER BY m.name",
            ['courseid'=>(int)$course->id]
        );
        $entry += [
            'status'=>'ok',
            'file'=>$safe,
            'bytes'=>filesize($destination),
            'sha256'=>hash_file('sha256', $destination),
            'modules'=>$modulecounts,
            'enablecompletion'=>(int)$course->enablecompletion,
        ];
        echo "OK\n";
    } catch (Throwable $e) {
        $failed++;
        $entry['status'] = 'failed';
        $entry['error'] = $e->getMessage();
        echo 'FAILED: ' . $e->getMessage() . "\n";
        if (isset($bc) && $bc instanceof backup_controller) {
            try { $bc->destroy(); } catch (Throwable $ignored) {}
        }
    }
    $manifest['courses'][] = $entry;
}

$manifestfile = $output . '/manifest.json';
file_put_contents($manifestfile, json_encode($manifest, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
file_put_contents($output . '/SHA256SUMS.txt', implode('', array_map(
    static fn($e) => ($e['status'] ?? '') === 'ok' ? $e['sha256'] . '  ' . $e['file'] . PHP_EOL : '',
    $manifest['courses']
)));

echo 'Output: ' . $output . PHP_EOL;
echo 'Courses: ' . count($manifest['courses']) . ', failed: ' . $failed . PHP_EOL;
echo $failed ? "COURSE_ARCHIVE=PARTIAL\n" : "COURSE_ARCHIVE=OK\n";
exit($failed ? 1 : 0);
