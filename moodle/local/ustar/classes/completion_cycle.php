<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Immutable identity for one independently verified route completion cycle. */
final class completion_cycle {
    private static function canonicalize($value) {
        if (!is_array($value)) { return $value; }
        if (array_is_list($value)) { return array_map([self::class, 'canonicalize'], $value); }
        ksort($value);
        foreach ($value as $key => $item) { $value[$key] = self::canonicalize($item); }
        return $value;
    }

    private static function logical_point(int $pointid): int {
        global $DB;
        $seen = [];
        $current = $pointid;
        while ($current > 0) {
            if (isset($seen[$current]) || count($seen) >= 64) {
                throw new \moodle_exception('Route point ancestry cycle');
            }
            $seen[$current] = true;
            $point = $DB->get_record('local_ustar_route_points', ['id' => $current], 'id,sourcepointid', MUST_EXIST);
            if (empty($point->sourcepointid)) { return (int)$point->id; }
            $current = (int)$point->sourcepointid;
        }
        return $pointid;
    }


    /**
     * Latest confirmed completion of the same logical route point. Expiration
     * does not erase a historical completion; revoked/error states never match.
     */
    public static function latest_confirmed(int $userid, int $pointid): ?\stdClass {
        global $DB;
        if ($userid <= 0 || $pointid <= 0
                || !$DB->get_manager()->table_exists(new \xmldb_table('local_ustar_completion_cycle'))) {
            return null;
        }
        $logicalpointid = self::logical_point($pointid);
        return $DB->get_record_sql(
            'SELECT *
               FROM {local_ustar_completion_cycle}
              WHERE userid = :userid
                AND logicalpointid = :logicalpointid
                AND status = :status
           ORDER BY completedat DESC, id DESC',
            ['userid' => $userid, 'logicalpointid' => $logicalpointid, 'status' => 'confirmed'],
            IGNORE_MULTIPLE
        ) ?: null;
    }

    /** Read verified history without reviving revoked facts from legacy projections. */
    public static function prior_verified(int $userid, int $pointid, int $versionid): ?\stdClass {
        global $DB;
        $logicalpointid = self::logical_point($pointid);
        $params = ['userid' => $userid, 'logicalpointid' => $logicalpointid, 'versionid' => $versionid];
        if ($DB->get_manager()->table_exists(new \xmldb_table('local_ustar_completion_cycle'))) {
            // A revoked canonical fact must never be revived by a stale projection.
            $rows = $DB->get_records_select('local_ustar_completion_cycle',
                'userid = :userid AND logicalpointid = :logicalpointid AND versionid = :versionid',
                $params, 'completedat DESC, id DESC', '*', 0, 1);
            if ($rows) {
                $cycle = reset($rows);
                return $cycle->status === 'confirmed' ? $cycle : null;
            }
        }
        $progress = $DB->get_record('local_ustar_route_progress', [
            'userid' => $userid, 'pointid' => $pointid, 'versionid' => $versionid,
            'status' => 'complete',
        ]);
        if (!$progress || (int)$progress->completedat <= 0) { return null; }
        $evidence = json_decode((string)$progress->evidencejson, true);
        if (!is_array($evidence) || !self::verified_evidence($evidence, (int)$progress->completedat)) {
            return null;
        }
        $progress->cyclekey = 'legacy-progress:' . $progress->id . ':' . $versionid;
        return $progress;
    }

    private static function verified_evidence(array $evidence, int $completedat): bool {
        if (($evidence['mode'] ?? '') === 'assessment_lifecycle') {
            return ($evidence['status'] ?? '') === 'passed'
                && (int)($evidence['cycle'] ?? 0) > 0
                && (int)($evidence['verifiedcompletedat'] ?? 0) === $completedat;
        }
        if (($evidence['mode'] ?? '') !== 'evaluated') { return false; }
        $required = false;
        foreach ($evidence['requirements'] ?? [] as $fact) {
            if (empty($fact['required'])) { continue; }
            $required = true;
            if (empty($fact['satisfied']) || !empty($fact['failed'])) { return false; }
        }
        return $required;
    }

    /** Restore or create the cycle represented by the current progress projection. */
    public static function for_progress(\stdClass $progress): ?\stdClass {
        $evidence = json_decode((string)$progress->evidencejson, true);
        if (!is_array($evidence)) { throw new \moodle_exception('Completion evidence is invalid'); }
        return self::confirm(
            (int)$progress->userid,
            (int)$progress->pointid,
            (int)$progress->versionid,
            (int)$progress->completedat,
            (int)($progress->expiresat ?? 0),
            $evidence
        );
    }

    /** Record one verified cycle. Redelivery returns the original row. */
    public static function confirm(
        int $userid,
        int $pointid,
        int $versionid,
        int $completedat,
        int $expiresat,
        array $evidence
    ): ?\stdClass {
        global $DB;
        if ($userid <= 0 || $pointid <= 0 || $versionid <= 0 || $completedat <= 0) {
            throw new \invalid_parameter_exception('A confirmed completion requires user, point, version and timestamp');
        }
        $mode = (string)($evidence['mode'] ?? '');
        if ($mode === 'inherited') { return null; }
        if (!self::verified_evidence($evidence, $completedat)) {
            throw new \invalid_parameter_exception('Completion requires satisfied, verified evidence');
        }
        if (!$DB->record_exists('local_ustar_route_versions', ['id' => $versionid, 'pointid' => $pointid])) {
            throw new \invalid_parameter_exception('Completion version does not belong to the route point');
        }
        if ($mode === 'assessment_lifecycle'
                && ((string)($evidence['status'] ?? '') !== 'passed'
                    || (int)($evidence['cycle'] ?? 0) <= 0
                    || (int)($evidence['verifiedcompletedat'] ?? 0) !== $completedat)) {
            throw new \invalid_parameter_exception(
                'Assessment completion requires a passed lifecycle cycle and matching verified timestamp'
            );
        }

        $logicalpointid = self::logical_point($pointid);
        $canonical = self::canonicalize($evidence);
        $fingerprint = hash('sha256', json_encode(
            $canonical,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
        $cyclekey = 'route-cycle-v1:' . hash('sha256', implode(':', [
            $userid, $logicalpointid, $versionid, $completedat, $fingerprint,
        ]));
        $existing = $DB->get_record('local_ustar_completion_cycle', ['cyclekey' => $cyclekey], '*', IGNORE_MISSING);
        if ($existing) { return $existing; }

        try {
            $id = (int)$DB->insert_record('local_ustar_completion_cycle', (object)[
                'userid' => $userid,
                'pointid' => $pointid,
                'versionid' => $versionid,
                'logicalpointid' => $logicalpointid,
                'cyclekey' => $cyclekey,
                'status' => 'confirmed',
                'completedat' => $completedat,
                'expiresat' => $expiresat > 0 ? $expiresat : null,
                'evidencejson' => json_encode(
                    $canonical,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
                'timecreated' => time(),
            ]);
        } catch (\dml_write_exception $exception) {
            $existing = $DB->get_record('local_ustar_completion_cycle', ['cyclekey' => $cyclekey], '*', IGNORE_MISSING);
            if (!$existing) { throw $exception; }
            return $existing;
        }
        return $DB->get_record('local_ustar_completion_cycle', ['id' => $id], '*', MUST_EXIST);
    }
}
