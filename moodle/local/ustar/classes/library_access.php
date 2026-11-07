<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

/** Library discovery and body access share one decision; grants never bypass source ACL. */
final class library_access {
    public const POLICY_PAID = 'paid';
    public const POLICY_GATED = 'gated';
    public const POLICY_FREE = 'free';
    public const POLICY_ASSIGNED = 'assigned';
    public const REASON_OK = 'ok';
    public const REASON_EMPLOYMENT = 'employment';
    public const REASON_UNPUBLISHED = 'unpublished';
    public const REASON_NOT_OWNED = 'not_owned';
    public const REASON_PURCHASE = 'purchase_required';
    public const REASON_ASSIGN = 'assign_required';
    public const REASON_GATE = 'gate_not_passed';
    public const REASON_AUTHORING = 'authoring';

    public static function fail(string $message): never {
        throw new \moodle_exception('libraryerror', 'local_ustar', '', $message);
    }

    public static function eligible(int $userid): bool {
        global $DB;
        if ($userid <= 1 || !$DB->record_exists('user', ['id'=>$userid,'deleted'=>0,'suspended'=>0])) {
            return false;
        }
        return is_siteadmin($userid) || (employment::is_active($userid)
            && capabilities::has($userid, capabilities::LEARNING_USE));
    }

    public static function is_admin(int $userid): bool {
        return self::eligible($userid) && (is_siteadmin($userid)
            || has_capability('local/ustar:admin', \context_system::instance(), $userid, false));
    }

    public static function is_authoring(int $userid, ?object $offer = null): bool {
        if (!self::eligible($userid)) { return false; }
        return self::is_admin($userid)
            || has_capability('local/ustar:librarypublish', \context_system::instance(), $userid, false)
            || ($offer && $offer->itemtype === 'catalog' && catalog::can_manage($userid));
    }

    public static function can_grant(int $userid): bool {
        return self::eligible($userid) && (self::is_admin($userid)
            || has_capability('local/ustar:librarygrant', \context_system::instance(), $userid, false));
    }

    public static function can_assess(int $userid): bool {
        return self::eligible($userid) && (self::is_admin($userid)
            || has_capability('local/ustar:libraryassess', \context_system::instance(), $userid, false));
    }

    /** Marketplace is opt-in for exact canonical departments, independently of sales. */
    public static function marketplace_departments(): array {
        $configured = json_decode((string)get_config('local_ustar', 'library_marketplace_departments'), true);
        if (!is_array($configured) || !array_is_list($configured)) { return []; }
        $known = array_map(static fn($row) => (string)$row['id'],
            structure::get(structure::NAME_STRUCTURE)['departments'] ?? []);
        return array_values(array_unique(array_filter($configured,
            static fn($id) => is_string($id) && in_array($id, $known, true))));
    }

    public static function can_view_marketplace(int $userid): bool {
        if (!self::eligible($userid)) { return false; }
        if (!view_as::active() && self::is_admin($userid)) { return true; }
        $allowed = self::marketplace_departments();
        if (!$allowed) { return false; }
        $department = (string)(content::user_scope($userid)['departmentid'] ?? '');
        return $department !== '' && in_array($department, $allowed, true);
    }

    /** Existing assignments/purchases remain reachable from My materials. */
    public static function can_view_offer_page(int $offerid, int $userid): bool {
        return self::eligible($userid) && (self::can_view_marketplace($userid)
            || (!view_as::active() && self::active_grant($offerid, $userid) !== null));
    }

    public static function save_marketplace_departments(array $ids, int $actorid, int $expectedrevision): void {
        global $DB;
        self::require_actor($actorid);
        if (!self::is_admin($actorid)) { self::fail('Доступ отделов настраивает администратор.'); }
        $known = array_map(static fn($row) => (string)$row['id'],
            structure::get(structure::NAME_STRUCTURE)['departments'] ?? []);
        foreach ($ids as $id) {
            if (!is_string($id) || !in_array($id, $known, true)) {
                self::fail('Отдел изменился или не найден. Обновите страницу.');
            }
        }
        $ids = array_values(array_unique($ids));
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')->get_lock('library-settings', 10);
        if (!$lock) { self::fail('Настройки сейчас изменяются. Повторите действие.'); }
        try {
            if ($expectedrevision !== (int)$DB->get_field('config_plugins', 'value',
                    ['plugin'=>'local_ustar', 'name'=>'library_settings_revision'])) {
                self::fail('Настройки изменились. Обновите страницу.');
            }
            $tx = $DB->start_delegated_transaction();
            try {
                set_config('library_marketplace_departments', json_encode($ids, JSON_THROW_ON_ERROR), 'local_ustar');
                set_config('library_settings_revision', max(time(), $expectedrevision + 1), 'local_ustar');
                library_offer::audit(0, 'marketplace_departments_changed', $actorid, ['departmentids' => $ids]);
                $tx->allow_commit();
            } catch (\Throwable $e) { $tx->rollback($e); throw $e; }
        } finally { $lock->release(); }
    }

    /** Domain commands authenticate the real actor even when called outside an HTTP controller. */
    public static function require_actor(int $actorid): void {
        global $USER;
        view_as::assert_writable();
        if ((int)($USER->id ?? 0) !== $actorid || !self::eligible($actorid)) {
            throw new \required_capability_exception(\context_system::instance(), 'local/ustar:use', 'nopermissions', '');
        }
    }

    public static function can_target(int $actorid, int $userid): bool {
        if (!self::can_grant($actorid) || $actorid === $userid || !self::eligible($userid)
                || !accounts::is_business_account($userid) || !employment::is_active($userid)) {
            return false;
        }
        if (self::is_admin($actorid)) { return true; }
        $scope = access_context::scope($actorid);
        return !empty($scope['allowed']) && in_array($userid, array_map('intval', $scope['userids'] ?? []), true);
    }

    public static function resolve(int $offerid, int $userid): array {
        global $DB;
        $offer = $DB->get_record('local_ustar_library_offer', ['id'=>$offerid]);
        if (!$offer) { return self::deny(self::REASON_UNPUBLISHED, null); }
        return self::evaluate($offer, $userid, self::eligible($userid) ? self::active_grant($offerid, $userid) : null);
    }

    /** One grant query; evaluate every hit through exactly the same gates as single resolution. */
    public static function resolve_many(array $offers, int $userid): array {
        global $DB;
        if (!$offers) { return []; }
        $grants = [];
        if (self::eligible($userid)) {
            [$in,$params] = $DB->get_in_or_equal(array_map(static fn($o)=>(int)$o->id, $offers), SQL_PARAMS_NAMED, 'of');
            $now = time();
            $rows = $DB->get_records_select('local_ustar_library_grant',
                "offerid $in AND userid=:uid AND revokedat=0 AND validfrom<=:starts
                    AND (validto=0 OR validto>:ends)",
                $params+['uid'=>$userid,'starts'=>$now,'ends'=>$now], 'id DESC');
            foreach ($rows as $row) { $grants[(int)$row->offerid] ??= $row; }
        }
        $out = [];
        foreach ($offers as $offer) {
            $out[(int)$offer->id] = self::evaluate($offer, $userid, $grants[(int)$offer->id] ?? null);
        }
        return $out;
    }

    private static function deny(string $reason, ?object $offer, bool $visible = false): array {
        return ['canview'=>$visible,'canopen'=>false,'reason'=>$reason,'grant'=>null,'offer'=>$offer];
    }

    private static function evaluate(object $offer, int $userid, ?object $grant): array {
        if (!self::eligible($userid)) { return self::deny(self::REASON_EMPLOYMENT, $offer); }
        if (self::is_authoring($userid, $offer)) {
            return ['canview'=>true,'canopen'=>true,'reason'=>self::REASON_AUTHORING,'grant'=>null,'offer'=>$offer];
        }
        if ($offer->visibility !== 'published') { return self::deny(self::REASON_UNPUBLISHED, $offer); }
        if (!self::subject_accessible($offer, $userid)) { return self::deny(self::REASON_NOT_OWNED, $offer); }
        $policy = (string)$offer->accesspolicy;
        if (!in_array($policy, [self::POLICY_FREE,self::POLICY_PAID,self::POLICY_GATED,self::POLICY_ASSIGNED], true)) {
            return self::deny(self::REASON_UNPUBLISHED, $offer);
        }
        if ($grant || $policy === self::POLICY_FREE || ($policy === self::POLICY_GATED && self::gate_passed($offer, $userid))) {
            return ['canview'=>true,'canopen'=>true,'reason'=>self::REASON_OK,'grant'=>$grant,'offer'=>$offer];
        }
        $reason = match ($policy) {
            self::POLICY_GATED => self::REASON_GATE,
            self::POLICY_ASSIGNED => self::REASON_ASSIGN,
            default => self::REASON_PURCHASE,
        };
        return self::deny($reason, $offer, true);
    }

    public static function active_grant(int $offerid, int $userid): ?object {
        global $DB;
        $now = time();
        $rows = $DB->get_records_select('local_ustar_library_grant',
            'offerid=:oid AND userid=:uid AND revokedat=0 AND validfrom<=:starts AND (validto=0 OR validto>:ends)',
            ['oid'=>$offerid,'uid'=>$userid,'starts'=>$now,'ends'=>$now], 'id DESC', '*', 0, 1);
        return $rows ? reset($rows) : null;
    }

    /** Discovery uses the canonical audience, separately from the body entitlement composed in content. */
    public static function subject_accessible(object $offer, int $userid): bool {
        global $DB;
        if ($offer->itemtype === 'content') {
            $item = $DB->get_record('local_ustar_content', ['id'=>(int)$offer->itemid]);
            return $item && $item->status === content::STATUS_PUBLISHED && $item->type !== 'folder'
                && content::audience_allows_record($item, $userid);
        }
        if ($offer->itemtype === 'catalog') { return self::catalog_active((int)$offer->itemid); }
        if ($offer->itemtype === 'assessment') {
            $item = $DB->get_record('local_ustar_library_assessment', ['id'=>(int)$offer->itemid]);
            return $item && $item->status === 'published'
                && (!in_array($item->targettype, ['quick','group'], true) || self::catalog_active((int)$item->targetkey));
        }
        return false; // Bundles have no implementation or body; never sell a placeholder.
    }

    public static function catalog_active(int $itemid): bool {
        global $DB;
        $seen = [];
        while ($itemid > 0) {
            if (isset($seen[$itemid]) || count($seen) >= 64) { return false; }
            $seen[$itemid] = true;
            $row = $DB->get_record('local_ustar_catalog', ['id'=>$itemid]);
            if (!$row || empty($row->active)) { return false; }
            $itemid = (int)$row->parentid;
        }
        return (bool)$seen;
    }

    private static function gate_passed(object $offer, int $userid): bool {
        global $DB;
        $pointid = (int)$offer->gatepointid;
        if ($pointid <= 0) { return false; }
        if (class_exists(catalog_mastery::class) && $pointid === catalog_mastery::POINT_ID) {
            return catalog_mastery::has_access($userid);
        }
        $version = route_model::current_published_version($pointid);
        if (!$version) { return false; }
        $row = $DB->get_record('local_ustar_route_progress', [
            'userid'=>$userid,'pointid'=>$pointid,'versionid'=>(int)$version->id,'status'=>'complete']);
        return $row && (empty($row->expiresat) || (int)$row->expiresat > time());
    }
}
