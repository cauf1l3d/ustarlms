<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Published grade order is immutable; positions bind a specific version. */
final class grade_ladders {
    /** @var array<string,\stdClass|null>|null */
    private static ?array $bindingcache = null;
    /** @var array<int,array<int,array<string,mixed>>> */
    private static array $versiongrades = [];
    public static function available(): bool {
        global $DB;
        return $DB->get_manager()->table_exists(new \xmldb_table('local_ustar_grade_bindings'));
    }

    /** @return array<int,array<string,mixed>> */
    public static function validate_grades(array $grades): array {
        if (count($grades) < 2 || count($grades) > 20) {
            throw new \invalid_parameter_exception('Укажите от двух до двадцати ступеней.');
        }
        $seen = []; $clean = [];
        foreach ($grades as $grade) {
            $key = trim((string)($grade['id'] ?? ''));
            $name = trim(clean_param((string)($grade['name'] ?? ''), PARAM_TEXT));
            if (!preg_match('/^[a-z][a-z0-9_]{1,31}$/', $key) || isset($seen[$key])
                    || $name === '' || \core_text::strlen($name) > 255) {
                throw new \invalid_parameter_exception('Проверьте уникальные ключи и названия ступеней.');
            }
            $seen[$key] = true;
            $clean[] = array_replace($grade, ['id' => $key, 'name' => $name]);
        }
        return $clean;
    }

    /** @return array<int,\stdClass> */
    public static function all(): array {
        global $DB;
        return self::available() ? array_values($DB->get_records('local_ustar_grade_ladders',
            [], 'name ASC, id ASC')) : [];
    }

    public static function create(string $name, array $grades, int $actorid): \stdClass {
        global $DB;
        self::assert_editor($actorid);
        $name = trim(clean_param($name, PARAM_TEXT));
        if ($name === '' || \core_text::strlen($name) > 255) {
            throw new \invalid_parameter_exception('Укажите название лестницы.');
        }
        $grades = self::validate_grades($grades);
        $now = time();
        $tx = $DB->start_delegated_transaction();
        try {
            $id = (int)$DB->insert_record('local_ustar_grade_ladders', (object)[
                'name' => $name, 'status' => 'active',
                'draftjson' => json_encode($grades, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'revision' => 1, 'createdby' => $actorid, 'timecreated' => $now, 'timemodified' => $now,
            ]);
            people::log_action($actorid, null, 'grade_ladder_created', ['ladderid' => $id]);
            $tx->allow_commit();
            return $DB->get_record('local_ustar_grade_ladders', ['id' => $id], '*', MUST_EXIST);
        } catch (\Throwable $e) { $tx->rollback($e); }
    }

    public static function save_draft(int $id, array $grades, int $expectedrevision, int $actorid): \stdClass {
        global $DB;
        self::assert_editor($actorid);
        $grades = self::validate_grades($grades);
        $tx = $DB->start_delegated_transaction();
        try {
            $ladder = $DB->get_record_sql(
                'SELECT * FROM {local_ustar_grade_ladders} WHERE id = :id FOR UPDATE', ['id' => $id], MUST_EXIST);
            if ($ladder->status !== 'active' || (int)$ladder->revision !== $expectedrevision) {
                throw new \invalid_parameter_exception('Черновик изменился или архивирован. Обновите страницу.');
            }
            $old = array_column(json_decode((string)$ladder->draftjson, true) ?: [], null, 'id');
            foreach ($grades as &$grade) {
                $grade = array_replace($old[$grade['id']] ?? [], $grade);
            }
            unset($grade);
            $ladder->draftjson = json_encode($grades, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $ladder->revision++;
            $ladder->timemodified = time();
            $DB->update_record('local_ustar_grade_ladders', $ladder);
            people::log_action($actorid, null, 'grade_ladder_draft_saved',
                ['ladderid' => $id, 'revision' => (int)$ladder->revision]);
            $tx->allow_commit();
            return $ladder;
        } catch (\Throwable $e) { $tx->rollback($e); }
    }

    public static function publish(int $id, int $expectedrevision, int $actorid): \stdClass {
        global $DB;
        self::assert_editor($actorid);
        $tx = $DB->start_delegated_transaction();
        try {
            $ladder = $DB->get_record_sql(
                'SELECT * FROM {local_ustar_grade_ladders} WHERE id = :id FOR UPDATE', ['id' => $id], MUST_EXIST);
            if ($ladder->status !== 'active' || (int)$ladder->revision !== $expectedrevision) {
                throw new \invalid_parameter_exception('Лестница изменилась. Обновите страницу.');
            }
            $grades = self::validate_grades(json_decode((string)$ladder->draftjson, true) ?: []);
            $json = json_encode($grades, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $hash = hash('sha256', $json);
            $latest = $DB->get_records('local_ustar_grade_ladder_ver', ['ladderid' => $id],
                'versionno DESC', '*', 0, 1);
            $previous = $latest ? reset($latest) : null;
            if ($previous && hash_equals((string)$previous->gradehash, $hash)) {
                $tx->allow_commit();
                return $previous;
            }
            $versionid = (int)$DB->insert_record('local_ustar_grade_ladder_ver', (object)[
                'ladderid' => $id, 'versionno' => $previous ? (int)$previous->versionno + 1 : 1,
                'gradesjson' => $json, 'gradehash' => $hash,
                'createdby' => $actorid, 'timecreated' => time(),
            ]);
            people::log_action($actorid, null, 'grade_ladder_published',
                ['ladderid' => $id, 'versionid' => $versionid, 'gradehash' => $hash]);
            $tx->allow_commit();
            return $DB->get_record('local_ustar_grade_ladder_ver', ['id' => $versionid], '*', MUST_EXIST);
        } catch (\Throwable $e) { $tx->rollback($e); }
    }

    public static function archive(int $id, int $expectedrevision, int $actorid): void {
        global $DB;
        self::assert_editor($actorid);
        $tx = $DB->start_delegated_transaction();
        try {
            $ladder = $DB->get_record_sql(
                'SELECT * FROM {local_ustar_grade_ladders} WHERE id = :id FOR UPDATE', ['id' => $id], MUST_EXIST);
            if ($ladder->status !== 'active' || (int)$ladder->revision !== $expectedrevision) {
                throw new \invalid_parameter_exception('Лестница изменилась. Обновите страницу.');
            }
            $ladder->status = 'archived';
            $ladder->revision++;
            $ladder->timemodified = time();
            $DB->update_record('local_ustar_grade_ladders', $ladder);
            people::log_action($actorid, null, 'grade_ladder_archived', ['ladderid' => $id]);
            $tx->allow_commit();
        } catch (\Throwable $e) { $tx->rollback($e); }
    }

    /** A single current binding per position; old version rows remain immutable. */
    public static function bind(string $positionid, int $versionid, int $expectedrevision,
            int $actorid, string $reason): \stdClass {
        global $DB;
        self::assert_editor($actorid);
        $positionid = clean_param($positionid, PARAM_ALPHANUMEXT);
        $reason = trim(clean_param($reason, PARAM_TEXT));
        if ($reason === '') { throw new \invalid_parameter_exception('Укажите основание изменения привязки.'); }
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')
            ->get_lock('grade-binding:' . sha1($positionid), 10);
        if (!$lock) { throw new \moodle_exception('Привязка изменяется. Повторите попытку.'); }
        try {
            $tx = $DB->start_delegated_transaction();
            try {
                $DB->get_record_sql('SELECT * FROM {local_ustar_structure} WHERE name = :name FOR UPDATE',
                    ['name' => structure::NAME_STRUCTURE], MUST_EXIST);
                $positions = people::position_map(structure::get(structure::NAME_STRUCTURE));
                if ($positionid === '' || !isset($positions[$positionid])) {
                    throw new \invalid_parameter_exception('Должность не найдена.');
                }
                $version = $DB->get_record('local_ustar_grade_ladder_ver', ['id' => $versionid], '*', MUST_EXIST);
                $ladder = $DB->get_record_sql('SELECT * FROM {local_ustar_grade_ladders}
                    WHERE id = :id FOR UPDATE', ['id' => (int)$version->ladderid], MUST_EXIST);
                if ($ladder->status !== 'active') {
                    throw new \invalid_parameter_exception('Архивную лестницу нельзя назначить должности.');
                }
                $binding = $DB->get_record_sql('SELECT * FROM {local_ustar_grade_bindings}
                    WHERE positionid = :positionid FOR UPDATE', ['positionid' => $positionid], IGNORE_MISSING);
                if (($binding && (int)$binding->revision !== $expectedrevision)
                        || (!$binding && $expectedrevision !== 0)) {
                    throw new \invalid_parameter_exception('Привязка изменилась. Обновите страницу.');
                }
                if ($binding && (int)$binding->ladderversionid === $versionid) {
                    $tx->allow_commit();
                    return $binding;
                }
                $oldid = $binding ? (int)$binding->ladderversionid : 0;
                if ($binding) {
                    $binding->ladderversionid = $versionid;
                    $binding->revision++;
                    $binding->timemodified = time();
                    $binding->usermodified = $actorid;
                    $DB->update_record('local_ustar_grade_bindings', $binding);
                } else {
                    $binding = (object)['positionid' => $positionid, 'ladderversionid' => $versionid,
                        'revision' => 1, 'timemodified' => time(), 'usermodified' => $actorid];
                    $binding->id = $DB->insert_record('local_ustar_grade_bindings', $binding);
                }
                people::log_action($actorid, null, 'grade_binding_changed', [
                    'positionid' => $positionid, 'fromversionid' => $oldid,
                    'toversionid' => $versionid, 'reason' => $reason,
                ]);
                $tx->allow_commit();
                self::$bindingcache = null;
                return $binding;
            } catch (\Throwable $e) { $tx->rollback($e); }
        } finally { $lock->release(); }
    }

    /** Explicit absence overrides the pre-migration name fallback without deleting history. */
    public static function unbind(string $positionid, int $expectedrevision,
            int $actorid, string $reason): void {
        global $DB;
        self::assert_editor($actorid);
        $positionid = clean_param($positionid, PARAM_ALPHANUMEXT);
        $reason = trim(clean_param($reason, PARAM_TEXT));
        if ($positionid === '' || $reason === '') {
            throw new \invalid_parameter_exception('Укажите должность и основание снятия привязки.');
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')
            ->get_lock('grade-binding:' . sha1($positionid), 10);
        if (!$lock) { throw new \moodle_exception('Привязка изменяется. Повторите попытку.'); }
        try {
            $tx = $DB->start_delegated_transaction();
            try {
                $binding = $DB->get_record_sql('SELECT * FROM {local_ustar_grade_bindings}
                    WHERE positionid = :positionid FOR UPDATE', ['positionid' => $positionid], MUST_EXIST);
                if ((int)$binding->revision !== $expectedrevision || empty($binding->ladderversionid)) {
                    throw new \invalid_parameter_exception('Привязка изменилась. Обновите страницу.');
                }
                $oldid = (int)$binding->ladderversionid;
                $binding->ladderversionid = null;
                $binding->revision++;
                $binding->timemodified = time();
                $binding->usermodified = $actorid;
                $DB->update_record('local_ustar_grade_bindings', $binding);
                people::log_action($actorid, null, 'grade_binding_removed', [
                    'positionid' => $positionid, 'fromversionid' => $oldid, 'reason' => $reason,
                ]);
                $tx->allow_commit();
                self::$bindingcache = null;
            } catch (\Throwable $e) { $tx->rollback($e); }
        } finally { $lock->release(); }
    }

    public static function binding(string $positionid): ?\stdClass {
        global $DB;
        if (!self::available() || $positionid === '') { return null; }
        self::$bindingcache ??= [];
        if (!array_key_exists($positionid, self::$bindingcache)) {
            self::$bindingcache[$positionid] = $DB->get_record('local_ustar_grade_bindings',
                ['positionid' => $positionid], '*', IGNORE_MISSING) ?: null;
        }
        return self::$bindingcache[$positionid];
    }

    /** @return array<int,array<string,mixed>>|null */
    public static function grades_for_position(string $positionid): ?array {
        global $DB;
        $binding = self::binding($positionid);
        if (!$binding) { return null; }
        $versionid = (int)$binding->ladderversionid;
        if ($versionid <= 0) { return null; }
        if (isset(self::$versiongrades[$versionid])) { return self::$versiongrades[$versionid]; }
        $version = $DB->get_record('local_ustar_grade_ladder_ver',
            ['id' => $versionid], '*', MUST_EXIST);
        return self::$versiongrades[$versionid] = self::validate_grades(
            json_decode((string)$version->gradesjson, true) ?: []);
    }

    /** Migration preserves the original catalog and only explicit stable-ID config bindings. */
    public static function seed_legacy(): void {
        global $DB;
        $data = json_decode(file_get_contents(__DIR__ . '/../data/consultant_grades.json'), true,
            512, JSON_THROW_ON_ERROR);
        $grades = self::validate_grades((array)($data['grades'] ?? []));
        $json = json_encode($grades, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $now = time();
        $ladder = $DB->get_record('local_ustar_grade_ladders',
            ['name' => 'Грейды консультантов'], '*', IGNORE_MISSING);
        $ladderid = $ladder ? (int)$ladder->id : (int)$DB->insert_record('local_ustar_grade_ladders', (object)[
            'name' => 'Грейды консультантов', 'status' => 'active', 'draftjson' => $json,
            'revision' => 1, 'createdby' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $version = $DB->get_record('local_ustar_grade_ladder_ver',
            ['ladderid' => $ladderid, 'versionno' => 1], '*', IGNORE_MISSING);
        $versionid = $version ? (int)$version->id : (int)$DB->insert_record('local_ustar_grade_ladder_ver', (object)[
            'ladderid' => $ladderid, 'versionno' => 1, 'gradesjson' => $json,
            'gradehash' => hash('sha256', $json), 'createdby' => 0, 'timecreated' => $now,
        ]);
        $positions = people::position_map(structure::get(structure::NAME_STRUCTURE));
        $keys = array_column($grades, 'id');
        $configs = $DB->get_records_select('config_plugins',
            'plugin = :plugin AND ' . $DB->sql_like('name', ':prefix'),
            ['plugin' => 'local_ustar', 'prefix' => $DB->sql_like_escape('careergrade_') . '%']);
        foreach ($configs as $config) {
            $positionid = substr((string)$config->name, strlen('careergrade_'));
            if (!isset($positions[$positionid]) || !in_array((string)$config->value, $keys, true)) {
                continue;
            }
            $existing = $DB->get_record('local_ustar_grade_bindings',
                ['positionid' => $positionid], 'id,ladderversionid', IGNORE_MISSING);
            if ($existing && (int)$existing->ladderversionid !== $versionid) { continue; }
            if (!$existing) {
                $DB->insert_record('local_ustar_grade_bindings', (object)[
                    'positionid' => $positionid, 'ladderversionid' => $versionid,
                    'revision' => 1, 'timemodified' => $now, 'usermodified' => 0,
                ]);
            }
            $DB->set_field('local_ustar_grade_rules', 'ladderversionid', $versionid,
                ['positionid' => $positionid]);
            foreach ($DB->get_records('local_ustar_employee_grades', ['positionid' => $positionid]) as $employeegrade) {
                if (in_array((string)$employeegrade->gradekey, $keys, true)) {
                    $DB->set_field('local_ustar_employee_grades', 'ladderversionid', $versionid,
                        ['id' => (int)$employeegrade->id]);
                }
            }
        }
        self::$bindingcache = null;
    }

    private static function assert_editor(int $actorid): void {
        global $USER;
        if (!self::available()) { throw new \moodle_exception('Сначала обновите базу грейдов.'); }
        if ((int)$USER->id !== $actorid || !has_capability('local/ustar:hrmanage',
                \context_system::instance(), $actorid)) {
            throw new \required_capability_exception(
                \context_system::instance(), 'local/ustar:hrmanage', 'nopermissions', '');
        }
        view_as::assert_writable();
    }
}
