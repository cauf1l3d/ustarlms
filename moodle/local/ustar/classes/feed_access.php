<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Current workforce and audience boundary for every feed read and write. */
final class feed_access {
    public static function require_reader(int $userid): void {
        if (!accounts::participates($userid) || !has_capability('local/ustar:use', \context_system::instance(), $userid)) {
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

    public static function require_writer(int $userid): void {
        self::require_actor($userid);
        if (!has_capability('local/ustar:feedpublish', \context_system::instance(), $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/ustar:feedpublish', 'nopermissions', '');
        }
    }

    public static function department_id(int $userid): string {
        $identity = organization_identity::resolve($userid);
        return $identity['conflicts'] ? '' : (string)$identity['departmentid'];
    }

    /** A manager must have the separate permission AND current scope. */
    public static function manageable_departments(int $userid): array {
        if (!has_capability('local/ustar:feedpublishdepartment', \context_system::instance(), $userid)) {
            return [];
        }
        $scope = organization_model::manager_scope($userid);
        return $scope['allowed'] ? array_values(array_filter($scope['departmentids'])) : [];
    }

    public static function assert_publisher(int $userid, string $type, string $publisherid,
            array $audience): void {
        self::require_writer($userid);
        if ($type === 'person' && $publisherid === (string)$userid && $audience === ['all']) {
            return;
        }
        if ($type === 'department' && in_array($publisherid, self::manageable_departments($userid), true)
                && $audience === [$publisherid]) {
            return;
        }
        if ($type === 'academy' && $publisherid === 'academy'
                && has_capability('local/ustar:feedpublishacademy', \context_system::instance(), $userid)) {
            if ($audience === ['all']) {
                return;
            }
            if (has_capability('local/ustar:feedsetaudience', \context_system::instance(), $userid)
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
        static $departments = [];
        if (!isset($departments[$userid])) {
            $ids = [self::department_id($userid)];
            // A current manager can read across their current reporting scope;
            // publishing from that scope still requires its separate capability.
            if (organization_model::is_manager($userid)) {
                $scope = organization_model::manager_scope($userid);
                $ids = array_merge($ids, $scope['departmentids'] ?? []);
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
