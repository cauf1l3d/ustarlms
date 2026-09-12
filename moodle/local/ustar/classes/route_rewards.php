<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Rewards for verified route progress; existing progress is the durable retry queue. */
final class route_rewards {
    public const XP = 10;
    public const COINS = 1;

    public static function enabled(): bool {
        return (int)get_config('local_ustar', 'route_rewards_startedat') > 0
            && !(bool)get_config('local_ustar', 'route_rewards_paused');
    }

    /** No retroactive grants, inherited facts, optional-only steps, or personality data. */
    public static function eligible(\stdClass $progress, array $evidence, int $startedat): bool {
        if ($startedat <= 0 || $progress->status !== 'complete'
                || (int)$progress->timecreated < $startedat || (int)$progress->completedat < $startedat) {
            return false;
        }
        if (($evidence['mode'] ?? '') === 'assessment_lifecycle') {
            return ($evidence['status'] ?? '') === 'passed'
                && (int)($evidence['verifiedcompletedat'] ?? 0) >= $startedat;
        }
        if (($evidence['mode'] ?? '') !== 'evaluated') { return false; }
        foreach ($evidence['requirements'] ?? [] as $fact) {
            if (!empty($fact['required']) && !empty($fact['satisfied']) && empty($fact['failed'])
                    && in_array($fact['type'] ?? '', ['course', 'cm', 'content', 'assessment', 'native'], true)
                    && (int)($fact['completedat'] ?? 0) >= $startedat) {
                return true;
            }
        }
        return false;
    }

    /** Failure preserves progress for the scheduled repair; learning remains usable. */
    public static function try_progress(int $userid, int $pointid, int $versionid): void {
        if (!self::enabled() || view_as::active()) { return; }
        try {
            global $DB;
            $progress = $DB->get_record('local_ustar_route_progress', compact('userid', 'pointid', 'versionid'));
            if ($progress) { self::grant($progress); }
        } catch (\Throwable $e) {
            debugging('USTAR route reward pending: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    private static function grant(\stdClass $progress): void {
        global $DB;
        $evidence = json_decode((string)$progress->evidencejson, true) ?: [];
        if (!self::eligible($progress, $evidence, (int)get_config('local_ustar', 'route_rewards_startedat'))) { return; }
        if (!accounts::learning_enabled((int)$progress->userid)) { return; }
        $version = $DB->get_record('local_ustar_route_versions', ['id' => $progress->versionid], '*', MUST_EXIST);
        $point = $DB->get_record('local_ustar_route_points', ['id' => $progress->pointid], '*', MUST_EXIST);
        // Keep one identity when a legacy materialised copy points to a common parent.
        $seen = [];
        while (!empty($point->sourcepointid)) {
            if (isset($seen[$point->id]) || count($seen) >= 64) { throw new \moodle_exception('Route point ancestry cycle'); }
            $seen[$point->id] = true;
            $point = $DB->get_record('local_ustar_route_points', ['id' => $point->sourcepointid], '*', MUST_EXIST);
        }
        $cycle = 'first';
        if ($version->renewalpolicy === route_model::RENEW_ALL) {
            // Materialised copies have different technical version IDs but the same published contract.
            $cycle = 'mandatory-' . sha1(json_encode([
                (int)($version->effectivedate ?? 0),
                json_decode((string)($version->requirementsjson ?? '[]'), true),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        // A local replacement is the same logical step for reward idempotency.
        $logicalpointid = ((string)($point->inheritstate ?? '') === 'override' && !empty($point->sourcepointid))
            ? (int)$point->sourcepointid : (int)$point->id;
        $key = 'route-reward-v1:' . (int)$progress->userid . ':' . $logicalpointid . ':' . $cycle;
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')->get_lock('reward:' . sha1($key), 10);
        if (!$lock) { throw new \moodle_exception('Route reward lock timeout'); }
        try {
            if ($DB->record_exists('local_ustar_coin_ledger', ['idempotencykey' => $key])) { return; }
            $transaction = $DB->start_delegated_transaction();
            try {
                target_core::record_evidence([
                    'userid' => (int)$progress->userid, 'evidencetype' => 'learning', 'outcome' => 'completed',
                    'sourcekind' => 'route_progress', 'sourceid' => (string)$progress->id,
                    'idempotencykey' => 'evidence:' . $key, 'validfrom' => (int)$progress->completedat,
                    'expiresat' => $progress->expiresat,
                    'details' => ['pointid' => (int)$progress->pointid, 'versionid' => (int)$progress->versionid,
                        'canonicalpointid' => (int)$point->id, 'rewardpolicy' => 'route-v1', 'xp' => self::XP],
                ], 0);
                $granted = economy::post((int)$progress->userid, self::COINS, 'route_reward', $key, 'route_progress',
                    (string)$progress->id, 'Точка маршрута: ' . format_string((string)$version->title), 0);
                $transaction->allow_commit();
                global $USER;
                if ($granted && (int)($USER->id ?? 0) === (int)$progress->userid && !(defined('CLI_SCRIPT') && CLI_SCRIPT)) {
                    \core\notification::success('Шаг подтверждён! +10 XP и +1 USCOIN. ' . format_string((string)$version->title));
                }
            } catch (\Throwable $e) { $transaction->rollback($e); }
        } finally { $lock->release(); }
    }

    public static function summary(int $userid): array {
        global $DB;
        $count = economy::available() ? (int)$DB->count_records('local_ustar_coin_ledger', [
            'userid' => $userid, 'txtype' => 'route_reward', 'sourcekind' => 'route_progress',
        ]) : 0;
        $badges = [];
        foreach ([1 => 'Первый шаг', 5 => 'Набираю темп', 10 => 'Уверенный прогресс', 25 => 'Мастер маршрута'] as $threshold => $name) {
            if ($count < $threshold) { continue; }
            $row = $DB->get_records('local_ustar_coin_ledger', ['userid' => $userid, 'txtype' => 'route_reward',
                'sourcekind' => 'route_progress'], 'timecreated ASC,id ASC', '*', $threshold - 1, 1);
            $grant = reset($row);
            $badges[] = ['name' => $name . ' · ' . $threshold . ' шагов', 'dateissued' => (int)$grant->timecreated];
        }
        return ['count' => $count, 'xp' => $count * self::XP, 'coins' => $count * self::COINS, 'badges' => $badges];
    }

    /** Bounded, wraparound scan: errors retry without starving later progress. */
    public static function reconcile(int $limit = 200): void {
        global $DB;
        if (!self::enabled()) { return; }
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')->get_lock('route-reward-repair', 0);
        if (!$lock) { return; }
        try {
            $cursor = (int)get_config('local_ustar', 'route_rewards_cursor');
            $rows = $DB->get_records_select('local_ustar_route_progress', 'id > :cursor AND timecreated >= :start',
                ['cursor' => $cursor, 'start' => (int)get_config('local_ustar', 'route_rewards_startedat')],
                'id ASC', '*', 0, max(1, min(500, $limit)));
            foreach ($rows as $row) {
                try { self::grant($row); } catch (\Throwable $e) { mtrace('USTAR reward pending progress=' . $row->id . ': ' . $e->getMessage()); }
                $cursor = (int)$row->id;
            }
            set_config('route_rewards_cursor', $rows ? $cursor : 0, 'local_ustar');
        } finally { $lock->release(); }
    }
}
