<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Files on Academy tasks and private notes, stored only through Moodle File API. */
final class task_files {
    public const ATTACHMENT = 'task_attachment';
    public const RESULT = 'task_result';
    public const MAX_FILES = 10;

    public static function max_file_bytes(): int {
        global $CFG;
        // A conservative product limit; the site/PHP limit can only reduce it.
        $effective = (int)get_max_upload_file_size((int)($CFG->maxbytes ?? 0));
        return $effective > 0 ? min(50 * 1024 * 1024, $effective) : 50 * 1024 * 1024;
    }

    /** Normalise one browser multipart field. Invalid or partial uploads stop the command. */
    public static function uploaded(string $field = 'attachments'): array {
        $input = $_FILES[$field] ?? null;
        if (!$input) { return []; }
        if (!isset($input['name'], $input['tmp_name'], $input['error'], $input['size'])) {
            throw new \invalid_parameter_exception('Некорректное вложение.');
        }
        $names = is_array($input['name']) ? $input['name'] : [$input['name']];
        if (count($names) > self::MAX_FILES) {
            throw new \invalid_parameter_exception('Можно приложить не более 10 файлов.');
        }
        $out = [];
        $seen = [];
        $total = 0;
        foreach ($names as $key => $name) {
            $error = is_array($input['error']) ? ($input['error'][$key] ?? UPLOAD_ERR_NO_FILE) : $input['error'];
            if ($error === UPLOAD_ERR_NO_FILE) { continue; }
            if ($error !== UPLOAD_ERR_OK) {
                throw new \invalid_parameter_exception('Не удалось загрузить вложение. Проверьте размер файла.');
            }
            $tmp = is_array($input['tmp_name']) ? ($input['tmp_name'][$key] ?? '') : $input['tmp_name'];
            $size = (int)(is_array($input['size']) ? ($input['size'][$key] ?? 0) : $input['size']);
            $filename = is_string($name) ? clean_param($name, PARAM_FILE) : '';
            $total += $size;
            if ($filename === '' || $filename === '.' || isset($seen[$filename])
                    || !is_string($tmp) || !is_uploaded_file($tmp)
                    || $size <= 0 || $size > self::max_file_bytes() || $total > 100 * 1024 * 1024
                    || filesize($tmp) !== $size) {
                throw new \invalid_parameter_exception('Файл повреждён, слишком велик или его имя повторяется.');
            }
            $seen[$filename] = true;
            $out[] = ['filename' => $filename, 'tmp' => $tmp, 'size' => $size];
        }
        return $out;
    }

    /** Must be called by a scoped task command inside its database transaction. */
    public static function store(int $taskid, string $area, array $uploads, int $version = 0): void {
        if (!$uploads) { return; }
        if ($taskid <= 0 || !in_array($area, [self::ATTACHMENT, self::RESULT], true)
                || ($area === self::RESULT && $version <= 0)) {
            throw new \coding_exception('Invalid task file destination');
        }
        $contextid = \context_system::instance()->id;
        $path = $area === self::RESULT ? '/v' . $version . '/' : '/';
        $storage = get_file_storage();
        foreach ($uploads as $upload) {
            if (!is_array($upload) || empty($upload['tmp']) || !is_uploaded_file($upload['tmp'])
                    || empty($upload['filename']) || $upload['filename'] !== clean_param($upload['filename'], PARAM_FILE)
                    || (int)($upload['size'] ?? 0) > self::max_file_bytes()) {
                throw new \invalid_parameter_exception('Недопустимое вложение.');
            }
            $record = [
                'contextid' => $contextid, 'component' => 'local_ustar', 'filearea' => $area,
                'itemid' => $taskid, 'filepath' => $path, 'filename' => $upload['filename'],
            ];
            if ($storage->get_file($contextid, 'local_ustar', $area,
                    $taskid, $path, $upload['filename'])) {
                throw new \invalid_parameter_exception('Файл с таким именем уже приложен. Переименуйте его.');
            }
            $storage->create_file_from_pathname($record, $upload['tmp']);
        }
    }

    /** Only the selected task loads its file list. ACL is checked again by pluginfile. */
    public static function list_for(int $taskid, int $actorid): array {
        learning_tasks::view($taskid, $actorid);
        global $DB;
        $contextid = \context_system::instance()->id;
        $modern = \local_ustar\task_workspace\service::meta($taskid);
        $task = $DB->get_record('local_ustar_learning_tasks', ['id' => $taskid]);
        $versions = $modern ? $DB->get_records('local_ustar_task_reports', ['taskid' => $taskid] +
            ((int)$task->assigneeid !== $actorid ? ['status' => 'final'] : []), 'taskversion DESC', 'id,taskversion', 0, 50) : [];
        $visible = array_column(array_values($versions), 'taskversion');
        $out = [];
        foreach ([self::ATTACHMENT => 'Вложение', self::RESULT => 'Результат'] as $area => $label) {
            $where = 'contextid=:ctx AND component=:component AND filearea=:area AND itemid=:task AND filename<>:directory';
            $params = ['ctx' => $contextid, 'component' => 'local_ustar', 'area' => $area, 'task' => $taskid, 'directory' => '.'];
            if ($modern && $area === self::RESULT) {
                if (!$visible) { continue; }
                [$insql, $inparams] = $DB->get_in_or_equal(array_map(static fn($v) => '/v' . $v . '/', $visible), SQL_PARAMS_NAMED, 'reportpath');
                $where .= ' AND filepath ' . $insql; $params += $inparams;
            }
            foreach ($DB->get_records_select('files', $where, $params, 'timecreated DESC, id DESC', 'id', 0, 250) as $row) {
                $file = get_file_storage()->get_file_by_id($row->id);
                $image = in_array($file->get_mimetype(), ['image/jpeg', 'image/png', 'image/webp'], true);
                $out[] = [
                    'name' => $file->get_filename(), 'label' => $label,
                    'size' => (int)$file->get_filesize(), 'image' => $image,
                    'version' => preg_match('~^/v(\d+)/$~', $file->get_filepath(), $match) ? (int)$match[1] : 0,
                    'url' => \moodle_url::make_pluginfile_url($contextid, 'local_ustar', $area,
                        $taskid, $file->get_filepath(), $file->get_filename(), !$image)->out(false),
                ];
            }
        }
        return $out;
    }

    public static function delete_note_files(int $taskid): void {
        $storage = get_file_storage();
        $contextid = \context_system::instance()->id;
        $storage->delete_area_files($contextid, 'local_ustar', self::ATTACHMENT, $taskid);
    }
}
