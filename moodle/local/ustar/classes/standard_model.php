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
