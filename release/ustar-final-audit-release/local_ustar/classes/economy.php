<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * USCOIN is a non-cash Academy balance, never a competition score.
 *
 * The immutable ledger is the audit history. local_ustar_coin_balance is a
 * locked projection used exclusively to make a debit atomic and non-negative.
 */
final class economy {
    public static function available(): bool {
        global $DB;
        $dbman = $DB->get_manager();
        return $dbman->table_exists(new \xmldb_table('local_ustar_coin_ledger'))
            && $dbman->table_exists(new \xmldb_table('local_ustar_coin_balance'));
    }

    /** Backward-compatible credit entry point. Negative postings are forbidden. */
    public static function post(int $userid, int $amount, string $type, string $idempotencykey,
            string $sourcekind = '', string $sourceid = '', string $comment = '', ?int $actorid = null): bool {
        if ($amount <= 0) {
            throw new \invalid_parameter_exception('USCOIN credits must be positive; use spend() for a debit.');
        }
        return self::apply($userid, $amount, $type, $idempotencykey, $sourcekind, $sourceid, $comment, $actorid);
    }

    /** Atomically debit a balance. A duplicate key is a no-op; overspending is rejected. */
    public static function spend(int $userid, int $amount, string $type, string $idempotencykey,
            string $sourcekind, string $sourceid, string $comment, int $actorid): bool {
        if ($amount <= 0 || $actorid <= 0) {
            throw new \invalid_parameter_exception('USCOIN debit amount and responsible actor are required.');
        }
        return self::apply($userid, -$amount, $type, $idempotencykey, $sourcekind, $sourceid, $comment, $actorid);
    }

    /** Reverse one prior debit once, preserving both the debit and its reversal. */
    public static function reverse(int $ledgerid, string $idempotencykey, string $comment, int $actorid): bool {
        global $DB;
        if ($actorid <= 0) {
            throw new \invalid_parameter_exception('A responsible actor is required for a USCOIN reversal.');
        }
        $original = $DB->get_record('local_ustar_coin_ledger', ['id' => $ledgerid], '*', MUST_EXIST);
        if ((int)$original->amount >= 0) {
            throw new \invalid_parameter_exception('Only a prior USCOIN debit may be reversed.');
        }
        if ($DB->record_exists('local_ustar_coin_ledger', ['reversalofid' => $ledgerid])) {
            return false;
        }
        try {
            return self::apply(
                (int)$original->userid,
                abs((int)$original->amount),
                'reversal',
                $idempotencykey,
                'ledger',
                (string)$ledgerid,
                $comment,
                $actorid,
                $ledgerid
            );
        } catch (\dml_write_exception $e) {
            // A concurrent reversal may win the unique reversalofid guard.
            // Treat that race as the same idempotent no-op as a prior check.
            if ($DB->record_exists('local_ustar_coin_ledger', ['reversalofid' => $ledgerid])) {
                return false;
            }
            throw $e;
        }
    }

    public static function balance(int $userid): int {
        global $DB;
        if (!self::available() || $userid <= 0) {
            return 0;
        }
        $balance = $DB->get_field('local_ustar_coin_balance', 'balance', ['userid' => $userid]);
        if ($balance !== false) {
            return max(0, (int)$balance);
        }
        // Safe read fallback for an interrupted historical backfill. A debit
        // never uses this path: apply() first creates and locks the projection.
        return max(0, (int)$DB->get_field_sql(
            'SELECT COALESCE(SUM(amount), 0) FROM {local_ustar_coin_ledger} WHERE userid = :userid',
            ['userid' => $userid]
        ));
    }

    public static function totals(int $userid): array {
        global $DB;
        if (!self::available()) {
            return ['balance' => 0, 'earned' => 0, 'spent' => 0];
        }
        $row = $DB->get_record_sql(
            'SELECT COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) AS earned,
                    COALESCE(SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END), 0) AS spent
               FROM {local_ustar_coin_ledger}
              WHERE userid = :userid',
            ['userid' => $userid]
        );
        return ['balance' => self::balance($userid), 'earned' => (int)$row->earned, 'spent' => (int)$row->spent];
    }

    public static function history(int $userid, int $limit = 20): array {
        global $DB;
        if (!self::available()) {
            return [];
        }
        $rows = [];
        foreach ($DB->get_records('local_ustar_coin_ledger', ['userid' => $userid], 'timecreated DESC', '*', 0, max(1, min(100, $limit))) as $row) {
            $rows[] = [
                'amount' => (int)$row->amount,
                'positive' => (int)$row->amount > 0,
                'type' => (string)$row->txtype,
                'comment' => (string)$row->comment,
                'date' => userdate((int)$row->timecreated, '%d.%m.%Y %H:%M'),
            ];
        }
        return $rows;
    }

    private static function apply(int $userid, int $amount, string $type, string $idempotencykey,
            string $sourcekind, string $sourceid, string $comment, ?int $actorid, ?int $reversalofid = null): bool {
        global $DB, $USER;
        if (!self::available() || $userid <= 0 || $amount === 0 || trim($idempotencykey) === '') {
            throw new \invalid_parameter_exception('USCOIN ledger, user, amount and idempotency key are required.');
        }
        $idempotencykey = \core_text::substr(trim($idempotencykey), 0, 128);
        if ($DB->record_exists('local_ustar_coin_ledger', ['idempotencykey' => $idempotencykey])) {
            return false;
        }
        $factory = \core\lock\lock_config::get_lock_factory('local_ustar');
        $keylock = $factory->get_lock('coin-event:' . sha1($idempotencykey), 10);
        if (!$keylock) {
            throw new \moodle_exception('Unable to acquire USCOIN idempotency lock.');
        }
        $userlock = null;
        try {
            if ($DB->record_exists('local_ustar_coin_ledger', ['idempotencykey' => $idempotencykey])) {
                return false;
            }
            $userlock = $factory->get_lock('coin-balance:' . $userid, 10);
            if (!$userlock) {
                throw new \moodle_exception('Unable to acquire USCOIN balance lock.');
            }
            $transaction = $DB->start_delegated_transaction();
            try {
                if ($DB->record_exists('local_ustar_coin_ledger', ['idempotencykey' => $idempotencykey])) {
                    $transaction->allow_commit();
                    return false;
                }
                $balance = self::locked_balance($userid);
                $nextbalance = (int)$balance->balance + $amount;
                if ($nextbalance < 0) {
                    throw new \moodle_exception('USCOIN debit refused: insufficient balance.');
                }
                $now = time();
                $DB->insert_record('local_ustar_coin_ledger', (object)[
                    'userid' => $userid,
                    'amount' => $amount,
                    'txtype' => clean_param($type, PARAM_ALPHANUMEXT),
                    'sourcekind' => clean_param($sourcekind, PARAM_ALPHANUMEXT),
                    'sourceid' => \core_text::substr(clean_param($sourceid, PARAM_TEXT), 0, 64),
                    'idempotencykey' => $idempotencykey,
                    'comment' => $comment,
                    'actorid' => $actorid ?? ((int)($USER->id ?? 0) ?: null),
                    'reversalofid' => $reversalofid,
                    'timecreated' => $now,
                ]);
                $balance->balance = $nextbalance;
                $balance->timemodified = $now;
                $DB->update_record('local_ustar_coin_balance', $balance);
                $transaction->allow_commit();
                return true;
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
        } finally {
            if ($userlock) {
                $userlock->release();
            }
            $keylock->release();
        }
    }

    private static function locked_balance(int $userid): \stdClass {
        global $DB;
        $record = $DB->get_record('local_ustar_coin_balance', ['userid' => $userid]);
        if (!$record) {
            $historical = (int)$DB->get_field_sql(
                'SELECT COALESCE(SUM(amount), 0) FROM {local_ustar_coin_ledger} WHERE userid = :userid',
                ['userid' => $userid]
            );
            if ($historical < 0) {
                throw new \moodle_exception('USCOIN balance migration is invalid: negative historical balance.');
            }
            $DB->insert_record('local_ustar_coin_balance', (object)[
                'userid' => $userid, 'balance' => $historical, 'timemodified' => time(),
            ]);
        }
        return $DB->get_record_sql(
            'SELECT * FROM {local_ustar_coin_balance} WHERE userid = :userid FOR UPDATE',
            ['userid' => $userid],
            MUST_EXIST
        );
    }
}
