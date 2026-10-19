<?php
namespace local_ustar\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/** Connect metadata only; the older USTAR domains still require their own privacy audit. */
final class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_ustar_conn_links', [
            'userid' => 'privacy:metadata:connect:userid',
            'provider' => 'privacy:metadata:connect:provider',
            'tenant' => 'privacy:metadata:connect:provider',
            'subject' => 'privacy:metadata:connect:subject',
            'identityhash' => 'privacy:metadata:connect:hash',
            'userchannelhash' => 'privacy:metadata:connect:hash',
            'state' => 'privacy:metadata:connect:state',
            'revision' => 'privacy:metadata:connect:state',
            'timecreated' => 'privacy:metadata:connect:time',
            'timemodified' => 'privacy:metadata:connect:time',
        ], 'privacy:metadata:connect');
        $collection->add_database_table('local_ustar_conn_challenges', [
            'userid' => 'privacy:metadata:connect:userid',
            'provider' => 'privacy:metadata:connect:provider',
            'tenant' => 'privacy:metadata:connect:provider',
            'subject' => 'privacy:metadata:connect:subject',
            'codehash' => 'privacy:metadata:connect:hash',
            'browserhash' => 'privacy:metadata:connect:hash',
            'proofhash' => 'privacy:metadata:connect:hash',
            'timecreated' => 'privacy:metadata:connect:time',
            'expiresat' => 'privacy:metadata:connect:time',
            'confirmedlinkid' => 'privacy:metadata:connect:state',
            'confirmedrevision' => 'privacy:metadata:connect:state',
        ], 'privacy:metadata:connect');
        $collection->add_database_table('local_ustar_conn_proofs', [
            'proofhash' => 'privacy:metadata:connect:hash',
            'challengeid' => 'privacy:metadata:connect:state',
            'timecreated' => 'privacy:metadata:connect:time',
            'expiresat' => 'privacy:metadata:connect:time',
        ], 'privacy:metadata:connect');
        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contexts = new contextlist();
        $contexts->add_from_sql('SELECT c.id FROM {context} c WHERE c.contextlevel = :level
            AND (EXISTS (SELECT l.id FROM {local_ustar_conn_links} l WHERE l.userid = :linkuser)
              OR EXISTS (SELECT x.id FROM {local_ustar_conn_challenges} x WHERE x.userid = :challengeuser))',
            ['level' => CONTEXT_SYSTEM, 'linkuser' => $userid, 'challengeuser' => $userid]);
        return $contexts;
    }

    public static function get_users_in_context(userlist $userlist): void {
        if ($userlist->get_context()->contextlevel !== CONTEXT_SYSTEM) { return; }
        foreach (['local_ustar_conn_links', 'local_ustar_conn_challenges'] as $table) {
            $userlist->add_from_sql('userid', 'SELECT DISTINCT userid FROM {' . $table . '}');
        }
    }

    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        if (!in_array(\context_system::instance()->id, $contextlist->get_contextids())) { return; }
        $userid = (int)$contextlist->get_user()->id;
        $links = $DB->get_records('local_ustar_conn_links', ['userid' => $userid], 'id',
            'id,provider,tenant,subject,state,revision,timecreated,timemodified');
        $challenges = $DB->get_records('local_ustar_conn_challenges', ['userid' => $userid], 'id',
            'id,provider,tenant,subject,timecreated,expiresat,confirmedlinkid,confirmedrevision');
        // Token/session hashes are security internals, not login credentials to export.
        writer::with_context(\context_system::instance())->export_data(['Connect'], (object)[
            'links' => array_values($links), 'confirmations' => array_values($challenges),
        ]);
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        if ($context->contextlevel === CONTEXT_SYSTEM) { self::erase(null); }
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        if (in_array(\context_system::instance()->id, $contextlist->get_contextids())) {
            self::erase([(int)$contextlist->get_user()->id]);
        }
    }

    public static function delete_data_for_users(approved_userlist $userlist): void {
        if ($userlist->get_context()->contextlevel === CONTEXT_SYSTEM) {
            self::erase(array_map('intval', $userlist->get_userids()));
        }
    }

    /** Privacy lifecycle is a deliberate erase, never a transfer to another userid. */
    private static function erase(?array $userids): void {
        global $DB;
        if ($userids === []) { return; }
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')->get_lock('connect-identity', 10);
        if (!$lock) { throw new \moodle_exception('identity_busy', 'local_ustar'); }
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                if ($userids === null) {
                    $DB->set_field('local_ustar_conn_proofs', 'challengeid', 0);
                    foreach (['local_ustar_conn_challenges', 'local_ustar_conn_links'] as $table) {
                        $DB->delete_records($table);
                    }
                } else {
                    [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
                    // Detach identity now; keep the opaque replay guard until
                    // its original expiry so erasure cannot resurrect a proof.
                    $DB->set_field_select('local_ustar_conn_proofs', 'challengeid', 0, 'challengeid IN
                        (SELECT id FROM {local_ustar_conn_challenges} WHERE userid ' . $insql . ')', $params);
                    $DB->delete_records_select('local_ustar_conn_challenges', 'userid ' . $insql, $params);
                    $DB->delete_records_select('local_ustar_conn_links', 'userid ' . $insql, $params);
                }
                $transaction->allow_commit();
            } catch (\Throwable $error) {
                $transaction->rollback($error);
            }
        } finally { $lock->release(); }
    }
}
