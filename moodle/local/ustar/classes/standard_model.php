<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Stable standards with immutable requirement versions.
 *
 * Routes may change their presentation and ordering independently; a standard
 * remains a named domain entity whose published version can be referenced by
 * gates, evidence and future route controllers.
 */
final class standard_model {
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    private const TYPES = [
        'learning', 'assessment', 'practice',
        'manager_review', 'checklist', 'certification',
    ];
    private const OUTCOMES = ['observed', 'completed', 'passed'];

    /** The published qualification contract for one stable position ID. */
    public static function position_code(string $positionid): string {
        return 'position_' . substr(hash('sha256', $positionid), 0, 40);
    }

    public static function current_position(string $positionid): ?\stdClass {
        return self::current(self::position_code($positionid));
    }

    /** Publish a reviewed snapshot of the editable position matrix. */
    public static function publish_position_matrix(string $positionid, string $expectedhash, int $actorid): \stdClass {
        global $DB;
        require_capability('local/ustar:admin', \context_system::instance(), $actorid);
        view_as::assert_writable();
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar_standards')
            ->get_lock('position-publish:' . self::position_code($positionid), 10);
        if (!$lock) {
            throw new \moodle_exception('Стандарт этой должности сейчас публикуется. Повторите попытку.');
        }
        try {
            $transaction = $DB->start_delegated_transaction();
            $DB->get_record_sql('SELECT id FROM {local_ustar_structure} WHERE name = :name FOR UPDATE',
                ['name' => structure::NAME_STRUCTURE], MUST_EXIST);
            $structure = structure::get(structure::NAME_STRUCTURE);
            $position = people::position_map($structure)[$positionid] ?? null;
            $matrix = $structure['matrix'][$positionid] ?? [];
            if (!$position || !is_array($matrix) || !$matrix
                    || !hash_equals(self::matrix_hash($matrix), $expectedhash)) {
                throw new \moodle_exception('Требования должности изменились или пусты. Обновите страницу перед публикацией.');
            }
            $skills = [];
            foreach ($structure['skills'] ?? [] as $skill) {
                $skills[(string)$skill['id']] = true;
            }
            $requirements = [];
            foreach ($matrix as $skillid => $level) {
                if (!isset($skills[(string)$skillid]) || (int)$level < 1 || (int)$level > 5) {
                    throw new \moodle_exception('Матрица содержит недействительный навык или уровень.');
                }
                $requirements[] = ['type' => 'practice', 'outcome' => 'passed',
                    'sourcekind' => 'ustar_skill', 'sourceid' => (string)$skillid,
                    'targetlevel' => (int)$level, 'required' => true];
            }
            if (self::missing_position_sources($positionid, $matrix)) {
                throw new \moodle_exception('Для части навыков нет действующего обязательного источника подтверждения. Настройте подтверждения перед публикацией.');
            }
            $active = self::current_position($positionid);
            if ($active) {
                $activeitems = json_decode((string)$active->requirementsjson, true);
                $activematrix = [];
                $canonical = is_array($activeitems) && count($activeitems) === count($requirements);
                foreach (is_array($activeitems) ? $activeitems : [] as $item) {
                    if (($item['type'] ?? '') !== 'practice' || ($item['outcome'] ?? '') !== 'passed'
                            || ($item['sourcekind'] ?? '') !== 'ustar_skill' || empty($item['required'])) {
                        $canonical = false;
                        break;
                    }
                    $activematrix[(string)($item['sourceid'] ?? '')] = (int)($item['targetlevel'] ?? 0);
                }
                if ($canonical && hash_equals(self::matrix_hash($matrix), self::matrix_hash($activematrix))) {
                    $transaction->allow_commit();
                    return $active;
                }
            }
            $standard = self::create(self::position_code($positionid),
                'Стандарт должности: ' . (string)$position['name'],
                'Опубликованный снимок обязательных навыков должности.', $actorid);
            $version = self::save_version((int)$standard->id,
                ['requirements' => $requirements], $actorid);
            $published = self::publish((int)$standard->id, (int)$version->id, $actorid);
            $transaction->allow_commit();
            return $published;
        } catch (\Throwable $e) {
            if (isset($transaction)) { $transaction->rollback($e); }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    /** @param array<string,int> $matrix */
    public static function matrix_hash(array $matrix): string {
        ksort($matrix, SORT_STRING);
        return hash('sha256', json_encode($matrix, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** Skill IDs with no runnable, required evidence in this position scope. */
    public static function missing_position_sources(string $positionid, array $matrix): array {
        global $DB;
        if (!$matrix) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($matrix), SQL_PARAMS_NAMED, 'skill');
        $params['positionid'] = $positionid;
        $rows = $DB->get_records_sql(
            "SELECT e.id, e.skillid, e.evidencetype, e.courseid, e.cmid,
                    cm.id AS moduleid, cm.course AS modulecourse,
                    cm.completion, cm.deletioninprogress, c.id AS courseexists
               FROM {local_ustar_skill_evidence} e
          LEFT JOIN {course_modules} cm ON cm.id = e.cmid
          LEFT JOIN {course} c ON c.id = e.courseid
              WHERE e.active = 1 AND e.required = 1 AND e.skillid {$insql}
                AND (e.positionid = :positionid OR e.positionid IS NULL OR e.positionid = '')",
            $params
        );
        $courseids = [];
        foreach ($rows as $row) {
            if (empty($row->cmid) && (string)$row->evidencetype === 'learning'
                    && !empty($row->courseexists)) {
                $courseids[(int)$row->courseid] = (int)$row->courseid;
            }
        }
        $tracked = [];
        if ($courseids) {
            [$coursesql, $courseparams] = $DB->get_in_or_equal(array_values($courseids), SQL_PARAMS_NAMED, 'course');
            foreach ($DB->get_records_sql(
                "SELECT course, COUNT(id) AS total FROM {course_modules}
                  WHERE course {$coursesql} AND deletioninprogress = 0 AND completion <> 0
               GROUP BY course", $courseparams) as $course) {
                $tracked[(int)$course->course] = true;
            }
        }
        $covered = [];
        foreach ($rows as $row) {
            if (!in_array((string)$row->evidencetype, ['learning', 'assessment'], true)) {
                continue;
            }
            if (!empty($row->cmid)) {
                if (!empty($row->moduleid) && !(int)$row->deletioninprogress && (int)$row->completion > 0
                        && (empty($row->courseid) || (int)$row->modulecourse === (int)$row->courseid)) {
                    $covered[(string)$row->skillid] = true;
                }
            } else if ((string)$row->evidencetype === 'learning' && isset($tracked[(int)$row->courseid])) {
                $covered[(string)$row->skillid] = true;
            }
        }
        return array_values(array_diff(array_keys($matrix), array_keys($covered)));
    }

    /** @return array<int,array<string,mixed>> */
    public static function normalize_requirements(array $requirements): array {
        $normalized = [];
        foreach ($requirements as $requirement) {
            if (!is_array($requirement)) {
                continue;
            }
            $type = clean_param((string)($requirement['type'] ?? ''), PARAM_ALPHANUMEXT);
            $outcome = clean_param((string)($requirement['outcome'] ?? ''), PARAM_ALPHANUMEXT);
            if (!in_array($type, self::TYPES, true) || !in_array($outcome, self::OUTCOMES, true)) {
                continue;
            }
            $sourcekind = clean_param((string)($requirement['sourcekind'] ?? ''), PARAM_ALPHANUMEXT);
            $sourceid = trim((string)($requirement['sourceid'] ?? ''));
            if ($sourcekind === '' || $sourceid === '') {
                continue;
            }
            $normalized[] = [
                'type' => $type,
                'outcome' => $outcome,
                'sourcekind' => $sourcekind,
                'sourceid' => \core_text::substr($sourceid, 0, 128),
                'required' => !array_key_exists('required', $requirement) || !empty($requirement['required']),
            ];
            if ($sourcekind === 'ustar_skill') {
                $level = (int)($requirement['targetlevel'] ?? 0);
                if ($level < 1 || $level > 5) {
                    array_pop($normalized);
                    continue;
                }
                $normalized[array_key_last($normalized)]['targetlevel'] = $level;
            }
        }
        return $normalized;
    }

    public static function create(string $code, string $title, string $description, int $actorid): \stdClass {
        global $DB;
        require_capability('local/ustar:admin', \context_system::instance(), $actorid);
        $code = \core_text::substr(trim(clean_param($code, PARAM_ALPHANUMEXT)), 0, 64);
        $title = \core_text::substr(trim($title), 0, 255);
        if ($code === '' || $title === '') {
            throw new \invalid_parameter_exception('Standard code and title are required');
        }
        $existing = $DB->get_record('local_ustar_standards', ['code' => $code], '*', IGNORE_MISSING);
        if ($existing) {
            return $existing;
        }
        $now = time();
        $id = (int)$DB->insert_record('local_ustar_standards', (object)[
            'code' => $code,
            'title' => $title,
            'description' => trim($description),
            'status' => self::STATUS_DRAFT,
            'activeversionid' => null,
            'ownerid' => $actorid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        return $DB->get_record('local_ustar_standards', ['id' => $id], '*', MUST_EXIST);
    }

    public static function save_version(int $standardid, array $data, int $actorid): \stdClass {
        global $DB;
        require_capability('local/ustar:admin', \context_system::instance(), $actorid);
        $factory = \core\lock\lock_config::get_lock_factory('local_ustar_standards');
        $lock = $factory->get_lock('standard:' . $standardid, 10);
        if (!$lock) {
            throw new \moodle_exception('Стандарт сейчас изменяется другим пользователем');
        }
        try {
            $transaction = $DB->start_delegated_transaction();
            $standard = $DB->get_record('local_ustar_standards', ['id' => $standardid], '*', MUST_EXIST);
            $latest = $DB->get_record_sql(
                'SELECT * FROM {local_ustar_standard_ver}
                  WHERE standardid = :standardid
               ORDER BY versionno DESC, id DESC',
                ['standardid' => $standardid],
                IGNORE_MULTIPLE
            );
            $requirements = self::normalize_requirements($data['requirements'] ?? []);
            if (!$requirements) {
                throw new \invalid_parameter_exception('Standard requires at least one valid evidence requirement');
            }
            $policy = (string)($data['renewalpolicy'] ?? route_model::RENEW_KEEP);
            if (!in_array($policy, [
                route_model::RENEW_KEEP,
                route_model::RENEW_ALL,
                route_model::RENEW_EXPIRY,
                route_model::RENEW_MANUAL,
            ], true)) {
                $policy = route_model::RENEW_KEEP;
            }
            $now = time();
            $id = (int)$DB->insert_record('local_ustar_standard_ver', (object)[
                'standardid' => $standardid,
                'versionno' => $latest ? (int)$latest->versionno + 1 : 1,
                'requirementsjson' => json_encode(
                    $requirements,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
                'renewalpolicy' => $policy,
                'validdays' => max(0, min(3650, (int)($data['validdays'] ?? 0))),
                'status' => self::STATUS_DRAFT,
                'effectivedate' => null,
                'createdby' => $actorid,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            $standard->timemodified = max($now, (int)$standard->timemodified + 1);
            $DB->update_record('local_ustar_standards', $standard);
            $transaction->allow_commit();
            return $DB->get_record('local_ustar_standard_ver', ['id' => $id], '*', MUST_EXIST);
        } finally {
            $lock->release();
        }
    }

    public static function publish(int $standardid, int $versionid, int $actorid): \stdClass {
        global $DB;
        require_capability('local/ustar:admin', \context_system::instance(), $actorid);
        $factory = \core\lock\lock_config::get_lock_factory('local_ustar_standards');
        $lock = $factory->get_lock('standard:' . $standardid, 10);
        if (!$lock) {
            throw new \moodle_exception('Стандарт сейчас изменяется другим пользователем');
        }
        try {
            $transaction = $DB->start_delegated_transaction();
            $standard = $DB->get_record('local_ustar_standards', ['id' => $standardid], '*', MUST_EXIST);
            $version = $DB->get_record(
                'local_ustar_standard_ver',
                ['id' => $versionid, 'standardid' => $standardid],
                '*',
                MUST_EXIST
            );
            if ((string)$version->status === self::STATUS_PUBLISHED
                    && (int)$standard->activeversionid === $versionid) {
                $transaction->allow_commit();
                return $version;
            }
            if ((string)$version->status !== self::STATUS_DRAFT) {
                throw new \invalid_parameter_exception('Only a draft standard version can be published');
            }
            $now = time();
            $DB->execute(
                'UPDATE {local_ustar_standard_ver}
                    SET status = :archived, timemodified = :modified
                  WHERE standardid = :standardid
                    AND status = :published
                    AND id <> :versionid',
                [
                    'archived' => self::STATUS_ARCHIVED,
                    'modified' => $now,
                    'standardid' => $standardid,
                    'published' => self::STATUS_PUBLISHED,
                    'versionid' => $versionid,
                ]
            );
            $version->status = self::STATUS_PUBLISHED;
            $version->effectivedate = $now;
            $version->timemodified = max($now, (int)$version->timemodified + 1);
            $DB->update_record('local_ustar_standard_ver', $version);
            $standard->status = self::STATUS_PUBLISHED;
            $standard->activeversionid = $versionid;
            $standard->timemodified = max($now, (int)$standard->timemodified + 1);
            $DB->update_record('local_ustar_standards', $standard);
            $transaction->allow_commit();
            return $version;
        } finally {
            $lock->release();
        }
    }

    public static function current(string $code, ?int $at = null): ?\stdClass {
        global $DB;
        $at = $at ?? time();
        $record = $DB->get_record_sql(
            'SELECT v.*
               FROM {local_ustar_standards} s
               JOIN {local_ustar_standard_ver} v ON v.id = s.activeversionid
              WHERE s.code = :code
                AND s.status = :standardstatus
                AND v.status = :versionstatus
                AND (v.effectivedate IS NULL OR v.effectivedate <= :attime)',
            [
                'code' => $code,
                'standardstatus' => self::STATUS_PUBLISHED,
                'versionstatus' => self::STATUS_PUBLISHED,
                'attime' => $at,
            ],
            IGNORE_MISSING
        );
        return $record ?: null;
    }
}
