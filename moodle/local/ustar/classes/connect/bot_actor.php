<?php
namespace local_ustar\connect;
defined('MOODLE_INTERNAL') || die();

/** Resolve actual provider proof to current Academy actor; no caller-selected identity. */
final class bot_actor {
    public static function authority(identity $sender, int $userid, ?\stdClass $link): string {
        return hash('sha256',json_encode([$sender->key(),$userid,(int)($link->id??0),(int)($link->revision??0),
            $userid ? actor_service::authorization_stamp($userid) : 'unlinked',
            $userid ? bot_agreement::pending($userid, self::invited($link)) : []]));
    }
    public static function link(identity $sender): ?\stdClass {
        global $DB;
        return $DB->get_record('local_ustar_conn_links',['identityhash'=>$sender->key(),'state'=>'linked']) ?: null;
    }
    public static function active(identity $sender): int {
        $id=identity_service::resolve_active_user($sender);
        if ($id) { receipt_actor::require_active($id); }
        return $id;
    }
    public static function run(int $userid, callable $operation, ?\stdClass $link = null, bool $agreement = false) {
        global $USER, $DB;
        $link ??= $DB->get_record('local_ustar_conn_links', [
            'userid' => $userid, 'provider' => 'telegram', 'state' => 'linked',
        ]) ?: null;
        if (\core\session\manager::is_loggedinas() || \local_ustar\view_as::active()) {
            throw new \InvalidArgumentException('actor_unavailable');
        }
        receipt_actor::require_active($userid);
        $user=\core_user::get_user($userid,'*',MUST_EXIST);
        $invited = self::invited($link);
        if (!self::eligible($userid, $invited) || ($invited && self::requires_native_role($userid))) {
            throw new \InvalidArgumentException('native_auth_required');
        }
        if (!$agreement && bot_agreement::is_required(bot_agreement::pending($userid, $invited))) {
            throw new \InvalidArgumentException('native_auth_required');
        }
        $previous=$USER;
        // Deliberately no login_user/set_user: the private facade must never mint a browser session.
        $USER=$user;
        try { return $operation(); } finally { $USER=$previous; }
    }
    public static function eligible(int $userid, bool $invited = false): bool {
        global $CFG,$DB;
        $user=\core_user::get_user($userid,'id,auth',MUST_EXIST);
        return !($user->auth!=='manual' || !empty($CFG->alternateloginurl)
                || get_config('tool_mfa','enabled') || (int)get_config('auth_manual','expiration')===1
                || (!$invited && $DB->get_field('user_preferences','value',['userid'=>$userid,'name'=>'auth_forcepasswordchange'])));
    }
    public static function invited(?\stdClass $link): bool {
        return !empty($link->botinvited);
    }

    public static function requires_native_role(int $userid): bool {
        return is_siteadmin($userid) || self::capability($userid, 'admin') || self::capability($userid, 'hrmanage')
            || self::capability($userid, 'executive') || \local_ustar\task_workspace\service::can_manage($userid);
    }

    public static function capability(int $userid, string $name): bool {
        return has_capability('local/ustar:'.$name,\context_system::instance(),$userid);
    }
}
