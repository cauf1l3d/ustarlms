<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Moodle owns the execution and attempt history of imported Studio SCORM. */
final class studio_scorm_runtime {
    /** Import one validated archive into a new Moodle activity and pin its source version. */
    public static function import(int $contentid, int $actorid, string $path, string $filename,
            int $expectedsourceversion): array {
        global $CFG, $DB;
        if (!material_studio::can_manage($actorid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/ustar:hrmanage', 'nopermissions', '');
        }
        view_as::assert_writable();
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/scorm/lib.php');
        require_once($CFG->dirroot . '/mod/scorm/locallib.php');
        require_once($CFG->libdir . '/filelib.php');
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar_content')
            ->get_lock('studio:' . $contentid, 10);
        if (!$lock) {
            throw new \moodle_exception('Материал редактируется в другой сессии.');
        }
        try {
            $tx = $DB->start_delegated_transaction();
            $content = $DB->get_record_sql(
                'SELECT * FROM {local_ustar_content} WHERE id = :id FOR UPDATE',
                ['id' => $contentid], MUST_EXIST);
            $blueprint = $DB->get_record_sql(
                'SELECT * FROM {local_ustar_content_blueprints} WHERE contentid = :id FOR UPDATE',
                ['id' => $contentid], MUST_EXIST);
            if ((string)$content->status !== content::STATUS_DRAFT
                    || (string)$blueprint->kind !== material_studio::KIND_SCORM
                    || (int)$blueprint->sourceversion !== $expectedsourceversion) {
                throw new \moodle_exception('Материал уже изменён. Обновите форму перед загрузкой.');
            }

            // A new course/module keeps the old Moodle SCORM attempts and
            // previously published route versions intact after a package update.
            $key = 'USTAR-SCORM-' . $contentid . '-v' . $expectedsourceversion . '-'
                . bin2hex(random_bytes(3));
            $course = create_course((object)[
                'fullname' => (string)$content->title, 'shortname' => $key, 'idnumber' => $key,
                'category' => \core_course_category::get_default()->id,
                'format' => 'topics', 'numsections' => 1, 'visible' => 1, 'enablecompletion' => 1,
            ]);
            foreach (enrol_get_instances((int)$course->id, false) as $instance) {
                if ((string)$instance->enrol !== 'manual') {
                    $plugin = enrol_get_plugin((string)$instance->enrol);
                    if ($plugin) { $plugin->update_status($instance, ENROL_INSTANCE_DISABLED); }
                }
            }
            $manual = enrol_get_plugin('manual');
            if (!$manual) {
                throw new \moodle_exception('Ручное зачисление Moodle отключено.');
            }
            if (!$DB->record_exists('enrol', [
                    'courseid' => (int)$course->id, 'enrol' => 'manual',
                    'status' => ENROL_INSTANCE_ENABLED])) {
                $roles = get_archetype_roles('student');
                if (!$roles) { throw new \moodle_exception('Не найдена роль обучающегося.'); }
                $manual->add_instance($course, [
                    'status' => ENROL_INSTANCE_ENABLED, 'roleid' => reset($roles)->id,
                ]);
            }

            $draftid = file_get_unused_draft_itemid();
            get_file_storage()->create_file_from_pathname((object)[
                'contextid' => \context_user::instance($actorid)->id,
                'component' => 'user', 'filearea' => 'draft', 'itemid' => $draftid,
                'filepath' => '/', 'filename' => $filename, 'userid' => $actorid,
                'mimetype' => 'application/zip',
            ], $path);
            $module = (object)[
                'modulename' => 'scorm',
                'module' => $DB->get_field('modules', 'id', ['name' => 'scorm'], MUST_EXIST),
                'course' => (int)$course->id, 'section' => 1,
                'name' => (string)$content->title, 'intro' => (string)($content->summary ?? ''),
                'introformat' => FORMAT_PLAIN, 'visible' => 1, 'visibleoncoursepage' => 1,
                'scormtype' => SCORM_TYPE_LOCAL, 'packagefile' => $draftid,
                'width' => 100, 'height' => 600, 'popup' => 0,
                'grademethod' => GRADEHIGHEST, 'maxgrade' => 100, 'maxattempt' => 0,
                'completion' => COMPLETION_TRACKING_AUTOMATIC,
                'completionstatusrequired' => 6, 'completionstatusallscos' => 0,
                'completionview' => 0, 'completionexpected' => 0,
            ];
            $module = add_moduleinfo($module, $course);
            if (!$DB->record_exists('scorm_scoes', ['scorm' => (int)$module->instance])) {
                throw new \invalid_parameter_exception('Moodle не нашёл исполняемую часть в SCORM ZIP. Проверьте manifest.');
            }

            $context = \context_system::instance();
            $fs = get_file_storage();
            $fs->delete_area_files($context->id, 'local_ustar',
                material_studio::FILEAREA_SCORM, (int)$blueprint->id);
            $fs->create_file_from_pathname((object)[
                'contextid' => $context->id, 'component' => 'local_ustar',
                'filearea' => material_studio::FILEAREA_SCORM,
                'itemid' => (int)$blueprint->id, 'filepath' => '/',
                'filename' => $filename, 'userid' => $actorid,
                'mimetype' => 'application/zip',
            ], $path);
            $blueprint->packagestatus = 'imported';
            $blueprint->packagefilename = $filename;
            $blueprint->timemodified = time();
            $blueprint->authorid = $actorid;
            $DB->update_record('local_ustar_content_blueprints', $blueprint);
            $content->sourcekind = content::SOURCE_MOODLE;
            $content->courseid = (int)$course->id;
            $content->cmid = (int)$module->coursemodule;
            $content->timemodified = max(time(), (int)$content->timemodified + 1);
            $content->usermodified = $actorid;
            $DB->update_record('local_ustar_content', $content);
            $DB->insert_record('local_ustar_workflow_events', (object)[
                'entitytype' => 'studio_scorm_runtime', 'entityid' => $contentid,
                'eventtype' => 'scorm_runtime_created', 'actorid' => $actorid,
                'reason' => null,
                'detailsjson' => json_encode([
                    'sourceversion' => $expectedsourceversion, 'cmid' => (int)$module->coursemodule,
                    'courseid' => (int)$course->id, 'filename' => $filename,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'timecreated' => time(),
            ]);
            $tx->allow_commit();
            return ['cmid' => (int)$module->coursemodule, 'courseid' => (int)$course->id];
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally {
            $lock->release();
        }
    }
}
