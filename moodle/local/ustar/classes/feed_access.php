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
        if ($type === 'person' && $publisherid === (string)$userid) {
            self::require_creator($userid);
            $scopes = self::parse_audience($audience);
            foreach ($scopes as $scope) {
                if ($scope['kind'] === 'department' && $scope['id'] !== self::department_id($userid)) {
                    throw new \invalid_parameter_exception('Можно выбрать только своё подразделение.');
                }
            }
            return;
        }
        self::require_writer($userid);
        $scopes = self::parse_audience($audience);
        if ($type === 'department' && in_array($publisherid, self::manageable_departments($userid), true)
                && $scopes === [['kind' => 'department', 'id' => $publisherid]]) {
            return;
        }
        if ($type === 'academy' && $publisherid === 'academy'
                && (self::can_manage($userid)
                    || has_capability('local/ustar:feedpublishacademy', \context_system::instance(), $userid))) {
            if ($scopes === [['kind' => 'all', 'id' => 'all']]) {
                return;
            }
            if ((self::can_manage($userid)
                    || has_capability('local/ustar:feedsetaudience', \context_system::instance(), $userid))) {
                return;
            }
        }
        throw new \required_capability_exception(\context_system::instance(),
            'local/ustar:feedpublishacademy', 'nopermissions', '');
    }

    /** Legacy department IDs remain valid; new scopes have an explicit kind. */
    public static function parse_audience(array $audience): array {
        global $DB;
        if (!$audience || count($audience) > 50 || count($audience) !== count(array_unique($audience))) {
            throw new \invalid_parameter_exception('Выберите от 1 до 50 уникальных адресатов.');
        }
        $departments = people::department_map(structure::get(structure::NAME_STRUCTURE));
        $positions = people::position_map(structure::get(structure::NAME_STRUCTURE));
        $scopes = [];
        $seen = [];
        foreach ($audience as $token) {
            $token = (string)$token;
            if ($token === 'all') {
                $scope = ['kind' => 'all', 'id' => 'all'];
            } else if ($token === 'manager:all') {
                $scope = ['kind' => 'manager', 'id' => 'all'];
            } else {
                $parts = explode(':', $token, 2);
                $kind = count($parts) === 2 ? $parts[0] : 'department';
                $id = count($parts) === 2 ? $parts[1] : $parts[0];
                if ($kind === 'department' && isset($departments[$id])) {
                    $scope = ['kind' => 'department', 'id' => $id];
                } else if ($kind === 'position' && isset($positions[$id])) {
                    $scope = ['kind' => 'position', 'id' => $id];
                } else if ($kind === 'user' && ctype_digit($id) && (int)$id > 1
                        && (string)(int)$id === $id
                        && $DB->record_exists('user', ['id' => (int)$id, 'deleted' => 0, 'suspended' => 0])
                        && accounts::participates((int)$id)) {
                    $scope = ['kind' => 'user', 'id' => $id];
                } else {
                    throw new \invalid_parameter_exception('Адресат публикации недоступен.');
                }
            }
            $key = $scope['kind'] . ':' . $scope['id'];
            if (isset($seen[$key])) {
                throw new \invalid_parameter_exception('Адресат выбран повторно.');
            }
            $seen[$key] = true;
            $scopes[] = $scope;
        }
        if (count($scopes) > 1 && in_array(['kind' => 'all', 'id' => 'all'], $scopes, true)) {
            throw new \invalid_parameter_exception('Для общей публикации отдельные адресаты не нужны.');
        }
        return $scopes;
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
        $params[$prefix . 'owner'] = $userid;
        $params[$prefix . 'departmentkind'] = 'department';
        $params[$prefix . 'userkind'] = 'user';
        $params[$prefix . 'userid'] = (string)$userid;
        $params[$prefix . 'positionkind'] = 'position';
        $positionid = view_as::active() ? view_as::position_id() :
            (string)(organization_identity::resolve($userid)['positionid'] ?? '');
        $params[$prefix . 'positionid'] = $positionid;
        $ids = [];
        foreach ($departments[$userid] as $offset => $id) {
            $name = $prefix . 'dept' . $offset;
            $ids[] = ':' . $name;
            $params[$name] = $id;
        }
        $deptcondition = $ids ? 'fa.scopeid IN (' . implode(',', $ids) . ')' : '1 = 0';
        $manager = !view_as::active() && organization_model::is_manager($userid);
        if ($manager) { $params[$prefix . 'managerkind'] = 'manager'; }
        $managercondition = $manager ? 'fa.scopekind = :' . $prefix . 'managerkind' : '1 = 0';
        return "({$alias}.actoruserid = :{$prefix}owner OR EXISTS
                (SELECT 1 FROM {local_ustar_feed_audience} fa
                       WHERE fa.postid = {$alias}.id AND
                         (fa.scopekind = :{$prefix}all OR
                          (fa.scopekind = :{$prefix}departmentkind AND {$deptcondition}) OR
                          (fa.scopekind = :{$prefix}userkind AND fa.scopeid = :{$prefix}userid) OR
                          (fa.scopekind = :{$prefix}positionkind AND fa.scopeid = :{$prefix}positionid) OR
                          {$managercondition})))";
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
