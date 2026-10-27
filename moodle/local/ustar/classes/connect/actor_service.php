<?php
namespace local_ustar\connect;
defined('MOODLE_INTERNAL') || die();

/** Separate provider-read authority. Never creates a Moodle login or a role grant. */
final class actor_service {
    private const IDLE = 900;
    private const ABSOLUTE = 28800;

    public static function open(string $entry, string $browser, string $kid, string $provider, string $tenant): array {
        self::enabled();
        return identity_service::atomic(function() use ($entry, $browser, $kid, $provider, $tenant): array {
            global $DB;
            self::enabled();
            $row = entry_service::require_session($entry, $browser, $kid, $provider, $tenant);
            $now = time();
            if (!(int)$row->actorready || (int)$row->authdate < $now-300 || (int)$row->authdate > $now+30) {
                throw new \InvalidArgumentException('native_auth_required');
            }
            $user = self::current_user($row);
            if (!$user) { throw new \InvalidArgumentException('invalid_entry'); }
            self::native_policy($user);
            if ($DB->count_records_select('local_ustar_conn_sessions',
                    'userid = :uid AND scope = :scope AND revokedat = 0 AND expiresat > :absolute AND lastseenat > :idle',
                    ['uid' => $user->id, 'scope' => 'actor_read', 'absolute' => $now, 'idle' => $now-self::IDLE]) >= 5) {
                throw new \InvalidArgumentException('rate_limited');
            }
            $token = opaque_token::issue('ua1');
            $actor = clone $row;
            unset($actor->id);
            $actor->tokenhash = opaque_token::digest($token, 'ua1');
            $actor->scope = 'actor_read';
            $actor->timecreated = $now;
            $actor->expiresat = $now+self::ABSOLUTE;
            $actor->lastseenat = $now;
            $actor->authstamp = self::auth_stamp($user);
            $actor->scopestamp = self::scope_stamp((int)$user->id, $now);
            $DB->insert_record('local_ustar_conn_sessions', $actor);
            $DB->set_field('local_ustar_conn_sessions', 'revokedat', $now, ['id' => $row->id]);
            return ['session' => $token, 'scope' => 'actor_read', 'expires_at' => $actor->expiresat,
                'idle_expires_at' => $now+self::IDLE];
        });
    }

    public static function status(string $token, string $browser, string $kid, string $provider, string $tenant): array {
        $result = identity_service::atomic(function() use ($token, $browser, $kid, $provider, $tenant): array {
            global $DB;
            // Wrong bindings never burn another browser's legitimate session.
            $row = self::bound($token, $browser, $kid, $provider, $tenant);
            $now = time();
            $denial = null;
            if ((int)$row->expiresat <= $now || (int)$row->expiresat > (int)$row->timecreated+self::ABSOLUTE
                    || (int)$row->lastseenat <= $now-self::IDLE || (int)$row->lastseenat > $now+30) {
                $denial = 'invalid_actor';
            } else {
                try { self::enabled(); }
                catch (\InvalidArgumentException $e) { $denial = $e->getMessage(); }
            }
            $user = $denial === null ? self::current_user($row) : null;
            if ($denial === null && (!$user || !hash_equals($row->authstamp, self::auth_stamp($user)))) {
                $denial = 'invalid_actor';
            }
            if ($denial === null) {
                try { self::native_policy($user); }
                catch (\InvalidArgumentException $e) { $denial = $e->getMessage(); }
            }
            if ($denial === null && !hash_equals($row->scopestamp, self::scope_stamp((int)$user->id, $now))) {
                $denial = 'invalid_actor';
            }
            if ($denial !== null) {
                $DB->set_field('local_ustar_conn_sessions', 'revokedat', $now, ['id' => $row->id]);
                // Return, COMMIT, then throw. Throwing here would roll back revocation.
                return ['denial' => $denial];
            }
            $profile = self::profile($user, $now);
            $DB->set_field('local_ustar_conn_sessions', 'lastseenat', $now, ['id' => $row->id]);
            return ['scope' => 'actor_read', 'expires_at' => (int)$row->expiresat,
                'idle_expires_at' => min((int)$row->expiresat, $now+self::IDLE),
                'features' => ['profile'], 'profile' => $profile];
        });
        if (isset($result['denial'])) { throw new \InvalidArgumentException($result['denial']); }
        return $result;
    }

    public static function revoke(string $token, string $browser, string $kid, string $provider, string $tenant): void {
        identity_service::atomic(function() use ($token, $browser, $kid, $provider, $tenant): void {
            global $DB;
            $row = self::bound($token, $browser, $kid, $provider, $tenant);
            $DB->set_field('local_ustar_conn_sessions', 'revokedat', time(), ['id' => $row->id]);
        });
    }

    private static function bound(string $token, string $browser, string $kid, string $provider, string $tenant): \stdClass {
        global $DB;
        try {
            $digest = opaque_token::digest($token, 'ua1');
            $binding = opaque_token::digest($browser, 'ub1');
        } catch (\InvalidArgumentException $e) { throw new \InvalidArgumentException('invalid_actor'); }
        $row = $DB->get_record('local_ustar_conn_sessions', ['tokenhash' => $digest]);
        if (!$row || $row->scope !== 'actor_read' || (int)$row->revokedat !== 0
                || $row->keyid !== $kid || $row->provider !== $provider || $row->tenant !== $tenant
                || !hash_equals($row->browserhash, $binding)) {
            throw new \InvalidArgumentException('invalid_actor');
        }
        return $row;
    }

    private static function current_user(\stdClass $row): ?\stdClass {
        global $DB;
        $sender = new identity($row->provider, $row->tenant, $row->subject);
        $link = $DB->get_record('local_ustar_conn_links', ['identityhash' => $sender->key(), 'state' => 'linked']);
        if (!$link || !hash_equals($row->identityhash, $sender->key()) || (int)$link->id !== (int)$row->linkid
                || (int)$link->revision !== (int)$row->linkrevision || (int)$link->userid !== (int)$row->userid
                || identity_service::resolve_active_user($sender) !== (int)$row->userid || (int)$row->userid <= 1) {
            return null;
        }
        return $DB->get_record('user', ['id' => $row->userid, 'deleted' => 0, 'suspended' => 0, 'confirmed' => 1]) ?: null;
    }

    private static function enabled(): void {
        global $CFG;
        if (($CFG->ustar_connect_provider_read_enabled ?? false) !== true) {
            throw new \InvalidArgumentException('actor_unavailable');
        }
    }

    private static function native_policy(\stdClass $user): void {
        global $CFG, $DB;
        // Conservative until an accepted native MFA/SSO handoff exists; no credential collection here.
        if ($user->auth !== 'manual' || !empty($CFG->alternateloginurl) || get_config('tool_mfa', 'enabled')
                || (int)get_config('auth_manual', 'expiration') === 1
                || \core\session\manager::is_loggedinas() || \local_ustar\view_as::active()
                || $DB->get_field('user_preferences', 'value', ['userid' => $user->id, 'name' => 'auth_forcepasswordchange'])) {
            throw new \InvalidArgumentException('native_auth_required');
        }
    }

    private static function auth_stamp(\stdClass $user): string {
        return hash('sha256', "ustar-connect-auth-v1\0" . $user->auth . "\0" . $user->password);
    }

    /** Invalidation snapshot only. Future object APIs must recheck current domain permissions. */
    private static function scope_stamp(int $userid, int $now): string {
        global $DB;
        accesslib_clear_all_caches(true);
        $caps = [];
        foreach (['use', 'viewteam', 'hr', 'hrmanage', 'approveregistration', 'manageadaptation',
                'gradeassessments', 'executive', 'admin', 'managecatalog'] as $name) {
            $caps[$name] = has_capability('local/ustar:' . $name, \context_system::instance(), $userid);
        }
        $roles = array_values($DB->get_records('role_assignments', ['userid' => $userid], 'id',
            'id,roleid,contextid,component,itemid'));
        $assignments = [];
        foreach (\local_ustar\organization_model::active_assignments($userid, $now) as $assignment) {
            $places = []; $seen = []; $placeid = (int)$assignment->staffplaceid;
            for ($i = 0; $i < 100 && $placeid > 0 && !isset($seen[$placeid]); $i++) {
                $seen[$placeid] = true;
                $place = \local_ustar\organization_model::staff_place($placeid, $now);
                $places[] = $place ?: ['inactive' => $placeid];
                $placeid = $place ? (int)$place->managerplaceid : 0;
            }
            $assignments[] = ['assignment' => $assignment, 'places' => $places];
        }
        $managers = []; $seen = []; $current = $userid;
        for ($i = 0; $i < 50 && $current > 0 && !isset($seen[$current]); $i++) {
            $seen[$current] = true;
            $current = \local_ustar\org::manager_id($current);
            $managers[] = $current;
        }
        return hash('sha256', "ustar-connect-scope-v1\0" . json_encode([
            'caps' => $caps, 'roles' => $roles,
            'identity' => \local_ustar\organization_identity::resolve($userid, $now),
            'assignments' => $assignments, 'managers' => $managers,
        ], JSON_THROW_ON_ERROR));
    }

    private static function profile(\stdClass $user, int $now): array {
        $identity = \local_ustar\organization_identity::resolve((int)$user->id, $now);
        $structure = \local_ustar\structure::get(\local_ustar\structure::NAME_STRUCTURE);
        $positions = \local_ustar\people::position_map($structure);
        $departments = \local_ustar\people::department_map($structure);
        return ['username' => self::text($user->username), 'fullname' => self::text(fullname($user)),
            'position' => self::text((string)($positions[$identity['positionid']]['name'] ?? '')),
            'department' => self::text((string)($departments[$identity['departmentid']]['name'] ?? '')),
            'account_state' => 'active'];
    }
    private static function text(string $value): string {
        return \core_text::substr(preg_replace('/[\x00-\x1F\x7F]/u', ' ', trim($value)), 0, 256);
    }
}
