<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Rewards for verified route completion cycles; progress remains the durable retry queue. */
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
                || (int)$progress->completedat < $startedat) {
            return false;
        }
        if (($evidence['mode'] ?? '') === 'assessment_lifecycle') {
            return ($evidence['status'] ?? '') === 'passed'
                && (int)($evidence['cycle'] ?? 0) > 0
                && (int)($evidence['verifiedcompletedat'] ?? 0) === (int)$progress->completedat
                && (int)$evidence['verifiedcompletedat'] >= $startedat;
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

    /** Failure preserves progress for scheduled reconciliation; learning remains usable. */
    public static function try_progress(int $userid, int $pointid, int $versionid): void {
        if (!self::enabled() || view_as::active()) { return; }
        try {
            global $DB;
            $progress = $DB->get_record('local_ustar_route_progress', compact('userid', 'pointid', 'versionid'));
            if ($progress && (string)$progress->status === 'complete') {
                $cycle = completion_cycle::for_progress($progress);
                if ($cycle) { self::grant_cycle($cycle); }
            }
        } catch (\Throwable $e) {
            debugging('USTAR route reward pending: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    private static function grant_cycle(\stdClass $cycle): void {
        global $DB;
        if ((string)$cycle->status !== 'confirmed') { return; }
        $evidence = json_decode((string)$cycle->evidencejson, true) ?: [];
        $startedat = (int)get_config('local_ustar', 'route_rewards_startedat');
        $completion = (object)['status' => 'complete', 'completedat' => (int)$cycle->completedat];
        if (!self::eligible($completion, $evidence, $startedat)) { return; }
        if (!accounts::participates((int)$cycle->userid)
                || !employment::learning_allowed((int)$cycle->userid)) { return; }

        $version = $DB->get_record('local_ustar_route_versions', ['id' => $cycle->versionid], '*', MUST_EXIST);
        $key = 'route-reward-v2:' . (string)$cycle->cyclekey;
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')->get_lock('reward:' . sha1($key), 10);
        if (!$lock) { throw new \moodle_exception('Route reward lock timeout'); }
        try {
            if ($DB->record_exists('local_ustar_coin_ledger', ['idempotencykey' => $key])) { return; }
            $transaction = $DB->start_delegated_transaction();
            try {
                target_core::record_evidence([
                    'userid' => (int)$cycle->userid,
                    'evidencetype' => 'learning',
                    'outcome' => 'completed',
                    'sourcekind' => 'completion_cycle',
                    'sourceid' => (string)$cycle->id,
                    'idempotencykey' => 'evidence:' . $key,
                    'validfrom' => (int)$cycle->completedat,
                    'expiresat' => $cycle->expiresat,
                    'details' => [
                        'pointid' => (int)$cycle->pointid,
                        'versionid' => (int)$cycle->versionid,
                        'logicalpointid' => (int)$cycle->logicalpointid,
                        'cyclekey' => (string)$cycle->cyclekey,
                        'rewardpolicy' => 'route-cycle-v2',
                        'xp' => self::XP,
                    ],
                ], 0);
                $granted = economy::post(
                    (int)$cycle->userid,
                    self::COINS,
                    'route_reward',
                    $key,
                    'completion_cycle',
                    (string)$cycle->id,
                    'Точка маршрута: ' . format_string((string)$version->title),
                    0
                );
                $transaction->allow_commit();
                global $USER;
                if ($granted && (int)($USER->id ?? 0) === (int)$cycle->userid
                        && !(defined('CLI_SCRIPT') && CLI_SCRIPT)) {
                    \core\notification::success(
                        'Шаг подтверждён! +10 XP и +1 USCOIN. ' . format_string((string)$version->title)
                    );
                }
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
        } finally {
            $lock->release();
        }
    }

    public static function summary(int $userid): array {
        global $DB;
        if (!accounts::participates($userid)) {
            return ['count' => 0, 'xp' => 0, 'coins' => 0, 'badges' => []];
        }
        $select = 'l.userid = :userid AND l.txtype = :txtype'
            . ' AND (l.sourcekind = :legacy OR l.sourcekind = :cycle)'
            . ' AND NOT EXISTS (SELECT 1 FROM {local_ustar_coin_ledger} rev'
            . ' WHERE rev.reversalofid = l.id)';
        $params = [
            'userid' => $userid,
            'txtype' => 'route_reward',
            'legacy' => 'route_progress',
            'cycle' => 'completion_cycle',
        ];
        $count = economy::available()
            ? (int)$DB->count_records_sql('SELECT COUNT(1) FROM {local_ustar_coin_ledger} l WHERE ' . $select, $params)
            : 0;
        $badges = [];
        foreach ([1 => 'Первый шаг', 5 => 'Набираю темп', 10 => 'Уверенный прогресс', 25 => 'Мастер маршрута']
                as $threshold => $name) {
            if ($count < $threshold) { continue; }
            $rows = $DB->get_records_sql('SELECT l.* FROM {local_ustar_coin_ledger} l WHERE '
                . $select . ' ORDER BY l.timecreated ASC,l.id ASC', $params, $threshold - 1, 1);
            $grant = reset($rows);
            $badges[] = ['name' => $name . ' · ' . $threshold . ' шагов', 'dateissued' => (int)$grant->timecreated];
        }
        return ['count' => $count, 'xp' => $count * self::XP, 'coins' => $count * self::COINS, 'badges' => $badges];
    }

    /** Repair old projections and independently persisted completion cycles, in bounded batches. */
    public static function reconcile(int $limit = 200): void {
        global $DB;
        if (!self::enabled()) { return; }
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')->get_lock('route-reward-repair', 0);
        if (!$lock) { return; }
        try {
            $limit = max(1, min(500, $limit));
            // Legacy progress can predate activation while its renewed completion is new.
            $cursor = (int)get_config('local_ustar', 'route_rewards_cursor');
            $rows = $DB->get_records_select('local_ustar_route_progress', 'id > :cursor',
                ['cursor' => $cursor], 'id ASC', '*', 0, $limit);
            foreach ($rows as $row) {
                try {
                    if ((string)$row->status === 'complete'
                            && (int)$row->completedat >= (int)get_config('local_ustar', 'route_rewards_startedat')) {
                        $cycle = completion_cycle::for_progress($row);
                        if ($cycle) { self::grant_cycle($cycle); }
                    }
                } catch (\Throwable $e) {
                    mtrace('USTAR reward pending progress=' . $row->id . ': ' . $e->getMessage());
                }
                $cursor = (int)$row->id;
            }
            set_config('route_rewards_cursor', $rows ? $cursor : 0, 'local_ustar');

            // Each historical renewal has its own durable cycle even when the projection moved on.
            $cursor = (int)get_config('local_ustar', 'route_rewards_cycle_cursor');
            $cycles = $DB->get_records_select('local_ustar_completion_cycle', 'id > :cursor',
                ['cursor' => $cursor], 'id ASC', '*', 0, $limit);
            foreach ($cycles as $cycle) {
                try {
                    self::grant_cycle($cycle);
                } catch (\Throwable $e) {
                    mtrace('USTAR reward pending cycle=' . $cycle->id . ': ' . $e->getMessage());
                }
                $cursor = (int)$cycle->id;
            }
            set_config('route_rewards_cycle_cursor', $cycles ? $cursor : 0, 'local_ustar');
        } finally {
            $lock->release();
        }
    }
}
