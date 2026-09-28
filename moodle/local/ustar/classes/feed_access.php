<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Current workforce and audience boundary for every feed read and write. */
final class feed_access {
    public static function can_manage(int $userid): bool {
        return has_capability('local/ustar:feedmanage', \context_system::instance(), $userid);
    }

    public static function can_create(int $userid): bool {
        return self::can_manage($userid)
            || has_capability('local/ustar:feedcreate', \context_system::instance(), $userid);
    }

    public static function can_edit(int $userid): bool {
        return self::can_manage($userid)
            || has_capability('local/ustar:feededit', \context_system::instance(), $userid);
    }

    public static function can_publish(int $userid): bool {
        return self::can_manage($userid)
            || has_capability('local/ustar:feedpublish', \context_system::instance(), $userid);
    }

    public static function can_moderate(int $userid): bool {
        return self::can_manage($userid)
            || has_capability('local/ustar:feedmoderate', \context_system::instance(), $userid);
    }

    public static function require_reader(int $userid): void {
        // Feed managers/site administrators are not forced through
        // accounts::participates(): that helper deliberately excludes site admins.
        if (self::can_manage($userid)) {
            return;
        }
        if (!accounts::participates($userid)
                || !has_capability('local/ustar:use', \context_system::instance(), $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/ustar:use', 'nopermissions', '');
        }
    }

    public static function require_actor(int $userid): void {
        global $USER;
        self::require_reader($userid);
        view_as::assert_writable();
        if ((int)$USER->id !== $userid) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/ustar:use', 'nopermissions', '');
        }
    }

    public static function require_creator(int $userid): void {
        self::require_actor($userid);
        if (!self::can_create($userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/ustar:feedcreate', 'nopermissions', '');
        }
    }

    public static function require_editor(int $userid): void {
        self::require_actor($userid);
        if (!self::can_edit($userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/ustar:feededit', 'nopermissions', '');
        }
    }

    public static function require_writer(int $userid): void {
        self::require_actor($userid);
        if (!self::can_publish($userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/ustar:feedpublish', 'nopermissions', '');
        }
    }

    public static function require_manager(int $userid): void {
        self::require_actor($userid);
        if (!self::can_manage($userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/ustar:feedmanage', 'nopermissions', '');
        }
    }

    public static function department_id(int $userid): string {
        if (view_as::active()) {
            $positions = people::position_map(structure::get(structure::NAME_STRUCTURE));
            return (string)($positions[view_as::position_id()]['department'] ?? '');
        }
        $identity = organization_identity::resolve($userid);
        return $identity['conflicts'] ? '' : (string)$identity['departmentid'];
    }

    /** A manager must have the separate permission AND current scope. */
    public static function manageable_departments(int $userid): array {
        if (self::can_manage($userid)) {
            return array_values(array_filter(array_map(
                static fn(array $department): string => (string)($department['id'] ?? ''),
                structure::get(structure::NAME_STRUCTURE)['departments'] ?? []
            )));
        }
        if (!has_capability('local/ustar:feedpublishdepartment', \context_system::instance(), $userid)) {
            return [];
        }
        return organization_model::manager_department_ids($userid);
    }

    public static function assert_publisher(int $userid, string $type, string $publisherid,
            array $audience): void {
        if ($type === 'person' && $publisherid === (string)$userid && $audience === ['all']) {
            self::require_creator($userid);
            return;
        }
        self::require_writer($userid);
        if ($type === 'department' && in_array($publisherid, self::manageable_departments($userid), true)
                && $audience === [$publisherid]) {
            return;
        }
        if ($type === 'academy' && $publisherid === 'academy'
                && (self::can_manage($userid)
                    || has_capability('local/ustar:feedpublishacademy', \context_system::instance(), $userid))) {
            if ($audience === ['all']) {
                return;
            }
            if ((self::can_manage($userid)
                    || has_capability('local/ustar:feedsetaudience', \context_system::instance(), $userid))
                    && $audience && count($audience) === count(array_unique($audience))) {
                $valid = array_column(structure::get(structure::NAME_STRUCTURE)['departments'] ?? [], 'id');
                if (!array_diff($audience, $valid)) {
                    return;
                }
            }
        }
        throw new \required_capability_exception(\context_system::instance(),
            'local/ustar:feedpublishacademy', 'nopermissions', '');
    }

    /** Add this clause BEFORE keyset paging/limit; never fetch and filter in PHP. */
    public static function audience_sql(int $userid, string $alias, array &$params, string $prefix = 'feed'): string {
        self::require_reader($userid);
        if (self::can_manage($userid) && !view_as::active()) {
            return '1 = 1';
        }
        static $departments = [];
        if (!isset($departments[$userid])) {
            $ids = [self::department_id($userid)];
            // A current manager can read across their current reporting scope;
            // publishing from that scope still requires its separate capability.
            if (!view_as::active()) {
                $ids = array_merge($ids, organization_model::manager_department_ids($userid));
            }
            $departments[$userid] = array_values(array_unique(array_filter($ids)));
        }
        $params[$prefix . 'all'] = 'all';
        $params[$prefix . 'departmentkind'] = 'department';
        $ids = [];
        foreach ($departments[$userid] as $offset => $id) {
            $name = $prefix . 'dept' . $offset;
            $ids[] = ':' . $name;
            $params[$name] = $id;
        }
        $deptcondition = $ids ? 'fa.scopeid IN (' . implode(',', $ids) . ')' : '1 = 0';
        return "EXISTS (SELECT 1 FROM {local_ustar_feed_audience} fa
                       WHERE fa.postid = {$alias}.id AND
                         (fa.scopekind = :{$prefix}all OR
                          (fa.scopekind = :{$prefix}departmentkind AND {$deptcondition})))";
    }

    /** Published posts require both current ACLs; a repost never copies private content. */
    public static function readable(int $postid, int $userid): \stdClass {
        global $DB;
        $params = ['id' => $postid, 'published' => 'published'];
        $audience = self::audience_sql($userid, 'p', $params);
        $post = $DB->get_record_sql("SELECT p.* FROM {local_ustar_feed_posts} p
                                      WHERE p.id = :id AND p.status = :published AND {$audience}", $params);
        if (!$post) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/ustar:use', 'nopermissions', '');
        }
        if ($post->sourcepostid) {
            $source = self::readable((int)$post->sourcepostid, $userid);
            if ($source->sourcepostid) {
                throw new \invalid_parameter_exception('Недопустимая цепочка репостов.');
            }
        }
        return $post;
    }
}
