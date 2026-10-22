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
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\user_preference_provider {

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
        foreach (['local_ustar_conn_launches', 'local_ustar_conn_sessions'] as $table) {
            $fields = [];
            foreach (['userid', 'provider', 'tenant', 'subject', 'keyid', 'identityhash',
                    'timecreated', 'expiresat'] as $field) {
                $fields[$field] = 'privacy:metadata:connect:' . match ($field) {
                    'userid' => 'userid', 'subject' => 'subject', 'identityhash' => 'hash',
                    'timecreated', 'expiresat' => 'time', default => 'provider',
                };
            }
            foreach ($table === 'local_ustar_conn_launches' ? ['noncehash', 'consumedat'] :
                    ['tokenhash', 'browserhash', 'linkid', 'linkrevision', 'revokedat',
                     'scope', 'authdate', 'actorready', 'lastseenat', 'authstamp', 'scopestamp'] as $field) {
                $fields[$field] = 'privacy:metadata:connect:' .
                    (str_ends_with($field, 'hash') || str_ends_with($field, 'stamp') ? 'hash'
                        : (in_array($field, ['authdate', 'lastseenat'], true) ? 'time' : 'state'));
            }
            $collection->add_database_table($table, $fields, 'privacy:metadata:connect');
        }
        $collection->add_database_table('local_ustar_conn_signups', [
            'identityhash' => 'privacy:metadata:connect:hash', 'commandhash' => 'privacy:metadata:connect:hash',
            'payloadhash' => 'privacy:metadata:connect:hash', 'keyid' => 'privacy:metadata:connect:provider',
            'userid' => 'privacy:metadata:connect:userid', 'requestid' => 'privacy:metadata:connect:state',
            'linkid' => 'privacy:metadata:connect:state', 'linkrevision' => 'privacy:metadata:connect:state',
            'timecreated' => 'privacy:metadata:connect:time', 'expiresat' => 'privacy:metadata:connect:time',
        ], 'privacy:metadata:connect');
        $collection->add_database_table('local_ustar_conn_acktokens', [
            'tokenhash' => 'privacy:metadata:connect:hash', 'userid' => 'privacy:metadata:connect:userid',
            'notificationid' => 'privacy:metadata:connect:state', 'deliveryid' => 'privacy:metadata:connect:state',
            'attempt' => 'privacy:metadata:connect:state', 'provider' => 'privacy:metadata:connect:provider',
            'tenant' => 'privacy:metadata:connect:provider', 'subject' => 'privacy:metadata:connect:subject',
            'linkid' => 'privacy:metadata:connect:state', 'linkrevision' => 'privacy:metadata:connect:state',
            'timecreated' => 'privacy:metadata:connect:time', 'expires' => 'privacy:metadata:connect:time',
        ], 'privacy:metadata:connect:acktokens');
        $collection->add_user_preference(\local_ustar\connect\notification_preferences::NAME, 'privacy:metadata:connect:preferences');
        $collection->add_database_table('local_ustar_notify_delivery', [
            'notificationid' => 'privacy:metadata:connect:state', 'channel' => 'privacy:metadata:connect:provider',
            'status' => 'privacy:metadata:connect:state', 'attempts' => 'privacy:metadata:connect:state',
            'providerref' => 'privacy:metadata:connect:state', 'lasterror' => 'privacy:metadata:connect:state',
            'connectversion' => 'privacy:metadata:connect:state', 'connlinkid' => 'privacy:metadata:connect:state',
            'connrevision' => 'privacy:metadata:connect:state', 'connkeyid' => 'privacy:metadata:connect:provider',
            'connleasehash' => 'privacy:metadata:connect:hash', 'connexpires' => 'privacy:metadata:connect:time',
            'connleaseuntil' => 'privacy:metadata:connect:time', 'connbegun' => 'privacy:metadata:connect:time',
            'timecreated' => 'privacy:metadata:connect:time', 'timemodified' => 'privacy:metadata:connect:time',
            'nextattempt' => 'privacy:metadata:connect:time',
        ], 'privacy:metadata:connect');
        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contexts = new contextlist();
        $contexts->add_from_sql('SELECT c.id FROM {context} c WHERE c.contextlevel = :level
            AND (EXISTS (SELECT l.id FROM {local_ustar_conn_links} l WHERE l.userid = :linkuser)
              OR EXISTS (SELECT x.id FROM {local_ustar_conn_challenges} x WHERE x.userid = :challengeuser)
              OR EXISTS (SELECT a.id FROM {local_ustar_conn_launches} a WHERE a.userid = :launchuser)
              OR EXISTS (SELECT s.id FROM {local_ustar_conn_sessions} s WHERE s.userid = :sessionuser)
              OR EXISTS (SELECT r.id FROM {local_ustar_conn_signups} r WHERE r.userid = :signupuser)
              OR EXISTS (SELECT a.id FROM {local_ustar_conn_acktokens} a WHERE a.userid = :actionuser)
              OR EXISTS (SELECT p.id FROM {user_preferences} p WHERE p.userid = :prefuser AND p.name = :prefname)
              OR EXISTS (SELECT d.id FROM {local_ustar_notify_delivery} d JOIN {local_ustar_notifications} n
                    ON n.id = d.notificationid WHERE d.connectversion = 1 AND n.userid = :deliveryuser))',
            ['level' => CONTEXT_SYSTEM, 'linkuser' => $userid, 'challengeuser' => $userid,
                'launchuser' => $userid, 'sessionuser' => $userid, 'signupuser' => $userid,
                'actionuser' => $userid, 'prefuser' => $userid, 'prefname' => \local_ustar\connect\notification_preferences::NAME, 'deliveryuser' => $userid]);
        return $contexts;
    }

    public static function get_users_in_context(userlist $userlist): void {
        if ($userlist->get_context()->contextlevel !== CONTEXT_SYSTEM) { return; }
        foreach (['local_ustar_conn_links', 'local_ustar_conn_challenges',
                'local_ustar_conn_launches', 'local_ustar_conn_sessions', 'local_ustar_conn_signups', 'local_ustar_conn_acktokens'] as $table) {
            $userlist->add_from_sql('userid', 'SELECT DISTINCT userid FROM {' . $table . '} WHERE userid > 0');
        }
        $userlist->add_from_sql('userid', 'SELECT userid FROM {user_preferences} WHERE name = :name',
            ['name' => \local_ustar\connect\notification_preferences::NAME]);
        $userlist->add_from_sql('userid', 'SELECT DISTINCT n.userid FROM {local_ustar_notify_delivery} d
            JOIN {local_ustar_notifications} n ON n.id = d.notificationid WHERE d.connectversion = 1');
    }

    public static function export_user_preferences(int $userid): void {
        global $DB;
        $raw = $DB->get_field('user_preferences', 'value', ['userid' => $userid,
            'name' => \local_ustar\connect\notification_preferences::NAME]);
        if ($raw !== false) {
            writer::export_user_preference('local_ustar', 'connect_notifications', $raw,
                get_string('privacy:metadata:connect:preferences', 'local_ustar'));
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
        $launches = $DB->get_records('local_ustar_conn_launches', ['userid' => $userid], 'id',
            'id,provider,tenant,subject,timecreated,expiresat,consumedat');
        $sessions = $DB->get_records('local_ustar_conn_sessions', ['userid' => $userid], 'id',
            'id,provider,tenant,subject,scope,authdate,actorready,lastseenat,timecreated,expiresat,revokedat');
        $signups = $DB->get_records('local_ustar_conn_signups', ['userid' => $userid], 'id',
            'id,requestid,timecreated,expiresat');
        $deliveries = $DB->get_records_sql('SELECT d.id,d.status,d.attempts,d.providerref,d.lasterror,
                d.timecreated,d.timemodified,d.nextattempt FROM {local_ustar_notify_delivery} d
            JOIN {local_ustar_notifications} n ON n.id = d.notificationid WHERE d.connectversion = 1 AND n.userid = :uid', ['uid' => $userid]);
        $actions = $DB->get_records('local_ustar_conn_acktokens', ['userid' => $userid], 'id',
            'id,notificationid,deliveryid,attempt,provider,tenant,subject,linkid,linkrevision,timecreated,expires');
        // Token/session hashes are security internals, not login credentials to export.
        writer::with_context(\context_system::instance())->export_data(['Connect'], (object)[
            'links' => array_values($links), 'confirmations' => array_values($challenges),
            'launches' => array_values($launches), 'entries' => array_values($sessions),
            'registrations' => array_values($signups),
            'notification_deliveries' => array_values($deliveries),
            'notification_actions' => array_values($actions),
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
                    $DB->delete_records('local_ustar_conn_acktokens');
                    $DB->delete_records('local_ustar_notify_delivery', ['connectversion' => 1]);
                    $DB->delete_records('user_preferences', ['name' => \local_ustar\connect\notification_preferences::NAME]);
                    $DB->set_field('local_ustar_conn_proofs', 'challengeid', 0);
                    foreach (['local_ustar_conn_signups', 'local_ustar_conn_sessions', 'local_ustar_conn_launches',
                            'local_ustar_conn_challenges', 'local_ustar_conn_links'] as $table) {
                        $DB->delete_records($table);
                    }
                } else {
                    [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
                    $DB->delete_records_select('local_ustar_conn_acktokens', 'userid ' . $insql, $params);
                    $DB->delete_records_select('local_ustar_notify_delivery', 'connectversion = 1 AND notificationid IN
                        (SELECT id FROM {local_ustar_notifications} WHERE userid ' . $insql . ')', $params);
                    $DB->delete_records_select('user_preferences', 'name = :prefname AND userid ' . $insql,
                        $params + ['prefname' => \local_ustar\connect\notification_preferences::NAME]);
                    // Detach identity now; keep the opaque replay guard until
                    // its original expiry so erasure cannot resurrect a proof.
                    $DB->set_field_select('local_ustar_conn_proofs', 'challengeid', 0, 'challengeid IN
                        (SELECT id FROM {local_ustar_conn_challenges} WHERE userid ' . $insql . ')', $params);
                    $DB->delete_records_select('local_ustar_conn_challenges', 'userid ' . $insql, $params);
                    $DB->delete_records_select('local_ustar_conn_signups', 'userid ' . $insql, $params);
                    // Also erase pre-link temporary entries for this verified identity.
                    foreach (['local_ustar_conn_sessions', 'local_ustar_conn_launches'] as $table) {
                        $DB->delete_records_select($table, 'identityhash IN
                            (SELECT identityhash FROM {local_ustar_conn_links} WHERE userid ' . $insql . ')', $params);
                        $DB->delete_records_select($table, 'userid ' . $insql, $params);
                    }
                    $DB->delete_records_select('local_ustar_conn_links', 'userid ' . $insql, $params);
                }
                $transaction->allow_commit();
            } catch (\Throwable $error) {
                $transaction->rollback($error);
            }
        } finally { $lock->release(); }
    }
}
